<?php

declare(strict_types=1);

namespace App\Tests\PasswordRecovery;

use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Pruebas de integración HTTP — Módulo: Recuperación de contraseña
 *
 * Endpoints cubiertos:
 *   POST /index.php?action=recover_request
 *   POST /index.php?action=recover_reset
 *
 * Mapeo con la planilla QA:
 *   RECUPERAR_001 → testSolicitudRecuperacionUsuarioRegistrado()
 *   RECUPERAR_002 → testSolicitudRecuperacionUsuarioInexistente()
 *   RECUPERAR_003 → testRestablecimientoConCodigoValido()
 *   RECUPERAR_004 → testRestablecimientoConCodigoInvalido()
 *
 * Requisito cubierto: RF02
 *
 * ─── Variables de entorno necesarias ────────────────────────────────────────
 *
 *   MAIL_ENABLED=true          Activa el envío de emails en el backend.
 *                              Con false, RECUPERAR_001 y RECUPERAR_003
 *                              se saltean automáticamente.
 *
 *   MAILTRAP_API_TOKEN=<token> Token de la API HTTP de Mailtrap.
 *                              Se obtiene en: https://mailtrap.io → API Tokens
 *
 *   MAILTRAP_INBOX_ID=<id>     ID numérico del inbox de Mailtrap.
 *                              Se ve en la URL al abrir el inbox:
 *                              https://mailtrap.io/inboxes/{id}/messages
 *
 * ─── Cómo configurar Mailtrap en el backend ─────────────────────────────────
 *
 *   En el .env del proyecto, apuntar el SMTP a Mailtrap:
 *     MAIL_HOST=sandbox.smtp.mailtrap.io
 *     MAIL_PORT=2525
 *     MAIL_USERNAME=<usuario Mailtrap>
 *     MAIL_PASSWORD=<contraseña Mailtrap>
 *     MAIL_ENABLED=true
 *
 *   En el .env de tests (o en phpunit.xml como <env>), agregar:
 *     MAILTRAP_API_TOKEN=<token>
 *     MAILTRAP_INBOX_ID=<id>
 *
 * ─── Flujo de RECUPERAR_003 ──────────────────────────────────────────────────
 *
 *   1. Se vacía el inbox de Mailtrap (borra mensajes anteriores).
 *   2. recover_request dispara el email con el código de 4 dígitos.
 *   3. El test consulta la API de Mailtrap hasta recibir el mensaje (máx. 15 s).
 *   4. Parsea el texto plano del email y extrae el código.
 *   5. recover_reset usa ese código para cambiar la contraseña.
 *   6. Verifica que el login con la nueva contraseña responde HTTP 200.
 *
 *   Nota sobre cookies y sesión:
 *     El flujo recover_request → recover_reset mantiene estado vía $_SESSION.
 *     postJson() usa file_get_contents, que no maneja cookies. Por eso
 *     postJsonConSesion() usa cURL + cookieJar para compartir el PHPSESSID
 *     entre los dos requests.
 */
final class PasswordRecoveryTest extends IntegrationTestCase
{
    /** Token JWT del admin, obtenido una vez por instancia en setUp(). */
    private string $adminToken = '';

