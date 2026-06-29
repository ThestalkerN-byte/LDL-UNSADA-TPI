<?php

declare(strict_types=1);

namespace App\Tests\CredentialManagement;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pruebas de integración HTTP — Módulo CREDADMIN: Gestión de credenciales (Admin)
 *
 * Endpoints cubiertos:
 *   PUT  /index.php?action=credential&id={id}
 *   POST /index.php?action=credential&sub=renew&id={id}
 *
 * Mapeo con la planilla QA:
 *   ADMIN_005 → testModificacionCorrectaDeFechaYSellos()
 *   ADMIN_006 → testRenovacionDeCredencialVigente()
 *   ADMIN_006 → testRenovacionDeCredencialVencida()
 *
 * Requisito cubierto: RF09
 *
 * Estrategia de limpieza:
 *   Los usuarios de prueba se crean directamente por Doctrine (crearUsuarioDePrueba)
 *   y se registran en $createdUserIds para que IntegrationTestCase los borre
 *   automáticamente en tearDown(). Las credenciales asociadas se eliminan solas
 *   por la FK con ON DELETE CASCADE definida en la entidad Credential.
 */
final class CredentialManagementTest extends IntegrationTestCase
{
    /** Token JWT del admin, obtenido una vez por test en setUp(). */
    private string $adminToken = '';

    /**
     * IDs de credenciales renovadas (nueva credencial que crea el endpoint renew).
     * Se limpian vía API antes de que tearDown() elimine el usuario,
     * por si la FK no alcanza a cubrirlas (credenciales huérfanas de renovación).
     *
     * @var int[]
     */
    private array $credencialesRenovadasIds = [];

    // =====================================================================
    // Ciclo de vida
    // =====================================================================

    protected function setUp(): void
    {
        // La clase base verifica la BD, limpia el rate limiter y controla el servidor.
        parent::setUp();

        // Login de admin una vez por test (el token expira entre suites largas).
        $this->adminToken = $this->login('admin', '123456');
    }

    protected function tearDown(): void
    {
        // Las credenciales RENOVADAS generan un nuevo registro que puede no estar
        // ligado al usuario si el endpoint crea una credencial independiente.
        // Las borramos vía API antes de que Doctrine elimine el usuario.
        foreach (array_unique(array_reverse($this->credencialesRenovadasIds)) as $id) {
            $this->deleteJson('credential', (int) $id, $this->adminToken);
        }
        $this->credencialesRenovadasIds = [];

        // La clase base elimina usuarios + sus credenciales originales (ON DELETE CASCADE).
        parent::tearDown();
    }

    // =====================================================================
    // ADMIN_005 — Modificación correcta de fecha y sellos
    // =====================================================================

    #[Test]
    #[TestDox('ADMIN_005 - La fecha de vencimiento y los sellos se actualizan correctamente (HTTP 200)')]
    public function testModificacionCorrectaDeFechaYSellos(): void
    {
        // Preparación: usuario + credencial inicial
        $usuario    = $this->crearUsuarioDePrueba();
        $credencial = $this->crearCredencialDeTesting($usuario->getId(), '+30 days', ['Sello Inicial']);

        $nuevaFecha   = (new \DateTimeImmutable('today +180 days'))->format('Y-m-d');
        $nuevosSellos = ['Sello A', 'Sello B'];

        // Acción: modificar fecha y sellos vía PUT
        [$httpCode, $body] = $this->putJson(
            'credential',
            (int) $credencial['id'],
            [
                'fecha_vencimiento' => $nuevaFecha,
                'sellos'            => $nuevosSellos,
            ],
            $this->adminToken
        );

        // Verificación de la respuesta del PUT
        $this->assertSame(200, $httpCode, 'PUT credential debería devolver HTTP 200.');
        $this->assertSame('success', $body['status'] ?? '', 'El campo status debe ser "success".');

        // Verificación leyendo el recurso actualizado
        [$getCode, $getBody] = $this->getJson('credential', ['id' => $credencial['id']], $this->adminToken);

        $this->assertSame(200, $getCode, 'GET credential debería devolver HTTP 200.');
        $this->assertSame('success', $getBody['status'] ?? '');
        $this->assertSame(
            $nuevaFecha,
            $getBody['data']['fecha_vencimiento'] ?? null,
            'La fecha de vencimiento almacenada debe coincidir con la enviada.'
        );

        // Verificación de sellos (si el endpoint los devuelve)
        if (isset($getBody['data']['sellos'])) {
            $sellosNormalizados = $this->normalizarSellos($getBody['data']['sellos']);
            $this->assertEqualsCanonicalizing(
                $nuevosSellos,
                $sellosNormalizados,
                'Los sellos almacenados deben coincidir con los enviados (sin importar el orden).'
            );
        }
    }

