<?php

declare(strict_types=1);

namespace Tests\Messaging;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pruebas de Integración (API E2E) - Módulo de Consultas y Mensajes
 *
 * SERVIDOR OBJETIVO:
 *   - Por defecto: servidor PHP embebido local (levantado por IntegrationTestCase).
 *   - Modo Render: si TEST_RENDER_URL está definida, apunta al entorno de
 *     producción/staging en la nube. En ese caso el servidor local no se inicia.
 *
 * BASE DE DATOS:
 *   Conexión directa a Aiven vía Doctrine (config/bootstrap.php) para la
 *   limpieza de datos generados durante la suite.
 *
 * Estrategia de Pruebas (Stateful Testing Híbrido):
 * -----------------------------------------------------------------------
 * 1. Preparación (HTTP): Se crea un único usuario QA temporal vía API.
 * 2. Ejecución (HTTP): Ese usuario transita por todos los estados de mensajes.
 * 3. Limpieza (Doctrine): Al finalizar, se hace un "Hard Delete" del usuario
 *    y sus mensajes para no dejar registros basura (estado = 0).
 *    La limpieza profunda (mensajes + historial) la hace IntegrationTestCase
 *    a través de purgarEntidadesRelacionadas() antes de borrar el usuario.
 *
 * Mapeo con Planilla QA:
 *   CONSULTA_002 → testUsuarioNoPuedeEnviarMensajeVacio()
 *   CONSULTA_001 → testUsuarioPuedeEnviarConsultaValida()
 *   CONSULTA_003 → testAdminPuedeVerConsultaEnBandeja()
 *   CONSULTA_004 → testAdminPuedeResponderConsulta()
 *   CONSULTA_005 → testUsuarioPuedeVerRespuestaDelAdmin()
 *   PRUEBA_QA    → testUsuarioComunNoPuedeResponderConsultas()
 */
class MessageTest extends IntegrationTestCase
{
    // ── Estado compartido entre tests ─────────────────────────────────────
    // Estático porque setUpBeforeClass/tearDownAfterClass son estáticos y
    // el flujo de mensajes es inherentemente secuencial (CONSULTA_001 → 005).

    private static ?string $adminToken   = null;
    private static ?string $qaUserToken  = null;
    private static ?int    $qaUserId     = null;
    private static ?int    $mensajeId    = null;

    // =====================================================================
    // Ciclo de vida
    // =====================================================================

    /**
     * Setup global: se ejecuta UNA SOLA VEZ antes del primer test.
     *
     * 1. Deja que IntegrationTestCase inicialice Doctrine, el .env y el
     *    servidor (local o Render según TEST_RENDER_URL).
     * 2. Obtiene el token de administrador vía API.
     * 3. Crea un usuario QA descartable vía API (para que quede registrado
     *    con todos los campos que la app requiere).
     * 4. Obtiene el token del usuario QA.
     */
    public static function setUpBeforeClass(): void
    {
        // Delega la inicialización de infraestructura a la clase base
        parent::setUpBeforeClass();

        // 1. Login como administrador
        self::$adminToken = self::loginConCurl('admin', '123456');
        if (self::$adminToken === null) {
            self::fail('CRÍTICO: No se pudo iniciar sesión como administrador.');
        }

        // 2. Crear usuario QA descartable vía API (verifica el flujo real de creación)
        $random    = mt_rand(1000, 9999);
        $usuarioQA = 'qa_msg_' . $random;
        $payload   = [
            'dni'      => '88' . $random . mt_rand(10, 99),
            'usuario'  => $usuarioQA,
            'nombre'   => 'QA',
            'apellido' => 'Mensajeria',
            'email'    => 'qamsg' . $random . '@test.com',
            'password' => 'Test1234',
            'rol'      => 'user',
        ];

        [$code, $response] = self::makeRequest('POST', '?action=user', $payload, self::$adminToken);
        if ($code !== 201) {
            self::fail("CRÍTICO: El servidor rechazó la creación del usuario QA. HTTP $code");
        }

        self::$qaUserId = $response['data']['id'] ?? null;

        // 3. Login como el usuario QA recién creado
        self::$qaUserToken = self::loginConCurl($usuarioQA, 'Test1234');
        if (self::$qaUserToken === null) {
            self::fail('CRÍTICO: No se pudo obtener el token JWT para el usuario QA.');
        }
    }

    /**
     * Teardown global: se ejecuta UNA SOLA VEZ al finalizar todos los tests.
     *
     * Realiza un "Hard Delete" del usuario QA y sus datos relacionados
     * directamente en la base de datos (saltando la API), para no dejar
     * registros con estado = 0 (baja lógica) que ensucien la BD.
     *
     * purgarEntidadesRelacionadas() (de IntegrationTestCase) borra mensajes
     * e historial antes de eliminar el usuario, evitando errores de FK.
     */
    public static function tearDownAfterClass(): void
    {
        // Hard delete del usuario QA y sus entidades relacionadas
        self::eliminarUsuarioPorId(self::$qaUserId ?? 0);

        // Resetea estado compartido para ejecuciones paralelas / repetidas
        self::$adminToken  = null;
        self::$qaUserToken = null;
        self::$qaUserId    = null;
        self::$mensajeId   = null;

        // Deja que la clase base cierre el servidor embebido y libere Doctrine
        parent::tearDownAfterClass();
    }

