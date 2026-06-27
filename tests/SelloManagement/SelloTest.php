<?php

declare(strict_types=1);

namespace App\Tests\Sello;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Tests de integración HTTP para la gestión de sellos institucionales:
 *
 *   GET    /index.php?action=sello
 *   POST   /index.php?action=sello
 *   PUT    /index.php?action=sello&id={id}
 *   DELETE /index.php?action=sello&id={id}
 *
 * DECISIÓN DE DISEÑO — Por qué los sellos no usan Doctrine:
 * ---------------------------------------------------------------
 * A diferencia de Users y Credentials, SelloController persiste los datos
 * en un archivo JSON local (data/sellos.json), no en la base de datos.
 * Por eso la limpieza post-test no puede hacerse con $em->remove(), sino
 * que se realiza mediante el endpoint DELETE de la propia API.
 *
 * El flujo de cada test es:
 *   setUp()    → crea un admin de prueba y obtiene su JWT
 *   test()     → crea sello/s via POST y registra sus IDs en $createdSelloIds
 *   tearDown() → borra los sellos via DELETE (antes de que parent borre el usuario)
 *
 * SELLO_007 — No tiene definición explícita en la planilla pero aparece
 * en la tabla de ejecución. Se infiere del user story ("Edición de datos")
 * como la edición de nombre y src de un sello existente.
 *
 * Mapeo con la planilla QA:
 *   SELLO_001 → testCrearSelloCorrectamenteDevuelve201
 *   SELLO_002 → testOcultarSelloActivoDevuelveVisibleFalse
 *   SELLO_003 → testMostrarSelloOcultoDevuelveVisibleTrue
 *   SELLO_004 → testEliminarSelloDevuelve200YDesaparece
 *   SELLO_005 → testListarSellosDevuelveTodos
 *   SELLO_006 → testCrearSelloSinNombreDevuelve400
 *   SELLO_007 → testEditarNombreYSrcDeUnSello
 *
 * Requisitos cubiertos: RF14
 */
final class SelloTest extends IntegrationTestCase
{
    /** @var int[] IDs de sellos creados en este test, para borrarlos en tearDown */
    private array $createdSelloIds = [];

    /** JWT del admin de prueba, válido durante todo el test */
    private string $token = '';

    // =====================================================================
    // Hooks de ciclo de vida
    // =====================================================================

    protected function setUp(): void
    {
        // Inicializa infraestructura (BD, servidor, rate limit)
        parent::setUp();

        // Crea un admin de prueba y obtiene su JWT.
        // El admin queda en $createdUserIds y se borra en parent::tearDown().
        $admin       = $this->crearUsuarioDePrueba('ClaveSegura123!', rol: 'admin');
        $this->token = $this->login($admin->getUsuario(), 'ClaveSegura123!');
    }

    protected function tearDown(): void
    {
        // 1. Borra los sellos de prueba via HTTP ANTES de que parent::tearDown()
        //    elimine el usuario admin (con el que el token fue firmado).
        foreach ($this->createdSelloIds as $id) {
            $this->deleteJson('sello', $id, $this->token);
        }
        $this->createdSelloIds = [];
        $this->token           = '';

        // 2. Borra el usuario admin de prueba (Doctrine)
        parent::tearDown();
    }

    // =====================================================================
    // Helper privado: creación de sello de prueba
    // =====================================================================

    /**
     * Crea un sello via POST y registra su ID para limpieza automática.
     * Devuelve [httpCode, body] para que el test pueda hacer sus assertions.
     */
    private function crearSelloDePrueba(
        string $nombre  = 'Sello QA Test',
        string $src     = 'images/test_qa.png',
        bool   $visible = true
    ): array {
        [$code, $body] = $this->postJson('sello', [
            'nombre'  => $nombre,
            'src'     => $src,
            'visible' => $visible,
        ], $this->token);

        if ($code === 201 && isset($body['data']['id'])) {
            $this->createdSelloIds[] = (int) $body['data']['id'];
        }

        return [$code, $body];
    }