    // =====================================================================
    // ADMIN_006 — Renovación de credencial vigente
    // =====================================================================

    #[Test]
    #[TestDox('ADMIN_006 - Una credencial vigente se renueva con fecha posterior a la original (HTTP 200)')]
    public function testRenovacionDeCredencialVigente(): void
    {
        $usuario        = $this->crearUsuarioDePrueba();
        $fechaOriginal  = (new \DateTimeImmutable('today +60 days'))->format('Y-m-d');
        $credencial     = $this->crearCredencialDeTesting($usuario->getId(), '+60 days', ['Sello Vigente']);

        // Acción: renovar vía POST con sub=renew
        [$httpCode, $body] = $this->postJsonConQueryExtra(
            'credential',
            ['sub' => 'renew', 'id' => $credencial['id']],
            [],
            $this->adminToken
        );

        $this->assertSame(200, $httpCode, 'POST renew debería devolver HTTP 200.');
        $this->assertSame('success', $body['status'] ?? '');

        // Registrar la credencial renovada para limpiarla en tearDown
        $idRenovada = $this->extraerIdRenovada($body, $credencial['id']);
        if ($idRenovada !== $credencial['id']) {
            $this->credencialesRenovadasIds[] = $idRenovada;
        }

        $fechaRenovada = $this->extraerFechaRenovada($body, $idRenovada);
        $this->assertNotEmpty($fechaRenovada, 'No se pudo obtener la fecha de vencimiento renovada.');

        $this->assertGreaterThan(
            strtotime($fechaOriginal),
            strtotime($fechaRenovada),
            'La credencial vigente renovada debe tener una fecha posterior a la original.'
        );
    }

    // =====================================================================
    // ADMIN_006 — Renovación de credencial vencida
    // =====================================================================

    #[Test]
    #[TestDox('ADMIN_006 - Una credencial vencida se renueva sumando un año desde hoy (HTTP 200)')]
    public function testRenovacionDeCredencialVencida(): void
    {
        $usuario    = $this->crearUsuarioDePrueba();
        $credencial = $this->crearCredencialDeTesting($usuario->getId(), '-60 days', ['Sello Vencido']);

        [$httpCode, $body] = $this->postJsonConQueryExtra(
            'credential',
            ['sub' => 'renew', 'id' => $credencial['id']],
            [],
            $this->adminToken
        );

        $this->assertSame(200, $httpCode, 'POST renew debería devolver HTTP 200.');
        $this->assertSame('success', $body['status'] ?? '');

        $idRenovada = $this->extraerIdRenovada($body, $credencial['id']);
        if ($idRenovada !== $credencial['id']) {
            $this->credencialesRenovadasIds[] = $idRenovada;
        }

        $fechaRenovada = $this->extraerFechaRenovada($body, $idRenovada);
        $fechaEsperada = (new \DateTimeImmutable('today +1 year'))->format('Y-m-d');

        $this->assertSame(
            $fechaEsperada,
            $fechaRenovada,
            'Una credencial vencida debe renovarse sumando exactamente un año desde hoy.'
        );
    }

    // =====================================================================
    // Helpers de preparación de datos
    // =====================================================================

