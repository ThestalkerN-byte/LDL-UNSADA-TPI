<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\AuthException;
use App\Request\Request;
use App\Service\AuthService;
use Doctrine\ORM\EntityManagerInterface;

/*
 * AUTH CONTROLLER: Endpoints de autenticación
 * =============================================
 * Endpoints:
 *   POST /index.php?action=login    → Iniciar sesión (público)
 *   POST /index.php?action=refresh  → Renovar tokens (público)
 *   GET  /index.php?action=me       → Obtener usuario actual (autenticado)
 *   POST /index.php?action=logout   → Cerrar sesión / revocar refresh (autenticado)
 *
 * Los endpoints públicos (login, refresh) NO llevan middleware
 * porque justamente son accesibles sin autenticación.
 * Los endpoints /me y /logout requieren AuthMiddleware::autenticado.
 *
 * Formato de respuesta consistente:
 *   Éxito: { "status": "success", "message": "...", "data": {...} }
 *   Error: { "status": "error", "message": "...", "data": [] }
 *
 * Decisión técnica:
 *   El controlador recibe EntityManager en lugar del service directamente
 *   porque sigue el patrón de los demás controladores existentes.
 *   Internamente crea AuthService.
 */
class AuthController
{
    private EntityManagerInterface $em;
    private AuthService $authService;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->authService = new AuthService($em);
    }

    // =========================================================================
    // POST /index.php?action=login
    // =========================================================================

    /**
     * Autentica al usuario y devuelve tokens JWT.
     *
     * Body esperado:
     *   { "identificador": "admin", "password": "..." }
     *   o { "usuario": "admin", "password": "..." }
     *   o { "dni": "12345678", "password": "..." }
     *
     * El identificador puede ser nombre de usuario O DNI.
     * AuthService::login() resuelve cuál de los dos es usando
     * UserRepository::findByIdentifier().
     */
    public function login(): void
    {
        // 1. Solo acepta POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        // 2. Decodifica el body JSON que envía el Frontend
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $identificador = trim((string) ($data['identificador'] ?? $data['usuario'] ?? $data['dni'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));

        // 3. Valida que no vengan vacíos
        if ($identificador === '' || $password === '') {
            $this->responder(400, 'error', 'El identificador y la contraseña son obligatorios.');
            return;
        }

        try {
            $resultado = $this->authService->login($identificador, $password);
            $this->responder(200, 'success', 'Login exitoso.', $resultado);
        } catch (AuthException $e) {
            $this->responder($e->getStatusCode(), 'error', $e->getMessage());
        }
    }

    // =========================================================================
    // POST /index.php?action=refresh
    // =========================================================================

    /**
     * Renueva tokens usando refresh token.
     *
     * Body esperado:
     *   { "refresh_token": "..." }
     *
     * Implementa rotación de refresh token (ver AuthService).
     * Si el token fue comprometido, al rotar el dueño legítimo
     * queda invalidado y debe volver a hacer login.
     */
    public function refresh(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            $this->responder(400, 'error', 'refresh_token es obligatorio.');
            return;
        }

        try {
            $resultado = $this->authService->refresh($refreshToken);
            $this->responder(200, 'success', 'Tokens renovados correctamente.', $resultado);
        } catch (AuthException $e) {
            $this->responder($e->getStatusCode(), 'error', $e->getMessage());
        }
    }

    // =========================================================================
    // GET /index.php?action=me
    // =========================================================================

    /**
     * Devuelve los datos del usuario autenticado.
     *
     * Requiere middleware: AuthMiddleware::autenticado (inyecta el User).
     * No expone datos sensibles (password, refresh_token).
     */
    public function me(Request $request): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->responder(405, 'error', 'Método no permitido. Use GET.');
            return;
        }

        /** @var User|null $user */
        $user = $request->getAttribute('usuario');
        if (!$user) {
            $this->responder(401, 'error', 'No autenticado.');
            return;
        }

        $this->responder(200, 'success', 'Perfil obtenido correctamente.', [
            'usuario' => $this->authService->serializar($user),
        ]);
    }

    // =========================================================================
    // POST /index.php?action=logout
    // =========================================================================

    /**
     * Cierra la sesión revocando el refresh token en la base de datos.
     *
     * Requiere middleware: AuthMiddleware::autenticado.
     * El access token seguirá válido hasta su expiración natural (stateless).
     */
    public function logout(Request $request): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        /** @var User|null $user */
        $user = $request->getAttribute('usuario');
        if (!$user) {
            $this->responder(401, 'error', 'No autenticado.');
            return;
        }

        $this->authService->revokeRefreshToken($user);
        $this->responder(200, 'success', 'Sesión cerrada correctamente.');
    }

    // =========================================================================
    // Helper privado: Respuesta JSON estandarizada
    // =========================================================================

    /**
     * Emite la respuesta JSON estandarizada y termina la ejecución.
     *
     * @param int    $httpCode Código de estado HTTP (200, 400, 401, 405...)
     * @param string $status   'success' o 'error'
     * @param string $message  Mensaje legible para el Frontend
     * @param array  $data     (Opcional) Datos adicionales que devolver
     */
    private function responder(int $httpCode, string $status, string $message, array $data = []): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
