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
 * AuthController::login()/logout() llaman a header(), http_response_code()
 * y exit() dentro de su método privado responder(). Invocar exit() dentro
 * del propio proceso de PHPUnit aborta el test runner completo, por lo que
 * NO es seguro instanciar AuthController en memoria.
 *
 * La forma correcta de probar este tipo de controlador es levantar el
 * servidor embebido de PHP (`php -S`) como proceso hijo y pegarle
 * peticiones HTTP reales. Así el exit() solo termina ese proceso hijo.
 *
 * REQUISITO: archivo .env en la raíz del proyecto con las variables:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, JWT_SECRET
 *
 * Si el .env no existe o las credenciales son incorrectas, los tests de
 * esta clase se marcan como "skipped" automáticamente (no fallan en falso).
 *
 * NOTA — LOGIN_005 / Logout con JWT:
 *   El sistema migró de sesiones PHP a JWT stateless. El logout ya NO
 *   invalida una cookie de sesión en el servidor; es el frontend quien
 *   descarta el token. Los tests reflejan ese comportamiento real: el
 *   endpoint responde 200 y le indica al frontend que descarte el token.
 *
 * Mapeo con la planilla QA:
 *   - LOGIN_001 -> testLoginConCredencialesValidasDevuelve200ConToken()
 *   - LOGIN_002 -> testLoginConPasswordIncorrectaDevuelve401()
 *   - LOGIN_003 -> testLoginConCamposVaciosDevuelve400()
 *   - LOGIN_004 -> testLoginConUsuarioInactivoDevuelve401()
 *   - LOGIN_005 -> testLogoutDevuelve200YConfirmaDescartarToken()
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

        // Carga el .env en este proceso (el mismo que usa la app) antes de
        // requerir bootstrap.php. Esto es necesario porque bootstrap.php
        // ahora lee las credenciales desde $_ENV en vez de tenerlas
        // hardcodeadas. El servidor PHP embebido carga su propio .env a
        // través de index.php -> bootstrap.php al arrancar.
        self::cargarDotEnv($projectRoot);

        // Limpia los archivos del RateLimiter antes de correr los tests.
        // Sin esto, si ya hubo 5+ intentos de login desde 127.0.0.1 en los
        // últimos 60 segundos (en una corrida anterior), los tests de login
        // recibirían 429 en vez del código esperado.
        self::limpiarRateLimitCache();

        // Arranca el servidor PHP embebido apuntando a la raíz del proyecto.
        self::startBuiltInServer($projectRoot, $host, $port);

        // Verifica que la conexión a la base de datos sea válida.
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
                'Tests de integración de AuthController omitidos. ' .
                'Causas posibles: (1) no existe el archivo .env en la raíz del proyecto, ' .
                '(2) las credenciales de DB_HOST / DB_USER en .env son incorrectas, ' .
                '(3) no hay conexión de red a la base de datos. ' .
                'Copiá .env.example a .env, completá las variables y volvé a correr.'
            );
        }

        // Limpia el rate limit antes de cada test individual para que los
        // intentos acumulados en tests anteriores no provoquen un 429.
        // El límite es 5 requests por IP cada 60s; sin esto, a partir del
        // 6to test de login todos recibirían 429 en vez del código esperado.
        self::limpiarRateLimitCache();
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

    /**
     * Carga el archivo .env del proyecto en $_ENV y putenv() para que
     * bootstrap.php y JwtService encuentren las variables que necesitan.
     *
     * Parsea el .env manualmente (sin depender de vlucas/phpdotenv) para
     * evitar fallos silenciosos cuando la librería no está en "require"
     * del composer.json sino solo en "scripts" (como ocurre en este proyecto).
     *
     * Reglas de parseo:
     *   - Ignora líneas vacías y comentarios (# ...)
     *   - Soporta CLAVE=valor y CLAVE="valor entre comillas"
     *   - No sobreescribe variables ya definidas en el entorno del sistema
     *     (mismo comportamiento que Dotenv::createImmutable)
     */
    private static function cargarDotEnv(string $projectRoot): void
    {
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return; // bootstrap.php lanzará excepción → catch → skipped
        }

        $lineas = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) {
            return;
        }

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            // Saltar comentarios y líneas vacías
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            // Debe contener '=' para ser una asignación válida
            if (!str_contains($linea, '=')) {
                continue;
            }

            [$clave, $valor] = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor);

            // Quitar comillas dobles o simples envolventes: VAR="valor" → valor
            if (
                (str_starts_with($valor, '"') && str_ends_with($valor, '"')) ||
                (str_starts_with($valor, "'") && str_ends_with($valor, "'"))
            ) {
                $valor = substr($valor, 1, -1);
            }

            // No sobreescribir variables que ya existan en el entorno real
            if (isset($_ENV[$clave]) || getenv($clave) !== false) {
                continue;
            }

            $_ENV[$clave]    = $valor;
            $_SERVER[$clave] = $valor;
            putenv("{$clave}={$valor}");
        }
    }

    /**
     * Elimina el directorio completo de rate limiting para que los tests
     * no reciban 429 por intentos acumulados de corridas anteriores.
     *
     * Por qué no usamos glob(): el RateLimiter genera la key "login:127.0.0.1"
     * y en Windows los dos puntos ":" crean NTFS Alternate Data Streams, por
     * lo que glob() no lista esos archivos. La solución robusta y cross-platform
     * es eliminar el directorio entero con el comando del sistema operativo;
     * el RateLimiter lo vuelve a crear automáticamente en el primer request.
     */
    private static function limpiarRateLimitCache(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ldl-rate-limits';
        if (!is_dir($dir)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('rd /s /q ' . escapeshellarg($dir) . ' 2>nul');
        } else {
            @exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private static function startBuiltInServer(string $projectRoot, string $host, string $port): void
    {
        // -d display_errors=Off evita que los PHP Deprecated/Notice de librerías
        // de vendor se inyecten en el body HTTP corrompiendo el JSON de respuesta.
        $command = "php -d display_errors=Off -S {$host}:{$port} -t " . escapeshellarg($projectRoot);

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
     * en la base de datos, con la contraseña hasheada con bcrypt tal como
     * lo hace el flujo real de la aplicación.
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
     * Devuelve [httpCode, body decodificado, header Authorization recibido].
     */
    private function postJson(string $action, array $payload, ?string $authHeader = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($authHeader !== null) {
            $headers[] = 'Authorization: Bearer ' . $authHeader;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
                'timeout'       => 5,
            ],
        ]);

        $url  = self::$baseUrl . '/index.php?action=' . $action;
        $body = file_get_contents($url, false, $context);

        $httpCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        $decoded = json_decode((string) $body, true);

        return [$httpCode, $decoded];
    }

    // =====================================================================
    // LOGIN_001 - Usuario registrado y activo, credenciales válidas
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_001 - Login con usuario y contraseña válidos devuelve 200 con JWT y datos del usuario')]
    public function testLoginConCredencialesValidasDevuelve200ConToken(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123', rol: 'admin');

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);

        // El sistema migró a JWT: el token debe venir en data.token
        $this->assertArrayHasKey('token', $body['data'],
            'La respuesta de login debe incluir un JWT en data.token');
        $this->assertNotEmpty($body['data']['token'],
            'El JWT no debe ser una cadena vacía');

        // El token JWT tiene 3 partes separadas por puntos (header.payload.firma)
        $this->assertSame(3, substr_count($body['data']['token'], '.') + 1,
            'El JWT debe tener exactamente 3 segmentos separados por "."');

        // También deben venir los datos del usuario
        $this->assertSame($user->getId(), $body['data']['id']);
        $this->assertSame('admin', $body['data']['rol']);
        $this->assertSame($user->getUsuario(), $body['data']['usuario']);
    }

    // =====================================================================
    // LOGIN_002 - Usuario registrado, contraseña incorrecta / inexistente
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
        $this->assertArrayNotHasKey('token', $body['data'] ?? []);
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
    #[TestDox('LOGIN_003 - Login con campos vacíos devuelve 400')]
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
    #[TestDox('LOGIN_004 - Login con usuario inactivo devuelve 401')]
    public function testLoginConUsuarioInactivoDevuelve401(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123', estado: false);

        [$httpCode, $body] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);

        // El controller unifica "no existe" e "inactivo" bajo el mismo 401
        // por diseño defensivo: no revela cuál de las dos condiciones falló.
        $this->assertSame(401, $httpCode);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Credenciales inválidas o usuario inactivo.', $body['message']);
    }

    // =====================================================================
    // LOGIN_005 - Logout (JWT stateless)
    // =====================================================================

    #[Test]
    #[TestDox('LOGIN_005 - Logout responde 200 e indica al frontend que descarte el token JWT')]
    public function testLogoutDevuelve200YConfirmaDescartarToken(): void
    {
        $user = $this->crearUsuarioDePrueba('ClaveSegura123');

        // 1. Login para obtener un JWT válido.
        [$loginCode, $loginBody] = $this->postJson('login', [
            'identificador' => $user->getUsuario(),
            'password'      => 'ClaveSegura123',
        ]);
        $this->assertSame(200, $loginCode);
        $token = $loginBody['data']['token'] ?? null;
        $this->assertNotNull($token, 'El login debería devolver un JWT.');

        // 2. Logout enviando el token como Authorization header.
        //    Con JWT el servidor no tiene estado que destruir; la
        //    responsabilidad de descartar el token es del frontend.
        [$logoutCode, $logoutBody] = $this->postJson('logout', [], $token);

        $this->assertSame(200, $logoutCode);
        $this->assertSame('success', $logoutBody['status']);
        $this->assertSame('Sesión cerrada correctamente.', $logoutBody['message']);
    }

    #[Test]
    #[TestDox('Logout sin token igualmente responde 200 (idempotente, stateless)')]
    public function testLogoutSinTokenEsIdempotente(): void
    {
        // JWT es stateless: el endpoint de logout siempre responde 200
        // porque no hay sesión del lado del servidor que verificar.
        [$httpCode, $body] = $this->postJson('logout', []);

        $this->assertSame(200, $httpCode);
        $this->assertSame('success', $body['status']);
    }
}