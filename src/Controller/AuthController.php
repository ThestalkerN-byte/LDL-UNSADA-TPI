<?php
namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para autenticación de usuarios.
 *
 * Maneja Login, Logout y Recuperación de contraseña.
 * Recibe el EntityManager por constructor (Inyección de Dependencias).
 * Siempre responde con JSON estandarizado: { "status", "message", "data" }.
 */
class AuthController {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
    }

    // =========================================================================
    // POST /index.php?action=login
    // =========================================================================

    /**
     * Autentica al usuario contra la base de datos.
     *
     * Permite identificarse con 'usuario' o 'dni' en el mismo campo.
     * La validación de la contraseña se hace con password_verify() en PHP,
     * NUNCA comparando texto plano ni en el SQL.
     * Al autenticarse, abre sesión segura y guarda id y rol en $_SESSION.
     */
    public function login(): void {

        // 1. Solo acepta POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        // 2. Decodifica el body JSON que envía el Frontend
        $data       = json_decode(file_get_contents('php://input'), true);
        $identificador = trim($data['identificador'] ?? '');
        $password      = trim($data['password'] ?? '');

        // 3. Valida que no vengan vacíos
        if (empty($identificador) || empty($password)) {
            $this->responder(400, 'error', 'El identificador y la contraseña son obligatorios.');
            return;
        }

        // 4. Busca el usuario en la BD por 'usuario' o 'dni' usando el Repositorio
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user     = $userRepo->findByUsuarioODni($identificador);

        // 5. Si no existe o está dado de baja (borrado lógico), rechaza
        if (!$user || !$user->isEstado()) {
            $this->responder(401, 'error', 'Credenciales inválidas o usuario inactivo.');
            return;
        }

        // 6. Valida el hash de la contraseña con password_verify()
        //    La contraseña NUNCA viaja ni se guarda como texto plano.
        if (!password_verify($password, $user->getPassword())) {
            $this->responder(401, 'error', 'Credenciales inválidas o usuario inactivo.');
            return;
        }

        // 7. Abre sesión segura y registra los datos necesarios en $_SESSION
        //    Estos datos son el "carnet digital" interno que usa el resto de los módulos.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true); // Previene Session Fixation

        $_SESSION['id_usuario'] = $user->getId();
        $_SESSION['rol']        = $user->getRol(); // 'admin' o 'user'

        // 8. Responde con los datos que el Frontend necesita para su vista
        $this->responder(200, 'success', 'Login exitoso.', [
            'id'     => $user->getId(),
            'nombre' => $user->getNombre() . ' ' . $user->getApellido(),
            'rol'    => $user->getRol(),
        ]);
    }

    // =========================================================================
    // POST /index.php?action=logout
    // =========================================================================

    /**
     * Cierra la sesión del usuario activo.
     *
     * Limpia el array $_SESSION, destruye la sesión en el servidor
     * e invalida la cookie de sesión en el cliente.
     */
    public function logout(): void {

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder(405, 'error', 'Método no permitido. Use POST.');
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Limpia todos los datos de sesión del servidor
        $_SESSION = [];

        // Invalida la cookie de sesión en el navegador del cliente
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        // Destruye la sesión en el servidor
        session_destroy();

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

        $data = json_decode(file_get_contents('php://input'), true);
        $identificador = trim($data['identificador'] ?? '');

        if (empty($identificador)) {
            $this->responder(400, 'error', 'El identificador (DNI, Usuario o Email) es obligatorio.');
            return;
        }

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        
        // Buscar por usuario o DNI primero
        $user = $userRepo->findByUsuarioODni($identificador);
        if (!$user) {
            // Intentar buscar por correo electrónico
            $user = $userRepo->findOneBy(['email' => $identificador]);
        }

        if (!$user || !$user->isEstado()) {
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