<?php
require_once __DIR__ . "/../models/User.php";

class UserController {
    private $user;

    public function __construct() {
        $this->user = new User();
    }

    public function handleRequest() {
        // Aseguramos que la respuesta para el frontend sea siempre JSON
        header('Content-Type: application/json; charset=utf-8');

        // Sanitización de la orden
        $op = filter_input(INPUT_GET, 'op', FILTER_DEFAULT) ?? "ver";
        $op = htmlspecialchars($op, ENT_QUOTES, 'UTF-8');
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($op) {
            case "ver":
                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "ID inválido."]);
                    break;
                }
                echo json_encode(["status" => "ok", "data" => $this->user->getById($id)]);
                break;

            case "buscar":
                // Limpieza del buscador para el frontend
                $termino = filter_input(INPUT_GET, 'termino', FILTER_DEFAULT) ?? '';
                $termino = htmlspecialchars(trim($termino), ENT_QUOTES, 'UTF-8');
                $soloActivos = isset($_GET["todos"]) ? false : true; 
                
                $resultados = $this->user->search($termino, $soloActivos);
                echo json_encode(["status" => "ok", "data" => $resultados]);
                break;

            case "crear":
                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode(["status" => "error", "message" => "Use POST para registrar."]);
                    break;
                }

                $datos = [
                    "dni"      => htmlspecialchars(trim($_POST["dni"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "usuario"  => htmlspecialchars(trim($_POST["usuario"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "password" => $_POST["password"] ?? null,
                    "nombre"   => htmlspecialchars(trim($_POST["nombre"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "apellido" => htmlspecialchars(trim($_POST["apellido"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "rol"      => htmlspecialchars(trim($_POST["rol"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8')
                ];

                if ($this->user->existsByDni($datos["dni"])) {
                    http_response_code(409); // Conflict
                    echo json_encode(["status" => "error", "message" => "El DNI ya está registrado."]);
                    break;
                }

                if ($this->user->create($datos)) {
                    http_response_code(201); // Created
                    echo json_encode(["status" => "ok", "message" => "Usuario registrado."]);
                }
                break;

            case "editar":
                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode(["status" => "error", "message" => "Use POST para actualizar."]);
                    break;
                }

                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
                $datos = [
                    "nombre"   => htmlspecialchars(trim($_POST["nombre"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "apellido" => htmlspecialchars(trim($_POST["apellido"] ?? ''), ENT_QUOTES, 'UTF-8'),
                    "rol"      => htmlspecialchars(trim($_POST["rol"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8')
                ];

                if ($this->user->update($id, $datos)) {
                    echo json_encode(["status" => "ok", "message" => "Perfil actualizado."]);
                }
                break;

            case "eliminar":
                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode(["status" => "error", "message" => "Use POST para la baja."]);
                    break;
                }

                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
                if ($this->user->softDelete($id)) {
                    echo json_encode(["status" => "ok", "message" => "Usuario inactivo."]);
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Acción inválida."]);
                break;
        }
    }
}
?>