    // =====================================================================
    // CASOS DE PRUEBA OFICIALES (RF07, RF11)
    // =====================================================================

    #[Test]
    #[TestDox('CONSULTA_002 - Un mensaje con contenido vacío es rechazado por el backend (HTTP 400)')]
    public function testUsuarioNoPuedeEnviarMensajeVacio(): void
    {
        $payload = ['contenido' => '   ']; // Campo vacío con espacios

        [$httpCode, $body] = self::makeRequest('POST', '?action=message', $payload, self::$qaUserToken);

        $this->assertEquals(400, $httpCode, 'El servidor debería rechazar peticiones POST sin contenido válido.');
        $this->assertEquals('error', $body['status']);
        $this->assertStringContainsString('obligatorio', $body['message'] ?? '');
    }

    #[Test]
    #[TestDox('CONSULTA_001 - Usuario autenticado envía una consulta válida correctamente (HTTP 201)')]
    public function testUsuarioPuedeEnviarConsultaValida(): void
    {
        $payload = ['contenido' => 'Hola, necesito ayuda técnica con el sistema.'];

        [$httpCode, $body] = self::makeRequest('POST', '?action=message', $payload, self::$qaUserToken);

        $this->assertEquals(201, $httpCode, "Fallo al crear la consulta. Código HTTP: $httpCode");
        $this->assertEquals('success', $body['status']);

        self::$mensajeId = $body['data']['id'] ?? null;
        $this->assertNotNull(self::$mensajeId, 'El backend no devolvió el ID del mensaje creado.');
    }

    #[Test]
    #[TestDox('CONSULTA_003 - Administrador visualiza el mensaje recién creado en su bandeja (HTTP 200)')]
    #[Depends('testUsuarioPuedeEnviarConsultaValida')]
    public function testAdminPuedeVerConsultaEnBandeja(): void
    {
        [$httpCode, $body] = self::makeRequest('GET', '?action=message', [], self::$adminToken);

        $this->assertEquals(200, $httpCode);

        $mensajeEncontrado = false;
        foreach ($body['data'] as $mensaje) {
            if ($mensaje['id'] === self::$mensajeId) {
                $mensajeEncontrado = true;
                $this->assertEquals('Pendiente', $mensaje['estado'], "El estado inicial del mensaje debe ser 'Pendiente'.");
                break;
            }
        }

        $this->assertTrue($mensajeEncontrado, 'El admin no encuentra el mensaje ID ' . self::$mensajeId . ' en la bandeja general.');
    }

    #[Test]
    #[TestDox('CONSULTA_004 - Administrador responde la consulta exitosamente (HTTP 200)')]
    #[Depends('testUsuarioPuedeEnviarConsultaValida')]
    public function testAdminPuedeResponderConsulta(): void
    {
        $payload = ['respuesta' => 'Respuesta automatizada desde PHPUnit QA'];

        [$httpCode, $body] = self::makeRequest('PUT', '?action=message&id=' . self::$mensajeId, $payload, self::$adminToken);

        $this->assertEquals(200, $httpCode, 'El Admin no pudo responder la consulta.');
        $this->assertEquals('Respondido', $body['data']['estado'] ?? '', "El estado del mensaje no se actualizó a 'Respondido'.");
    }

    #[Test]
    #[TestDox('CONSULTA_005 - Usuario lee su consulta y visualiza la respuesta del Admin')]
    #[Depends('testAdminPuedeResponderConsulta')]
    public function testUsuarioPuedeVerRespuestaDelAdmin(): void
    {
        [$httpCode, $body] = self::makeRequest('GET', '?action=message', [], self::$qaUserToken);

        $this->assertEquals(200, $httpCode);

        $miMensaje = null;
        foreach ($body['data'] as $mensaje) {
            if ($mensaje['id'] === self::$mensajeId) {
                $miMensaje = $mensaje;
                break;
            }
        }

        $this->assertNotNull($miMensaje, 'El usuario QA no encuentra su propio mensaje en su bandeja personal.');
        $this->assertEquals('Respondido', $miMensaje['estado']);
        $this->assertEquals('Respuesta automatizada desde PHPUnit QA', $miMensaje['respuesta']);
    }

    // =====================================================================
    // VULNERABILIDAD EXTRA - VALIDACIÓN DE ROLES (RBAC)
    // =====================================================================

    #[Test]
    #[TestDox('PRUEBA QA - Vulnerabilidad Crítica: Un usuario común NO debe poder usar PUT para responder (Esperado: 403)')]
    public function testUsuarioComunNoPuedeResponderConsultas(): void
    {
        // 1. Crear un mensaje trampa con el usuario QA
        [$code, $res] = self::makeRequest('POST', '?action=message', ['contenido' => 'Mensaje trampa'], self::$qaUserToken);
        $trampaId = $res['data']['id'] ?? 0;

        // 2. Intentar responderlo con el token del usuario común (debe ser rechazado)
        [$httpCode, $body] = self::makeRequest(
            'PUT',
            "?action=message&id=$trampaId",
            ['respuesta' => 'Hackeado por el usuario QA'],
            self::$qaUserToken
        );

        $this->assertEquals(
            403,
            $httpCode,
            "⚠️ VULNERABILIDAD RBAC ENCONTRADA: Un usuario común usó PUT y pudo responder una consulta " .
            "(Estado devuelto: $httpCode). Falta control de rol en MessageController::reply()."
        );
    }
}