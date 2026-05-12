<?php
require_once __DIR__ . "/../config/Database.php";

class Credential {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function emitir($idUsuario) {
        $sql = "INSERT INTO credenciales (id_usuario, fecha_emision, fecha_vencimiento, estado) 
                VALUES (:id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'Activa')";
        return $this->db->query($sql, [":id" => $idUsuario]);
    }

    public function renovar($idCredencial) {
        $sql = "UPDATE credenciales 
                SET fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL 1 YEAR), estado = 'Activa' 
                WHERE id_credencial = :id";
        return $this->db->query($sql, [":id" => $idCredencial]);
    }

    public function getByUserId($idUsuario) {
        $sql = "SELECT * FROM credenciales WHERE id_usuario = :id ORDER BY fecha_emision DESC LIMIT 1";
        return $this->db->query($sql, [":id" => $idUsuario])->fetch(PDO::FETCH_ASSOC);
    }

    public function checkVencimiento() {
        $sql = "UPDATE credenciales 
                SET estado = 'Vencida' 
                WHERE fecha_vencimiento < CURDATE() AND estado = 'Activa'";
        return $this->db->query($sql);
    }
}
?>