    /**
     * Crea una credencial de prueba vía API para el usuario dado.
     * Devuelve ['id', 'fecha_vencimiento', 'sellos'].
     *
     * La credencial no se registra en $createdUserIds porque se elimina
     * automáticamente por ON DELETE CASCADE al borrar el usuario.
     *
     * @param int|string $idUsuario
     * @param string     $offsetFecha  Offset relativo a hoy en formato DateTimeImmutable
     *                                 (ej. '+30 days', '-60 days')
     * @param string[]   $sellos
     */
    private function crearCredencialDeTesting(int|string $idUsuario, string $offsetFecha, array $sellos): array
    {
        $fechaVencimiento = (new \DateTimeImmutable('today ' . $offsetFecha))->format('Y-m-d');

        [$httpCode, $body] = $this->postJson(
            'credential',
            [
                'id_usuario'        => $idUsuario,
                'fecha_vencimiento' => $fechaVencimiento,
                'sellos'            => $sellos,
            ],
            $this->adminToken
        );

        $this->assertContains(
            $httpCode,
            [200, 201],
            'No se pudo crear la credencial de testing. HTTP ' . $httpCode
        );
        $this->assertSame('success', $body['status'] ?? '');

        $id = $body['data']['id'] ?? null;
        $this->assertNotNull($id, 'El endpoint no devolvió el ID de la credencial creada.');

        return [
            'id'                => $id,
            'fecha_vencimiento' => $fechaVencimiento,
            'sellos'            => $sellos,
        ];
    }

    // =====================================================================
    // Helpers HTTP especializados
    // =====================================================================

    /**
     * POST con query params adicionales arbitrarios (ej. sub=renew&id=5).
     *
     * Los helpers postJson/getJson de IntegrationTestCase construyen la URL
     * como ?action=X, pero el endpoint de renovación necesita ?action=credential&sub=renew&id=N.
     * Este helper usa makeRequest() que admite la URI completa.
     *
     * @param string      $action      Valor del parámetro ?action=
     * @param array       $extraParams Parámetros adicionales de query string (sub, id, etc.)
     * @param array       $payload     Body JSON
     * @param string|null $token       JWT
     *
     * @return array{int, array}  [httpCode, body decodificado]
     */
    private function postJsonConQueryExtra(
        string  $action,
        array   $extraParams,
        array   $payload  = [],
        ?string $token    = null,
    ): array {
        $query = '?action=' . $action;
        foreach ($extraParams as $key => $value) {
            $query .= '&' . urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        return self::makeRequest('POST', $query, $payload, $token);
    }

    // =====================================================================
    // Helpers de extracción de datos de respuesta
    // =====================================================================

    /**
     * Extrae el ID de la credencial resultante de la renovación.
     * Prueba las claves que el endpoint podría devolver (nueva_id, id_nueva, id).
     * Si ninguna existe o coincide con el ID original, devuelve el original.
     *
     * @param int|string $idOriginal
     * @return int|string
     */
    private function extraerIdRenovada(array $body, int|string $idOriginal): int|string
    {
        $data = $body['data'] ?? [];

        return $data['nueva_id']
            ?? $data['id_nueva']
            ?? $data['id']
            ?? $idOriginal;
    }

    /**
     * Obtiene la fecha de vencimiento de la credencial renovada.
     * Primero intenta leerla del body de la respuesta; si no viene,
     * consulta el recurso vía GET.
     *
     * @param int|string $idCredencial
     */
    private function extraerFechaRenovada(array $body, int|string $idCredencial): string
    {
        $fecha = $body['data']['fecha_vencimiento'] ?? null;
        if (!empty($fecha)) {
            return $fecha;
        }

        [$getCode, $getBody] = $this->getJson('credential', ['id' => $idCredencial], $this->adminToken);

        $this->assertSame(200, $getCode, 'No se pudo consultar la credencial renovada. HTTP ' . $getCode);
        $this->assertSame('success', $getBody['status'] ?? '');

        return $getBody['data']['fecha_vencimiento'] ?? '';
    }

    /**
     * Normaliza un array de sellos que puede venir como strings o como objetos
     * (arrays asociativos con clave 'nombre', 'name' o 'descripcion').
     *
     * @param  array<int, string|array<string,string>> $sellos
     * @return string[]
     */
    private function normalizarSellos(array $sellos): array
    {
        return array_map(static function (mixed $sello): string {
            if (is_array($sello)) {
                return $sello['nombre'] ?? $sello['name'] ?? $sello['descripcion'] ?? '';
            }

            return (string) $sello;
        }, $sellos);
    }
}