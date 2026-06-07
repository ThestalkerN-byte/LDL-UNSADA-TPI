<?php

declare(strict_types=1);

/*
 * =========================================================================
 * FRONT CONTROLLER — API REST ICB
 * =========================================================================
 *
 * Punto de entrada único de la aplicación (Front Controller Pattern).
 * Apache redirige todas las peticiones a este archivo vía .htaccess.
 *
 * Flujo:
 *   1. Carga Composer (autoload de clases)
 *   2. Carga variables de entorno (.env)
 *   3. Inicializa Doctrine (EntityManager)
 *   4. Crea Request, Router registra rutas → dispatch
 *   5. Captura excepciones y devuelve JSON siempre
 *
 * Los middlewares se definen por ruta. El AuthMiddleware inyecta el
 * Usuario en el Request como atributo para que los controllers lo usen.
 * =========================================================================
 */

// ─── Directorio base ───────────────────────────────────────────────────────
// Detecta si estamos en local (con carpeta public/) o en produccion (plano).
// En local:  __DIR__ = .../public/   → base = .../
// En prod:   __DIR__ = .../icb/      → base = .../icb/
$baseDir = basename(__DIR__) === 'public' ? dirname(__DIR__) : __DIR__;

// ─── Autoloader ────────────────────────────────────────────────────────────
require_once $baseDir . '/vendor/autoload.php';

// ─── Variables de entorno ─────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable($baseDir);
$dotenv->safeLoad();

// ─── Doctrine ORM ──────────────────────────────────────────────────────────
$entityManager = require $baseDir . '/config/doctrine.php';

// ─── Cabeceras HTTP ───────────────────────────────────────────────────────
header('Content-Type: application/json');

// CORS: origen configurable via .env. Si no está definido, solo el mismo origen.
// En desarrollo se puede usar CORS_ORIGIN=* o CORS_ORIGIN=http://localhost:3000
$corsOrigin = $_ENV['CORS_ORIGIN'] ?? '';
if ($corsOrigin === '*') {
    header('Access-Control-Allow-Origin: *');
} elseif ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
}
// else: no CORS header → same-origin only
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('Cache-Control: no-store, private, must-revalidate');
header_remove('X-Powered-By');

// Manejar preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Inicialización ───────────────────────────────────────────────────────
use ICB\Controller\AdminController;
use ICB\Controller\AuthController;
use ICB\Controller\ConversacionController;
use ICB\Controller\SelloController;
use ICB\Middleware\AuthMiddleware;
use ICB\RateLimiting\RateLimiter;
use ICB\Request\Request;
use ICB\Router\Router;
use ICB\Service\AuthService;

$request = new Request();
$router = new Router($entityManager);

$authService = new AuthService($entityManager);
$authMiddleware = new AuthMiddleware($entityManager, $authService);
$rateLimiter = new RateLimiter();

// ─── Rutas públicas ───────────────────────────────────────────────────────

// Health check
$router->get('/api/health', function () use ($entityManager) {
    $dbStatus = 'disconnected';
    try {
        $entityManager->getConnection()->executeQuery('SELECT 1');
        $dbStatus = 'connected';
    } catch (\Throwable) {
        $dbStatus = 'error';
    }

    return [
        'status'    => 'ok',
        'database'  => $dbStatus,
        'timestamp' => date('c'),
    ];
});

// Sellos públicos
$router->get('/api/sellos', [SelloController::class, 'listarActivos']);

// Auth público (con rate limiting: 5 intentos por minuto para login,
// 10 por minuto para refresh — evita fuerza bruta y abuso de tokens)
$router->post('/api/auth/login', [AuthController::class, 'login'], [
    $rateLimiter->limit(5, 60, 'login'),
]);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh'], [
    $rateLimiter->limit(10, 60, 'refresh'),
]);

// Auth protegido (cualquier usuario autenticado)
$router->get('/api/auth/me', [AuthController::class, 'me'], [
    $authMiddleware->autenticado(),
]);

// --- Recuperación de contraseña (público, con rate limiting) ---
$router->post('/api/auth/recuperar-solicitar', [AuthController::class, 'recuperarSolicitar'], [
    $rateLimiter->limit(3, 300, 'recuperar'), // 3 solicitudes cada 5 min
]);
$router->post('/api/auth/recuperar-confirmar', [AuthController::class, 'recuperarConfirmar'], [
    $rateLimiter->limit(5, 300, 'recuperar-confirmar'), // 5 intentos cada 5 min
]);

// --- Mi credencial activa (requiere autenticación) ---
$router->get('/api/auth/mi-credencial', [AuthController::class, 'miCredencial'], [
    $authMiddleware->autenticado(),
]);

