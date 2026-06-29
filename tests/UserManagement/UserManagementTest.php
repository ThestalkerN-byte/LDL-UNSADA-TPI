<?php

declare(strict_types=1);

namespace App\Tests\UserManagement;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pruebas del modulo Gestion de usuarios (Admin)
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
final class UserManagementTest extends IntegrationTestCase
{
    private static ?string $adminToken = null;

    /**
     * Usuarios creados mediante API.
     * Se limpian con DELETE /user para evitar borrado fisico con Doctrine.
     *
     * @var int[]
     */
    private array $usuariosCreadosPorApi = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$adminToken === null) {
            self::$adminToken = $this->login('admin', '123456');
        }

        $this->assertNotEmpty(
            self::$adminToken,
            'No se pudo obtener token JWT del administrador.'
        );
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->usuariosCreadosPorApi) as $id) {
            $this->deleteJson('user', (int) $id, self::$adminToken);
        }

        $this->usuariosCreadosPorApi = [];

        parent::tearDown();
    }

    private function generarUsuarioPayload(?string $dni = null): array
    {
        $random = random_int(10000, 99999);

        return [
            'usuario' => 'userQA_' . $random,
            'password' => 'ClaveSegura123!',
            'nombre' => 'Usuario',
            'apellido' => 'Testing',
            'dni' => $dni ?? (string) random_int(40000000, 49999999),
            'email' => 'qa_' . $random . '@test.com',
            'rol' => 'user',
        ];
    }

    private function crearUsuarioDesdeApi(?array $payload = null): array
    {
        $payload = $payload ?? $this->generarUsuarioPayload();

        [$httpCode, $body] = $this->postJson(
            'user',
            $payload,
            self::$adminToken
        );

        if ($httpCode !== 201) {
            $this->fail(
                'No se pudo crear el usuario de testing. HTTP ' . $httpCode .
                ' Body: ' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
        }

        $id = $body['data']['id'] ?? null;

        $this->assertNotNull($id, 'No se obtuvo el ID del usuario creado.');

        $this->usuariosCreadosPorApi[] = (int) $id;

        return [
            'httpCode' => $httpCode,
            'body' => $body,
        ];
    }

    #[Test]
    #[TestDox('Consulta de usuarios - El admin puede ver el listado')]
    public function testConsultaDeUsuarios(): void
    {
        [$httpCode, $body] = $this->getJson(
            'user',
            [],
            self::$adminToken
        );

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertIsArray($body['data']);
    }

    #[Test]
    #[TestDox('ADMIN_001 - Alta correcta de usuario')]
    public function testAltaCorrectaUsuario(): void
    {
        $response = $this->crearUsuarioDesdeApi();

        $this->assertSame(201, $response['httpCode']);
        $this->assertSame('success', $response['body']['status']);
        $this->assertSame('Usuario creado correctamente.', $response['body']['message']);
        $this->assertArrayHasKey('id', $response['body']['data']);
    }

    #[Test]
    #[TestDox('ADMIN_002 - Alta rechazada por DNI duplicado')]
    public function testAltaRechazadaPorDniDuplicado(): void
    {
        $dniDuplicado = (string) random_int(50000000, 59999999);

        $primerResponse = $this->crearUsuarioDesdeApi(
            $this->generarUsuarioPayload($dniDuplicado)
        );

        $this->assertSame(201, $primerResponse['httpCode']);

        [$httpCode, $body] = $this->postJson(
            'user',
            $this->generarUsuarioPayload($dniDuplicado),
            self::$adminToken
        );

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('El DNI ya está registrado.', $body['message']);
    }

    #[Test]
    #[TestDox('ADMIN_003 - Modificacion de datos de usuario')]
    public function testModificacionDeDatosUsuario(): void
    {
        $altaResponse = $this->crearUsuarioDesdeApi();

        $this->assertSame(201, $altaResponse['httpCode']);

        $id = (int) $altaResponse['body']['data']['id'];

        $payloadUpdate = [
            'nombre' => 'Usuario Editado',
            'apellido' => 'Testing Editado',
            'email' => 'editado_' . random_int(10000, 99999) . '@test.com',
            'rol' => 'admin',
            'password' => 'ClaveEditada123!',
        ];

        [$httpCode, $body] = $this->putJson(
            'user',
            $id,
            $payloadUpdate,
            self::$adminToken
        );

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Usuario actualizado correctamente.', $body['message']);
        $this->assertSame('Usuario Editado', $body['data']['nombre']);
        $this->assertSame('Testing Editado', $body['data']['apellido']);
        $this->assertSame('admin', $body['data']['rol']);
    }

    #[Test]
    #[TestDox('ADMIN_004 - Baja logica del usuario')]
    public function testBajaLogicaUsuario(): void
    {
        $altaResponse = $this->crearUsuarioDesdeApi();

        $this->assertSame(201, $altaResponse['httpCode']);

        $id = (int) $altaResponse['body']['data']['id'];

        [$httpCode, $body] = $this->deleteJson(
            'user',
            $id,
            self::$adminToken
        );

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame('Usuario dado de baja correctamente.', $body['message']);

        $this->usuariosCreadosPorApi = array_values(
            array_filter(
                $this->usuariosCreadosPorApi,
                fn (int $userId): bool => $userId !== $id
            )
        );
    }
}
