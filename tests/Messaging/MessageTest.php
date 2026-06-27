<?php

declare(strict_types=1);

namespace Tests\Messaging;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de Integración (API E2E) - Módulo de Consultas y Mensajes
 *
 * SERVIDOR OBJETIVO: Entorno de producción/staging en la nube (Render).
 * BASE DE DATOS OBJETIVO: Conexión directa a Aiven para limpieza.
 *
 * Estrategia de Pruebas (Stateful Testing Híbrido):
 * -----------------------------------------------------------------------
 * 1. Preparación (HTTP): Se crea un único usuario QA temporal vía API en Render.
 * 2. Ejecución (HTTP): Ese usuario transita por todos los estados de los mensajes.
 * 3. Limpieza (Doctrine): Al finalizar, el script se conecta directo a Aiven 
 * saltándose la API, y ejecuta un "Hard Delete" (Borrado Físico) del 
 * usuario y sus mensajes para no dejar registros basura (estado = 0).
 *
 * Mapeo con Planilla QA:
 * - CONSULTA_002 -> testUsuarioNoPuedeEnviarMensajeVacio()
 * - CONSULTA_001 -> testUsuarioPuedeEnviarConsultaValida()
 * - CONSULTA_003 -> testAdminPuedeVerConsultaEnBandeja()
 * - CONSULTA_004 -> testAdminPuedeResponderConsulta()
 * - CONSULTA_005 -> testUsuarioPuedeVerRespuestaDelAdmin()
 * - PRUEBA_QA  -> testUsuarioComunNoPuedeResponderConsultas()
 */
class MessageTest extends TestCase {

    /** @var string URL base del entorno en la nube (Render) */
    private static string $baseUrl = "https://server-qbnm.onrender.com/index.php";

    // --- Variables de Estado Compartido ---
    private static ?string $adminToken = null;
    private static ?string $qaUserToken = null;
    private static ?int $qaUserId = null;
    private static ?int $mensajeId = null;

    /** @var EntityManagerInterface Conexión directa a Aiven para limpieza física */
    private static ?EntityManagerInterface $em = null;

    /**
     * Setup Global: Se ejecuta UNA SOLA VEZ antes de correr el primer test.
     * Inicia sesión como Admin, crea el usuario de QA y arranca Doctrine.
     */
    public static function setUpBeforeClass(): void {
        // 1. Iniciar el ORM Doctrine (Conexión a Aiven) usando la config del proyecto
        $projectRoot = dirname(__DIR__, 2);
        self::$em = require $projectRoot . '/config/bootstrap.php';

        // 2. Obtener token de administrador legítimo vía API (Render)
        self::$adminToken = self::login('admin', '123456');
        if (!self::$adminToken) {
            self::fail("CRÍTICO: No se pudo iniciar sesión como administrador en Render.");
        }

        // 3. Crear un usuario de QA descartable para esta suite
        $random = mt_rand(1000, 9999);
        $usuarioQA = 'qa_msg_' . $random;
        $payload = [
            'dni'      => '88' . $random . mt_rand(10, 99),
            'usuario'  => $usuarioQA,
            'nombre'   => 'QA',
            'apellido' => 'Mensajeria',
            'email'    => 'qamsg' . $random . '@test.com',
            'password' => 'Test1234',
            'rol'      => 'user'
        ];

        [$code, $response] = self::makeRequest('POST', '?action=user', $payload, self::$adminToken);
        if ($code !== 201) {
            self::fail("CRÍTICO: Render rechazó la creación del usuario de QA. HTTP $code");
        }

        self::$qaUserId = $response['data']['id'] ?? null;

        // 4. Iniciar sesión como el usuario de QA recién creado
        self::$qaUserToken = self::login($usuarioQA, 'Test1234');
        if (!self::$qaUserToken) {
            self::fail("CRÍTICO: No se pudo obtener el token JWT para el usuario de QA.");
        }
    }

