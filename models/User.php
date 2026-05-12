<?php
require_once __DIR__ . "/../config/Database.php";

class User {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function login($identificador, $password) {
        $sql = "SELECT * FROM usuarios 
                WHERE (usuario = :usuario OR dni = :dni) 
                AND password = :password LIMIT 1";
        return $this->db->query($sql, [
            ":usuario" => $identificador,
            ":dni" => $identificador,
            ":password" => $password
        ])->fetch(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT * FROM usuarios WHERE id_usuario = :id";
        return $this->db->query($sql, [":id" => $id])->fetch(PDO::FETCH_ASSOC);
    }
}
?>