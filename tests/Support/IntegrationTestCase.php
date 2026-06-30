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
 *   - Helpers HTTP: postJson(), getJson(), putJson(), deleteJson() con soporte JWT
 *   - makeRequest() con cURL para suites que apuntan a servidores remotos (ej. Render)
 *
 * Modos de operación
 * ------------------
 * Modo LOCAL (por defecto):
 *   Levanta un servidor PHP embebido en TEST_SERVER_HOST:TEST_SERVER_PORT.
 *   Ideal para CI y desarrollo local.
 *
 * Modo RENDER (nube):
 *   Si la variable de entorno TEST_RENDER_URL está definida, se usa esa
 *   URL como base en lugar del servidor embebido. El servidor embebido
 *   no se inicia. Útil para suites E2E contra staging/producción.
 *
 *   Ejemplo en phpunit.xml:
 *     <env name="TEST_RENDER_URL" value="https://server-qbnm.onrender.com/index.php"/>
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

    /** @var resource|null Proceso del servidor PHP embebido */
    private static $serverProcess = null;

    private static string $baseUrl      = '';
    private static string $serverHost   = '127.0.0.1';
    private static string $serverPort   = '8099';
    private static string $projectRoot  = '';
    private static ?EntityManagerInterface $em = null;
    private static bool   $dbAvailable  = false;
    private static string $skipReason   = 'Causa desconocida.';

    /**
     * true cuando se opera contra un servidor remoto (Render).
     * En ese modo el servidor embebido no se inicia/detiene.
     */
    private static bool $modoRender = false;

    /** @var int[] IDs de usuarios creados en este test, para limpiarlos en tearDown */
    protected array $createdUserIds = [];

    // =====================================================================
    // Hooks de ciclo de vida de PHPUnit
    // =====================================================================

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        self::$projectRoot = $projectRoot;

        // ── Detección de modo Render ──────────────────────────────────────
        $renderUrl = getenv('TEST_RENDER_URL');
        if ($renderUrl !== false && $renderUrl !== '') {
            self::$modoRender = true;
            self::$baseUrl    = rtrim($renderUrl, '/');
        } else {
            self::$modoRender = false;
            $host  = getenv('TEST_SERVER_HOST') ?: '127.0.0.1';
            $port  = getenv('TEST_SERVER_PORT') ?: '8099';
            self::$baseUrl    = "http://{$host}:{$port}";
            self::$serverHost = $host;
            self::$serverPort = $port;
        }

        if (getenv('SKIP_DB_INTEGRATION_TESTS') === '1') {
            self::$dbAvailable = false;
            self::$skipReason  = 'SKIP_DB_INTEGRATION_TESTS=1 está activo.';
            return;
        }

        self::cargarDotEnv($projectRoot);
        self::limpiarRateLimitCache();

        if (!self::$modoRender) {
            self::startBuiltInServer($projectRoot, self::$serverHost, self::$serverPort);
        }

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
        if (!self::$modoRender) {
            self::stopBuiltInServer();
        }

        self::$em          = null;
        self::$dbAvailable = false;
        self::$skipReason  = 'Causa desconocida.';
    }

    protected function setUp(): void
    {
        if (!self::$dbAvailable) {
            $this->markTestSkipped('Test de integración omitido. Razón: ' . self::$skipReason);
        }

        self::limpiarRateLimitCache();

        if (!self::$modoRender) {
            self::verificarYReiniciarServidor();
        }
    }

    protected function tearDown(): void
    {
        if (!self::$dbAvailable || self::$em === null) {
            return;
        }

        // Borra todos los usuarios creados durante el test.
        // Antes de eliminar al usuario, purga las entidades relacionadas
        // para no violar restricciones de clave foránea.
        foreach ($this->createdUserIds as $id) {
            $user = self::$em->getRepository(User::class)->find($id);
            if ($user !== null) {
                self::purgarEntidadesRelacionadas($user);
                self::$em->remove($user);
            }
        }

        if ($this->createdUserIds !== []) {
            self::$em->flush();
        }

        $this->createdUserIds = [];
    }

    // =====================================================================
    // Limpieza de entidades relacionadas (hard delete)
    // =====================================================================

    /**
     * Elimina del EntityManager todos los registros relacionados a un usuario
     * antes de borrarlo, evitando violaciones de FK.
     *
     * Cubre: mensajes (Message) e historial de auditoría (History).
     * Los subclases pueden hacer override para añadir más entidades.
     *
     * No llama flush() — el llamador es responsable de hacerlo.
     */
    protected static function purgarEntidadesRelacionadas(User $user): void
    {
        if (self::$em === null) {
            return;
        }

        // Mensajes del usuario
        $mensajes = self::$em->getRepository(\App\Entity\Message::class)->findBy(['user' => $user]);
        foreach ($mensajes as $mensaje) {
            self::$em->remove($mensaje);
        }

        // Historial de auditoría atado a este usuario como "admin"
        $historiales = self::$em->getRepository(\App\Entity\History::class)->findBy(['admin' => $user]);
        foreach ($historiales as $historial) {
            self::$em->remove($historial);
        }
    }

    /**
     * Hard delete directo por ID: purga entidades relacionadas, elimina el
     * usuario y hace flush. Útil en tearDownAfterClass de subclases cuando
     * el usuario no pasó por $createdUserIds.
     */
    protected static function eliminarUsuarioPorId(int $userId): void
    {
        if (self::$em === null || $userId <= 0) {
            return;
        }

        $user = self::$em->getRepository(User::class)->find($userId);
        if ($user === null) {
            return;
        }

        self::purgarEntidadesRelacionadas($user);
        self::$em->remove($user);
        self::$em->flush();
    }

    // =====================================================================
    // Infraestructura: carga de .env
    // =====================================================================

    /**
     * Parsea el archivo .env manualmente y carga las variables en $_ENV,
     * $_SERVER y putenv(). No depende de vlucas/phpdotenv.
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
     */
    private static function verificarYReiniciarServidor(): void
    {
        $conn = @fsockopen(self::$serverHost, (int) self::$serverPort, $errno, $errstr, 1.0);

        if ($conn !== false) {
            fclose($conn);
            return;
        }

        self::stopBuiltInServer();
        usleep(300_000);
        self::startBuiltInServer(self::$projectRoot, self::$serverHost, self::$serverPort);
    }

    private static function startBuiltInServer(string $projectRoot, string $host, string $port): void
    {
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

    // =====================================================================
    // Helpers HTTP — file_get_contents (servidor local)
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
    // Helper HTTP — cURL (servidor remoto / Render)
    // =====================================================================

    /**
     * Realiza una petición HTTP usando cURL.
     *
     * Necesario para entornos remotos (Render/Aiven) donde:
     *   - SSL_VERIFYPEER puede dar problemas con certificados locales.
     *   - file_get_contents() con wrapper http puede estar deshabilitado.
     *
     * Se usa internamente por makeRequest(); los tests pueden llamarlo
     * directamente si necesitan control fino sobre la URL completa.
     *
     * @param string      $method  GET, POST, PUT, DELETE
     * @param string      $uri     Fragmento de URL a partir de $baseUrl (ej. "?action=message")
     * @param array       $payload Datos a enviar como JSON (ignorado en GET/DELETE)
     * @param string|null $token   JWT para Authorization: Bearer
     *
     * @return array{int, array}  [httpStatusCode, decodedBody]
     */
    protected static function makeRequest(
        string  $method,
        string  $uri,
        array   $payload = [],
        ?string $token   = null,
    ): array {
        // En modo local $baseUrl es "http://host:port" (sin path).
        // En modo Render $baseUrl ya incluye "/index.php" (desde TEST_RENDER_URL).
        // Si $uri empieza con '?' necesitamos el path intermedio para el servidor local;
        // para Render el separador ya está incluido en $baseUrl, así que no se duplica.
        if (str_starts_with($uri, '?') && !str_ends_with(self::$baseUrl, '.php')) {
            $url = self::$baseUrl . '/index.php' . $uri;
        } else {
            $url = self::$baseUrl . $uri;
        }
        $ch  = curl_init($url);

        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer $token";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            static::fail("CRÍTICO: Falló la conexión cURL ($method $uri). Error: $error");
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // El servidor a veces antepone warnings de PHP al JSON —
        // buscamos el primer '{' para descartar basura de texto.
        $jsonStart = strpos($response, '{');
        if ($jsonStart !== false) {
            $response = substr($response, $jsonStart);
        }

        $decoded = json_decode($response, true) ?? [];

        return [$httpCode, $decoded];
    }

    /**
     * Login estático usando makeRequest() (cURL).
     * Útil en setUpBeforeClass() de subclases.
     */
    protected static function loginConCurl(string $usuario, string $password): ?string
    {
        $payload = ['identificador' => $usuario, 'password' => $password];
        [$code, $body] = static::makeRequest('POST', '?action=login', $payload);

        return $code === 200
            ? ($body['data']['token'] ?? $body['token'] ?? null)
            : null;
    }

    // =====================================================================
    // Privados
    // =====================================================================

    /**
     * Extrae el HTTP status code de los headers y decodifica el body JSON.
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