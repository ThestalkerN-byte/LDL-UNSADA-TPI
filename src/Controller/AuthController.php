<?php
namespace App\Controller;

use App\Entity\User;
use App\RateLimiting\RateLimiter;
use App\Security\JwtService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para autenticación de usuarios.
 *
 * Maneja Login, Logout y Recuperación de contraseña.
 * Recibe el EntityManager por constructor (Inyección de Dependencias).
 * Siempre responde con JSON estandarizado: { "status", "message", "data" }.
 *
 * MIGRACIÓN A JWT:
 *   El login ahora devuelve un JWT en el campo "token" de la respuesta.
 *   El frontend debe guardar este token y enviarlo en cada request
 *   como header "Authorization: Bearer <token>".
 *   El logout es stateless (el frontend solo descarta el token).
 *   La sesión PHP sigue usándose solo para el flujo de recuperación
 *   de contraseña (recovery code en $_SESSION).
 */
class AuthController {

    private EntityManagerInterface $em;
    private JwtService $jwtService;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        $this->jwtService = new JwtService();
    }

    // =========================================================================
    // POST /index.php?action=login
    // =========================================================================

    /**
     * Autentica al usuario contra la base de datos y devuelve un JWT.
     *
     * Permite identificarse con 'usuario' o 'dni' en el mismo campo.
     * La validación de la contraseña se hace con password_verify() en PHP.
     *
     * RESPUESTA (cambió — ahora incluye token JWT):
     *   {
     *     "status": "success",
     *     "message": "Login exitoso.",
     *     "data": { "token": "eyJ...", "id": 1, "usuario": "...", "rol": "..." }
     *   }
     *
     * El frontend debe guardar "token" y enviarlo en cada request como
     *   Authorization: Bearer eyJ...
     */
    public function login(): void {

        // 1. Solo acepta POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        // 2. Rate limiting: máximo 5 intentos por IP cada 60 segundos
        $rateLimiter = new RateLimiter();
        $ip          = $rateLimiter->getClientIp();
        $rateCheck   = $rateLimiter->check("login:{$ip}", max: 5, window: 60);
        if ($rateCheck !== null) {
            $this->responder($rateCheck['code'], 'error', $rateCheck['error']);
            return;
        }

        // 3. Decodifica el body JSON que envía el Frontend
        $data       = json_decode(file_get_contents('php://input'), true);
        $identificador = trim($data['identificador'] ?? '');
        $password      = trim($data['password'] ?? '');

        // 4. Valida que no vengan vacíos
        if (empty($identificador) || empty($password)) {
            $this->responder(400, 'error', 'El identificador y la contraseña son obligatorios.');
            return;
        }

        // 5. Busca el usuario en la BD por 'usuario' o 'dni' usando el Repositorio
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user     = $userRepo->findByUsuarioODni($identificador);

        // 6. Si no existe o está dado de baja (borrado lógico), rechaza
        if (!$user || !$user->isEstado()) {
            $this->responder(401, 'error', 'Credenciales inválidas o usuario inactivo.');
            return;
        }

        // 7. Valida el hash de la contraseña con password_verify()
        //    La contraseña NUNCA viaja ni se guarda como texto plano.
        if (!password_verify($password, $user->getPassword())) {
            $this->responder(401, 'error', 'Credenciales inválidas o usuario inactivo.');
            return;
        }

        // 8. Genera JWT (stateless — no se usa sesión PHP)
        $token = $this->jwtService->generateToken(
            userId: $user->getId(),
            usuario: $user->getUsuario(),
            rol: $user->getRol()
        );

        // 9. Responde con el token + datos del usuario
        //    El frontend guarda el token y lo envía como Authorization header.
        $this->responder(200, 'success', 'Login exitoso.', [
            'token'       => $token,
            'id'          => $user->getId(),
            'usuario'     => $user->getUsuario(),
            'nombre'      => $user->getNombre(),
            'apellido'    => $user->getApellido(),
            'dni'         => $user->getDni(),
            'email'       => $user->getEmail(),
            'rol'         => $user->getRol(),
            'foto_perfil' => $user->getFotoPerfil(),
            'telefono'    => $user->getTelefono(),
            'direccion'   => $user->getDireccion(),
        ]);
    }

    // =========================================================================
    // POST /index.php?action=logout
    // =========================================================================

    /**
     * Logout stateless.
     *
     * Con JWT el logout es responsabilidad del frontend: solo descarta el token.
     * Este endpoint existe para compatibilidad: si el frontend lo llama,
     * responde éxito sin hacer nada (no hay sesión que destruir).
     *
     * NOTA: el frontend DEBE eliminar el token almacenado (localStorage/sessionStorage)
     * y redirigir al login.
     */
    public function logout(): void {

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        // JWT es stateless — no hay nada que destruir del lado del servidor.
        // El frontend debe descartar el token.
        $this->responder(200, 'success', 'Sesión cerrada correctamente.');
    }

    // =========================================================================
    // POST /index.php?action=recover_request
    // =========================================================================

    /**
     * Inicia el proceso de recuperación. Genera un código de 4 dígitos.
     */
    public function recoverRequest(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        // Rate limiting: máximo 3 intentos por IP cada 120 segundos
        $rateLimiter = new RateLimiter();
        $ip          = $rateLimiter->getClientIp();
        $rateCheck   = $rateLimiter->check("recover:{$ip}", max: 3, window: 120);
        if ($rateCheck !== null) {
            $this->responder($rateCheck['code'], 'error', $rateCheck['error']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $identificador = trim($data['identificador'] ?? '');

        if (empty($identificador)) {
            $this->responder(400, 'error', 'El identificador (DNI, Usuario o Email) es obligatorio.');
            return;
        }

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        
        // Buscar por usuario o DNI primero (solo activos)
        $user = $userRepo->findByUsuarioODni($identificador);
        if (!$user) {
            // Intentar buscar por correo electrónico (solo activos)
            $user = $userRepo->findActiveByEmail($identificador);
        }

        if (!$user) {
            $this->responder(404, 'error', 'Usuario no registrado o inactivo.');
            return;
        }

        // Generar un código numérico aleatorio de 4 dígitos
        $codigo = (string)mt_rand(1000, 9999);

        // Guardar el código y usuario en la sesión para validarlo en el siguiente paso
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['recovery_code']    = $codigo;
        $_SESSION['recovery_user_id'] = $user->getId();

        // Respondemos con éxito e incluimos el código en la respuesta para facilitar pruebas locales sin correo real.
        $this->responder(200, 'success', 'Código de recuperación generado.', [
            'codigo_simulado' => $codigo,
            'email'           => $user->getEmail()
        ]);
    }

    // =========================================================================
    // POST /index.php?action=recover_reset
    // =========================================================================

    /**
     * Valida el código de 4 dígitos y actualiza la contraseña del usuario.
     */
    public function recoverReset(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $codigo      = trim($data['codigo'] ?? '');
        $newPassword = trim($data['password'] ?? '');

        if (empty($codigo) || empty($newPassword)) {
            $this->responder(400, 'error', 'El código y la nueva contraseña son obligatorios.');
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $codigoGuardado = $_SESSION['recovery_code'] ?? null;
        $userIdGuardado = $_SESSION['recovery_user_id'] ?? null;

        if (!$codigoGuardado || !$userIdGuardado || $codigo !== $codigoGuardado) {
            $this->responder(400, 'error', 'Código de recuperación inválido o expirado.');
            return;
        }

        $user = $this->em->getRepository(User::class)->find($userIdGuardado);
        if (!$user || !$user->isEstado()) {
            $this->responder(404, 'error', 'Usuario no encontrado o inactivo.');
            return;
        }

        // Hashear la nueva contraseña y actualizar
        $user->setPassword(password_hash($newPassword, PASSWORD_BCRYPT));
        $this->em->flush();

        // Limpiar sesión de recuperación
        unset($_SESSION['recovery_code']);
        unset($_SESSION['recovery_user_id']);

        $this->responder(200, 'success', 'Contraseña restablecida correctamente.');
    }

    // =========================================================================
    // Helper privado: Respuesta JSON estandarizada
    // =========================================================================


    /**
     * Emite la respuesta JSON estandarizada y termina la ejecución.
     *
     * @param int    $httpCode Código de estado HTTP (200, 400, 401, 405...)
     * @param string $status   'success' o 'error'
     * @param string $message  Mensaje legible para el Frontend
     * @param array  $data     (Opcional) Datos adicionales que devolver
     */
    private function responder(int $httpCode, string $status, string $message, array $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}