<?php
declare(strict_types=1);
namespace ICB\Service;

use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use ICB\Entity\Usuario;
use ICB\Exception\AuthException;

/*
 * AUTH SERVICE: Autenticación y generación de tokens JWT
 * ======================================================
 * Propósito: manejar login, refresh de tokens, y validación de JWT.
 * 
 * Flujo de login:
 *   1. Busca usuario por identificador (usuario o DNI) usando UsuarioRepository::findByIdentifier()
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
 *   - Access token: JWT con claims sub (id_usuario), roles, iat, exp
 *   - Refresh token: string aleatorio de 64 caracteres hex, almacenado en DB
 *   - Rotación de refresh token: cada vez que se usa, se genera uno nuevo
 *     (el viejo queda invalidado). Esto protege contra robo de tokens.
 *   - Algoritmo JWT: HS256 (HMAC-SHA256) con clave secreta desde .env
 *   - Los claims incluyen los roles para no tener que consultar la DB
 *     en cada request (el middleware verifica contra el token)
 *   - La serialización del usuario excluye datos sensibles (password_hash,
 *     refresh_token, tokens de reseteo)
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
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '') {
            throw new \RuntimeException(
                'JWT_SECRET no está definido en el archivo .env. '
                . 'Ejecutá: php -r "echo bin2hex(random_bytes(32));" y copiá el resultado.'
            );
        }
        $this->jwtSecret = $secret;

        $this->jwtExpires = (int)($_ENV['JWT_EXPIRES'] ?? 900);        // 15 min
        $this->refreshExpires = (int)($_ENV['JWT_REFRESH_EXPIRES'] ?? 2592000); // 30 días
    }

    /**
     * Login: autentica usuario por usuario/DNI + password.
     * @throws AuthException si credenciales inválidas o usuario inactivo
     * @return array con 'access_token', 'refresh_token', 'expires_in', 'usuario'
     */
    public function login(string $identifier, string $password): array
    {
        // Buscar por usuario O DNI (usando el repositorio existente)
        $usuario = $this->em->getRepository(Usuario::class)->findByIdentifier($identifier);

        if (!$usuario || !password_verify($password, $usuario->getPasswordHash())) {
            throw new AuthException('Credenciales inválidas');
        }

        if ($usuario->getEstado() !== 'Activo') {
            throw new AuthException('Usuario inactivo');
        }

        return $this->generarTokens($usuario);
    }

    /**
     * Refresh: renueva tokens usando refresh token.
     * Implementa ROTACIÓN: el viejo refresh token se invalida y se genera uno nuevo.
     * @throws AuthException si el refresh token es inválido o expiró
     */
    public function refresh(string $refreshToken): array
    {
        $usuario = $this->em->getRepository(Usuario::class)->findOneBy([
            'refreshToken' => $refreshToken
        ]);

        if (!$usuario) {
            $this->detectarReusoToken($refreshToken);
            throw new AuthException('Refresh token inválido');
        }

        // Verificar expiración
        $ahora = new \DateTime();
        if ($usuario->getRefreshTokenExpira() < $ahora) {
            // Limpiar token expirado
            $usuario->setRefreshToken(null);
            $usuario->setRefreshTokenExpira(null);
            $this->em->flush();
            throw new AuthException('Refresh token expirado');
        }

        // Generar nuevos tokens (el viejo se invalida al setear el nuevo)
        return $this->generarTokens($usuario);
    }

    /**
     * Genera un access token JWT y un refresh token para el usuario.
     * Guarda el refresh token en la base de datos.
     */
    private function generarTokens(Usuario $usuario): array
    {
        $ahora = time();
        $expira = $ahora + $this->jwtExpires;

        // Obtener roles del usuario desde la colección ManyToMany
        $roles = [];
        foreach ($usuario->getRoles() as $rol) {
            $roles[] = $rol->getNombre();
        }

        // Payload del access token JWT
        $payload = [
            'sub'   => $usuario->getIdUsuario(),
            'usuario' => $usuario->getUsuario(),
            'roles' => $roles,
            'iat'   => $ahora,
            'exp'   => $expira,
        ];

        $accessToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

        // Guardar token anterior para detección de reuso
        $oldToken = $usuario->getRefreshToken();

        // Generar nuevo refresh token (64 caracteres hex)
        $refreshToken = bin2hex(random_bytes(32));
        $refreshExpira = new \DateTime("+{$this->refreshExpires} seconds");

        $usuario->setRefreshToken($refreshToken);
        $usuario->setRefreshTokenExpira($refreshExpira);
        $this->em->flush();

        // Persistir hash del token anterior para detectar reuso
        if ($oldToken !== null) {
            $usedTokensDir = sys_get_temp_dir() . '/icb-used-tokens';
            if (!is_dir($usedTokensDir)) {
                @mkdir($usedTokensDir, 0700, true);
            }
            $usedTokenFile = $usedTokensDir . '/' . md5($oldToken) . '.json';
            file_put_contents($usedTokenFile, json_encode([
                'user_id' => $usuario->getIdUsuario(),
                'rotated_at' => time(),
            ]));
            chmod($usedTokenFile, 0600);
        }

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $this->jwtExpires,
            'usuario'       => $this->serializar($usuario),
        ];
    }

    /**
     * Valida un access token JWT y devuelve el payload decodificado.
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
     * Detecta si un refresh token ya fue rotado (reuso).
     * Si se detecta reuso, invalida TODOS los tokens del usuario.
     */
    private function detectarReusoToken(string $refreshToken): void
    {
        $usedTokensDir = sys_get_temp_dir() . '/icb-used-tokens';
        $usedTokenFile = $usedTokensDir . '/' . md5($refreshToken) . '.json';

        if (!file_exists($usedTokenFile)) {
            return;
        }

        $data = json_decode(file_get_contents($usedTokenFile), true);
        $userId = $data['user_id'] ?? null;

        if ($userId) {
            $storedUser = $this->em->find(Usuario::class, $userId);
            if ($storedUser) {
                $storedUser->setRefreshToken(null);
                $storedUser->setRefreshTokenExpira(null);
                $this->em->flush();
                error_log('[ICB SECURITY] Refresh token reuse detected for user ID ' . $userId);
            }
        }
    }

    /**
     * Serializa un usuario para respuesta JSON (sin datos sensibles).
     * Excluye: password_hash, refresh_token, refresh_token_expira,
     * reset_token, reset_token_expira.
     */
    private function serializar(Usuario $usuario): array
    {
        $roles = [];
        foreach ($usuario->getRoles() as $rol) {
            $roles[] = $rol->getNombre();
        }

        return [
            'id'       => $usuario->getIdUsuario(),
            'usuario'  => $usuario->getUsuario(),
            'dni'      => $usuario->getDni(),
            'nombre'   => htmlspecialchars($usuario->getNombre() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'apellido' => htmlspecialchars($usuario->getApellido() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'email'    => $usuario->getEmail(),
            'telefono' => htmlspecialchars($usuario->getTelefono() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'funcion'  => htmlspecialchars($usuario->getFuncion() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'estado'   => $usuario->getEstado(),
            'roles'    => $roles,
            'fecha_alta' => $usuario->getFechaAlta()->format('Y-m-d H:i:s'),
        ];
    }
}
