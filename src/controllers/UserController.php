<?php
namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador REST para gestión de usuarios.
 * Solo accesible por administradores.
 */
class UserController {
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository) {
        $this->userRepository = $userRepository;
    }

    /**
     * GET /users
     * Devuelve todos los usuarios.
     */
    public function index(): JsonResponse {
        $usuarios = $this->userRepository->findAll();
        return new JsonResponse($usuarios);
    }

    /**
     * GET /users/{id}
     * Devuelve un usuario por ID.
     */
    public function show(int $id): JsonResponse {
        $usuario = $this->userRepository->find($id);
        if (!$usuario) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], 404);
        }
        return new JsonResponse($usuario);
    }

    /**
     * POST /users
     * Crea un nuevo usuario.
     */
    public function create(Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $usuario = $this->userRepository->create($data);
        return new JsonResponse(['message' => 'Usuario creado', 'usuario' => $usuario], 201);
    }

    /**
     * PUT /users/{id}
     * Actualiza un usuario existente.
     */
    public function update(Request $request, int $id): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $usuario = $this->userRepository->update($id, $data);
        if (!$usuario) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], 404);
        }
        return new JsonResponse(['message' => 'Usuario actualizado', 'usuario' => $usuario]);
    }

    /**
     * DELETE /users/{id}
     * Elimina un usuario.
     */
    public function delete(int $id): JsonResponse {
        $resultado = $this->userRepository->delete($id);
        if (!$resultado) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], 404);
        }
        return new JsonResponse(['message' => 'Usuario eliminado']);
    }
}