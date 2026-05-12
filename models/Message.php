<?php
require_once __DIR__ . "/../config/Database.php";

class Message {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function enviar($idUsuario, $asunto, $mensaje) {
        $sql = "INSERT INTO mensajes (id_usuario, asunto, mensaje, estado) 
                VALUES (:id, :asunto, :mensaje, 'Pendiente')";
        return $this->db->query($sql, [
            ":id" => $idUsuario,
            ":asunto" => $asunto,
            ":mensaje" => $mensaje
        ]);
    }

    public function responder($idMensaje, $respuesta) {
        $sql = "UPDATE mensajes SET respuesta = :resp, estado = 'Respondido' 
                WHERE id_mensaje = :id";
        return $this->db->query($sql, [":resp" => $respuesta, ":id" => $idMensaje]);
    }

    // ✅ Nuevo: listar mensajes por usuario
    public function listByUser($idUsuario) {
        $sql = "SELECT * FROM mensajes WHERE id_usuario = :id ORDER BY fecha_envio DESC";
        return $this->db->query($sql, [":id" => $idUsuario])->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>