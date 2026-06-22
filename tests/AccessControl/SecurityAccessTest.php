
<?php
namespace Tests\AccessControl;

use PHPUnit\Framework\TestCase;

class SecurityAccessTest extends TestCase {
    
    private string $baseUrl = "http://127.0.0.1:8099/index.php";

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

        $response = file_get_contents($url, false, $context);
        
        $http_response_header = $http_response_header ?? [];
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $matches);
        $statusCode = (int)$matches[1];

        // Si el estado es 200, el test descubrió una vulnerabilidad de seguridad grave.
        $this->assertEquals(
            401, 
            $statusCode, 
            "Fallo Crítico (SEGURIDAD_002): Se permitió el acceso a la credencial sin estar autenticado. El servidor devolvió $statusCode."
        );
    }

    /**
     * Prueba para SEGURIDAD_001: Usuario común intentando acceder al panel admin.
     * Resultado esperado: Código HTTP 403.
     */
    public function testUsuarioComunEnPanelAdminRetorna403(): void {
        $url = $this->baseUrl . "?action=user";

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer token_falso_o_usuario_comun\r\n",
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        
        $response = file_get_contents($url, false, $context);
        
        $http_response_header = $http_response_header ?? [];
        preg_match('#HTTP/\d+\.\d+ (\d+)#', $http_response_header[0], $matches);
        $statusCode = (int)$matches[1];

        $this->assertEquals(
            403, 
            $statusCode, 
            "Fallo Crítico (SEGURIDAD_001): Un usuario sin rol de administrador pudo acceder a recursos de gestión. El servidor devolvió $statusCode."
        );
    }
}
