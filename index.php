<?php

/**
 * PUNTO DE ENTRADA ÚNICO DE LA API
 *
 * Todas las peticiones HTTP entran por aquí.
 * El router lee el parámetro ?action= de la URL y delega
 * al controlador correcto, inyectándole el EntityManager de Doctrine.
 */

// 1. Carga el autoloader de Composer (todas las clases disponibles de una sola vez)
require_once __DIR__ . '/vendor/autoload.php';

// 2. Carga el bootstrap de Doctrine y obtiene el $entityManager configurado.
$entityManager = require __DIR__ . '/config/bootstrap.php';

// 3. Cabeceras globales: Todas las respuestas de esta API son JSON con UTF-8
header('Content-Type: application/json; charset=utf-8');

// ─── Seguridad ────────────────────────────────────────────
// Estas cabeceras se envían en TODA respuesta de la API.
// Las páginas HTML estáticas (frontend/) no pasan por aquí,
// se sirven directamente por Apache.

// HSTS: obliga a usar HTTPS por 1 año (incluye subdominios)
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Evita que el navegador interprete archivos con tipo MIME incorrecto
header('X-Content-Type-Options: nosniff');

// Deniega que la API se cargue dentro de un iframe (protección clickjacking)
header('X-Frame-Options: DENY');

// Controla qué información envía el navegador en el header Referer
header('Referrer-Policy: strict-origin-when-cross-origin');

// Restringe qué APIs del navegador puede usar la página
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
// ───────────────────────────────────────────────────────────

// ─── CORS ──────────────────────────────────────────────────
// El origen permitido se lee del .env (CORS_ORIGIN).
// Si no está definido, se usa "*" (compatible con desarrollo local).
$corsOrigin = $_ENV['CORS_ORIGIN'] ?? getenv('CORS_ORIGIN') ?: '*';
header("Access-Control-Allow-Origin: $corsOrigin");
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
// ───────────────────────────────────────────────────────────

// Responde inmediatamente a las peticiones preflight de los navegadores
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 4. Lee la acción solicitada desde la URL (?action=login)
$action = $_GET['action'] ?? null;

// 5. Middleware de autenticación JWT (stateless)
//    ───────────────────────────────────────────
//    Las rutas públicas (login, recover, credential pública, sellos)
//    SKIP el middleware. El resto requiere token JWT en header
//    "Authorization: Bearer <token>".
//
//    Si el middleware falla, se devuelve JSON con error 401 y se corta.
//    Si pasa, UserContext queda seteado para los controladores.
use App\Security\AuthMiddleware;
use App\Security\UserContext;

// Iniciar sesión solo si se necesita (recovery code usa $_SESSION).
// Para auth normal no usamos sesión — todo va por JWT.
if ($action === 'recover_request' || $action === 'recover_reset') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if (!AuthMiddleware::isPublic($action)) {
    $authMiddleware = new AuthMiddleware($entityManager);
    $authResult = $authMiddleware->handle();
    if ($authResult !== null) {
        http_response_code($authResult['code'] ?? 401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => $authResult['error'],
            'data'    => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 6. Router principal: instancia el controlador correcto inyectándole el EntityManager
//    Envuelto en try/catch para garantizar que incluso errores inesperados
//    devuelvan JSON con el formato estándar que el frontend espera.
try {

    switch ($action) {

        // --- Autenticación ---
        case 'login':
            $controller = new \App\Controller\AuthController($entityManager);
            $controller->login();
            break;

        case 'logout':
            $controller = new \App\Controller\AuthController($entityManager);
            $controller->logout();
            break;

        case 'recover_request':
            $controller = new \App\Controller\AuthController($entityManager);
            $controller->recoverRequest();
            break;

        case 'recover_reset':
            $controller = new \App\Controller\AuthController($entityManager);
            $controller->recoverReset();
            break;

        // --- Usuarios (Panel Admin) ---
        case 'user':
            $controller = new \App\Controller\UserController($entityManager);
            $controller->handleRequest();
            break;

        // --- Credenciales ---
        case 'credential':
            $controller = new \App\Controller\CredentialController($entityManager);
            $controller->handleRequest();
            break;

        // --- Biblioteca de Sellos (Predefinidos con imagen) ---
        case 'sello':
            $controller = new \App\Controller\SelloController($entityManager);
            $controller->handleRequest();
            break;

        // --- Mensajería Interna ---
        case 'message':
            $controller = new \App\Controller\MessageController($entityManager);
            $controller->handleRequest();
            break;

        // --- Historial de Auditoría ---
        case 'history':
            $controller = new \App\Controller\HistoryController($entityManager);
            $controller->handleRequest();
            break;

        // --- Acción no reconocida ---
        default:
            http_response_code(404);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Acción no válida o no encontrada.',
                'data'    => [],
            ], JSON_UNESCAPED_UNICODE);
            break;
    }

} catch (\Throwable $e) {
    // ─── Error inesperado ────────────────────────────────────────
    // Si algo explota (Doctrine, PHP, etc.), atrapamos TODO
    // y devolvemos JSON con el formato que el frontend espera.

    // Genera un ID único para correlacionar este error en los logs
    $correlationId = bin2hex(random_bytes(8));

    // Log interno con todo el detalle (visible solo para el equipo)
    error_log(sprintf(
        '[%s] CorrelationID: %s — %s — %s:%d',
        date('Y-m-d H:i:s'),
        $correlationId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    error_log(sprintf(
        '[%s] CorrelationID: %s — Stack trace: %s',
        date('Y-m-d H:i:s'),
        $correlationId,
        $e->getTraceAsString()
    ));

    // Determina si mostramos detalle según el entorno
    $isDebug = ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) === 'true';

    http_response_code(500);
    echo json_encode([
        'status'         => 'error',
        'message'        => $isDebug
            ? "Error interno: {$e->getMessage()}"
            : 'Error interno del servidor. Intente nuevamente más tarde.',
        'data'           => [],
        'correlation_id' => $correlationId,
    ], JSON_UNESCAPED_UNICODE);
}