    // =====================================================================
    // SELLO_001 - Alta de sello
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_001 - Alta de sello correcta devuelve 201 con datos del sello')]
    public function testCrearSelloCorrectamenteDevuelve201(): void
    {
        $nombre = 'Sello QA ' . uniqid();

        [$httpCode, $body] = $this->crearSelloDePrueba($nombre, 'images/sello_qa.png');

        $this->assertSame(201, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Sello creado correctamente.', $body['message']);

        // Verifica estructura del sello devuelto
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertIsInt($body['data']['id']);
        $this->assertSame($nombre, $body['data']['nombre']);
        $this->assertSame('images/sello_qa.png', $body['data']['src']);
        $this->assertTrue($body['data']['visible'],
            'Un sello recién creado debe ser visible por defecto.');
    }

    #[Test]
    #[TestDox('SELLO_001 - Alta de sello sin src explícito crea sello con src vacío')]
    public function testCrearSelloSinSrcDevuelve201(): void
    {
        [$httpCode, $body] = $this->postJson('sello', [
            'nombre' => 'Sello sin src ' . uniqid(),
        ], $this->token);

        if ($httpCode === 201 && isset($body['data']['id'])) {
            $this->createdSelloIds[] = (int) $body['data']['id'];
        }

        $this->assertSame(201, $httpCode);
        $this->assertSame('success', $body['status']);
    }

    // =====================================================================
    // SELLO_002 - Ocultar sello activo (toggle visible → false)
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_002 - Ocultar sello activo actualiza visible a false')]
    public function testOcultarSelloActivoDevuelveVisibleFalse(): void
    {
        // Crea sello visible
        [$_code, $createBody] = $this->crearSelloDePrueba('Sello Activo ' . uniqid());
        $id = $createBody['data']['id'];

        // Oculta el sello
        [$httpCode, $body] = $this->putJson('sello', $id, ['visible' => false], $this->token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Sello actualizado.', $body['message']);
        $this->assertFalse($body['data']['visible'],
            'El sello debe quedar con visible=false tras ocultarlo.');
        $this->assertSame($id, $body['data']['id'],
            'El ID del sello no debe cambiar tras la actualización.');
    }

    // =====================================================================
    // SELLO_003 - Mostrar sello oculto (toggle visible → true)
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_003 - Mostrar sello oculto actualiza visible a true')]
    public function testMostrarSelloOcultoDevuelveVisibleTrue(): void
    {
        // Crea sello directamente oculto
        [$_code, $createBody] = $this->crearSelloDePrueba('Sello Oculto ' . uniqid(), visible: false);
        $id = $createBody['data']['id'];

        // Muestra el sello
        [$httpCode, $body] = $this->putJson('sello', $id, ['visible' => true], $this->token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Sello actualizado.', $body['message']);
        $this->assertTrue($body['data']['visible'],
            'El sello debe quedar con visible=true tras mostrarlo.');
    }

    // =====================================================================
    // SELLO_004 - Eliminación permanente
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_004 - Eliminar sello devuelve 200 y el sello desaparece del listado')]
    public function testEliminarSelloDevuelve200YDesaparece(): void
    {
        [$_code, $createBody] = $this->crearSelloDePrueba('Sello Para Eliminar ' . uniqid());
        $id = $createBody['data']['id'];

        // Elimina el sello
        [$deleteCode, $deleteBody] = $this->deleteJson('sello', $id, $this->token);

        $this->assertSame(200, $deleteCode);
        $this->assertSame('success', $deleteBody['status']);
        $this->assertSame('Sello eliminado correctamente.', $deleteBody['message']);

        // Quita el ID de la lista para que tearDown no intente borrarlo de nuevo
        $this->createdSelloIds = array_filter(
            $this->createdSelloIds,
            fn(int $i): bool => $i !== $id
        );

        // Verifica que el sello ya no aparece en el listado
        [$listCode, $listBody] = $this->getJson('sello', [], $this->token);
        $this->assertSame(200, $listCode);

        $ids = array_column($listBody['data'], 'id');
        $this->assertNotContains($id, $ids,
            "El sello con id={$id} sigue en el listado tras eliminarlo.");
    }

    #[Test]
    #[TestDox('SELLO_004 - Intentar eliminar sello inexistente devuelve 404')]
    public function testEliminarSelloInexistenteDevuelve404(): void
    {
        [$httpCode, $body] = $this->deleteJson('sello', 999999, $this->token);

        $this->assertSame(404, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Sello no encontrado.', $body['message']);
    }

    // =====================================================================
    // SELLO_005 - Listado de sellos
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_005 - Listado devuelve todos los sellos (activos y ocultos) con sus estados')]
    public function testListarSellosDevuelveTodos(): void
    {
        // Crea un sello visible y uno oculto para asegurarnos de que
        // el endpoint devuelve ambos estados
        [$_code, $bodyV] = $this->crearSelloDePrueba('Sello Visible QA ' . uniqid(), visible: true);
        [$_code, $bodyO] = $this->crearSelloDePrueba('Sello Oculto QA ' . uniqid(), visible: false);

        $idVisible = $bodyV['data']['id'];
        $idOculto  = $bodyO['data']['id'];

        [$httpCode, $body] = $this->getJson('sello', [], $this->token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Sellos obtenidos.', $body['message']);
        $this->assertIsArray($body['data']);

        $ids = array_column($body['data'], 'id');

        // Ambos sellos deben aparecer en el listado
        $this->assertContains($idVisible, $ids,
            'El sello visible debe aparecer en el listado.');
        $this->assertContains($idOculto, $ids,
            'El sello oculto también debe aparecer en el listado (no se filtra por visible).');

        // Verifica la estructura de cada sello devuelto
        foreach ($body['data'] as $sello) {
            $this->assertArrayHasKey('id', $sello);
            $this->assertArrayHasKey('nombre', $sello);
            $this->assertArrayHasKey('src', $sello);
            $this->assertArrayHasKey('visible', $sello);
        }
    }

    #[Test]
    #[TestDox('SELLO_005 - El listado requiere autenticación JWT')]
    public function testListarSellosSinTokenDevuelve401(): void
    {
        [$httpCode, $body] = $this->getJson('sello');

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
    }

    // =====================================================================
    // SELLO_006 - Validación de campo obligatorio
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_006 - Crear sello sin nombre devuelve 400 con mensaje de campo obligatorio')]
    public function testCrearSelloSinNombreDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('sello', [
            'src'     => 'images/sello.png',
            'visible' => true,
            // 'nombre' ausente
        ], $this->token);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('El campo nombre es obligatorio.', $body['message']);
    }

    #[Test]
    #[TestDox('SELLO_006 - Crear sello con nombre vacío devuelve 400')]
    public function testCrearSelloConNombreVacioDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('sello', [
            'nombre' => '',
            'src'    => 'images/sello.png',
        ], $this->token);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('El campo nombre es obligatorio.', $body['message']);
    }

    // =====================================================================
    // SELLO_007 - Edición de nombre y src
    // =====================================================================

    #[Test]
    #[TestDox('SELLO_007 - Editar nombre y src de un sello existente devuelve 200 con datos actualizados')]
    public function testEditarNombreYSrcDeUnSello(): void
    {
        [$_code, $createBody] = $this->crearSelloDePrueba('Nombre Original ' . uniqid());
        $id = $createBody['data']['id'];

        $nuevoNombre = 'Nombre Editado ' . uniqid();
        $nuevoSrc    = 'images/editado_qa.png';

        [$httpCode, $body] = $this->putJson('sello', $id, [
            'nombre' => $nuevoNombre,
            'src'    => $nuevoSrc,
        ], $this->token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Sello actualizado.', $body['message']);
        $this->assertSame($nuevoNombre, $body['data']['nombre'],
            'El nombre debe reflejar el valor editado.');
        $this->assertSame($nuevoSrc, $body['data']['src'],
            'El src debe reflejar el valor editado.');
        $this->assertSame($id, $body['data']['id'],
            'El ID no debe cambiar tras la edición.');
    }

    #[Test]
    #[TestDox('SELLO_007 - Editar sello inexistente devuelve 404')]
    public function testEditarSelloInexistenteDevuelve404(): void
    {
        [$httpCode, $body] = $this->putJson('sello', 999999, [
            'nombre' => 'No existe',
        ], $this->token);

        $this->assertSame(404, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Sello no encontrado.', $body['message']);
    }
}