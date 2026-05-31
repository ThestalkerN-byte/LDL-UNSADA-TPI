<?php
namespace App\Controller;

use App\Service\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador REST para autenticación de usuarios.
 * Recibe las peticiones HTTP relacionadas con login, logout y recuperación de contraseña.
 * Delegamos la lógica en AuthService, y devolvemos siempre JSON.
 */
class AuthController {
    private AuthService $authService;

    public function __construct(AuthService $authService) {
        $this->authService = $authService;
    }

    /**
     * POST /login
     * Recibe credenciales y devuelve token o error.
     */
    public function login(Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $usuario = $data['usuario'] ?? null;
        $password = $data['password'] ?? null;

        $resultado = $this->authService->login($usuario, $password);
        return new JsonResponse($resultado);
    }

    /**
     * POST /logout
     * Cierra la sesión del usuario.
     */
    public function logout(): JsonResponse {
        $resultado = $this->authService->logout();
        return new JsonResponse($resultado);
    }

    /**
     * POST /recover
     * Inicia proceso de recuperación de contraseña.
     */
    public function recover(Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $usuario = $data['usuario'] ?? null;

        $resultado = $this->authService->recoverPassword($usuario);
        return new JsonResponse($resultado);
    }
}