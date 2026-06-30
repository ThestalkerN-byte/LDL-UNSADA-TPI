<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Tests de integración HTTP sobre el buscador de usuarios del panel admin:
 *
 *   GET /index.php?action=user
 *   GET /index.php?action=user&dni={dni}
 *   GET /index.php?action=user&apellido={apellido}
 *   GET /index.php?action=user&rol={rol}
 *
 * El endpoint requiere autenticación JWT (cualquier usuario activo).
 * Cada test crea su propio usuario admin, obtiene el token, y usa
 * usuarios de prueba con datos únicos (prefijo "BUSCADORQA") para
 * no interferir con datos reales de la base de datos.
 *
 * Mapeo con la planilla QA:
 *   BUSCADOR_001 → testBusquedaPorDniExistenteDevuelveUsuario
 *   BUSCADOR_002 → testBusquedaPorDniInexistenteDevuelveArrayVacio
 *   BUSCADOR_003 → testBusquedaPorApellidoDevuelveCoincidencias
 *   BUSCADOR_004 → testFiltradoPorRolDevuelveSoloEseRol
 *
 * Requisitos cubiertos: RF10
 */
final class BuscadorTest extends IntegrationTestCase
{
    // =====================================================================
    // Helper privado: login rápido para obtener JWT
    // =====================================================================

    /**
     * Crea un admin de prueba, hace login y devuelve el JWT.
     * El admin queda registrado en $createdUserIds para limpieza automática.
     */
    private function loginComoAdmin(): string
    {
        $admin = $this->crearUsuarioDePrueba('ClaveSegura123!', rol: 'admin');
        return $this->login($admin->getUsuario(), 'ClaveSegura123!');
    }

    // =====================================================================
    // BUSCADOR_001 - Búsqueda por DNI existente
    // =====================================================================

