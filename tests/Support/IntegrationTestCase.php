<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Clase base para todos los tests de integración HTTP.
 *
 * Centraliza la infraestructura compartida que antes estaba duplicada
 * en cada clase de test:
 *
 *   - Carga del archivo .env (sin depender de vlucas/phpdotenv)
 *   - Arranque y apagado del servidor PHP embebido (php -S)
 *   - Conexión al EntityManager de Doctrine
 *   - Limpieza del rate limiter entre tests
 *   - Creación y limpieza automática de usuarios de prueba
 *   - Helpers HTTP: postJson() y getJson() con soporte JWT
 *
 * Cómo usarla:
 *   Extender esta clase en lugar de TestCase.
 *   Los tests de integración heredan toda la infraestructura
 *   y solo necesitan escribir los métodos de test.
 */
abstract class IntegrationTestCase extends TestCase
{
    // ── Propiedades estáticas ─────────────────────────────────────────────
    // Son estáticas porque setUpBeforeClass/tearDownAfterClass son estáticos.
    // Como todos los subclases comparten estas propiedades (PHP no hace
    // late-static binding en propiedades), funcionan correctamente mientras
    // PHPUnit ejecute las clases de forma secuencial (comportamiento por defecto).

    /** @var resource|null Proceso del servidor PHP embebido */
    private static $serverProcess = null;

    private static string $baseUrl      = '';
    private static string $serverHost   = '127.0.0.1';
    private static string $serverPort   = '8099';
    private static string $projectRoot  = '';
    private static ?EntityManagerInterface $em = null;
    private static bool   $dbAvailable  = false;
    private static string $skipReason   = 'Causa desconocida.';

    /** @var int[] IDs de usuarios creados en este test, para limpiarlos en tearDown */
    protected array $createdUserIds = [];

    // =====================================================================
    // Hooks de ciclo de vida de PHPUnit
    // =====================================================================

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $host        = getenv('TEST_SERVER_HOST') ?: '127.0.0.1';
        $port        = getenv('TEST_SERVER_PORT') ?: '8099';
        self::$baseUrl    = "http://{$host}:{$port}";
        self::$serverHost = $host;
        self::$serverPort = $port;
        self::$projectRoot = $projectRoot;

        if (getenv('SKIP_DB_INTEGRATION_TESTS') === '1') {
            self::$dbAvailable = false;
            self::$skipReason  = 'SKIP_DB_INTEGRATION_TESTS=1 está activo.';
            return;
        }

        self::cargarDotEnv($projectRoot);
        self::limpiarRateLimitCache();
        self::startBuiltInServer($projectRoot, $host, $port);

        try {
            self::$em = require $projectRoot . '/config/bootstrap.php';
            self::$em->getConnection()->executeQuery('SELECT 1');
            self::$dbAvailable = true;
        } catch (\Throwable $e) {
            self::$dbAvailable = false;
            self::$skipReason  = get_class($e) . ': ' . $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopBuiltInServer();
        self::$em          = null;
        self::$dbAvailable = false;
        self::$skipReason  = 'Causa desconocida.';
    }

    protected function setUp(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('Test de integración omitido. Razón: ' . self::$skipReason);
        }

        // Limpia el rate limit antes de cada test para evitar acumulación
        // de intentos entre tests y recibir 429 en vez del código esperado.
        self::limpiarRateLimitCache();

