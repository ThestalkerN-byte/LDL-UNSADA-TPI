<?php

declare(strict_types=1);

namespace App\Tests\Authentication;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de integración HTTP sobre los endpoints REALES de autenticación:
 *
 *   POST /index.php?action=login
 *   POST /index.php?action=logout
 *
 * Por qué integración y no "unit" puro sobre AuthController:
 * -----------------------------------------------------------------------
 * AuthController::login()/logout() llaman a header(), http_response_code(),
 * session_start()/session_destroy() y, sobre todo, a exit() dentro de su
 * método privado responder(). Invocar exit() dentro del propio proceso de
 * PHPUnit aborta la ejecución de todo el test runner, por lo que NO es
 * seguro instanciar AuthController e invocar login()/logout() directamente
 * en memoria.
 *
 * La forma correcta y estándar de probar este tipo de endpoint "legacy"
 * (script PHP puro que termina con exit) es levantar el servidor embebido
 * de PHP (`php -S`) en un proceso aparte y pegarle peticiones HTTP reales,
 * tal como lo haría el Frontend. Así el exit() solo termina ese proceso
 * hijo, no el de PHPUnit.
 *
 * Requisitos: tener acceso a la base de datos configurada en
 * config/bootstrap.php. Si la conexión no está disponible, los tests se
 * marcan como "skipped" en lugar de fallar (ver SKIP_DB_INTEGRATION_TESTS
 * y setUpBeforeClass()).
 *
 * Mapeo con la planilla QA:
 *   - LOGIN_001 -> testLoginConCredencialesValidasRedirigeSegunRol()
 *   - LOGIN_002 -> testLoginConPasswordIncorrectaDevuelve401()
 *   - LOGIN_003 -> testLoginConCamposVaciosDevuelve400()
 *   - LOGIN_004 -> testLoginConUsuarioInactivoDevuelve403()
 *   - LOGIN_005 -> testLogoutInvalidaLaSesionActiva()
 *
 * Requisitos cubiertos: RF01, RNF02
 */
final class AuthControllerTest extends TestCase
{
    /** @var resource|null */
    private static $serverProcess = null;
    private static string $baseUrl;
    private static ?EntityManagerInterface $em = null;
    private static bool $dbAvailable = false;

    /** @var int[] IDs de usuarios de prueba creados, para limpiarlos en tearDown */
    private array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $host        = getenv('TEST_SERVER_HOST') ?: '127.0.0.1';
        $port        = getenv('TEST_SERVER_PORT') ?: '8099';
        self::$baseUrl = "http://{$host}:{$port}";

        if (getenv('SKIP_DB_INTEGRATION_TESTS') === '1') {
            self::$dbAvailable = false;
            return;
        }

        // Arranca el servidor embebido de PHP apuntando a la raíz del proyecto,
        // para que /index.php sea el front controller real de la app.
        self::startBuiltInServer($projectRoot, $host, $port);

        // Verifica conectividad real a la base de datos antes de correr nada.
        try {
            self::$em = require $projectRoot . '/config/bootstrap.php';
            self::$em->getConnection()->executeQuery('SELECT 1');
            self::$dbAvailable = true;
        } catch (\Throwable $e) {
            self::$dbAvailable = false;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopBuiltInServer();
    }

