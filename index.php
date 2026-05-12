<?php

$action = $_GET['action'] ?? null;

switch ($action) {
    case "login":
    case "logout":
    case "recuperar":
        require_once __DIR__ . "/controllers/AuthController.php";
        $controller = new AuthController();
        $controller->handleRequest($action);
        break;

    case "user":
        require_once __DIR__ . "/controllers/UserController.php";
        $controller = new UserController();
        $controller->handleRequest();
        break;

    case "credential":
        require_once __DIR__ . "/controllers/CredentialController.php";
        $controller = new CredentialController();
        $controller->handleRequest();
        break;

    case "message":
        require_once __DIR__ . "/controllers/MessageController.php";
        $controller = new MessageController();
        $controller->handleRequest();
        break;

    case "history":
        require_once __DIR__ . "/controllers/HistoryController.php";
        $controller = new HistoryController();
        $controller->handleRequest();
        break;

    default:
        echo json_encode([
            "status" => "error",
            "message" => "Acción no válida"
        ]);
        break;
}
?>