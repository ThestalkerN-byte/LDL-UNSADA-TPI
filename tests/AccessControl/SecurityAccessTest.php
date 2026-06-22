<?php
namespace Tests\AccessControl;

use PHPUnit\Framework\TestCase;

class SecurityAccessTest extends TestCase {
    
    // Apunta directamente a la API en Render
    private string $baseUrl = "https://server-fdnh.onrender.com/index.php";

    /**
     * Prueba para SEGURIDAD_002: Intentar acceder a una credencial sin estar autenticado.
     * Resultado esperado: Código HTTP 401.
     */
    public function testAccesoCredencialSinAutenticarRetorna401(): void {
        $url = $this->baseUrl . "?action=credential&id=1";

        $options = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);

        file_get_contents($url, false, $context);
        
        $http_response_header = $http_response_header ?? [];
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0] ?? '', $matches);
        $statusCode = (int)($matches[1] ?? 0);

        $this->assertEquals(
            401, 
            $statusCode, 
            "Fallo (SEGURIDAD_002): Se permitió el acceso a la credencial sin estar autenticado. Estado devuelto: $statusCode."
        );
    }

    /**
     * Prueba para SEGURIDAD_001: Usuario común intentando acceder al panel admin.
     * Resultado esperado: Código HTTP 403.
     */
    public function testUsuarioComunEnPanelAdminRetorna403(): void {
        
        // PASO 1: Iniciar sesión como usuario común para obtener el token real
        $loginUrl = $this->baseUrl . "?action=login";
        $loginData = json_encode([
            'identificador' => 'Sebas12', 
            'password' => '121212'
        ]);

        $loginOptions = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $loginData,
                'ignore_errors' => true
            ]
        ];
        $loginContext = stream_context_create($loginOptions);
        $loginResponse = file_get_contents($loginUrl, false, $loginContext);
        
        $loginJson = json_decode($loginResponse, true);
        $token = $loginJson['data']['token'] ?? null;

        $this->assertNotNull($token, "Error en el test: No se pudo obtener el token en el login de Sebas12.");

        // PASO 2: Intentar acceder al panel de administración con el token obtenido
        $adminUrl = $this->baseUrl . "?action=user";
        
        $adminOptions = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer " . $token . "\r\n",
                'ignore_errors' => true
            ]
        ];
        $adminContext = stream_context_create($adminOptions);
        
        file_get_contents($adminUrl, false, $adminContext);
        
        $http_response_header = $http_response_header ?? [];
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0] ?? '', $matches);
        $statusCode = (int)($matches[1] ?? 0);

        $this->assertEquals(
            403, 
            $statusCode, 
            "Fallo (SEGURIDAD_001): Un usuario común pudo acceder a recursos de administración. Estado devuelto: $statusCode."
        );
    }
}