// ─── Rutas de usuario autenticado (cualquier rol) ─────────────────────────

// --- Conversaciones ---
$router->get('/api/conversaciones', [ConversacionController::class, 'listar'], [
    $authMiddleware->autenticado(),
]);
$router->post('/api/conversaciones', [ConversacionController::class, 'crear'], [
    $authMiddleware->autenticado(),
    $rateLimiter->limit(10, 60, 'conversaciones-crear'),
]);
$router->get('/api/conversaciones/{id}/mensajes', [ConversacionController::class, 'mensajes'], [
    $authMiddleware->autenticado(),
]);
$router->post('/api/conversaciones/{id}/mensajes', [ConversacionController::class, 'enviarMensaje'], [
    $authMiddleware->autenticado(),
    $rateLimiter->limit(20, 60, 'conversaciones-mensajes'),
]);

// ─── Rutas de administración (requieren rol Admin) ──────────────────────

// --- Usuarios ---
$router->get('/api/admin/usuarios', [AdminController::class, 'listarUsuarios'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-usuarios-list'),
]);
$router->get('/api/admin/usuarios/{id}', [AdminController::class, 'obtenerUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-usuarios-get'),
]);
$router->post('/api/admin/usuarios', [AdminController::class, 'crearUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-usuarios-create'),
]);
$router->put('/api/admin/usuarios/{id}', [AdminController::class, 'actualizarUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-usuarios-update'),
]);
$router->delete('/api/admin/usuarios/{id}', [AdminController::class, 'eliminarUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(10, 60, 'admin-usuarios-delete'),
]);
$router->post('/api/admin/usuarios/{id}/restaurar', [AdminController::class, 'restaurarUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-usuarios-restore'),
]);
$router->get('/api/admin/usuarios/{id}/historial', [AdminController::class, 'historialUsuario'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-usuarios-history'),
]);

// --- Credenciales ---
$router->get('/api/admin/credenciales', [AdminController::class, 'listarCredenciales'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-credenciales-list'),
]);
$router->post('/api/admin/credenciales', [AdminController::class, 'emitirCredencial'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-credenciales-create'),
]);
$router->post('/api/admin/credenciales/{id}/renovar', [AdminController::class, 'renovarCredencial'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-credenciales-renew'),
]);

// --- Sellos ---
$router->get('/api/admin/sellos', [AdminController::class, 'listarSellos'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-sellos-list'),
]);
$router->get('/api/admin/sellos/{id}', [AdminController::class, 'obtenerSello'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-sellos-get'),
]);
$router->post('/api/admin/sellos', [AdminController::class, 'crearSello'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-sellos-create'),
]);
$router->post('/api/admin/sellos/upload', [AdminController::class, 'subirSello'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(5, 60, 'admin-sellos-upload'),
]);
$router->put('/api/admin/sellos/{id}', [AdminController::class, 'actualizarSello'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-sellos-update'),
]);
$router->delete('/api/admin/sellos/{id}', [AdminController::class, 'eliminarSello'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(10, 60, 'admin-sellos-delete'),
]);

// --- Historial (auditoría) ---
$router->get('/api/admin/historial', [AdminController::class, 'listarHistorial'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-historial-list'),
]);

// --- Conversaciones (admin) ---
$router->get('/api/admin/conversaciones', [AdminController::class, 'listarConversaciones'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-conversaciones-list'),
]);
$router->get('/api/admin/conversaciones/{id}/mensajes', [AdminController::class, 'verMensajesConversacion'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(60, 60, 'admin-conversaciones-mensajes'),
]);
$router->post('/api/admin/conversaciones/{id}/mensajes', [AdminController::class, 'responderConversacion'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-conversaciones-respond'),
]);
$router->post('/api/admin/conversaciones/{id}/cerrar', [AdminController::class, 'cerrarConversacion'], [
    $authMiddleware->admin(),
    $rateLimiter->limit(20, 60, 'admin-conversaciones-close'),
]);

// ─── Dispatch ─────────────────────────────────────────────────────────────
try {
    $resultado = $router->dispatch($request);

    http_response_code($resultado['code'] ?? 200);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    $correlationId = bin2hex(random_bytes(8));
    $mensajeTruncado = mb_substr($e->getMessage(), 0, 500);
    error_log("[ICB ERROR:{$correlationId}] " . $mensajeTruncado . ' in ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);
    echo json_encode([
        'error'   => 'Error interno del servidor',
        'message' => ($_ENV['APP_DEBUG'] ?? '') === 'true' ? $e->getMessage() : 'Error interno del servidor',
        'code'    => 500,
        'correlation_id' => $correlationId,
    ], JSON_UNESCAPED_UNICODE);
}