    #[Test]
    #[TestDox('BUSCADOR_001 - Búsqueda por DNI existente devuelve el usuario correspondiente')]
    public function testBusquedaPorDniExistenteDevuelveUsuario(): void
    {
        $token = $this->loginComoAdmin();

        // DNI único con prefijo reconocible para no confundir con datos reales
        $dniUnico = '88' . substr(preg_replace('/\D/', '', uniqid()), 0, 7);
        $this->crearUsuarioDePrueba(dni: $dniUnico);

        [$httpCode, $body] = $this->getJson('user', ['dni' => $dniUnico], $token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertNotEmpty($body['data'],
            'La búsqueda por DNI existente debe devolver al menos un usuario.');

        // Verifica que el usuario encontrado tiene el DNI buscado
        $dnisEncontrados = array_column($body['data'], 'dni');
        $this->assertContains($dniUnico, $dnisEncontrados,
            "El usuario con DNI {$dniUnico} debe aparecer en los resultados.");
    }

    #[Test]
    #[TestDox('BUSCADOR_001 - Búsqueda por DNI parcial devuelve coincidencias')]
    public function testBusquedaPorDniParcialDevuelveCoincidencias(): void
    {
        $token = $this->loginComoAdmin();

        // Crea dos usuarios con el mismo prefijo de DNI para validar búsqueda parcial
        $prefijoDni = '77' . substr(preg_replace('/\D/', '', uniqid()), 0, 5);
        $this->crearUsuarioDePrueba(dni: $prefijoDni . '00');
        $this->crearUsuarioDePrueba(dni: $prefijoDni . '99');

        [$httpCode, $body] = $this->getJson('user', ['dni' => $prefijoDni], $token);

        $this->assertSame(200, $httpCode);

        $dnisEncontrados = array_column($body['data'], 'dni');
        $this->assertContains($prefijoDni . '00', $dnisEncontrados);
        $this->assertContains($prefijoDni . '99', $dnisEncontrados);
    }

    // =====================================================================
    // BUSCADOR_002 - Búsqueda por DNI inexistente
    // =====================================================================

    #[Test]
    #[TestDox('BUSCADOR_002 - Búsqueda por DNI inexistente devuelve array vacío (HTTP 200)')]
    public function testBusquedaPorDniInexistenteDevuelveArrayVacio(): void
    {
        // NOTA: La API devuelve 200 + data:[] cuando no hay resultados.
        // El mensaje "No se encontró ningún usuario" lo muestra el FRONTEND
        // al recibir la respuesta con data vacío — no es un mensaje de la API.
        $token = $this->loginComoAdmin();

        [$httpCode, $body] = $this->getJson('user', ['dni' => '00000000000'], $token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertIsArray($body['data']);
        $this->assertEmpty($body['data'],
            'La búsqueda por DNI inexistente debe devolver un array vacío.');
    }

    // =====================================================================
    // BUSCADOR_003 - Búsqueda por apellido
    // =====================================================================

    #[Test]
    #[TestDox('BUSCADOR_003 - Búsqueda por apellido devuelve todos los usuarios que coinciden')]
    public function testBusquedaPorApellidoDevuelveCoincidencias(): void
    {
        $token = $this->loginComoAdmin();

        // Apellido único: garantiza que solo encontramos nuestros usuarios de prueba
        $apellidoUnico = 'BUSCADORQA_' . strtoupper(substr(uniqid(), -6));
        $this->crearUsuarioDePrueba(apellido: $apellidoUnico);
        $this->crearUsuarioDePrueba(apellido: $apellidoUnico);
        $this->crearUsuarioDePrueba(apellido: 'OtroApellido_' . uniqid()); // no debe aparecer

        [$httpCode, $body] = $this->getJson('user', ['apellido' => $apellidoUnico], $token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);

        // Solo deben aparecer los 2 usuarios con ese apellido exacto
        $apellidosEncontrados = array_column($body['data'], 'apellido');
        $this->assertCount(2, array_filter(
            $apellidosEncontrados,
            fn(string $a): bool => $a === $apellidoUnico
        ), "Deben encontrarse exactamente 2 usuarios con apellido '{$apellidoUnico}'.");
    }

    #[Test]
    #[TestDox('BUSCADOR_003 - Búsqueda por apellido parcial devuelve coincidencias')]
    public function testBusquedaPorApellidoParcialDevuelveCoincidencias(): void
    {
        $token = $this->loginComoAdmin();

        $sufijo = strtoupper(substr(uniqid(), -6));
        $this->crearUsuarioDePrueba(apellido: 'BUSCADORQA_García_' . $sufijo);
        $this->crearUsuarioDePrueba(apellido: 'BUSCADORQA_Garcés_' . $sufijo);

        // Búsqueda parcial por "BUSCADORQA" — debe traer ambos
        [$httpCode, $body] = $this->getJson('user', ['apellido' => 'BUSCADORQA'], $token);

        $this->assertSame(200, $httpCode);
        $apellidos = array_column($body['data'], 'apellido');
        $this->assertGreaterThanOrEqual(2, count(array_filter(
            $apellidos,
            fn(string $a): bool => str_contains($a, 'BUSCADORQA')
        )));
    }

    // =====================================================================
    // BUSCADOR_004 - Filtrado por rol
    // =====================================================================

    #[Test]
    #[TestDox('BUSCADOR_004 - Filtrar por rol "user" devuelve solo usuarios con ese rol')]
    public function testFiltradoPorRolUserDevuelveSoloUsers(): void
    {
        $token = $this->loginComoAdmin();

        // Crea usuarios con roles distintos
        $apellido = 'BUSCADORQA_ROL_' . strtoupper(substr(uniqid(), -5));
        $this->crearUsuarioDePrueba(rol: 'user',  apellido: $apellido . '_U');
        $this->crearUsuarioDePrueba(rol: 'user',  apellido: $apellido . '_U2');
        $this->crearUsuarioDePrueba(rol: 'admin', apellido: $apellido . '_A');

        [$httpCode, $body] = $this->getJson('user', ['rol' => 'user', 'apellido' => 'BUSCADORQA_ROL_'], $token);

        $this->assertSame(200, $httpCode);
        $this->assertNotEmpty($body['data'],
            'Debe haber al menos un usuario con rol "user".');

        // Verifica que NINGÚN resultado tenga rol distinto a "user"
        foreach ($body['data'] as $usuario) {
            $this->assertSame('user', $usuario['rol'],
                "El usuario {$usuario['usuario']} tiene rol '{$usuario['rol']}', se esperaba 'user'.");
        }
    }

    #[Test]
    #[TestDox('BUSCADOR_004 - Filtrar por rol "admin" devuelve solo administradores')]
    public function testFiltradoPorRolAdminDevuelveSoloAdmins(): void
    {
        $token = $this->loginComoAdmin();

        $apellido = 'BUSCADORQA_ADM_' . strtoupper(substr(uniqid(), -5));
        $this->crearUsuarioDePrueba(rol: 'admin', apellido: $apellido . '_A');
        $this->crearUsuarioDePrueba(rol: 'user',  apellido: $apellido . '_U');

        [$httpCode, $body] = $this->getJson('user', ['rol' => 'admin', 'apellido' => 'BUSCADORQA_ADM_'], $token);

        $this->assertSame(200, $httpCode);
        $this->assertNotEmpty($body['data']);

        foreach ($body['data'] as $usuario) {
            $this->assertSame('admin', $usuario['rol'],
                "El usuario {$usuario['usuario']} tiene rol '{$usuario['rol']}', se esperaba 'admin'.");
        }
    }

    // =====================================================================
    // Sin filtros y combinación de filtros
    // =====================================================================

    #[Test]
    #[TestDox('Búsqueda sin filtros devuelve todos los usuarios activos')]
    public function testBusquedaSinFiltrosDevuelveTodosLosActivos(): void
    {
        $token = $this->loginComoAdmin();
        $this->crearUsuarioDePrueba();

        [$httpCode, $body] = $this->getJson('user', [], $token);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertNotEmpty($body['data'],
            'Sin filtros debe devolver todos los usuarios activos (al menos el que creamos).');

        // Verifica que todos los devueltos están activos (estado = true)
        foreach ($body['data'] as $usuario) {
            $this->assertTrue($usuario['estado'],
                "El usuario {$usuario['usuario']} está inactivo pero apareció en la búsqueda.");
        }
    }

    #[Test]
    #[TestDox('Combinación de filtros DNI + rol devuelve solo las coincidencias exactas')]
    public function testCombinacionDeFiltrosDniYRol(): void
    {
        $token = $this->loginComoAdmin();

        $dniBase = '66' . substr(preg_replace('/\D/', '', uniqid()), 0, 7);
        $this->crearUsuarioDePrueba(rol: 'user',  dni: $dniBase . '0');
        $this->crearUsuarioDePrueba(rol: 'admin', dni: $dniBase . '1'); // mismo prefijo, rol diferente

        // Filtra por el prefijo del DNI Y por rol 'user' → solo debe aparecer el primero
        [$httpCode, $body] = $this->getJson('user', ['dni' => $dniBase, 'rol' => 'user'], $token);

        $this->assertSame(200, $httpCode);
        $this->assertNotEmpty($body['data']);

        foreach ($body['data'] as $usuario) {
            $this->assertSame('user', $usuario['rol']);
            $this->assertStringContainsString($dniBase, $usuario['dni']);
        }
    }

    #[Test]
    #[TestDox('Endpoint sin autenticación devuelve 401')]
    public function testEndpointSinAutenticacionDevuelve401(): void
    {
        // El AuthMiddleware protege ?action=user; sin JWT debe rechazar con 401
        [$httpCode, $body] = $this->getJson('user');

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
    }
}