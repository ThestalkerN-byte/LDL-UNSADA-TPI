<?php
namespace App\Service;

use App\Repository\UserRepository;

/**
 * Servicio de autenticación.
 * Contiene la lógica de login, logout y recuperación de contraseña.
 * No accede directamente a la base de datos, usa UserRepository.
 */
class AuthService {
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function login(?string $usuario, ?string $password): array {
        $user = $this->userRepository->findByUsuarioODni($usuario);
        if (!$user) {
            return ['error' => 'Usuario no encontrado'];
        }
        if ($user->getPassword() !== $password) {
            return ['error' => 'Contraseña incorrecta'];
        }
        return ['status' => 'ok', 'message' => 'Login exitoso'];
    }

    public function logout(): array {
        return ['status' => 'ok', 'message' => 'Sesión cerrada'];
    }

    public function recoverPassword(?string $usuario): array {
        // Aquí iría la lógica de envío de email o token de recuperación
        return ['status' => 'ok', 'message' => 'Proceso de recuperación iniciado'];
    }
}