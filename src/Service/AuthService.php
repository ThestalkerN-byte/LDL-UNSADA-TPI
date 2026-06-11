<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\AuthException;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/*
 * AUTH SERVICE: Autenticación y generación de tokens JWT
 * ======================================================
 * Propósito: manejar login, refresh de tokens, y validación de JWT.
 *
 * Flujo de login:
 *   1. Busca usuario por identificador (usuario o DNI) usando UserRepository::findByIdentifier()
 *   2. Verifica password con password_verify() contra el hash almacenado
 *   3. Genera access token JWT (corta duración: 15 min por defecto)
 *   4. Genera refresh token (larga duración: 30 días)
 *   5. Guarda refresh token en DB para poder revocarlo
 *   6. Devuelve ambos tokens + datos serializados del usuario
 *
 * Flujo de refresh:
 *   1. Busca usuario por refresh token
 *   2. Verifica que no haya expirado
 *   3. Implementa ROTACIÓN: el viejo token se invalida al generar uno nuevo
 *      (protección contra robo de tokens — si alguien lo intercepta,
 *       al usarlo, el dueño legítimo queda invalidado y se da cuenta)
 *   4. Devuelve nuevos tokens
 *
 * Decisiones técnicas:
 *   - Access token: JWT con claims sub (id), usuario, rol, iat, exp
 *   - Refresh token: string aleatorio de 64 caracteres hex, almacenado en DB
 *   - Rotación de refresh token: cada vez que se usa, se genera uno nuevo
 *     (el viejo queda invalidado). Esto protege contra robo de tokens.
 *   - Algoritmo JWT: HS256 (HMAC-SHA256) con clave secreta desde .env
 *   - Los claims incluyen el rol para no tener que consultar la DB
 *     en cada request (el middleware verifica contra el token y recarga User)
 *   - La serialización del usuario excluye datos sensibles (password,
 *     refresh_token, refresh_token_expira)
 */
class AuthService
{
    private EntityManagerInterface $em;
    private string $jwtSecret;
    private int $jwtExpires;       // en segundos
    private int $refreshExpires;   // en segundos

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;

        // JWT_SECRET es OBLIGATORIO — sin fallback por seguridad
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
        if ($secret === '') {
            throw new \RuntimeException(
                'JWT_SECRET no está definido en el archivo .env. '
                . 'Ejecutá: php -r "echo bin2hex(random_bytes(32));" y copiá el resultado.'
            );
        }