    /**
     * TearDown Global: Se ejecuta UNA SOLA VEZ al finalizar todos los tests.
     * Limpieza por "Hard Delete": En lugar de pedirle a la API que haga la baja
     * lógica, interceptamos el registro directamente en la base de datos y lo destruimos.
     */
    public static function tearDownAfterClass(): void {
        if (self::$qaUserId !== null && self::$em !== null) {
            $user = self::$em->getRepository(User::class)->find(self::$qaUserId);
            if ($user) {
                // 1. Borrar mensajes del usuario
                $mensajes = self::$em->getRepository(\App\Entity\Message::class)->findBy(['user' => $user]);
                foreach ($mensajes as $mensaje) {
                    self::$em->remove($mensaje);
                }

                // 2. Borrar huellas de auditoría (Historial) atadas a este usuario
                $historiales = self::$em->getRepository(\App\Entity\History::class)->findBy(['admin' => $user]);
                foreach ($historiales as $historial) {
                    self::$em->remove($historial);
                }
                
                // 3. Borrar al usuario sin violar la llave foránea
                self::$em->remove($user);
                self::$em->flush();
            }
        }
    }

    // =====================================================================
    // CASOS DE PRUEBA OFICIALES (RF07, RF11)
    // =====================================================================

    #[Test]
    #[TestDox('CONSULTA_002 - Un mensaje con contenido vacío es rechazado por el backend (HTTP 400)')]
    public function testUsuarioNoPuedeEnviarMensajeVacio(): void {
        $payload = ['contenido' => '   ']; // Simulación de campo vacío con espacios
        
        [$httpCode, $body] = self::makeRequest('POST', '?action=message', $payload, self::$qaUserToken);
        
        $this->assertEquals(400, $httpCode, "El servidor debería rechazar peticiones POST sin contenido válido.");
        $this->assertEquals('error', $body['status']);
        $this->assertStringContainsString('obligatorio', $body['message'] ?? '');
    }

    #[Test]
    #[TestDox('CONSULTA_001 - Usuario autenticado envía una consulta válida correctamente (HTTP 201)')]
    public function testUsuarioPuedeEnviarConsultaValida(): void {
        $contenidoPrueba = "Hola, necesito ayuda técnica con el sistema de Render.";
        $payload = ['contenido' => $contenidoPrueba];
        
        [$httpCode, $body] = self::makeRequest('POST', '?action=message', $payload, self::$qaUserToken);
        
        $this->assertEquals(201, $httpCode, "Fallo al crear la consulta. Código HTTP: $httpCode");
        $this->assertEquals('success', $body['status']);
        
        // Registrar el ID del mensaje para el flujo de los siguientes tests
        self::$mensajeId = $body['data']['id'] ?? null;
        $this->assertNotNull(self::$mensajeId, "El backend no devolvió el ID del mensaje creado.");
    }

    #[Test]
    #[TestDox('CONSULTA_003 - Administrador visualiza el mensaje recién creado en su bandeja (HTTP 200)')]
    #[Depends('testUsuarioPuedeEnviarConsultaValida')]
    public function testAdminPuedeVerConsultaEnBandeja(): void {
        [$httpCode, $body] = self::makeRequest('GET', '?action=message', [], self::$adminToken);
        
        $this->assertEquals(200, $httpCode);
        
        $mensajeEncontrado = false;
        foreach ($body['data'] as $mensaje) {
            if ($mensaje['id'] === self::$mensajeId) {
                $mensajeEncontrado = true;
                $this->assertEquals('Pendiente', $mensaje['estado'], "El estado inicial del mensaje debe ser 'Pendiente'");
                break;
            }
        }
        
        $this->assertTrue($mensajeEncontrado, "El admin no encuentra el mensaje ID " . self::$mensajeId . " en la bandeja general.");
    }

    #[Test]
    #[TestDox('CONSULTA_004 - Administrador responde la consulta exitosamente (HTTP 200)')]
    #[Depends('testUsuarioPuedeEnviarConsultaValida')]
    public function testAdminPuedeResponderConsulta(): void {
        $payload = ['respuesta' => 'Respuesta automatizada desde PHPUnit QA'];
        
        [$httpCode, $body] = self::makeRequest('PUT', "?action=message&id=" . self::$mensajeId, $payload, self::$adminToken);
        
        $this->assertEquals(200, $httpCode, "El Admin no pudo responder la consulta.");
        $this->assertEquals('Respondido', $body['data']['estado'] ?? '', "El estado del mensaje no se actualizó a 'Respondido'");
    }

