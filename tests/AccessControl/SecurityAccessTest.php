<?php
namespace Tests\AccessControl;

use PHPUnit\Framework\TestCase;

class SecurityAccessTest extends TestCase {

    private string $baseUrl = "http://127.0.0.1:8000/index.php";
    private ?int $usuarioCreadoId = null;

    protected function tearDown(): void {
        if ($this->usuarioCreadoId !== null) {
            $adminToken = $this->login('admin', '123456');
            if ($adminToken) {
                $ch = curl_init($this->baseUrl . "?action=user&id=" . $this->usuarioCreadoId);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                // Usamos el token JWT en lugar de cookies
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $adminToken"]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            }
            $this->usuarioCreadoId = null;
        }
    }

    /** Hace login, aísla el JSON y extrae el Token JWT. */
    private function login(string $identificador, string $password): ?string {
        $ch = curl_init($this->baseUrl . "?action=login");
        $payload = json_encode(['identificador' => $identificador, 'password' => $password]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        if ($response === false) {
            $this->fail("Error de conexión al intentar login: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        // Limpiamos la respuesta de posibles textos "Deprecated" de PHP para quedarnos solo con el JSON
        $jsonStart = strpos($response, '{');
        if ($jsonStart !== false) {
            $response = substr($response, $jsonStart);
        }

        $data = json_decode($response, true);
        
        // Dependiendo cómo lo envíe tu API, sacamos el token
        return $data['data']['token'] ?? $data['token'] ?? null;
    }

    /** Realiza peticiones inyectando el token JWT en el encabezado. */
    private function getStatusCode(string $url, ?string $token = null): int {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = "Authorization: Bearer $token";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status;
    }

    private function asegurarUsuarioComunDeTesting(): string {
        $adminToken = $this->login('admin', '123456');
        if (!$adminToken) {
            $this->fail("Setup: No se pudo iniciar sesión con el administrador.");
        }

        $random   = mt_rand(1000, 9999);
        $testUser = 'userQA_' . $random;
        $payload = json_encode([
            'dni'      => '99' . $random . mt_rand(10, 99),
            'usuario'  => $testUser,
            'nombre'   => 'Usuario',
            'apellido' => 'Testing',
            'email'    => 'qa' . $random . '@test.com',
            'password' => 'Test1234',
            'rol'      => 'user',
            'estado'   => 1
        ]);

        $ch = curl_init($this->baseUrl . "?action=user");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer $adminToken"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $createResponse = curl_exec($ch); 
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 && $httpCode !== 200) {
            $this->fail("Setup: El servidor rechazó la creación del usuario. Estado HTTP: $httpCode. Respuesta: $createResponse");
        }

        // Limpiamos advertencias Deprecated para leer el ID
        $jsonStart = strpos($createResponse, '{');
        if ($jsonStart !== false) {
            $responseData = json_decode(substr($createResponse, $jsonStart), true);
            $this->usuarioCreadoId = $responseData['data']['id'] ?? null;
        }

        $qaToken = $this->login($testUser, 'Test1234');
        if (!$qaToken) {
            $this->fail("Setup: El usuario se creó, pero el login posterior falló.");
        }

        return $qaToken;
    }

    public function testAccesoCredencialSinAutenticarRetorna401(): void {
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=credential&id=1");
        $this->assertEquals(401, $statusCode,
            "Fallo (SEGURIDAD_002): se permitió acceso a la credencial sin estar autenticado. Estado: $statusCode.");
    }

    public function testUsuarioComunEnPanelAdminRetorna403(): void {
        $tokenComun = $this->asegurarUsuarioComunDeTesting();
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $tokenComun);
        $this->assertEquals(403, $statusCode,
            "Fallo Crítico (SEGURIDAD_001): un usuario común accedió al panel admin. Estado: $statusCode.");
    }

    public function testAdminConPermisosAccedeCorrectamente(): void {
        $tokenAdmin = $this->login('admin', '123456');
        $this->assertNotNull($tokenAdmin, "El test requiere credenciales de administrador válidas para arrancar.");
        
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $tokenAdmin);
        $this->assertEquals(200, $statusCode,
            "El admin con credenciales válidas no pudo acceder al panel. Estado: $statusCode.");
    }

    public function testAccesoConSesionInvalidadaRetorna401(): void {
        $tokenInvalido = $this->login('admin', '123456');
        $this->assertNotNull($tokenInvalido, "Setup: No se pudo iniciar sesión para probar la invalidación.");

        // Destruimos la sesión en el servidor
        $ch = curl_init($this->baseUrl . "?action=logout");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenInvalido"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);

        $statusCode = $this->getStatusCode($this->baseUrl . "?action=credential&id=1", $tokenInvalido);
        $this->assertEquals(401, $statusCode,
            "Fallo (SEGURIDAD_003): se permitió el acceso usando un token que ya había sido cerrado (logout). Estado: $statusCode.");
    }
}
