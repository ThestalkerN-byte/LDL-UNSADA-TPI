<?php
namespace Tests\AccessControl;

use PHPUnit\Framework\TestCase;

class SecurityAccessTest extends TestCase {

    private string $baseUrl = "http://127.0.0.1:8000/index.php";
    
    // Guardaremos el ID del usuario creado temporalmente para borrarlo al final
    private ?int $usuarioCreadoId = null;

    /**
     * Se ejecuta automáticamente después de CADA test.
     * Si el test creó un usuario, lo da de baja lógica para mantener limpia la BD.
     */
    protected function tearDown(): void {
        if ($this->usuarioCreadoId !== null) {
            $adminCookie = $this->login('admin', '123456');
            if ($adminCookie) {
                $ch = curl_init($this->baseUrl . "?action=user&id=" . $this->usuarioCreadoId);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_COOKIEFILE, $adminCookie);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            }
            $this->usuarioCreadoId = null; // Reseteamos para el próximo test
        }
    }

    /**
     * Hace login enviando un JSON y devuelve la ruta a un cookie jar con la sesión activa. 
     */
    private function login(string $identificador, string $password): ?string {
        $cookieJar = tempnam(sys_get_temp_dir(), 'cookies_');
        $ch = curl_init($this->baseUrl . "?action=login");

        $payload = json_encode([
            'identificador' => $identificador,
            'password'      => $password
        ]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->fail("Error de conexión al intentar login: $error. ¿Está el servidor local corriendo?");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return $cookieJar;
    }

    /**
     * Realiza peticiones adjuntando la cookie de sesión si existe. 
     */
    private function getStatusCode(string $url, ?string $cookieJar = null): int {
        $ch = curl_init($url);
        if ($cookieJar) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        
        if ($response === false || curl_getinfo($ch, CURLINFO_HTTP_CODE) === 0) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->fail("Error de conexión: No se pudo contactar al servidor ($error). Verifique php -S 127.0.0.1:8000.");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status;
    }

    /**
     * Crea un usuario común temporal de testing y devuelve su sesión.
     */
    private function asegurarUsuarioComunDeTesting(): string {
        $adminCookie = $this->login('admin', '123456');
        if (!$adminCookie) {
            $this->fail("Setup: No se pudo iniciar sesión con el administrador.");
        }

        $random   = mt_rand(1000, 9999);
        $testDni  = '99' . $random . mt_rand(10, 99);
        $testUser = 'userQA_' . $random;
        $testEmail= 'qa' . $random . '@test.com';

        $ch = curl_init($this->baseUrl . "?action=user");
        $payload = json_encode([
            'dni'      => $testDni,
            'usuario'  => $testUser,
            'nombre'   => 'Usuario',
            'apellido' => 'Testing',
            'email'    => $testEmail,
            'password' => 'test1234',
            'rol'      => 'user',
            'estado'   => 1
        ]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $adminCookie);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $createResponse = curl_exec($ch); 
        
        if ($createResponse === false) {
            $this->fail("Setup: Error de conexión al intentar crear el usuario: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 && $httpCode !== 200) {
            $this->fail("Setup: El servidor rechazó la creación del usuario. Estado HTTP: $httpCode. Respuesta: $createResponse");
        }

        $responseData = json_decode($createResponse, true);
        $this->usuarioCreadoId = $responseData['data']['id'] ?? null;

        $qaCookie = $this->login($testUser, 'test1234');
        if (!$qaCookie) {
            $this->fail("Setup: El usuario se creó (ID: {$this->usuarioCreadoId}), pero el login posterior falló.");
        }

        return $qaCookie;
    }

    public function testAccesoCredencialSinAutenticarRetorna401(): void {
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=credential&id=1");

        $this->assertEquals(401, $statusCode,
            "Fallo (SEGURIDAD_002): se permitió acceso a la credencial sin estar autenticado. Estado: $statusCode.");
    }

    public function testUsuarioComunEnPanelAdminRetorna403(): void {
        $cookieComun = $this->asegurarUsuarioComunDeTesting();
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $cookieComun);

        $this->assertEquals(403, $statusCode,
            "Fallo Crítico (SEGURIDAD_001): un usuario común con sesión real accedió al panel admin. Estado: $statusCode.");
    }

    public function testAdminConPermisosAccedeCorrectamente(): void {
        $cookieAdmin = $this->login('admin', '123456');
        $this->assertNotNull($cookieAdmin, "El test requiere credenciales de administrador válidas para arrancar.");
        
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $cookieAdmin);

        $this->assertEquals(200, $statusCode,
            "El admin con credenciales válidas no pudo acceder al panel. Estado: $statusCode.");
    }
}
