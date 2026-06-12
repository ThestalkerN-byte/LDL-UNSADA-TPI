<?php

require_once __DIR__ . "/../models/User.php";

class UserController
{
    private $entityManager;

    public function __construct($entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function handleRequest()
    {
        header('Content-Type: application/json; charset=utf-8');

        $op = filter_input(INPUT_GET, 'op') ?? 'ver';
        $method = $_SERVER['REQUEST_METHOD'];

        $userRepository = $this->entityManager->getRepository(User::class);

        switch ($op) {

            case "ver":

                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

                if (!$id) {
                    http_response_code(400);
                    echo json_encode([
                        "status" => "error",
                        "message" => "ID inválido"
                    ]);
                    break;
                }

                $usuario = $userRepository->find($id);

                if (!$usuario) {
                    http_response_code(404);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Usuario no encontrado"
                    ]);
                    break;
                }

                echo json_encode([
                    "status" => "ok",
                    "data" => [
                        "id_usuario" => $usuario->getIdUsuario(),
                        "dni" => $usuario->getDni(),
                        "usuario" => $usuario->getUsuario(),
                        "nombre" => $usuario->getNombre(),
                        "apellido" => $usuario->getApellido(),
                        "rol" => $usuario->getRol(),
                        "estado" => $usuario->getEstado()
                    ]
                ]);
                break;


            case "buscar":

                $termino = trim($_GET['termino'] ?? '');

                $qb = $this->entityManager->createQueryBuilder();

                $qb->select('u')
                    ->from(User::class, 'u')
                    ->where('u.dni LIKE :t')
                    ->orWhere('u.apellido LIKE :t')
                    ->orWhere('u.rol LIKE :t')
                    ->setParameter('t', "%{$termino}%");

                $usuarios = $qb->getQuery()->getResult();

                $resultado = [];

                foreach ($usuarios as $usuario) {
                    $resultado[] = [
                        "id_usuario" => $usuario->getIdUsuario(),
                        "dni" => $usuario->getDni(),
                        "usuario" => $usuario->getUsuario(),
                        "nombre" => $usuario->getNombre(),
                        "apellido" => $usuario->getApellido(),
                        "rol" => $usuario->getRol(),
                        "estado" => $usuario->getEstado()
                    ];
                }

                echo json_encode([
                    "status" => "ok",
                    "data" => $resultado
                ]);
                break;


            case "crear":

                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Use POST"
                    ]);
                    break;
                }

                $dni = trim($_POST['dni'] ?? '');

                $usuarioExistente = $userRepository->findOneBy([
                    'dni' => $dni
                ]);

                if ($usuarioExistente) {
                    http_response_code(409);
                    echo json_encode([
                        "status" => "error",
                        "message" => "El DNI ya existe"
                    ]);
                    break;
                }

                $usuario = new User();

                $usuario->setDni($dni);
                $usuario->setUsuario(trim($_POST['usuario'] ?? ''));
                $usuario->setPassword(
                    password_hash(
                        $_POST['password'] ?? '',
                        PASSWORD_BCRYPT
                    )
                );
                $usuario->setNombre(trim($_POST['nombre'] ?? ''));
                $usuario->setApellido(trim($_POST['apellido'] ?? ''));
                $usuario->setRol(trim($_POST['rol'] ?? 'Usuario'));
                $usuario->setEstado('Activo');

                $this->entityManager->persist($usuario);
                $this->entityManager->flush();

                http_response_code(201);

                echo json_encode([
                    "status" => "ok",
                    "message" => "Usuario registrado"
                ]);

                break;


            case "editar":

                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Use POST"
                    ]);
                    break;
                }

                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

                $usuario = $userRepository->find($id);

                if (!$usuario) {
                    http_response_code(404);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Usuario no encontrado"
                    ]);
                    break;
                }

                $usuario->setNombre(trim($_POST['nombre'] ?? ''));
                $usuario->setApellido(trim($_POST['apellido'] ?? ''));
                $usuario->setRol(trim($_POST['rol'] ?? 'Usuario'));

                $this->entityManager->flush();

                echo json_encode([
                    "status" => "ok",
                    "message" => "Usuario actualizado"
                ]);

                break;


            case "eliminar":

                if ($method !== 'POST') {
                    http_response_code(405);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Use POST"
                    ]);
                    break;
                }

                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

                $usuario = $userRepository->find($id);

                if (!$usuario) {
                    http_response_code(404);
                    echo json_encode([
                        "status" => "error",
                        "message" => "Usuario no encontrado"
                    ]);
                    break;
                }

                $usuario->setEstado('Inactivo');

                $this->entityManager->flush();

                echo json_encode([
                    "status" => "ok",
                    "message" => "Usuario dado de baja"
                ]);

                break;


            default:

                http_response_code(400);

                echo json_encode([
                    "status" => "error",
                    "message" => "Operación no válida"
                ]);

                break;
        }
    }
}
