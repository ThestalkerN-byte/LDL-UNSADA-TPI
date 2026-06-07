<?php

declare(strict_types=1);

/*
 * =========================================================================
 * SWAGGER UI — Documentación interactiva de la API
 * =========================================================================
 *
 * Archivo protegido por HTTP Basic Auth. Las credenciales se leen desde
 * las variables de entorno SWAGGER_USER y SWAGGER_PASS (definidas en .env).
 *
 * Funciona en:
 *   - Apache/Nginx  → usa $_SERVER['PHP_AUTH_USER']
 *   - PHP built-in  → usa $_SERVER['HTTP_AUTHORIZATION'] (header manual)
 *
 * Si las credenciales son incorrectas o faltan, devuelve 401 Unauthorized
 * con el header WWW-Authenticate para que el navegador muestre el popup.
 * =========================================================================
 */

// ─── Cargar .env (misma lógica que index.php) ──────────────────────────────
$baseDir = basename(__DIR__) === 'public' ? dirname(__DIR__) : __DIR__;
require_once $baseDir . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable($baseDir);
$dotenv->safeLoad();

// ─── Credenciales de acceso (desde .env) ───────────────────────────────────
define('VALID_USER', $_ENV['SWAGGER_USER'] ?? '');
define('VALID_PASS', $_ENV['SWAGGER_PASS'] ?? '');

// ─── Verificar autenticación ───────────────────────────────────────────────
function obtenerCredencialesBasicAuth(): array
{
    // Apache / Nginx con PHP-FPM: setean PHP_AUTH_USER automáticamente
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
    }

    // PHP built-in server: hay que parsear el header manualmente
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (str_starts_with($authHeader, 'Basic ')) {
        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            $parts = explode(':', $decoded, 2);
            return [$parts[0], $parts[1]];
        }
    }

    return [null, null];
}

[$user, $pass] = obtenerCredencialesBasicAuth();

if ($user !== VALID_USER || $pass !== VALID_PASS) {
    header('WWW-Authenticate: Basic realm="ICB API — Documentación"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Autenticación requerida para acceder a la documentación de la API.';
    exit;
}

// ─── HTML de Swagger UI ────────────────────────────────────────────────────
// A partir de acá, el usuario está autenticado y se renderiza la UI
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ICB API — Documentación</title>
    <!-- Swagger UI desde CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html { box-sizing: border-box; }
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        var spec = <?php echo file_get_contents(__DIR__ . '/openapi.json'); ?>;
        SwaggerUIBundle({
            spec: spec,
            dom_id: '#swagger-ui',
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIBundle.SwaggerUIStandalonePreset,
            ],
        });
    </script>
</body>
</html>
