<?php
declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT SERVICE: Generación y validación de tokens JWT
 * ====================================================
 *
 * Servicio stateless para manejar access tokens JWT con algoritmo HS256.
 * Se configura via .env: JWT_SECRET (clave secreta) y JWT_EXPIRY (segundos).
 *
 * FLUJO:
 *   Login exitoso → JwtService::generateToken($user) → devuelve token
 *   Request entrante → AuthMiddleware → JwtService::validateToken($token) → payload
 *
 * DECISIÓN TÉCNICA:
 *   No implementamos refresh token porque el frontend es HTML plano sin
 *   lógica de auto-refresh. Si el token expira, el usuario vuelve a login.
 *   Para un universitario con datos no sensibles, es suficiente.
 *
 * @see \App\Security\AuthMiddleware
 */
class JwtService
{
    private string $secret;
    private int $expiry;

    public function __construct()
    {
        // JWT_SECRET es OBLIGATORIO — sin fallback por seguridad
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException(
                'JWT_SECRET no está definido en .env. '
                . 'Ejecutá: php -r "echo bin2hex(random_bytes(32));" y copiá el resultado.'
            );
        }
        $this->secret = $secret;
        $this->expiry = (int)($_ENV['JWT_EXPIRY'] ?? 3600); // 1 hora por defecto
    }

    /**
     * Genera un JWT para el usuario autenticado.
     *
     * @param  int    $userId   ID del usuario
     * @param  string $usuario  Nombre de usuario
     * @param  string $rol      Rol del usuario ('admin' o 'user')
     * @return string           Token JWT (HS256)
     */
    public function generateToken(int $userId, string $usuario, string $rol): string
    {
        $now = time();
        $payload = [
            'sub'     => $userId,              // subject — ID del usuario
            'usuario' => $usuario,              // nombre de usuario (para mostrar)
            'rol'     => $rol,                  // rol para autorización
            'iat'     => $now,                  // emitido en
            'exp'     => $now + $this->expiry,  // expira en
        ];

        return JWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Valida un JWT y devuelve el payload decodificado.
     *
     * @param  string $token  Token JWT a validar
     * @return object         Payload decodificado (sub, usuario, rol, iat, exp)
     *
     * @throws \RuntimeException Si el token es inválido, expiró o la firma no coincide
     */
    public function validateToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new \RuntimeException('Token expirado. Inicie sesión nuevamente.');
        } catch (\Exception $e) {
            throw new \RuntimeException('Token inválido o firma incorrecta.');
        }
    }
}
