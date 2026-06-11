<?php

declare(strict_types=1);

/*
 * =========================================================================
 * SWAGGER UI — Documentación interactiva de la API
 * =========================================================================
 *
 * Protegido por HTTP Basic Auth (SWAGGER_USER / SWAGGER_PASS en .env).
 * =========================================================================
 */

$baseDir = basename(__DIR__) === 'public' ? dirname(__DIR__) : __DIR__;
require_once $baseDir . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable($baseDir);
$dotenv->safeLoad();

define('VALID_USER', $_ENV['SWAGGER_USER'] ?? '');
define('VALID_PASS', $_ENV['SWAGGER_PASS'] ?? '');

function obtenerCredencialesBasicAuth(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
    }

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
    header('WWW-Authenticate: Basic realm="LDL UNSADA API — Documentación"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Autenticación requerida para acceder a la documentación de la API.';
    exit;
}

$specPath = __DIR__ . '/openapi.json';
if (!is_readable($specPath)) {
    http_response_code(500);
    echo 'No se encontró openapi.json. Ejecutá: ./vendor/bin/openapi src/Docs -o public/openapi.json';
    exit;
}

$spec = file_get_contents($specPath);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>LDL UNSADA API — Documentación</title>
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
        var spec = <?php echo $spec; ?>;
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
