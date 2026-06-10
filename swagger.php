<?php
// swagger.php
require_once __DIR__ . '/vendor/autoload.php';

// Si el frontend de Swagger pide el JSON de especificación, lo generamos al vuelo.
// Esto lee automáticamente los atributos de la clase OpenApiSpec.
if (isset($_GET['json'])) {
    $openapi = \OpenApi\Generator::scan([__DIR__ . '/src/Docs']);
    header('Content-Type: application/json; charset=utf-8');
    echo $openapi->toJson();
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Swagger UI - LDL TPI</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
  <style>
      body { margin: 0; padding: 0; background-color: #fafafa; }
  </style>
</head>
<body>
<div id="swagger-ui"></div>

<script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
<script>
  window.onload = () => {
    window.ui = SwaggerUIBundle({
      url: 'swagger.php?json=1', // Le pide el JSON generado a este mismo archivo
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIBundle.SwaggerUIStandalonePreset
      ],
      layout: "BaseLayout"
    });
  };
</script>
</body>
</html>
