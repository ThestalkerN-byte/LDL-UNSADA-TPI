<?php

declare(strict_types=1);

namespace App\Tests\Authentication;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Pruebas unitarias de la capa de negocio de autenticación (AuthService).
 *
 * A diferencia de AuthController (que depende de superglobales, headers
 * HTTP y `exit`), AuthService recibe sus dependencias por inyección
 * (UserRepository), por lo que puede probarse de forma totalmente aislada
 * usando mocks, sin necesidad de base de datos ni de un servidor HTTP.
 *
 * Mapeo con la planilla QA:
 *   - LOGIN_001 -> testLoginConCredencialesValidasDevuelveExito()
 *   - LOGIN_002 -> testLoginConPasswordIncorrectaEsRechazado()
 *   - LOGIN_003 -> testLoginConIdentificadorVacioEsRechazado() / testLoginConPasswordVaciaEsRechazado()
 *   - LOGIN_004 -> testLoginConUsuarioInactivoEsRechazado()
 *   - LOGIN_005 -> testLogoutSiempreDevuelveExito()
 *
 * Requisitos cubiertos: RF01
 */
#[CoversClass(AuthService::class)]
final class AuthServiceTest extends TestCase
{
    private UserRepository&\PHPUnit\Framework\MockObject\MockObject $userRepository;
    private AuthService $authService;

    protected function setUp(): void
    {
        // UserRepository extiende EntityRepository; createMock() genera un
        // doble de prueba sin invocar el constructor real (no requiere EM).
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->authService    = new AuthService($this->userRepository);
    }

    /**
     * Construye un User de prueba sin tocar la base de datos,
     * seteando el id por reflexión (la entidad no expone setId()).
     */
    private function makeUser(
        string $usuario,
        string $passwordPlano,
        string $rol = 'user',
        bool $estado = true,
        int $id = 1
    ): User {
        $user = new User();
        $user->setUsuario($usuario);
        $user->setPassword($passwordPlano); // AuthService compara texto plano
        $user->setNombre('Nombre');
        $user->setApellido('Apellido');
        $user->setDni('30111222');
        $user->setEmail($usuario . '@example.com');
        $user->setRol($rol);
        $user->setEstado($estado);

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    // -----------------------------------------------------------------
    // LOGIN_001 - Usuario registrado y activo / credenciales válidas
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('LOGIN_001 - Login con usuario y contraseña válidos devuelve éxito')]
    public function testLoginConCredencialesValidasDevuelveExito(): void
    {
        $user = $this->makeUser('admin', '123456');

        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('admin')
            ->willReturn($user);

        $resultado = $this->authService->login('admin', '123456');

        $this->assertSame('ok', $resultado['status']);
        $this->assertSame('Login exitoso', $resultado['message']);
        $this->assertArrayNotHasKey('error', $resultado);
    }

    // -----------------------------------------------------------------
    // LOGIN_002 - Usuario registrado, contraseña incorrecta
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('LOGIN_002 - Login con contraseña incorrecta es rechazado')]
    public function testLoginConPasswordIncorrectaEsRechazado(): void
    {
        $user = $this->makeUser('admin', '123456');

        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('admin')
            ->willReturn($user);

        $resultado = $this->authService->login('admin', 'password-incorrecta');

        $this->assertArrayHasKey('error', $resultado);
        $this->assertSame('Contraseña incorrecta', $resultado['error']);
    }

    #[Test]
    #[TestDox('Login con usuario inexistente es rechazado')]
    public function testLoginConUsuarioInexistenteEsRechazado(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('usuario_que_no_existe')
            ->willReturn(null);

        $resultado = $this->authService->login('usuario_que_no_existe', 'cualquier-clave');

        $this->assertArrayHasKey('error', $resultado);
        $this->assertSame('Usuario no encontrado', $resultado['error']);
    }

    // -----------------------------------------------------------------
    // LOGIN_003 - Campos obligatorios vacíos
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('LOGIN_003 - Login con identificador vacío es rechazado')]
    public function testLoginConIdentificadorVacioEsRechazado(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('')
            ->willReturn(null);

        $resultado = $this->authService->login('', '123456');

        $this->assertArrayHasKey('error', $resultado);
    }

    #[Test]
    #[TestDox('LOGIN_003 - Login con password vacía es rechazado')]
    public function testLoginConPasswordVaciaEsRechazado(): void
    {
        $user = $this->makeUser('admin', '123456');

        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('admin')
            ->willReturn($user);

        $resultado = $this->authService->login('admin', '');

        $this->assertArrayHasKey('error', $resultado);
        $this->assertSame('Contraseña incorrecta', $resultado['error']);
    }

    // -----------------------------------------------------------------
    // LOGIN_004 - Usuario dado de baja (estado inactivo)
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('LOGIN_004 - Login de usuario inactivo es rechazado (no encontrado por el repositorio)')]
    public function testLoginConUsuarioInactivoEsRechazado(): void
    {
        // UserRepository::findByUsuarioODni filtra "estado = true" en la
        // query, por lo que un usuario inactivo nunca es devuelto por el
        // repositorio real; replicamos ese contrato con el mock.
        $this->userRepository
            ->expects($this->once())
            ->method('findByUsuarioODni')
            ->with('usuario_inactivo')
            ->willReturn(null);

        $resultado = $this->authService->login('usuario_inactivo', '123456');

        $this->assertArrayHasKey('error', $resultado);
        $this->assertSame('Usuario no encontrado', $resultado['error']);
    }

    // -----------------------------------------------------------------
    // LOGIN_005 - Logout
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('LOGIN_005 - Logout siempre devuelve estado ok')]
    #[AllowMockObjectsWithoutExpectations]
    public function testLogoutSiempreDevuelveExito(): void
    {
        $resultado = $this->authService->logout();

        $this->assertSame('ok', $resultado['status']);
        $this->assertSame('Sesión cerrada', $resultado['message']);
    }
}