<?php

declare(strict_types=1);

namespace App\Tests\Authentication;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Tests de integración HTTP sobre los endpoints de autenticación:
 *
 *   POST /index.php?action=login
 *   POST /index.php?action=logout
 *
 * La infraestructura (servidor embebido, BD, .env, rate limit)
 * es heredada de IntegrationTestCase.
 *
 * Mapeo con la planilla QA:
 *   LOGIN_001 → testLoginConCredencialesValidasDevuelve200ConToken
 *   LOGIN_002 → testLoginConPasswordIncorrectaDevuelve401
 *   LOGIN_003 → testLoginConCamposVaciosDevuelve400 / testLoginSinPasswordDevuelve400
 *   LOGIN_004 → testLoginConUsuarioInactivoDevuelve401
 *   LOGIN_005 → testLogoutDevuelve200YConfirmaDescartarToken
 *
 * Requisitos cubiertos: RF01, RNF02
 */
final class AuthControllerTest extends IntegrationTestCase
{
    // =====================================================================
    // LOGIN_001 - Credenciales válidas
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_001 - Login con usuario y contraseña válidos devuelve 200 con JWT')]
    public function testLoginConCredencialesValidasDevuelve200ConToken(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123!', rol: 'admin');

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123!',
        ]);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertArrayHasKey('token', $body['data'],
            'La respuesta debe incluir un JWT en data.token');
        $this->assertNotEmpty($body['data']['token']);
        $this->assertSame(3, substr_count($body['data']['token'], '.') + 1);
        $this->assertSame($user->getId(), $body['data']['id']);
        $this->assertSame('admin', $body['data']['rol']);
    }

    // =====================================================================
    // LOGIN_002 - Contraseña incorrecta / usuario inexistente
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_002 - Login con contraseña incorrecta devuelve 401')]
    public function testLoginConPasswordIncorrectaDevuelve401(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveCorrecta123!');

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'clave-incorrecta',
        ]);

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Credenciales inválidas o usuario inactivo.', $body['message']);
        $this->assertArrayNotHasKey('token', $body['data'] ?? []);
    }

    #[Test]
    #[TestDox('Login con usuario inexistente devuelve 401')]
    public function testLoginConUsuarioInexistenteDevuelve401(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => 'usuario_que_nunca_existira_xyz_999',
            'password'      => 'cualquier-clave',
        ]);

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
    }

    // =====================================================================
    // LOGIN_003 - Campos vacíos
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_003 - Login con ambos campos vacíos devuelve 400')]
    public function testLoginConCamposVaciosDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => '',
            'password'      => '',
        ]);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('El identificador y la contraseña son obligatorios.', $body['message']);
    }

    #[Test]
    #[TestDox('LOGIN_003 - Login sin enviar el campo password devuelve 400')]
    public function testLoginSinPasswordDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => 'admin',
        ]);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
    }

    // =====================================================================
    // LOGIN_004 - Usuario inactivo
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_004 - Login con usuario inactivo devuelve 401')]
    public function testLoginConUsuarioInactivoDevuelve401(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123!', estado: false);

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123!',
        ]);

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Credenciales inválidas o usuario inactivo.', $body['message']);
    }

    // =====================================================================
    // LOGIN_005 - Logout (JWT stateless)
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_005 - Logout con JWT válido devuelve 200')]
    public function testLogoutDevuelve200YConfirmaDescartarToken(): void
    {
        $user  = $this->crearUsuarioDePrueba('ClaveSegura123!');
        $token = $this->login($user->getUsuario(), 'ClaveSegura123!');

        [$logoutCode, $logoutBody] = $this->postJson('logout', [], $token);

        $this->assertSame(200, $logoutCode);
        $this->assertSame('success', $logoutBody['status']);
        $this->assertSame('Sesión cerrada correctamente.', $logoutBody['message']);
    }

    #[Test]
    #[TestDox('Logout sin token devuelve 200 (idempotente, stateless)')]
    public function testLogoutSinTokenEsIdempotente(): void
    {
        [$httpCode, $body] = $this->postJson('logout', []);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
    }
}
