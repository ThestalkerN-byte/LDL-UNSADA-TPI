<?php

declare(strict_types=1);

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del módulo Gestión de usuarios (Admin)
 *
 * Mapeo con la planilla QA:
 * 
 *   - ADMIN_001 -> testAltaCorrectaUsuario()
 *   - ADMIN_002 -> testAltaRechazadaPorDniDuplicado()
 *   - ADMIN_003 -> testModificacionDeDatosUsuario()
 *   - ADMIN_004 -> testBajaLogicaUsuario()
 *
 * Requisito cubierto: RF08
 */
final class UserManagementTest extends TestCase
{
    private string $baseUrl = "http://127.0.0.1:8000/index.php";

    private ?EntityManagerInterface $em = null;

    private array $usuariosCreadosIds = [];

    protected function setUp(): void
    {
        $this->em = require dirname(__DIR__, 2) . '/config/bootstrap.php';
    }

    protected function tearDown(): void
    {
        if ($this->usuariosCreadosIds === []) {
            return;
        }

        $adminCookie = $this->login('admin', '123456');

        if (!$adminCookie) {
            $this->usuariosCreadosIds = [];
            return;
        }

        foreach ($this->usuariosCreadosIds as $id) {
            $this->requestJson('DELETE', "?action=user&id=" . $id, null, $adminCookie);
        }

        $this->usuariosCreadosIds = [];
    }

