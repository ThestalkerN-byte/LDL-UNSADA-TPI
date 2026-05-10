<?php
require_once __DIR__ . "/../models/User.php";

class AuthController {
    private $user;

    public function __construct() {
        $this->user = new User();
    }

    public function handleRequest($action) {
        switch ($action) {
            case "login":
                $identificador = $_GET["usuario"] ?? $_GET["dni"] ?? null;
                $password = $_GET["password"] ?? null;
                echo json_encode($this->user->login($identificador, $password));
                break;

            case "logout":
                echo json_encode(["status" => "ok", "message" => "Sesión cerrada"]);
                break;

            case "recuperar":
                echo json_encode(["status" => "ok", "message" => "Función de recuperación"]);
                break;
        }
    }
}
?>