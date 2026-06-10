<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para gestión de usuarios.
 * Solo accesible por administradores.
 */
class UserController {
    private EntityManagerInterface $em;
    private UserRepository $userRepository;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        $this->userRepository = $em->getRepository(User::class);
    }

    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        match ($method) {
            'GET'    => $id ? $this->show($id) : $this->index(),
            'POST'   => $this->create(),
            'PUT'    => $id ? $this->update($id) : $this->responder(400, 'error', 'Se requiere un ID para actualizar.'),
            'DELETE' => $id ? $this->delete($id) : $this->responder(400, 'error', 'Se requiere un ID para eliminar.'),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    private function index(): void {
        $usuarios = $this->userRepository->findBy(['estado' => true]);
        $data = array_map(fn(User $u) => $this->serializeUser($u), $usuarios);
        $this->responder(200, 'success', 'Usuarios listados.', $data);
    }

    private function show(int $id): void {
        $usuario = $this->userRepository->find($id);
        if (!$usuario || !$usuario->isEstado() === false) {
            $this->responder(404, 'error', 'Usuario no encontrado.');
            return;
        }
        $this->responder(200, 'success', 'Usuario encontrado.', $this->serializeUser($usuario));
    }

    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['usuario']) || empty($data['password']) || empty($data['nombre']) || empty($data['apellido']) || empty($data['dni']) || empty($data['email']) || empty($data['rol'])) {
            $this->responder(400, 'error', 'Faltan campos obligatorios.');
            return;
        }

        $usuario = new User();
        $usuario->setUsuario($data['usuario']);
        $usuario->setPassword(password_hash($data['password'], PASSWORD_DEFAULT));
        $usuario->setNombre($data['nombre']);
        $usuario->setApellido($data['apellido']);
        $usuario->setDni($data['dni']);
        $usuario->setEmail($data['email']);
        $usuario->setRol($data['rol']);
        $usuario->setEstado(true);

        $this->em->persist($usuario);
        $this->em->flush();

        $this->responder(201, 'success', 'Usuario creado.', $this->serializeUser($usuario));
    }

    private function update(int $id): void {
        $usuario = $this->userRepository->find($id);
        if (!$usuario || !$usuario->isEstado() === false) {
            $this->responder(404, 'error', 'Usuario no encontrado.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!empty($data['nombre'])) $usuario->setNombre($data['nombre']);
        if (!empty($data['apellido'])) $usuario->setApellido($data['apellido']);
        if (!empty($data['email'])) $usuario->setEmail($data['email']);
        if (!empty($data['rol'])) $usuario->setRol($data['rol']);
        if (!empty($data['password'])) $usuario->setPassword(password_hash($data['password'], PASSWORD_DEFAULT));

        $this->em->flush();
        $this->responder(200, 'success', 'Usuario actualizado.', $this->serializeUser($usuario));
    }

    private function delete(int $id): void {
        $usuario = $this->userRepository->find($id);
        if (!$usuario || !$usuario->isEstado() === false) {
            $this->responder(404, 'error', 'Usuario no encontrado.');
            return;
        }

        $usuario->setEstado(false);
        $this->em->flush();

        $this->responder(200, 'success', 'Usuario eliminado lógicamente.');
    }

    private function serializeUser(User $usuario): array {
        return [
            'id' => $usuario->getId(),
            'usuario' => $usuario->getUsuario(),
            'nombre' => $usuario->getNombre(),
            'apellido' => $usuario->getApellido(),
            'dni' => $usuario->getDni(),
            'email' => $usuario->getEmail(),
            'rol' => $usuario->getRol(),
            'estado' => $usuario->isEstado()
        ];
    }

    private function responder(int $httpCode, string $status, string $message, array $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}