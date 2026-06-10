<?php
declare(strict_types=1);
namespace App\Middleware;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Usuario;
use App\Request\Request;
use App\Service\AuthService;

/*
 * AUTH MIDDLEWARE: Protección de rutas con JWT
 * ==============================================
 * Propósito: verificar que el request tenga un JWT válido y opcionalmente
 * que el usuario tenga rol de administrador.
 *
 * Uso en Router:
 *   $authMiddleware = new AuthMiddleware($em, $authService);
 *   $router->get('/api/auth/me', [AuthController::class, 'me'], [
 *       $authMiddleware->autenticado()
 *   ]);
 *   $router->get('/api/admin/usuarios', [AdminController::class, 'listar'], [
 *       $authMiddleware->admin()
 *   ]);
 *
 * Flujo:
 *   1. Extrae token del header Authorization: Bearer <token>
 *   2. Valida el JWT con AuthService::validateToken()
 *   3. Carga el usuario completo desde la DB
 *   4. Inyecta el usuario en Request como atributo 'usuario'
 *   5. Si es middleware admin, verifica que tenga rol Admin
 *
 * Contrato con el Router:
 *   - El middleware recibe un Request y debe devolver ?array
 *   - null → continúa (ok)
 *   - array → corta la ejecución con ese error
 *   - El Router verifica si el resultado es un array y lo devuelve
 *     como respuesta JSON
 *
 * Decisión técnica:
 *   Usamos closures en vez de una clase invocable (__invoke) para mantener
 *   la compatibilidad con el Router que espera callables simples.
 *   Cada método devuelve un closure con las dependencias ya resueltas
 *   (dependency injection manual, sin contenedor).
 *
 *   Separamos autenticado() de admin() en vez de un solo middleware
 *   con opciones porque es más explícito al declarar rutas y evita
 *   condicionales internos. Se ve claro qué ruta es "solo autenticado"
 *   y cuál requiere admin.
 */
class AuthMiddleware
{
    private EntityManagerInterface $em;
    private AuthService $authService;

    public function __construct(EntityManagerInterface $em, AuthService $authService)
    {
        $this->em = $em;
        $this->authService = $authService;
    }

    /**
     * Middleware que requiere un usuario autenticado (cualquier rol).
     * Inyecta el Usuario completo en $request->getAttribute('usuario').
     *
     * Flujo:
     *   1. Extrae Bearer token del header Authorization
     *   2. Valida el JWT (firma + expiración)
     *   3. Carga el usuario desde DB para tener datos frescos
     *   4. Verifica que siga activo
     *   5. Inyecta el usuario como atributo del request
     */
    public function autenticado(): callable
    {
        return function (Request $request): ?array {
            $token = $request->bearerToken();
            if (!$token) {
                return ['error' => 'Token de autenticación requerido', 'code' => 401];
            }

            try {
                $payload = $this->authService->validateToken($token);
            } catch (\Exception $e) {
                return ['error' => 'Token inválido o expirado', 'code' => 401];
            }

            // Cargar usuario completo desde la DB
            // No confiar solo en el payload — el usuario pudo ser desactivado
            $usuario = $this->em->find(Usuario::class, $payload->sub);
            if (!$usuario || $usuario->getEstado() !== 'Activo') {
                return ['error' => 'Usuario no encontrado o inactivo', 'code' => 401];
            }

            $request->setAttribute('usuario', $usuario);
            return null;
        };
    }

    /**
     * Middleware que requiere rol de administrador.
     * Primero verifica autenticación, luego verifica el rol Admin
     * usando el método esAdmin() de la entidad Usuario.
     *
     * Relación:
     *   - Depende de autenticado() como paso previo
     *   - Usa Usuario::esAdmin() que verifica existencia del rol
     *     "Admin" en la colección ManyToMany
     */
    public function admin(): callable
    {
        return function (Request $request): ?array {
            // Primero verificar autenticación (reutiliza la lógica)
            $authResult = ($this->autenticado())($request);
            if ($authResult !== null) {
                return $authResult;
            }

            // Verificar rol Admin mediante el método de negocio de Usuario
            $usuario = $request->getAttribute('usuario');
            if (!$usuario->esAdmin()) {
                return ['error' => 'Se requiere rol de administrador', 'code' => 403];
            }

            return null;
        };
    }
}