    private function login(string $identificador, string $password): ?string
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'cookies_');

        $payload = json_encode([
            'identificador' => $identificador,
            'password'      => $password,
        ]);

        $ch = curl_init($this->baseUrl . "?action=login");

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $this->fail("No se pudo conectar al login: $error. Revisar servidor local.");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            return null;
        }

        return $cookieJar;
    }

    private function requestJson(string $method, string $queryString, ?array $payload = null, ?string $cookieJar = null): array
    {
        $ch = curl_init($this->baseUrl . $queryString);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }

        $response = curl_exec($ch);

        if ($response === false || curl_getinfo($ch, CURLINFO_HTTP_CODE) === 0) {
            $error = curl_error($ch);
            $this->fail("No se pudo contactar al servidor local: $error.");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'httpCode' => $httpCode,
            'body'     => json_decode((string) $response, true),
            'raw'      => $response,
        ];
    }

    private function generarUsuarioPayload(?string $dni = null): array
    {
        $random = mt_rand(10000, 99999);

        return [
            'usuario'  => 'userQA_' . $random,
            'password' => 'test1234',
            'nombre'   => 'Usuario',
            'apellido' => 'Testing',
            'dni'      => $dni ?? '99' . $random . mt_rand(10, 99),
            'email'    => 'qa' . $random . '@test.com',
            'rol'      => 'user',
            'estado'   => 1,
        ];
    }

    private function crearUsuarioDeTesting(string $adminCookie, ?array $payload = null): array
    {
        $payload = $payload ?? $this->generarUsuarioPayload();

        $response = $this->requestJson(
            'POST',
            "?action=user",
            $payload,
            $adminCookie
        );

        if (isset($response['body']['data']['id'])) {
            $this->usuariosCreadosIds[] = (int) $response['body']['data']['id'];
        }

        return $response;
    }

    private function buscarUsuarioEnBase(int $id): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($id);
    }

    private function obtenerEstadoUsuario(User $user): bool
    {
        $reflection = new ReflectionProperty(User::class, 'estado');

        return (bool) $reflection->getValue($user);
    }

    // --------------------------------------------------
    // Consulta de usuarios
    // --------------------------------------------------
    #[Test]
    #[TestDox('Consulta de usuarios - El admin puede ver el listado')]
    public function testConsultaDeUsuarios(): void
    {
        $adminCookie = $this->login('admin', '123456');

        $this->assertNotNull($adminCookie, "No se pudo iniciar sesión con el administrador.");

        $response = $this->requestJson('GET', "?action=user", null, $adminCookie);

        $this->assertSame(200, $response['httpCode']);
        $this->assertSame('success', $response['body']['status']);
        $this->assertIsArray($response['body']['data']);
    }

    // ------------------------------------------------------
    // ADMIN_001 - Alta correcta de usuario
    // ------------------------------------------------------
    #[Test]
    #[TestDox('ADMIN_001 - Alta correcta de usuario')]
    public function testAltaCorrectaUsuario(): void
    {
        $adminCookie = $this->login('admin', '123456');

        $this->assertNotNull($adminCookie, "No se pudo iniciar sesión con el administrador.");

        $response = $this->crearUsuarioDeTesting($adminCookie);

        $this->assertSame(201, $response['httpCode']);
        $this->assertSame('success', $response['body']['status']);
        $this->assertSame('Usuario creado correctamente.', $response['body']['message']);
        $this->assertArrayHasKey('id', $response['body']['data']);
    }

    // ----------------------------------------------------------
    // ADMIN_002 - Rechazo por DNI duplicado
    // ----------------------------------------------------------
    #[Test]
    #[TestDox('ADMIN_002 - Alta rechazada por DNI duplicado')]
    public function testAltaRechazadaPorDniDuplicado(): void
    {
        $adminCookie = $this->login('admin', '123456');

        $this->assertNotNull($adminCookie, "No se pudo iniciar sesión con el administrador.");

        $dniDuplicado = '99' . mt_rand(100000, 999999);

        $primerResponse = $this->crearUsuarioDeTesting(
            $adminCookie,
            $this->generarUsuarioPayload($dniDuplicado)
        );

        $this->assertSame(201, $primerResponse['httpCode']);

        $response = $this->requestJson(
            'POST',
            "?action=user",
            $this->generarUsuarioPayload($dniDuplicado),
            $adminCookie
        );

        $this->assertSame(400, $response['httpCode']);
        $this->assertSame('error', $response['body']['status']);
        $this->assertSame('El DNI ya está registrado.', $response['body']['message']);
    }

    // --------------------------------------------------------------
    // ADMIN_003 - Modificación de datos de usuario
    // --------------------------------------------------------------
    #[Test]
    #[TestDox('ADMIN_003 - Modificación de datos de usuario')]
    public function testModificacionDeDatosUsuario(): void
    {
        $adminCookie = $this->login('admin', '123456');

        $this->assertNotNull($adminCookie, "No se pudo iniciar sesión con el administrador.");

        $altaResponse = $this->crearUsuarioDeTesting($adminCookie);

        $this->assertSame(201, $altaResponse['httpCode']);

        $id = (int) $altaResponse['body']['data']['id'];

        $payloadUpdate = [
            'nombre'   => 'Usuario Editado',
            'apellido' => 'Testing Editado',
            'email'    => 'editado' . mt_rand(10000, 99999) . '@test.com',
            'rol'      => 'admin',
            'password' => 'test5678',
        ];

        $response = $this->requestJson(
            'PUT',
            "?action=user&id=" . $id,
            $payloadUpdate,
            $adminCookie
        );

        $this->assertSame(200, $response['httpCode']);
        $this->assertSame('success', $response['body']['status']);
        $this->assertSame('Usuario actualizado correctamente.', $response['body']['message']);
        $this->assertSame('Usuario Editado', $response['body']['data']['nombre']);
        $this->assertSame('Testing Editado', $response['body']['data']['apellido']);
        $this->assertSame('admin', $response['body']['data']['rol']);
    }

    // --------------------------------------------------------
    // ADMIN_004 - Baja lógica del usuario
    // --------------------------------------------------------
    #[Test]
    #[TestDox('ADMIN_004 - Baja lógica del usuario')]
    public function testBajaLogicaUsuario(): void
    {
        $adminCookie = $this->login('admin', '123456');

        $this->assertNotNull($adminCookie, "No se pudo iniciar sesión con el administrador.");

        $altaResponse = $this->crearUsuarioDeTesting($adminCookie);

        $this->assertSame(201, $altaResponse['httpCode']);

        $id = (int) $altaResponse['body']['data']['id'];

        $deleteResponse = $this->requestJson(
            'DELETE',
            "?action=user&id=" . $id,
            null,
            $adminCookie
        );

        $this->assertSame(200, $deleteResponse['httpCode']);
        $this->assertSame('success', $deleteResponse['body']['status']);
        $this->assertSame('Usuario dado de baja correctamente.', $deleteResponse['body']['message']);

        $usuarioEnBase = $this->buscarUsuarioEnBase($id);

        $this->assertNotNull($usuarioEnBase, "El usuario no debería eliminarse físicamente.");
        $this->assertFalse($this->obtenerEstadoUsuario($usuarioEnBase));

        $this->usuariosCreadosIds = [];
    }
}

