<?php
require_once __DIR__ . "/../models/History.php";

class HistoryController {
    private $history;

    public function __construct() {
        $this->history = new History();
    }

    public function handleRequest() {
        echo json_encode(["status" => "ok", "message" => "Módulo de historial"]);
    }
}
?>