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

// 4. Lee la acción solicitada desde la URL (?action=login)
$action = $_GET['action'] ?? null;

// 5. Router principal: instancia el controlador correcto inyectándole el EntityManager
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