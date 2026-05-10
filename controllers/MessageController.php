<?php
require_once __DIR__ . "/../models/Message.php";

class MessageController {
    private $message;

    public function __construct() {
        $this->message = new Message();
    }

    public function handleRequest() {
        echo json_encode(["status" => "ok", "message" => "Módulo de mensajes"]);
    }
}
?>