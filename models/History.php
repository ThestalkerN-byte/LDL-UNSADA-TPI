<?php
require_once __DIR__ . "/../config/Database.php";

class History {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function registrar($idAdmin, $accion, $detalle) {
        $sql = "INSERT INTO historial (id_admin, accion, detalle) 
                VALUES (:id, :accion, :detalle)";
        return $this->db->query($sql, [
            ":id" => $idAdmin,
            ":accion" => $accion,
            ":detalle" => $detalle
        ]);
    }
}
?>