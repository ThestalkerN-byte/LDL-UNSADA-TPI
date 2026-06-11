<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Exception\AuthException;
use App\Middleware\AuthMiddleware;
use App\Request\Request;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AuthMiddlewareTest extends TestCase
{
    private EntityManagerInterface $em;
    private AuthService $authService;
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->middleware = new AuthMiddleware($this->em, $this->authService);
    }

    public function testAutenticadoSinTokenRetorna401(): void
    {
        $request = new Request();
        $handler = $this->middleware->autenticado();

        $result = $handler($request);

        $this->assertIsArray($result);
        $this->assertSame(401, $result['code']);
        $this->assertSame('Token de autenticación requerido', $result['error']);
        $this->assertNull($request->getAttribute('usuario'));
    }

    public function testAutenticadoConTokenInvalidoRetorna401(): void
    {
        $request = new Request(['HTTP_AUTHORIZATION' => 'Bearer token-invalido']);

        $this->authService
            ->expects($this->once())
            ->method('validateToken')
            ->with('token-invalido')
            ->willThrowException(new AuthException('Token inválido o expirado'));

        $handler = $this->middleware->autenticado();
        $result = $handler($request);

        $this->assertIsArray($result);
        $this->assertSame(401, $result['code']);
        $this->assertSame('Token inválido o expirado', $result['error']);
        $this->assertNull($request->getAttribute('usuario'));
    }

    public function testAutenticadoConTokenValidoRetornaNullEInyectaUsuario(): void
    {
        $user = $this->crearUsuario(1, 'usuario', true);
        $request = new Request(['HTTP_AUTHORIZATION' => 'Bearer token-valido']);
        $payload = (object) ['sub' => 1];

        $this->authService
            ->expects($this->once())
            ->method('validateToken')
            ->with('token-valido')
            ->willReturn($payload);

        $this->em
            ->expects($this->once())
            ->method('find')
            ->with(User::class, 1)
            ->willReturn($user);

        $handler = $this->middleware->autenticado();
        $result = $handler($request);

        $this->assertNull($result);
        $this->assertSame($user, $request->getAttribute('usuario'));
    }

    public function testAdminConUserNoAdminRetorna403(): void
    {
        $user = $this->crearUsuario(2, 'usuario', true);
        $request = new Request(['HTTP_AUTHORIZATION' => 'Bearer token-usuario']);
        $payload = (object) ['sub' => 2];

        $this->authService
            ->method('validateToken')
            ->willReturn($payload);

        $this->em
            ->method('find')
            ->with(User::class, 2)
            ->willReturn($user);

        $handler = $this->middleware->admin();
        $result = $handler($request);

        $this->assertIsArray($result);
        $this->assertSame(403, $result['code']);
        $this->assertSame('Se requiere rol de administrador', $result['error']);
    }

    public function testAdminConUserAdminRetornaNull(): void
    {
        $user = $this->crearUsuario(3, 'admin', true);
        $request = new Request(['HTTP_AUTHORIZATION' => 'Bearer token-admin']);
        $payload = (object) ['sub' => 3];

        $this->authService
            ->method('validateToken')
            ->willReturn($payload);

        $this->em
            ->method('find')
            ->with(User::class, 3)
            ->willReturn($user);

        $handler = $this->middleware->admin();
        $result = $handler($request);

        $this->assertNull($result);
        $this->assertSame($user, $request->getAttribute('usuario'));
        $this->assertTrue($user->isAdmin());
    }

    private function crearUsuario(int $id, string $rol, bool $estado): User
    {
        $user = new User();
        $user->setUsuario('testuser');
        $user->setPassword('hash');
        $user->setNombre('Test');
        $user->setApellido('User');
        $user->setDni('12345678');
        $user->setEmail('test@example.com');
        $user->setRol($rol);
        $user->setEstado($estado);

        $reflection = new ReflectionClass(User::class);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, $id);

        return $user;
    }
}
