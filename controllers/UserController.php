<?php
require_once __DIR__ . "/../models/User.php";

class UserController {
    private $user;

    public function __construct() {
        $this->user = new User();
    }

    public function handleRequest() {
        $id = $_GET["id"] ?? null;
        echo json_encode($this->user->getById($id));
    }
}
?>