    // =====================================================================
    // Ciclo de vida
    // =====================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminToken = $this->login('admin', '123456');
    }

    // Los usuarios creados con crearUsuarioDePrueba() quedan en $createdUserIds
    // y son eliminados automáticamente por tearDown() de IntegrationTestCase.

    // =====================================================================
    // RECUPERAR_001 — Solicitud de recuperación con usuario registrado
    // =====================================================================

    #[Test]
    #[TestDox('RECUPERAR_001 - La solicitud de recuperación con email registrado responde 200 y devuelve el email ofuscado')]
    public function testSolicitudRecuperacionUsuarioRegistrado(): void
    {
        $this->saltearSiSinSmtp('RECUPERAR_001');

        $usuario = $this->crearUsuarioDePrueba('Test123456');

        [$httpCode, $body] = $this->postJson(
            'recover_request',
            ['identificador' => $usuario->getEmail()],
        );

        $this->assertSame(200, $httpCode, 'Se esperaba HTTP 200 con SMTP activo.');
        $this->assertArrayHasKey('status', $body);
        $this->assertSame('success', $body['status']);
        $this->assertStringContainsString('Código enviado', $body['message'] ?? '');

        // Verifica que la respuesta incluye el email ofuscado (ej. "t***@example.test")
        $this->assertArrayHasKey('data', $body, 'La respuesta debe incluir "data" con el email ofuscado.');
        $emailOfuscado = $body['data']['email'] ?? '';
        $this->assertNotEmpty($emailOfuscado, 'El campo data.email no debe estar vacío.');
        $this->assertStringContainsString('*', $emailOfuscado, 'El email en data debe estar ofuscado.');
    }

    // =====================================================================
    // RECUPERAR_002 — Solicitud de recuperación con usuario inexistente
    // =====================================================================

    #[Test]
    #[TestDox('RECUPERAR_002 - La solicitud con un identificador inexistente devuelve HTTP 404')]
    public function testSolicitudRecuperacionUsuarioInexistente(): void
    {
        [$httpCode, $body] = $this->postJson(
            'recover_request',
            ['identificador' => 'usuario_inexistente_' . time()],
        );

        $this->assertSame(404, $httpCode);
        $this->assertSame('error', $body['status'] ?? '');
        $this->assertSame('Usuario no registrado o inactivo.', $body['message'] ?? '');
    }

    // =====================================================================
    // RECUPERAR_003 — Restablecimiento con código válido (end-to-end vía Mailtrap)
    // =====================================================================

    #[Test]
    #[TestDox('RECUPERAR_003 - El código recibido por email permite cambiar la contraseña (HTTP 200)')]
    public function testRestablecimientoConCodigoValido(): void
    {
        $this->saltearSiSinSmtp('RECUPERAR_003');
        $this->saltearSiSinMailtrap('RECUPERAR_003');

        $usuario   = $this->crearUsuarioDePrueba('Test123456');
        $cookieJar = tempnam(sys_get_temp_dir(), 'recover_cookie_');

        // 1. Vaciar el inbox de Mailtrap para evitar que el test lea un email
        //    de una ejecución anterior.
        $this->vaciarInboxMailtrap();

        // 2. Disparar el flujo de recuperación. El backend envía el email a Mailtrap.
        [$requestCode] = $this->postJsonConSesion(
            'recover_request',
            ['identificador' => $usuario->getEmail()],
            $cookieJar
        );

        $this->assertSame(
            200,
            $requestCode,
            'recover_request debe responder HTTP 200 con SMTP activo. HTTP: ' . $requestCode
        );

        // 3. Esperar y leer el email desde la API de Mailtrap; extraer el código.
        $codigo = $this->extraerCodigoDesdeMailtrap($usuario->getEmail());

        $this->assertMatchesRegularExpression(
            '/^\d{4}$/',
            $codigo,
            'El código extraído del email debe ser un número de 4 dígitos.'
        );

        // 4. Restablecer la contraseña dentro de la misma sesión.
        [$resetCode, $resetBody] = $this->postJsonConSesion(
            'recover_reset',
            [
                'codigo'   => $codigo,
                'password' => 'NuevaPass123',
            ],
            $cookieJar
        );

        $this->assertSame(200, $resetCode, 'recover_reset debe devolver HTTP 200.');
        $this->assertSame('success', $resetBody['status'] ?? '');
        $this->assertSame('Contraseña restablecida correctamente.', $resetBody['message'] ?? '');

        // 5. Verificar que el login con la nueva contraseña funciona.
        [$loginCode] = $this->postJson(
            'login',
            [
                'identificador' => $usuario->getUsuario(),
                'password'      => 'NuevaPass123',
            ]
        );

        $this->assertSame(200, $loginCode, 'El login con la nueva contraseña debe devolver HTTP 200.');

        // Limpieza del archivo de cookies temporal.
        if (file_exists($cookieJar)) {
            unlink($cookieJar);
        }
    }

    // =====================================================================
    // RECUPERAR_004 — Restablecimiento con código inválido o expirado
    // =====================================================================

    #[Test]
    #[TestDox('RECUPERAR_004 - Un código de recuperación inválido o expirado devuelve HTTP 400')]
    public function testRestablecimientoConCodigoInvalido(): void
    {
        [$httpCode, $body] = $this->postJson(
            'recover_reset',
            [
                'codigo'   => '0000',
                'password' => 'NuevaPass123',
            ]
        );

        $this->assertSame(400, $httpCode);
        $this->assertSame('error', $body['status'] ?? '');
        $this->assertSame('Código de recuperación inválido o expirado.', $body['message'] ?? '');
    }

    // =====================================================================
    // Skip helpers
    // =====================================================================

    /**
     * Salta el test con un mensaje claro si el backend no tiene SMTP configurado.
     * Se activa cuando MAIL_ENABLED != 'true' en el entorno de ejecución.
     */
    private function saltearSiSinSmtp(string $nombreTest): void
    {
        $mailEnabled = strtolower(trim(getenv('MAIL_ENABLED') ?: ''));

        if ($mailEnabled !== 'true') {
            $this->markTestSkipped(
                "{$nombreTest} requiere SMTP activo (MAIL_ENABLED=true). " .
                'Configurá el servidor de correo y activá MAIL_ENABLED en el .env para correr este test.'
            );
        }
    }

    /**
     * Salta el test si no están configuradas las credenciales de Mailtrap.
     * Se necesitan tanto el token de API como el ID del inbox.
     */
    private function saltearSiSinMailtrap(string $nombreTest): void
    {
        $token   = getenv('MAILTRAP_API_TOKEN');
        $inboxId = getenv('MAILTRAP_INBOX_ID');

        if (empty($token) || empty($inboxId)) {
            $this->markTestSkipped(
                "{$nombreTest} requiere Mailtrap configurado. " .
                'Definí MAILTRAP_API_TOKEN y MAILTRAP_INBOX_ID en el entorno o en phpunit.xml.'
            );
        }
    }

    // =====================================================================
    // Mailtrap API helpers
    // =====================================================================

    /**
     * Elimina todos los mensajes del inbox de Mailtrap para que el test
     * siguiente no lea un email de una ejecución anterior.
     */
    private function vaciarInboxMailtrap(): void
    {
        $token   = getenv('MAILTRAP_API_TOKEN');
        $inboxId = getenv('MAILTRAP_INBOX_ID');

        $ch = curl_init("https://mailtrap.io/api/v1/inboxes/{$inboxId}/clean");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_HTTPHEADER     => ["Api-Token: {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $cleanResponse = curl_exec($ch);
        $cleanHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Si el vaciado falla, el test debe abortar con un mensaje claro
        // en vez de leer un email viejo de la ejecución anterior.
        $this->assertContains(
            $cleanHttpCode,
            [200, 204],
            "No se pudo vaciar el inbox de Mailtrap. HTTP {$cleanHttpCode}: {$cleanResponse}"
        );

        // Pausa breve para que Mailtrap procese el borrado antes de enviar el nuevo email.
        usleep(1_000_000);
    }

    /**
     * Espera hasta 30 segundos a que llegue un email al inbox de Mailtrap
     * y extrae el código de recuperación de 4 dígitos del cuerpo de texto.
     *
     * El inbox se vació justo antes de llamar a recover_request, así que
     * el primer mensaje que aparezca es necesariamente el que enviamos.
     * No filtramos por destinatario porque la estructura exacta del campo
     * varía entre versiones de la API de Mailtrap.
     *
     * El backend envía:
     *   "Tu código de recuperación de contraseña es: {$codigo}"
     *
     * @param string $emailDestinatario Email del usuario (usado solo en el mensaje de fallo).
     * @return string Código de 4 dígitos extraído del email.
     */
    private function extraerCodigoDesdeMailtrap(string $emailDestinatario): string
    {
        $token   = getenv('MAILTRAP_API_TOKEN');
        $inboxId = getenv('MAILTRAP_INBOX_ID');
        $apiBase = "https://mailtrap.io/api/v1/inboxes/{$inboxId}";

        $deadline  = microtime(true) + 30;
        $messageId = null;

        // Polling: esperar a que aparezca al menos un mensaje en el inbox.
        // El inbox fue vaciado justo antes de disparar recover_request,
        // por lo que cualquier mensaje que llegue es el nuestro.
        $lastRawResponse = '';
        while (microtime(true) < $deadline) {
            $ch = curl_init("{$apiBase}/messages");
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ["Api-Token: {$token}"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $lastRawResponse = (string) $response;
            $messages = json_decode($lastRawResponse, true) ?? [];

            if (!empty($messages) && isset($messages[0]['id'])) {
                $messageId = $messages[0]['id'];
                break;
            }

            usleep(2_000_000); // 2 s entre intentos
        }

        $this->assertNotNull(
            $messageId,
            "El email de recuperación no llegó al inbox de Mailtrap en 30 segundos. " .
            "Destinatario esperado: {$emailDestinatario}. " .
            "\u00daltima respuesta de la API: {$lastRawResponse}"
        );

        // Obtener el cuerpo de texto plano del mensaje.
        $ch = curl_init("{$apiBase}/messages/{$messageId}/body.txt");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Api-Token: {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $textBody = (string) curl_exec($ch);
        curl_close($ch);

        // Parsear el código: "Tu código de recuperación de contraseña es: 1234"
        preg_match('/es:\s*(\d{4})/', $textBody, $matches);
        $codigo = $matches[1] ?? null;

        $this->assertNotEmpty(
            $codigo,
            "No se encontró el código de 4 dígitos en el email de Mailtrap. " .
            "Cuerpo recibido: {$textBody}"
        );

        return $codigo;
    }

    // =====================================================================
    // Helper HTTP con soporte de sesión (específico de este flujo)
    // =====================================================================

    /**
     * POST JSON conservando la sesión PHP entre requests mediante un cookieJar.
     *
     * Los helpers postJson/getJson de IntegrationTestCase usan file_get_contents,
     * que no soporta cookies. El flujo recover_request → recover_reset necesita
     * que ambos requests compartan el mismo PHPSESSID para que el servidor
     * encuentre el recovery_code en $_SESSION.
     *
     * @param string $action    Valor del query param ?action=
     * @param array  $payload   Body JSON
     * @param string $cookieJar Ruta al archivo de cookies temporal (cURL COOKIEJAR)
     *
     * @return array{int, array} [httpCode, body decodificado]
     */
    private function postJsonConSesion(string $action, array $payload, string $cookieJar): array
    {
        $baseUrl = $this->resolverBaseUrl();
        $url     = $baseUrl . '/index.php?action=' . $action;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->fail("CRÍTICO: Falló la conexión cURL (POST $action). Error: $error");
        }

        // Descartar basura de texto antes del JSON (ej. notices de PHP).
        $jsonStart = strpos($body, '{');
        if ($jsonStart !== false) {
            $body = substr($body, $jsonStart);
        }

        return [$httpCode, json_decode($body, true) ?? []];
    }

    /**
     * Devuelve la URL base del servidor (sin /index.php ni query string).
     *
     * En modo local:  "http://127.0.0.1:8099"
     * En modo Render: "https://server.onrender.com"
     */
    private function resolverBaseUrl(): string
    {
        $renderUrl = getenv('TEST_RENDER_URL');
        if ($renderUrl !== false && $renderUrl !== '') {
            return rtrim(str_replace('/index.php', '', $renderUrl), '/');
        }

        $host = getenv('TEST_SERVER_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_SERVER_PORT') ?: '8099';

        return "http://{$host}:{$port}";
    }
}