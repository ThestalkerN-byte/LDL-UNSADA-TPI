<?php
declare(strict_types=1);

namespace App\Security;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;

/**
 * AUTH MIDDLEWARE: Validación de JWT en cada request
 * ====================================================
 *
 * Propósito: extraer el token JWT del header Authorization, validarlo,
 * cargar el usuario desde la DB y setear UserContext para los controladores.
 *
 * Este middleware se ejecuta en index.php ANTES del switch de rutas.
 * Las rutas públicas (login, logout, recover_request, recover_reset) SKIP el middleware.
 * TODO lo demás requiere token JWT en header Authorization: Bearer &lt;token&gt;.
 *
 * FLUJO:
 *   1. Extrae token del header "Authorization: Bearer <token>"
 *   2. Valida el JWT con JwtService::validateToken()
 *   3. Carga el usuario completo desde la DB (datos frescos)
 *   4. Setea UserContext con {id, usuario, rol} para los controladores
 *
 * DECISIÓN TÉCNICA:
 *   Separamos la lógica de leer el token de la validación del JWT para
 *   poder testear cada parte por separado. Además cargamos el usuario
 *   desde DB (no solo confiamos en el payload) para detectar bajas lógicas.
 *
 * @see \App\Security\JwtService
 * @see \App\Security\UserContext
 */
class AuthMiddleware
{
    private JwtService $jwtService;
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->jwtService = new JwtService();
        $this->em = $em;
    }

    /**
     * Ejecuta el middleware: valida JWT y setea UserContext.
     *
     * @return array|null null si OK, array con error si falla
     */
    public function handle(): ?array
    {
        $token = $this->extractToken();

        if (!$token) {
            return ['error' => 'Token de autenticación requerido', 'code' => 401];
        }

        try {
            $payload = $this->jwtService->validateToken($token);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'code' => 401];
        }

        // Cargar usuario desde DB para verificar que sigue activo
        $user = $this->em->getRepository(User::class)->find($payload->sub);

        if (!$user || !$user->isEstado()) {
            return ['error' => 'Usuario no encontrado o inactivo', 'code' => 401];
        }

        // Setear contexto para los controladores
        UserContext::set([
            'id'      => $user->getId(),
            'usuario' => $user->getUsuario(),
            'rol'     => $user->getRol(),
        ]);

        return null;
    }

    /**
     * Extrae el token Bearer del header Authorization.
     *
     * Soporta:
     *   - HTTP_AUTHORIZATION (Apache/FastCGI por defecto)
     *   - REDIRECT_HTTP_AUTHORIZATION (rewrite interno de Apache)
     *   - apache_request_headers() (fallback)
     */
    private function extractToken(): ?string
    {
        $header = '';

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Lista de acciones que NO requieren autenticación.
     */
    public static function publicActions(): array
    {
        return [
            'login',
            'logout',
            'recover_request',
            'recover_reset',
        ];
    }

    /**
     * Verifica si una acción es pública (no requiere JWT).
     */
    public static function isPublic(string $action): bool
    {
        return in_array($action, self::publicActions(), true);
    }
}
