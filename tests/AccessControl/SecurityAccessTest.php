<?php
namespace Tests\AccessControl;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de integración HTTP sobre los endpoints REALES de control de acceso:
 *
 * POST /index.php?action=login
 * GET /index.php?action=user
 * GET /index.php?action=credential&id=...
 *
 * SERVIDOR OBJETIVO: Entorno de producción/staging en la nube (Render).
 * BASE DE DATOS OBJETIVO: Conexión directa a Aiven para limpieza.
 *
 * Por qué integración con cURL en lugar de mocks:
 * -----------------------------------------------------------------------
 * Estas pruebas validan el sistema desde afuera, simulando peticiones HTTP 
 * reales para verificar que las reglas de negocio (autenticación vía JWT 
 * y autorización por roles) se cumplen estrictamente en el servidor.
 * * * NOTA — Manejo de Estado y JWT:
 * Al ser una API stateless basada en JWT, cada petición autenticada requiere
 * la inyección del token en el encabezado HTTP (Authorization: Bearer).
 * * * NOTA — Limpieza Física Híbrida:
 * La limpieza de datos (tearDown) ya no utiliza la API para hacer una baja lógica
 * (que dejaría basura con estado=0). En su lugar, se conecta directamente a la DB
 * a través de Doctrine y ejecuta un Hard Delete del usuario de prueba.
 *
 * Mapeo con la planilla QA (Seguridad):
 * - SEGURIDAD_001 -> testUsuarioComunEnPanelAdminRetorna403()
 * - SEGURIDAD_002 -> testAccesoCredencialSinAutenticarRetorna401()
 * * Requisitos cubiertos: RNF02 (Control de Acceso, Validación de Roles).
 */
class SecurityAccessTest extends TestCase {

    /** @var string URL base del entorno en la nube (Render) */
    private string $baseUrl = "https://server-qbnm.onrender.com/index.php";
    
    /** @var int|null ID del usuario de testing creado temporalmente */
    private ?int $usuarioCreadoId = null;

    /** @var EntityManagerInterface Conexión directa a Aiven para limpieza física */
    private static ?EntityManagerInterface $em = null;

    /**
     * Setup Global: Se ejecuta UNA SOLA VEZ antes de correr los tests de esta clase.
     * Inicia el ORM Doctrine para poder interactuar directamente con la DB en el tearDown.
     */
    public static function setUpBeforeClass(): void {
        $projectRoot = dirname(__DIR__, 2);
        self::$em = require $projectRoot . '/config/bootstrap.php';
    }

    /**
     * Limpieza (TearDown): Se ejecuta automáticamente al finalizar CADA test.
     * Limpieza por "Hard Delete": Interceptamos el registro directamente en 
     * la base de datos de Aiven y lo destruimos para no dejar basura.
     */
    protected function tearDown(): void {
        if ($this->usuarioCreadoId !== null && self::$em !== null) {
            $user = self::$em->getRepository(User::class)->find($this->usuarioCreadoId);
            if ($user) {
                // Borramos huellas de auditoría atadas a este usuario QA para evitar 
                // errores de Foreign Key Constraint en Aiven al intentar eliminarlo.
                $historiales = self::$em->getRepository(\App\Entity\History::class)->findBy(['admin' => $user]);
                foreach ($historiales as $historial) {
                    self::$em->remove($historial);
                }
                
                // Borrado físico real del usuario
                self::$em->remove($user);
                self::$em->flush();
            }
            $this->usuarioCreadoId = null;
        }
    }

    // =====================================================================
    // Helpers de infraestructura y peticiones
    // =====================================================================

