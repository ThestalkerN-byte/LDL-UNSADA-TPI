<?php
declare(strict_types=1);
namespace ICB\Controller;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Usuario;
use ICB\Exception\AuthException;
use ICB\Exception\ValidationException;
use ICB\Request\Request;
use ICB\Service\AuthService;
use ICB\Service\CredencialService;
use ICB\Service\RecuperacionService;

/*
 * AUTH CONTROLLER: Endpoints de autenticación
 * =============================================
 * Endpoints:
 *   POST /api/auth/login                 → Iniciar sesión (público)
 *   POST /api/auth/refresh               → Renovar tokens (público)
 *   GET  /api/auth/me                    → Obtener usuario actual (autenticado)
 *   POST /api/auth/recuperar-solicitar   → Solicitar recuperación (público)
 *   POST /api/auth/recuperar-confirmar   → Confirmar recuperación (público)
 *   GET  /api/auth/mi-credencial         → Mi credencial activa (autenticado)
 *
 * Los endpoints públicos (login, refresh, recuperar-*) NO llevan middleware
 * porque justamente son accesibles sin autenticación.
 * Los endpoints /me y /mi-credencial requieren AuthMiddleware::autenticado.
 *
 * Formato de respuesta consistente:
 *   Éxito: { "data": {...}, "message": "..." }
 *   Error: { "error": "...", "code": N }
 *
 * Decisión técnica:
 *   El controlador recibe EntityManager en lugar de los services directamente
 *   porque sigue el patrón de los demás controladores existentes.
 *   Internamente crea AuthService, CredencialService y RecuperacionService.
 */
class AuthController
{
    private EntityManagerInterface $em;
    private AuthService $authService;
    private CredencialService $credencialService;
    private RecuperacionService $recuperacionService;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->authService = new AuthService($em);
        $this->credencialService = new CredencialService($em);
        $this->recuperacionService = new RecuperacionService($em);
    }

    /**
     * POST /api/auth/login
     *
     * Body esperado:
     *   { "usuario": "admin", "password": "..." }
     *   o { "dni": "12345678", "password": "..." }
     *
     * El identificador puede ser nombre de usuario O DNI.
     * AuthService::login() resuelve cuál de los dos es usando
     * UsuarioRepository::findByIdentifier().
     *
     * @return array Respuesta JSON con tokens y datos del usuario
     */
    public function login(Request $request, array $params): array
    {
        $identifier = $request->body('usuario') ?? $request->body('dni');
        $password = $request->body('password');

        if (!$identifier || !$password) {
            return ['error' => 'Usuario/DNI y password son requeridos', 'code' => 400];
        }

        try {
            $resultado = $this->authService->login($identifier, $password);
            return [
                'data' => $resultado,
                'message' => 'Inicio de sesión exitoso',
            ];
        } catch (AuthException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /**
     * POST /api/auth/refresh
     *
     * Body esperado:
     *   { "refresh_token": "..." }
     *
     * Implementa rotación de refresh token (ver AuthService).
     * Si el token fue comprometido, al rotar el dueño legítimo
     * queda invalidado y debe volver a hacer login.
     *
     * @return array Respuesta JSON con nuevos tokens
     */
    public function refresh(Request $request, array $params): array
    {
        $refreshToken = $request->body('refresh_token');

        if (!$refreshToken) {
            return ['error' => 'refresh_token es requerido', 'code' => 400];
        }

        try {
            $resultado = $this->authService->refresh($refreshToken);
            return [
                'data' => $resultado,
                'message' => 'Tokens renovados exitosamente',
            ];
        } catch (AuthException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /**
     * GET /api/auth/me
     *
     * Requiere middleware: AuthMiddleware::autenticado (inyecta el Usuario).
     * Devuelve los datos del usuario autenticado sin exponer datos sensibles
     * (password_hash, refresh_token, tokens de reseteo).
     *
     * @return array Datos del usuario autenticado
     */
    public function me(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');
        return [
            'data'    => $this->serializar($usuario),
            'message' => 'Perfil obtenido correctamente',
        ];
    }

    /*
     * =====================================================================
     * POST /api/auth/recuperar-solicitar
     * =====================================================================
     *
     * Inicia el proceso de recuperación de contraseña.
     * Recibe el email del usuario, genera un token de un solo uso
     * y lo devuelve en la respuesta para que el frontend pueda probarlo.
     *
     * En producción, este token se enviaría por email.
     *
     * Body: { "email": "usuario@ejemplo.com" }
     * =====================================================================
     */
    public function recuperarSolicitar(Request $request, array $params): array
    {
        try {
            $email = $request->body('email');
            $token = $this->recuperacionService->solicitar($email);

            $data = [
                'mensaje' => 'Si el email está registrado, recibirás instrucciones',
            ];

            // En desarrollo (APP_ENV=dev), devolvemos el token para testing.
            // En producción se enviaría por email y NO se incluye en la respuesta.
            if (($_ENV['APP_ENV'] ?? '') === 'dev') {
                $data['token'] = $token;
            }

            return [
                'data' => $data,
                'message' => 'Solicitud de recuperación procesada',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        }
    }

    /*
     * =====================================================================
     * POST /api/auth/recuperar-confirmar
     * =====================================================================
     *
     * Confirma la recuperación de contraseña con el token recibido.
     * Valida el token, verifica que no haya expirado, y actualiza la password.
     *
     * Body: { "token": "...", "password": "nueva-contraseña" }
     * =====================================================================
     */
    public function recuperarConfirmar(Request $request, array $params): array
    {
        try {
            $token = $request->body('token');
            $password = $request->body('password');

            $this->recuperacionService->confirmar($token, $password);

            return [
                'message' => 'Contraseña actualizada exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        }
    }

    /*
     * =====================================================================
     * GET /api/auth/mi-credencial
     * =====================================================================
     *
     * Devuelve la credencial activa del usuario autenticado.
     * Requiere middleware: AuthMiddleware::autenticado.
     *
     * Si el usuario no tiene una credencial activa, devuelve null.
     * =====================================================================
     */
    public function miCredencial(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');

        $credencial = $this->credencialService->obtenerActiva($usuario->getIdUsuario());

        if (!$credencial) {
            return ['data' => null, 'message' => 'No tienes una credencial activa'];
        }

        return [
            'data'    => $credencial,
            'message' => 'Credencial obtenida correctamente',
        ];
    }

    /**
     * Serializa usuario para respuesta JSON.
     * Excluye datos sensibles: password_hash, refresh_token,
     * refresh_token_expira, reset_token, reset_token_expira.
     *
     * Incluye direccion (campo extra para el reverso de la credencial).
     */
    private function serializar(Usuario $usuario): array
    {
        $roles = [];
        foreach ($usuario->getRoles() as $rol) {
            $roles[] = $rol->getNombre();
        }

        return [
            'id'        => $usuario->getIdUsuario(),
            'usuario'   => $usuario->getUsuario(),
            'dni'       => $usuario->getDni(),
            'nombre'    => htmlspecialchars($usuario->getNombre() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'apellido'  => htmlspecialchars($usuario->getApellido() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'email'     => $usuario->getEmail(),
            'telefono'  => htmlspecialchars($usuario->getTelefono() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'direccion' => htmlspecialchars($usuario->getDireccion() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'funcion'   => htmlspecialchars($usuario->getFuncion() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'estado'    => $usuario->getEstado(),
            'roles'     => $roles,
            'fecha_alta' => $usuario->getFechaAlta()->format('Y-m-d H:i:s'),
        ];
    }
}
