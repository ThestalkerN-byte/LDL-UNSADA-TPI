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

// Soporte para peticiones CORS en entornos de desarrollo (opcional, ajustar en producción)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Responde inmediatamente a las peticiones preflight de los navegadores
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 4. Construir objeto Request y leer la acción solicitada desde la URL (?action=login)
$request = new \App\Request\Request($_SERVER, $_GET, $_POST);
$action = $request->get('action');

// 4.1 Instanciar servicios/middlewares reutilizables
$userRepo = $entityManager->getRepository(\App\Entity\User::class);
$authService = new \App\Service\AuthService($entityManager);
$authMiddleware = new \App\Middleware\AuthMiddleware($entityManager, $authService);
$rateLimiter = new \App\RateLimiting\RateLimiter();

// 5. Router principal: instancia el controlador correcto inyectándole el EntityManager
switch ($action) {

    // --- Autenticación (público con rate limit) ---
    case 'login':
        // Aplicar rate limiter en login (5 intentos por minuto por IP)
        $rlResult = ($rateLimiter->limit(5, 60, 'login'))($request);
        if ($rlResult !== null) {
            http_response_code($rlResult['code'] ?? 429);
            echo json_encode($rlResult, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\AuthController($entityManager);
        $controller->login();
        break;

    case 'refresh':
        // Aplicar rate limiter en refresh (10 intentos por minuto por IP)
        $rlResult = ($rateLimiter->limit(10, 60, 'refresh'))($request);
        if ($rlResult !== null) {
            http_response_code($rlResult['code'] ?? 429);
            echo json_encode($rlResult, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\AuthController($entityManager);
        $controller->refresh();
        break;

    // --- Autenticación (requiere JWT) ---
    case 'me':
        $authRes = ($authMiddleware->autenticado())($request);
        if ($authRes !== null) {
            http_response_code($authRes['code'] ?? 401);
            echo json_encode($authRes, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\AuthController($entityManager);
        $controller->me($request);
        break;

    case 'logout':
        $authRes = ($authMiddleware->autenticado())($request);
        if ($authRes !== null) {
            http_response_code($authRes['code'] ?? 401);
            echo json_encode($authRes, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\AuthController($entityManager);
        $controller->logout($request);
        break;

    // --- Usuarios (Panel Admin) ---
    case 'user':
        $authRes = ($authMiddleware->admin())($request);
        if ($authRes !== null) {
            http_response_code($authRes['code'] ?? 403);
            echo json_encode($authRes, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\UserController($userRepo);
        http_response_code(501);
        echo json_encode([
            'status' => 'error',
            'message' => 'Gestión de usuarios en construcción.',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // --- Credenciales (requiere JWT) ---
    case 'credential':
        $authRes = ($authMiddleware->autenticado())($request);
        if ($authRes !== null) {
            http_response_code($authRes['code'] ?? 401);
            echo json_encode($authRes, JSON_UNESCAPED_UNICODE);
            break;
        }

        $controller = new \App\Controller\CredentialController($entityManager);
        $controller->handleRequest();
        break;

    // --- Mensajería Interna ---
    case 'message':
        $controller = new \App\Controller\MessageController(
            $entityManager->getRepository(\App\Entity\Message::class)
        );
        http_response_code(501);
        echo json_encode([
            'status' => 'error',
            'message' => 'Mensajería en construcción.',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // --- Historial de Auditoría ---
    case 'history':
        $controller = new \App\Controller\HistoryController(
            $entityManager->getRepository(\App\Entity\History::class)
        );
        http_response_code(501);
        echo json_encode([
            'status' => 'error',
            'message' => 'Historial en construcción.',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // --- Acción no reconocida ---
    default:
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Acción no válida o no encontrada.',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        break;
}
