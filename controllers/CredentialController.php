<?php
require_once __DIR__ . "/../models/Credential.php";

class CredentialController {
    private $credential;

    public function __construct() {
        $this->credential = new Credential();
    }

    public function handleRequest() {
        echo json_encode(["status" => "ok", "message" => "Módulo de credenciales"]);
    }
}
?>