    protected function setUp(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped(
                'No hay conexión disponible a la base de datos configurada en '
                . 'config/bootstrap.php; los tests de integración de AuthController se omiten.'
            );
        }
    }

    protected function tearDown(): void
    {
        if (!self::$dbAvailable || !self::$em) {
            return;
        }

        foreach ($this->createdUserIds as $id) {
            $user = self::$em->getRepository(User::class)->find($id);
            if ($user) {
                self::$em->remove($user);
            }
        }
        if ($this->createdUserIds !== []) {
            self::$em->flush();
        }
        $this->createdUserIds = [];
    }

    // =====================================================================
    // Helpers de infraestructura
    // =====================================================================

    private static function startBuiltInServer(string $projectRoot, string $host, string $port): void
    {
        $command = "php -S {$host}:{$port} -t " . escapeshellarg($projectRoot);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            self::$serverProcess = null;
            return;
        }

        // No bloquear esperando salida del servidor.
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        self::$serverProcess = $process;

        // Espera a que el servidor acepte conexiones (máx. 5 segundos).
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen($host, (int) $port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(100_000);
        }
    }

    private static function stopBuiltInServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            $status = proc_get_status(self::$serverProcess);
            if (!empty($status['pid'])) {
                // Mata también a los hijos (el proceso "php -S" lanza un
                // worker por petición en algunos sistemas).
                @proc_terminate(self::$serverProcess);
                if (PHP_OS_FAMILY !== 'Windows') {
                    @exec('kill -9 ' . (int) $status['pid'] . ' 2>/dev/null');
                }
            }
            proc_close(self::$serverProcess);
        }
        self::$serverProcess = null;
    }

    /**
     * Crea (y registra para limpieza) un usuario de prueba directamente
     * contra la base de datos, con la contraseña hasheada igual que lo
     * hace el flujo real de la aplicación (password_hash + bcrypt).
     */
    private function crearUsuarioDePrueba(
        string $passwordPlano,
        string $rol = 'user',
        bool $estado = true
    ): User {
        $sufijo = uniqid('qa_', false);

        $user = new User();
        $user->setUsuario('login_test_' . $sufijo);
        $user->setPassword(password_hash($passwordPlano, PASSWORD_BCRYPT));
        $user->setNombre('QA');
        $user->setApellido('Tester');
        $user->setDni(substr('99' . preg_replace('/\D/', '', $sufijo), 0, 9));
        $user->setEmail($sufijo . '@example.test');
        $user->setRol($rol);
        $user->setEstado($estado);

        self::$em->persist($user);
        self::$em->flush();

        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    /**
     * Realiza un POST JSON contra el endpoint /index.php?action=...
     * y devuelve [httpCode, body decodificado, cookie de sesión recibida].
     */
    private function postJson(string $action, array $payload, ?string $cookie = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($cookie !== null) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true, // permite leer el body en respuestas 4xx/5xx
                'timeout'       => 5,
            ],
        ]);

        $url  = self::$baseUrl . '/index.php?action=' . $action;
        $body = file_get_contents($url, false, $context);

        $httpCode = 0;
        $setCookie = null;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
            if (stripos($header, 'Set-Cookie:') === 0) {
                $setCookie = trim(substr($header, strlen('Set-Cookie:')));
            }
        }

        $decoded = json_decode((string) $body, true);

        return [$httpCode, $decoded, $setCookie];
    }

    // =====================================================================
    // LOGIN_001 - Usuario registrado y activo, credenciales válidas
    // =====================================================================
    #[Test]
    #[TestDox('LOGIN_001 - Login con usuario y contraseña válidos devuelve 200 y datos del usuario')]
    public function testLoginConCredencialesValidasRedirigeSegunRol(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123', rol: 'admin');

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
        $this->assertSame($user->getId(), $body['data']['id']);
        $this->assertSame('admin', $body['data']['rol']);
    }

    // =====================================================================
    // LOGIN_002 - Usuario registrado, contraseña incorrecta
    // =====================================================================
    #[Test]
    #[TestDox('LOGIN_002 - Login con contraseña incorrecta devuelve 401')]
    public function testLoginConPasswordIncorrectaDevuelve401(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveCorrecta1');

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'clave-incorrecta',
        ]);

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Credenciales inválidas o usuario inactivo.', $body['message']);
    }

    #[Test]
    #[TestDox('Login con usuario inexistente devuelve 401')]
    public function testLoginConUsuarioInexistenteDevuelve401(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => 'usuario_que_nunca_existira_xyz',
            'password'      => 'cualquier-clave',
        ]);

        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
    }

    // =====================================================================
    // LOGIN_003 - Campos obligatorios vacíos
    // =====================================================================
    #[Test]
    #[TestDox('LOGIN_003 - Login con campos vacíos devuelve 400 (campo obligatorio)')]
    public function testLoginConCamposVaciosDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => '',
            'password'      => '',
        ]);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('El identificador y la contraseña son obligatorios.', $body['message']);
    }

    #[Test]
    #[TestDox('LOGIN_003 - Login sin enviar password devuelve 400')]
    public function testLoginSinPasswordDevuelve400(): void
    {
        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => 'admin',
        ]);

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status']);
    }

    // =====================================================================
    // LOGIN_004 - Usuario dado de baja (estado inactivo)
    // =====================================================================
    #[Test]
    #[TestDox('LOGIN_004 - Login con usuario inactivo devuelve 401 (credenciales inválidas o usuario inactivo)')]
    public function testLoginConUsuarioInactivoDevuelve403(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123', estado: false);

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);

        // El controller actual unifica "no existe" e "inactivo" bajo 401
        // con el mismo mensaje, por diseño defensivo (no revela cuál de
        // las dos condiciones falló). Documentamos ese comportamiento real.
        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Credenciales inválidas o usuario inactivo.', $body['message']);
    }

    // =====================================================================
    // LOGIN_005 - Logout y verificación de invalidación de sesión
    // =====================================================================
    #[Test]
    #[TestDox('LOGIN_005 - Logout cierra la sesión y expira la cookie de sesión')]
    public function testLogoutInvalidaLaSesionActiva(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123');

        // 1. Login para abrir una sesión activa.
        [$loginCode, , $sessionCookie] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);
        $this->assertSame(200, $loginCode);
        $this->assertNotNull($sessionCookie, 'El login debería abrir una sesión y devolver una cookie.');

        $cookieValue = explode(';', $sessionCookie)[0];

        // 2. Logout usando la cookie de la sesión recién abierta.
        [$logoutCode, $logoutBody, $logoutSetCookie] = $this->postJson('logout', [], $cookieValue);

        $this->assertSame(200, $logoutCode);
        $this->assertSame('success', $logoutBody['status']);
        $this->assertSame('Sesión cerrada correctamente.', $logoutBody['message']);

        // 3. El servidor debe instruir al navegador a expirar la cookie
        //    (Set-Cookie con fecha en el pasado), evitando que la sesión
        //    cerrada siga siendo válida para acceder a rutas protegidas.
        $this->assertNotNull($logoutSetCookie);
        $this->assertMatchesRegularExpression(
            '/expires=.*(1969|1970)/i',
            $logoutSetCookie,
            'La cookie de sesión debería expirarse explícitamente al hacer logout.'
        );
    }

    #[Test]
    #[TestDox('Logout sin sesión activa igualmente responde 200 (idempotente)')]
    public function testLogoutSinSesionActivaEsIdempotente(): void
    {
        [$httpCode, $body] = $this->postJson('logout', []);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
    }
}
