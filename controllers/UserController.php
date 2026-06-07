<?php
require_once __DIR__ . "/../models/User.php";

class UserController {
    private $entityManager;

    // Doctrine requiere recibir el EntityManager (el administrador de la BD)
    public function __construct($entityManager) {
        $this->entityManager = $entityManager;
    }

    public function handleRequest() {
        header('Content-Type: application/json; charset=utf-8');
        
        $op = filter_input(INPUT_GET, 'op', FILTER_DEFAULT) ?? "ver";
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($op) {
            case "alta":
                if ($method !== 'POST') {
                    echo json_encode(["status" => "error", "message" => "Método no permitido."]);
                    return;
                }

                // Capturamos los datos del POST
                $dni = $_POST['dni'] ?? '';
                $usuarioInput = $_POST['usuario'] ?? '';
                $password = $_POST['password'] ?? '';
                $nombre = $_POST['nombre'] ?? '';
                $apellido = $_POST['apellido'] ?? '';
                $rol = $_POST['rol'] ?? 'Usuario';

                // VALIDACIÓN: ¿El DNI ya existe? (Doctrine lo busca con findOneBy)
                $userRepository = $this->entityManager->getRepository(User::class);
                $usuarioExistente = $userRepository->findOneBy(['dni' => $dni]);

                if ($usuarioExistente) {
                    echo json_encode(["status" => "error", "message" => "El DNI ya está registrado."]);
                    return;
                }

                // CREACIÓN: Instanciamos la entidad y usamos los setters
                $nuevoUsuario = new User();
                $nuevoUsuario->setDni($dni);
                $nuevoUsuario->setUsuario($usuarioInput);
                $nuevoUsuario->setPassword(password_hash($password, PASSWORD_BCRYPT));
                $nuevoUsuario->setNombre($nombre);
                $nuevoUsuario->setApellido($apellido);
                $nuevoUsuario->setRol($rol);
                $nuevoUsuario->setEstado('Activo');

                // GUARDADO: Le avisamos a Doctrine que lo persista e impacte en la BD
                $this->entityManager->persist($nuevoUsuario);
                $this->entityManager->flush();

                echo json_encode(["status" => "ok", "message" => "Usuario registrado."]);
                break;

            case "baja":
                if ($method !== 'POST') {
                    echo json_encode(["status" => "error", "message" => "Método no permitido."]);
                    return;
                }

                $dni = $_POST['dni'] ?? '';

                // Buscamos al usuario por DNI
                $userRepository = $this->entityManager->getRepository(User::class);
                $usuario = $userRepository->findOneBy(['dni' => $dni]);

                if (!$usuario) {
                    echo json_encode(["status" => "error", "message" => "Usuario no encontrado."]);
                    return;
                }

                // BORRADO LÓGICO: Cambiamos el estado a Inactivo y flush
                $usuario->setEstado('Inactivo');
                $this->entityManager->flush();

                echo json_encode(["status" => "ok", "message" => "Usuario dado de baja correctamente."]);
                break;

            default:
                echo json_encode(["status" => "error", "message" => "Operación no válida."]);
                break;
        }
    }
}