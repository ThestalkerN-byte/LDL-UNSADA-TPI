<?php
require_once __DIR__ . "/../config/Database.php";

class User {
    private $db;

    public function __construct() {
        // Usa la conexión que armó el equipo de Infraestructura
        $this->db = new Database();
    }

    // Usado para el Login (solo acá se consulta el password)
    public function login($identificador) {
        $sql = "SELECT id_usuario, usuario, password, rol, estado FROM usuarios 
                WHERE (usuario = :usuario OR dni = :dni) LIMIT 1";
        return $this->db->query($sql, [
            ":usuario" => $identificador,
            ":dni" => $identificador
        ])->fetch(PDO::FETCH_ASSOC);
    }

    // Ver un usuario por ID (Sin exponer la clave)
    public function getById($id) {
        $sql = "SELECT id_usuario, dni, usuario, nombre, apellido, rol, estado FROM usuarios WHERE id_usuario = :id";
        return $this->db->query($sql, [":id" => $id])->fetch(PDO::FETCH_ASSOC);
    }

    // Validación para evitar DNI duplicados
    public function existsByDni($dni) {
        $sql = "SELECT COUNT(*) as total FROM usuarios WHERE dni = :dni";
        $result = $this->db->query($sql, [":dni" => $dni])->fetch(PDO::FETCH_ASSOC);
        return $result['total'] > 0;
    }

    // RF08: Crear usuario (El admin asigna los datos)
    public function create($datos) {
        $sql = "INSERT INTO usuarios (dni, usuario, password, nombre, apellido, rol, estado) 
                VALUES (:dni, :usuario, :password, :nombre, :apellido, :rol, 'Activo')";
        return $this->db->query($sql, [
            ":dni"      => $datos['dni'],
            ":usuario"  => $datos['usuario'],
            ":password" => $datos['password'], 
            ":nombre"   => $datos['nombre'],
            ":apellido" => $datos['apellido'],
            ":rol"      => $datos['rol']
        ]);
    }

    // RF08: Editar Perfil
    public function update($id, $datos) {
        $sql = "UPDATE usuarios 
                SET nombre = :nombre, apellido = :apellido, rol = :rol 
                WHERE id_usuario = :id";
        return $this->db->query($sql, [
            ":nombre"   => $datos['nombre'],
            ":apellido" => $datos['apellido'],
            ":rol"      => $datos['rol'],
            ":id"       => $id
        ]);
    }

    // CU3: Borrado Lógico
    public function softDelete($id) {
        $sql = "UPDATE usuarios SET estado = 'Inactivo' WHERE id_usuario = :id";
        return $this->db->query($sql, [":id" => $id]);
    }

    // RF10: Buscador Seguro (Para que el frontend arme la tabla)
    public function search($termino, $soloActivos = true) {
        $sql = "SELECT id_usuario, dni, usuario, nombre, apellido, rol, estado 
                FROM usuarios 
                WHERE (dni LIKE :t OR apellido LIKE :t OR rol LIKE :t)";
        
        if ($soloActivos) {
            $sql .= " AND estado = 'Activo'";
        }
        
        $params = [":t" => "%" . $termino . "%"];
        return $this->db->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>