    #[Test]
    #[TestDox('CONSULTA_005 - Usuario lee su consulta y visualiza la respuesta del Admin')]
    #[Depends('testAdminPuedeResponderConsulta')]
    public function testUsuarioPuedeVerRespuestaDelAdmin(): void {
        [$httpCode, $body] = self::makeRequest('GET', '?action=message', [], self::$qaUserToken);
        
        $this->assertEquals(200, $httpCode);
        
        $miMensaje = null;
        foreach ($body['data'] as $mensaje) {
            if ($mensaje['id'] === self::$mensajeId) {
                $miMensaje = $mensaje;
                break;
            }
        }

        $this->assertNotNull($miMensaje, "El usuario QA no encuentra su propio mensaje en su bandeja personal.");
        $this->assertEquals('Respondido', $miMensaje['estado']);
        $this->assertEquals('Respuesta automatizada desde PHPUnit QA', $miMensaje['respuesta']);
    }

    // =====================================================================
    // VULNERABILIDAD EXTRA - VALIDACIÓN DE ROLES EN CONTROLADOR (RBAC)
    // =====================================================================

    #[Test]
    #[TestDox('PRUEBA QA - Vulnerabilidad Crítica: Un usuario común NO debe poder usar el método PUT para responder (Esperado: 403)')]
    public function testUsuarioComunNoPuedeResponderConsultas(): void {
        // 1. Crear un mensaje de prueba
        $payload = ['contenido' => 'Mensaje trampa'];
        [$code, $res] = self::makeRequest('POST', '?action=message', $payload, self::$qaUserToken);
        $trampaId = $res['data']['id'] ?? 0;

        // 2. Intentar responderlo inyectando el token del usuario común (Debería ser rechazado)
        $ataquePayload = ['respuesta' => 'Hackeado por el usuario QA'];
        [$httpCode, $body] = self::makeRequest('PUT', "?action=message&id=$trampaId", $ataquePayload, self::$qaUserToken);

        // Aserción estricta de QA:
        // Exigimos 403 Forbidden. Si el controlador devuelve 200, el test falla y demuestra
        // que el endpoint no está verificando si quien responde tiene rol 'admin'.
        $this->assertEquals(403, $httpCode, 
            "⚠️ VULNERABILIDAD RBAC ENCONTRADA: Un usuario común usó el método PUT y pudo responder una consulta (Estado devuelto: $httpCode). Falta control de rol en MessageController::reply().");
    }

    // =====================================================================
    // MÉTODOS AUXILIARES HTTP CLIENT (cURL encapsulado)
    // =====================================================================

    /**
     * Encapsula la lógica de peticiones cURL apuntando a la nube (Render).
     * Retorna array estructurado: [int $statusCode, array $responseBody]
     */
    private static function makeRequest(string $method, string $uri, array $payload = [], ?string $token = null): array {
        $url = self::$baseUrl . $uri;
        $ch = curl_init($url);
        
        $headers = ['Content-Type: application/json'];
        if ($token) {
            $headers[] = "Authorization: Bearer $token";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Evita que cURL aborte la conexión en Windows por falta del certificado local
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        
        // Si cURL falla por completo (timeout, error de red, DNS), abortamos el test con el error exacto
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            self::fail("CRÍTICO: Falló la conexión cURL hacia Render ($method $uri). Error: $error");
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $jsonStart = strpos($response, '{');
        if ($jsonStart !== false) {
            $response = substr($response, $jsonStart);
        }

        $decoded = json_decode($response, true) ?? [];
        return [$httpCode, $decoded];
    }

    /**
     * Inicia sesión y extrae directamente el token JWT del response.
     */
    private static function login(string $usuario, string $password): ?string {
        $payload = ['identificador' => $usuario, 'password' => $password];
        [$code, $body] = self::makeRequest('POST', '?action=login', $payload);
        return $code === 200 ? ($body['data']['token'] ?? $body['token'] ?? null) : null;
    }
}