        // Health check: verifica que el servidor embebido sigue respondiendo.
        // El servidor php -S puede caerse después de muchas conexiones SSL
        // a Aiven (cada request abre una conexión nueva a la BD remota).
        // Si no responde, lo reinicia automáticamente antes de continuar.
        self::verificarYReiniciarServidor();
    }

    protected function tearDown(): void
    {
        if (!self::$dbAvailable || self::$em === null) {
            return;
        }

        // Borra todos los usuarios creados durante el test
        foreach ($this->createdUserIds as $id) {
            $user = self::$em->getRepository(User::class)->find($id);
            if ($user !== null) {
                self::$em->remove($user);
            }
        }

        if ($this->createdUserIds !== []) {
            self::$em->flush();
        }

        $this->createdUserIds = [];
    }

    // =====================================================================
    // Infraestructura: carga de .env
    // =====================================================================

    /**
     * Parsea el archivo .env manualmente y carga las variables en $_ENV,
     * $_SERVER y putenv(). No depende de vlucas/phpdotenv (que en este
     * proyecto está mal ubicado en "scripts" en vez de "require").
     *
     * Reglas:
     *   - Ignora líneas vacías y comentarios (# ...)
     *   - Soporta CLAVE=valor y CLAVE="valor entre comillas"
     *   - No sobreescribe variables ya definidas en el entorno del sistema
     */
    private static function cargarDotEnv(string $projectRoot): void
    {
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lineas = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) {
            return;
        }

        foreach ($lineas as $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            if (!str_contains($linea, '=')) {
                continue;
            }

            [$clave, $valor] = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor);

            // Quitar comillas envolventes: VAR="valor con espacios" → valor con espacios
            if (
                (str_starts_with($valor, '"') && str_ends_with($valor, '"')) ||
                (str_starts_with($valor, "'") && str_ends_with($valor, "'"))
            ) {
                $valor = substr($valor, 1, -1);
            }

            if (isset($_ENV[$clave]) || getenv($clave) !== false) {
                continue;
            }

            $_ENV[$clave]    = $valor;
            $_SERVER[$clave] = $valor;
            putenv("{$clave}={$valor}");
        }
    }

    // =====================================================================
    // Infraestructura: servidor PHP embebido
    // =====================================================================

    /**
     * Verifica que el servidor embebido sigue respondiendo y lo reinicia
     * si se cayó. El servidor php -S puede morir después de muchas
     * conexiones a la base de datos remota (Aiven con SSL).
     *
     * Intenta conectar al puerto; si falla, mata el proceso anterior
     * y arranca uno nuevo antes de que el test continúe.
     */
    private static function verificarYReiniciarServidor(): void
    {
        $conn = @fsockopen(self::$serverHost, (int) self::$serverPort, $errno, $errstr, 1.0);

        if ($conn !== false) {
            fclose($conn);
            return; // El servidor responde — todo bien
        }

        // El servidor no responde: lo reinicia
        self::stopBuiltInServer();
        usleep(300_000); // 300 ms para liberar el puerto
        self::startBuiltInServer(self::$projectRoot, self::$serverHost, self::$serverPort);
    }

    private static function startBuiltInServer(string $projectRoot, string $host, string $port): void
    {
        // -d display_errors=Off: evita que los PHP Deprecated/Notice de
        // librerías de vendor se inyecten en el body HTTP corrompiendo el JSON.
        $command = "php -d display_errors=Off -S {$host}:{$port} -t " . escapeshellarg($projectRoot);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            return;
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        self::$serverProcess = $process;

        // Espera a que el servidor acepte conexiones (máx. 5 segundos)
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

    // =====================================================================
    // Infraestructura: rate limiter
    // =====================================================================

    /**
     * Elimina el directorio de rate limiting antes de cada test.
     *
     * Usa el comando del SO en vez de glob() porque en Windows los archivos
     * del rate limiter tienen ":" en el nombre (NTFS Alternate Data Streams)
     * y glob() no los lista correctamente.
     */
    protected static function limpiarRateLimitCache(): void
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

    // =====================================================================
    // Helper: creación de usuarios de prueba
    // =====================================================================

    /**
     * Crea un usuario de prueba directamente en la base de datos (sin pasar
     * por la API) y lo registra para limpieza automática en tearDown().
     *
     * La contraseña se guarda hasheada con bcrypt, igual que lo hace el
     * flujo real de la aplicación.
     *
     * @param string      $passwordPlano  Contraseña en texto plano
     * @param string      $rol            'user' o 'admin'
     * @param bool        $estado         true = activo, false = inactivo
     * @param string|null $apellido       Apellido exacto (si null, genera uno único)
     * @param string|null $dni            DNI exacto (si null, genera uno único)
     */
    protected function crearUsuarioDePrueba(
        string  $passwordPlano = 'ClaveSegura123!',
        string  $rol           = 'user',
        bool    $estado        = true,
        ?string $apellido      = null,
        ?string $dni           = null,
    ): User {
        $sufijo = uniqid('qa_', false);

        $user = new User();
        $user->setUsuario('test_' . $sufijo);
        $user->setPassword(password_hash($passwordPlano, PASSWORD_BCRYPT));
        $user->setNombre('QA');
        $user->setApellido($apellido ?? ('Tester_' . $sufijo));
        $user->setDni($dni ?? substr('99' . preg_replace('/\D/', '', $sufijo), 0, 9));
        $user->setEmail($sufijo . '@example.test');
        $user->setRol($rol);
        $user->setEstado($estado);

        self::$em->persist($user);
        self::$em->flush();

        $this->createdUserIds[] = $user->getId();

        return $user;
    }


    /**
     * Expone el EntityManager a las subclases que necesitan limpiar
     * entidades relacionadas antes del borrado de User (p.ej. History).
     *
     * Devuelve null si la BD no está disponible o aún no se inicializó.
     */
    protected static function getEntityManager(): ?EntityManagerInterface
    {
        return self::$em;
    }

    // =====================================================================
    // Helpers HTTP
    // =====================================================================

    /**
     * POST JSON → [httpCode, body decodificado]
     *
     * @param string      $action  Valor del query param ?action=
     * @param array       $payload Datos a enviar como JSON
     * @param string|null $token   JWT para el header Authorization: Bearer
     */
    protected function postJson(string $action, array $payload, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ]);

        $url  = self::$baseUrl . '/index.php?action=' . $action;
        $body = file_get_contents($url, false, $context);

        return $this->parsearRespuesta($body, $http_response_header ?? []);
    }

    /**
     * GET con query params y JWT opcionales → [httpCode, body decodificado]
     *
     * @param string      $action       Valor del query param ?action=
     * @param array       $queryParams  Parámetros adicionales de la URL
     * @param string|null $token        JWT para el header Authorization: Bearer
     */
    protected function getJson(string $action, array $queryParams = [], ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $query = $queryParams !== [] ? '&' . http_build_query($queryParams) : '';
        $url   = self::$baseUrl . '/index.php?action=' . $action . $query;

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ]);

        $body = file_get_contents($url, false, $context);

        return $this->parsearRespuesta($body, $http_response_header ?? []);
    }

    /**
     * Helper de login: autentica un usuario y devuelve su JWT.
     * Si el login falla, llama a $this->fail() y detiene el test.
     */
    protected function login(string $identificador, string $password): string
    {
        [$code, $body] = $this->postJson('login', [
            'identificador' => $identificador,
            'password'      => $password,
        ]);

        if ($code !== 200 || empty($body['data']['token'])) {
            $this->fail(
                "No se pudo obtener JWT para '{$identificador}'. " .
                "HTTP {$code}: " . ($body['message'] ?? 'sin mensaje')
            );
        }

        return $body['data']['token'];
    }

    /**
     * PUT JSON con ID en query string → [httpCode, body decodificado]
     *
     * @param string      $action  Valor del query param ?action=
     * @param int         $id      ID del recurso a actualizar (&id={id})
     * @param array       $payload Datos a enviar como JSON
     * @param string|null $token   JWT para el header Authorization: Bearer
     */
    protected function putJson(string $action, int $id, array $payload, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $url     = self::$baseUrl . '/index.php?action=' . $action . '&id=' . $id;
        $context = stream_context_create([
            'http' => [
                'method'        => 'PUT',
                'header'        => implode("\r\n", $headers),
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ]);

        $body = file_get_contents($url, false, $context);

        return $this->parsearRespuesta($body, $http_response_header ?? []);
    }

    /**
     * DELETE con ID en query string → [httpCode, body decodificado]
     *
     * @param string      $action Valor del query param ?action=
     * @param int         $id     ID del recurso a eliminar (&id={id})
     * @param string|null $token  JWT para el header Authorization: Bearer
     */
    protected function deleteJson(string $action, int $id, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $url     = self::$baseUrl . '/index.php?action=' . $action . '&id=' . $id;
        $context = stream_context_create([
            'http' => [
                'method'        => 'DELETE',
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ]);

        $body = file_get_contents($url, false, $context);

        return $this->parsearRespuesta($body, $http_response_header ?? []);
    }

    // =====================================================================
    // Privados
    // =====================================================================

    /**
     * Extrae el HTTP status code de los headers y decodifica el body JSON.
     * Recibe $http_response_header como parámetro porque esa variable mágica
     * solo existe en el scope donde se llamó file_get_contents().
     */
    private function parsearRespuesta(string|false $body, array $responseHeaders): array
    {
        $httpCode = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        return [$httpCode, json_decode((string) $body, true)];
    }
}