        $this->jwtSecret = $secret;
        $this->jwtExpires = (int) ($_ENV['JWT_EXPIRES'] ?? 900);        // 15 min
        $this->refreshExpires = (int) ($_ENV['JWT_REFRESH_EXPIRES'] ?? 2592000); // 30 días
    }

    /**
     * Login: autentica usuario por usuario/DNI + password.
     *
     * @throws AuthException si credenciales inválidas o usuario inactivo
     * @return array con 'access_token', 'refresh_token', 'expires_in', 'usuario'
     */
    public function login(string $identifier, string $password): array
    {
        // Buscar por usuario O DNI (usando el repositorio existente)
        $user = $this->em->getRepository(User::class)->findByIdentifier($identifier);

        if (!$user || !password_verify($password, $user->getPassword())) {
            throw new AuthException('Credenciales inválidas');
        }

        if (!$user->isEstado()) {
            throw new AuthException('Usuario inactivo');
        }

        return $this->generarTokens($user);
    }

    /**
     * Refresh: renueva tokens usando refresh token.
     * Implementa ROTACIÓN: el viejo refresh token se invalida y se genera uno nuevo.
     *
     * @throws AuthException si el refresh token es inválido o expiró
     * @return array con 'access_token', 'refresh_token', 'expires_in', 'usuario'
     */
    public function refresh(string $refreshToken): array
    {
        $user = $this->em->getRepository(User::class)->findOneBy([
            'refreshToken' => $refreshToken,
        ]);

        if (!$user) {
            $this->detectarReusoToken($refreshToken);
            throw new AuthException('Refresh token inválido');
        }

        // Verificar expiración
        $ahora = new \DateTime();
        if ($user->getRefreshTokenExpira() === null || $user->getRefreshTokenExpira() < $ahora) {
            // Limpiar token expirado
            $user->setRefreshToken(null);
            $user->setRefreshTokenExpira(null);
            $this->em->flush();
            throw new AuthException('Refresh token expirado');
        }

        // Generar nuevos tokens (el viejo se invalida al setear el nuevo)
        return $this->generarTokens($user);
    }

    /**
     * Valida un access token JWT y devuelve el payload decodificado.
     *
     * @throws AuthException si el token es inválido o expiró
     */
    public function validateToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Exception $e) {
            throw new AuthException('Token inválido o expirado');
        }
    }

    /**
     * Revoca el refresh token del usuario (logout).
     * Invalida la sesión persistente en la base de datos.
     */
    public function revokeRefreshToken(User $user): void
    {
        $user->setRefreshToken(null);
        $user->setRefreshTokenExpira(null);
        $this->em->flush();
    }

    /**
     * Genera un access token JWT y un refresh token para el usuario.
     * Guarda el refresh token en la base de datos.
     *
     * @return array con 'access_token', 'refresh_token', 'expires_in', 'usuario'
     */
    private function generarTokens(User $user): array
    {
        $ahora = time();
        $expira = $ahora + $this->jwtExpires;

        // Payload del access token JWT
        $payload = [
            'sub' => $user->getId(),
            'usuario' => $user->getUsuario(),
            'rol' => $user->getRol(),
            'iat' => $ahora,
            'exp' => $expira,
        ];

        $accessToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

        // Guardar token anterior para detección de reuso
        $oldToken = $user->getRefreshToken();

        // Generar nuevo refresh token (64 caracteres hex)
        $refreshToken = bin2hex(random_bytes(32));
        $refreshExpira = new \DateTime("+{$this->refreshExpires} seconds");

        $user->setRefreshToken($refreshToken);
        $user->setRefreshTokenExpira($refreshExpira);
        $this->em->flush();

        // Persistir hash del token anterior para detectar reuso
        if ($oldToken !== null) {
            $this->registrarTokenRotado($oldToken, $user->getId());
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwtExpires,
            'usuario' => $this->serializar($user),
        ];
    }

    /**
     * Detecta si un refresh token ya fue rotado (reuso).
     * Si se detecta reuso, invalida TODOS los tokens del usuario.
     */
    private function detectarReusoToken(string $refreshToken): void
    {
        $usedTokensDir = sys_get_temp_dir() . '/ldl-used-tokens';
        $usedTokenFile = $usedTokensDir . '/' . md5($refreshToken) . '.json';

        if (!file_exists($usedTokenFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($usedTokenFile), true);
        $userId = $data['user_id'] ?? null;

        if ($userId) {
            $storedUser = $this->em->find(User::class, $userId);
            if ($storedUser) {
                $storedUser->setRefreshToken(null);
                $storedUser->setRefreshTokenExpira(null);
                $this->em->flush();
                error_log('[LDL SECURITY] Refresh token reuse detected for user ID ' . $userId);
            }
        }
    }

    /**
     * Registra un refresh token rotado en disco para detectar reuso posterior.
     */
    private function registrarTokenRotado(string $oldToken, int $userId): void
    {
        $usedTokensDir = sys_get_temp_dir() . '/ldl-used-tokens';
        if (!is_dir($usedTokensDir)) {
            @mkdir($usedTokensDir, 0700, true);
        }

        $usedTokenFile = $usedTokensDir . '/' . md5($oldToken) . '.json';
        file_put_contents($usedTokenFile, json_encode([
            'user_id' => $userId,
            'rotated_at' => time(),
        ]));
        @chmod($usedTokenFile, 0600);
    }

    /**
     * Serializa un usuario para respuesta JSON (sin datos sensibles).
     * Excluye: password, refresh_token, refresh_token_expira.
     */
    public function serializar(User $user): array
    {
        return [
            'id' => $user->getId(),
            'usuario' => $user->getUsuario(),
            'dni' => $user->getDni(),
            'nombre' => $user->getNombre(),
            'apellido' => $user->getApellido(),
            'email' => $user->getEmail(),
            'rol' => $user->getRol(),
            'estado' => $user->isEstado(),
        ];
    }
}
