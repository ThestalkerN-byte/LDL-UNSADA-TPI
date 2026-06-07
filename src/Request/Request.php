<?php
declare(strict_types=1);

namespace ICB\Request;

/*
 * REQUEST: Wrapper de la request HTTP entrante
 * =============================================
 * Propósito: encapsular $_SERVER, php://input y headers
 * para que controllers y middleware no dependan de superglobals.
 *
 * Flujo:
 *   index.php crea un Request → lo pasa al Router → Router hace dispatch
 *   → Middleware puede modificarlo (ej: setAttribute('usuario', $user))
 *   → Controller lee body/headers/atributos
 *
 * Decisión técnica:
 *   En lugar de pasar $_POST/$_GET directamente por todo el sistema,
 *   centralizamos el parseo acá. Si después cambiamos de SAPI (ej: RoadRunner,
 *   Swoole), solo cambia esta clase. Los controllers no se enteran.
 *
 *   Atributos: mecanismo para que middleware inyecte datos (usuario, roles,
 *   permisos) sin tener que modificar la firma de los controllers.
 *   El controller hace $request->getAttribute('usuario') y listo.
 */
class Request
{
    private string $method;
    private string $uri;           // URI sin query string
    private array $body;           // JSON o form-data parseado
    private array $query;          // $_GET
    private array $headers;
    private bool $isMultipart;     // Si el content-type es multipart/form-data
    private array $attributes = []; // Para datos inyectados (usuario, roles)

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // URI sin query string
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // ─── Base path ──────────────────────────────────────────────────────
        // Si la app está en un subdirectorio del servidor web (ej: /icb/public/),
        // APP_BASE_PATH permite recortar ese prefijo de la URI para que el Router
        // vea las rutas relativas correctas (/api/auth/login y no /icb/public/api/auth/login).
        //
        // Configuración en .env:
        //   APP_BASE_PATH=/icb/public    → para Apache con document root en /var/www/html
        //   APP_BASE_PATH=               → para PHP built-in server desde public/
        //
        $basePath = $_ENV['APP_BASE_PATH'] ?? '';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        // Sacar trailing slash (pero mantener / para root)
        $this->uri = $uri !== '/' ? rtrim($uri, '/') : '/';

        // Detectar si es multipart/form-data (upload de archivos)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $this->isMultipart = str_starts_with($contentType, 'multipart/form-data');

        // Body: si es multipart PHP ya parseó $_POST automáticamente
        // Si no, leemos el JSON desde php://input
        if ($this->isMultipart) {
            $this->body = $_POST;
        } else {
            $rawBody = file_get_contents('php://input');
            $this->body = $rawBody ? (json_decode($rawBody, true) ?? []) : [];
        }

        // Query params
        $this->query = $_GET;

        // Headers: traducir HTTP_* de $_SERVER a nombres limpios
        $this->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $this->headers[$headerName] = $value;
            }
        }
        // Content-Type especial (no viene con prefijo HTTP_)
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
    }

    /**
     * Obtiene un archivo subido desde $_FILES.
     *
     * @param string $key Nombre del campo file en el formulario
     * @return array|null Datos del archivo (name, type, tmp_name, error, size) o null si no existe
     *
     * Uso:
     *   $archivo = $request->file('imagen');
     *   if ($archivo) { ... }
     */
    public function file(string $key): ?array
    {
        return isset($_FILES[$key]) && $_FILES[$key]['error'] !== UPLOAD_ERR_NO_FILE
            ? $_FILES[$key]
            : null;
    }

    /**
     * Verifica si existe un archivo subido para la clave dada.
     */
    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /*
     * Obtener campo del body, o todo el body si no se pasa key.
     *
     * Uso:
     *   $email = $request->body('email');
     *   $todoElBody = $request->body();
     */
    public function body(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    /*
     * Obtener query param, o todos si no se pasa key.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /*
     * Obtener header por nombre (case-insensitive).
     * Los headers se almacenan en mayúsculas con guiones.
     * Ejemplo: $request->header('Content-Type') → 'application/json'
     */
    public function header(string $name): ?string
    {
        $name = strtoupper(str_replace('_', '-', $name));
        return $this->headers[$name] ?? null;
    }

    /*
     * Extraer token Bearer del header Authorization.
     * Ejemplo: "Authorization: Bearer xxx.yyy.zzz" → "xxx.yyy.zzz"
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('AUTHORIZATION');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /*
     * Atributos: datos inyectados por middleware.
     *
     * Ejemplo en AuthMiddleware:
     *   $request->setAttribute('usuario', $usuario);
     *
     * Luego en el controller:
     *   $usuario = $request->getAttribute('usuario');
     */
    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /*
     * Obtiene la IP del cliente desde REMOTE_ADDR.
     * Esta es la IP real de la conexión TCP.
     * Para IP detrás de proxies, ver RateLimiter::getClientIp().
     */
    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