    /** * Helper: Autentica a un usuario y extrae su Token JWT.
     * Realiza un POST con el payload. Intercepta y limpia textos "Deprecated"
     * de PHP que puedan corromper el body antes de decodificar el JSON.
     */
    private function login(string $identificador, string $password): ?string {
        $ch = curl_init($this->baseUrl . "?action=login");
        $payload = json_encode(['identificador' => $identificador, 'password' => $password]);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Evita que cURL aborte la conexión en Windows por falta del certificado local
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        if ($response === false) {
            $this->fail("Error de conexión al intentar login hacia Render: " . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        // Limpiamos la respuesta de posibles warnings de PHP para aislar el JSON
        $jsonStart = strpos($response, '{');
        if ($jsonStart !== false) {
            $response = substr($response, $jsonStart);
        }

        $data = json_decode($response, true);
        
        // Soporta variaciones en la estructura de respuesta de la API
        return $data['data']['token'] ?? $data['token'] ?? null;
    }

    /** * Helper: Realiza peticiones GET inyectando el JWT en el encabezado.
     * Retorna únicamente el código de estado HTTP para aserciones de control de acceso.
     */
    private function getStatusCode(string $url, ?string $token = null): int {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = "Authorization: Bearer $token";
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status;
    }

    /**
     * Helper: Crea un usuario con rol "user" directamente a través de la API
     * (iniciando sesión temporalmente como admin) y devuelve su token JWT.
     * Garantiza que las pruebas de roles utilicen cuentas limpias y sin privilegios.
     */
    private function asegurarUsuarioComunDeTesting(): string {
        $adminToken = $this->login('admin', '123456');
        if (!$adminToken) {
            $this->fail("Setup: No se pudo iniciar sesión con el administrador en Render.");
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $createResponse = curl_exec($ch); 
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 201 && $httpCode !== 200) {
            $this->fail("Setup: El servidor de Render rechazó la creación del usuario. Estado HTTP: $httpCode.");
        }

        // Aislamos el JSON para leer el ID y registrarlo para la limpieza física (tearDown)
        $jsonStart = strpos($createResponse, '{');
        if ($jsonStart !== false) {
            $responseData = json_decode(substr($createResponse, $jsonStart), true);
            $this->usuarioCreadoId = $responseData['data']['id'] ?? null;
        }

        // Logueamos al nuevo usuario para retornar su token de prueba
        $qaToken = $this->login($testUser, 'Test1234');
        if (!$qaToken) {
            $this->fail("Setup: El usuario se creó en Render, pero el login posterior falló.");
        }

        return $qaToken;
    }

    // =====================================================================
    // SEGURIDAD_002 - Protección de endpoints contra usuarios anónimos
    // =====================================================================

    #[Test]
    #[TestDox('SEGURIDAD_002 - Acceso a credenciales sin JWT retorna 401 Unauthorized')]
    public function testAccesoCredencialSinAutenticarRetorna401(): void {
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=credential&id=1");
        $this->assertEquals(401, $statusCode,
            "Fallo (SEGURIDAD_002): se permitió acceso a la credencial sin estar autenticado. Estado: $statusCode.");
    }

    // =====================================================================
    // SEGURIDAD_001 - Autorización y Roles (Escalamiento de privilegios)
    // =====================================================================

    #[Test]
    #[TestDox('SEGURIDAD_001 - Usuario con rol común accediendo a endpoints de Admin retorna 403 Forbidden')]
    public function testUsuarioComunEnPanelAdminRetorna403(): void {
        $tokenComun = $this->asegurarUsuarioComunDeTesting();
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $tokenComun);
        $this->assertEquals(403, $statusCode,
            "Fallo Crítico (SEGURIDAD_001): un usuario común accedió al panel admin. Estado: $statusCode.");
    }

    #[Test]
    #[TestDox('Acceso legítimo de un Administrador al panel de usuarios retorna 200 OK')]
    public function testAdminConPermisosAccedeCorrectamente(): void {
        $tokenAdmin = $this->login('admin', '123456');
        $this->assertNotNull($tokenAdmin, "El test requiere credenciales de administrador válidas en Render para arrancar.");
        
        $statusCode = $this->getStatusCode($this->baseUrl . "?action=user", $tokenAdmin);
        $this->assertEquals(200, $statusCode,
            "El admin con credenciales válidas no pudo acceder al panel en Render. Estado: $statusCode.");
    }
}
