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
 * Nota sobre MAIL_ENABLED=false:
 *   En local el servidor no tiene SMTP configurado. El backend genera el
 *   recovery_code y lo guarda en sesión ANTES de intentar enviar el email,
 *   por lo que el código siempre existe en $_SESSION aunque el envío devuelva 503.
 *   Los tests que necesitan el código lo leen directamente del archivo de sesión.
 *
 * Nota sobre cookies y sesión (RECUPERAR_003):
 *   El flujo recover_request → recover_reset mantiene estado vía $_SESSION de PHP.
 *   Para encadenar los dos requests con la misma sesión se usa un cookieJar temporal
 *   (archivo de cookies de cURL) que preserva el PHPSESSID entre llamadas.
 *   Este mecanismo es específico de esta suite y no forma parte de IntegrationTestCase.
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
    #[TestDox('RECUPERAR_001 - La solicitud de recuperación con email registrado responde 200 o 503 (sin SMTP)')]
    public function testSolicitudRecuperacionUsuarioRegistrado(): void
    {
        $usuario = $this->crearUsuarioDePrueba('Test123456');

        [$httpCode, $body] = $this->postJson(
            'recover_request',
            ['identificador' => $usuario->getEmail()],
        );

        /*
         * La planilla QA espera HTTP 200.
         * Con MAIL_ENABLED=false el backend puede responder 503 porque no tiene
         * SMTP configurado, pero el proceso de recuperación sí se inicia.
         * Aceptamos ambos códigos y verificamos el contrato de respuesta de cada uno.
         */
        $this->assertContains(
            $httpCode,
            [200, 503],
            'Se esperaba HTTP 200 (email enviado) o 503 (SMTP no configurado).'
        );

        $this->assertArrayHasKey('status', $body);

        if ($httpCode === 200) {
            $this->assertSame('success', $body['status']);
            $this->assertStringContainsString('Código enviado', $body['message'] ?? '');
        }

        if ($httpCode === 503) {
            $this->assertSame('error', $body['status']);
            $this->assertStringContainsString('recuperación por email', $body['message'] ?? '');
        }
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
    // RECUPERAR_003 — Restablecimiento con código válido
    // =====================================================================

    #[Test]
    #[TestDox('RECUPERAR_003 - El código de recuperación válido permite cambiar la contraseña (HTTP 200)')]
    public function testRestablecimientoConCodigoValido(): void
    {
        $usuario   = $this->crearUsuarioDePrueba('Test123456');
        $cookieJar = tempnam(sys_get_temp_dir(), 'recover_cookie_');

        // 1. Disparar el flujo de recuperación manteniendo la sesión vía cookieJar.
        //    Aunque MAIL_ENABLED=false devuelva 503, el backend ya guardó
        //    recovery_code + recovery_user_id en $_SESSION.
        [$requestCode] = $this->postJsonConSesion(
            'recover_request',
            ['identificador' => $usuario->getEmail()],
            $cookieJar
        );

        $this->assertContains(
            $requestCode,
            [200, 503],
            'recover_request debe responder 200 o 503 (sin SMTP). HTTP: ' . $requestCode
        );

        // 2. Leer el recovery_code directamente del archivo de sesión del servidor.
        $sessionId = $this->extraerSessionIdDesdeCookieJar($cookieJar);
        $codigo    = $this->extraerCodigoDesdeSesion($sessionId);

        // 3. Restablecer la contraseña enviando el código dentro de la misma sesión.
        [$resetCode, $resetBody] = $this->postJsonConSesion(
            'recover_reset',
            [
                'codigo'    => $codigo,
                'password'  => 'NuevaPass123',
            ],
            $cookieJar,
            $sessionId
        );

        $this->assertSame(200, $resetCode, 'recover_reset debería devolver HTTP 200.');
        $this->assertSame('success', $resetBody['status'] ?? '');
        $this->assertSame('Contraseña restablecida correctamente.', $resetBody['message'] ?? '');

        // 4. Verificar que el login con la nueva contraseña funciona.
        [$loginCode] = $this->postJson(
            'login',
            [
                'identificador' => $usuario->getUsuario(),
                'password'      => 'NuevaPass123',
            ]
        );

        $this->assertSame(200, $loginCode, 'El login con la nueva contraseña debe devolver HTTP 200.');

        // Limpieza del archivo de cookies temporal
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
    // Helpers HTTP con soporte de sesión (específicos de este flujo)
    // =====================================================================

    /**
     * POST JSON conservando la sesión PHP entre requests mediante un cookieJar.
     *
     * Los helpers postJson/getJson de IntegrationTestCase usan file_get_contents,
     * que no soporta cookies. El flujo recover_request → recover_reset necesita
     * que ambos requests compartan el mismo PHPSESSID para que el servidor
     * encuentre el recovery_code en $_SESSION.
     *
     * @param string      $action     Valor del query param ?action=
     * @param array       $payload    Body JSON
     * @param string      $cookieJar  Ruta al archivo de cookies temporal (cURL COOKIEJAR)
     * @param string|null $sessionId  Si se conoce, fuerza el PHPSESSID como cookie
     *
     * @return array{int, array}  [httpCode, body decodificado]
     */
    private function postJsonConSesion(
        string  $action,
        array   $payload,
        string  $cookieJar,
        ?string $sessionId = null,
    ): array {
        // makeRequest() de la clase base no soporta cookies; construimos la URL
        // manualmente siguiendo la misma lógica de resolución de path.
        $baseUrl = $this->resolverBaseUrl();
        $url     = $baseUrl . '/index.php?action=' . $action;

        $ch = curl_init($url);

        $headers = ['Content-Type: application/json'];

        $options = [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
        ];

        // Forzar la sesión si ya se conoce el PHPSESSID (segundo request del flujo)
        if ($sessionId !== null) {
            $options[CURLOPT_COOKIE] = 'PHPSESSID=' . $sessionId;
        }

        curl_setopt_array($ch, $options);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->fail("CRÍTICO: Falló la conexión cURL (POST $action). Error: $error");
        }

        // Descartar basura de texto antes del JSON (ej. notices de PHP)
        $jsonStart = strpos($body, '{');
        if ($jsonStart !== false) {
            $body = substr($body, $jsonStart);
        }

        return [$httpCode, json_decode($body, true) ?? []];
    }

    /**
     * Devuelve la URL base del servidor (sin /index.php ni query string).
     * Replica la lógica de makeRequest() para construir URLs fuera de él.
     *
     * En modo local:  "http://127.0.0.1:8099"
     * En modo Render: "https://server.onrender.com/index.php" → se quita /index.php
     */
    private function resolverBaseUrl(): string
    {
        // Accedemos a la propiedad estática heredada a través de la reflexión,
        // ya que es private en IntegrationTestCase. El workaround más simple
        // y sin romper encapsulación es reconstruirla desde las mismas env vars.
        $renderUrl = getenv('TEST_RENDER_URL');
        if ($renderUrl !== false && $renderUrl !== '') {
            // Quitar /index.php si viene incluido en la URL de Render
            return rtrim(str_replace('/index.php', '', $renderUrl), '/');
        }

        $host = getenv('TEST_SERVER_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_SERVER_PORT') ?: '8099';

        return "http://{$host}:{$port}";
    }

    // =====================================================================
    // Helpers de sesión PHP
    // =====================================================================

    /**
     * Lee el archivo de cookies de cURL y extrae el valor de PHPSESSID.
     * El formato Netscape cookies tiene 7 columnas separadas por tabs;
     * el valor es la última columna.
     */
    private function extraerSessionIdDesdeCookieJar(string $cookieJar): string
    {
        $contenido = file_get_contents($cookieJar);
        $this->assertNotFalse($contenido, 'No se pudo leer el archivo de cookies: ' . $cookieJar);

        foreach (explode("\n", $contenido) as $linea) {
            if (!str_contains($linea, 'PHPSESSID')) {
                continue;
            }

            $partes = preg_split('/\s+/', trim($linea));
            if (count($partes) >= 7) {
                return $partes[6];
            }
        }

        $this->fail('No se encontró PHPSESSID en el archivo de cookies: ' . $cookieJar);
    }

    /**
     * Lee el archivo de sesión físico del servidor PHP embebido y extrae
     * el recovery_code guardado por AuthController::recoverRequest().
     *
     * El archivo de sesión tiene el formato de serialización de PHP:
     *   recovery_code|s:6:"123456";recovery_user_id|i:42;
     *
     * Solo funciona cuando el servidor corre localmente (misma máquina que el test).
     * En modo Render no existe acceso al filesystem del servidor remoto.
     */
    private function extraerCodigoDesdeSesion(string $sessionId): string
    {
        $savePath    = $this->resolverSessionSavePath();
        $sessionFile = rtrim($savePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'sess_' . $sessionId;

        $this->assertFileExists(
            $sessionFile,
            'No se encontró el archivo de sesión. Path: ' . $sessionFile
        );

        $contenido = file_get_contents($sessionFile);
        $this->assertNotFalse($contenido, 'No se pudo leer el archivo de sesión: ' . $sessionFile);

        preg_match('/recovery_code\|s:\d+:"([^"]+)";/', $contenido, $matches);
        $codigo = $matches[1] ?? null;

        $this->assertNotEmpty(
            $codigo,
            'No se encontró recovery_code en la sesión. Contenido: ' . $contenido
        );

        return $codigo;
    }

    /**
     * Devuelve la ruta donde PHP almacena los archivos de sesión.
     * Respeta la configuración real de session.save_path del proceso PHP actual.
     */
    private function resolverSessionSavePath(): string
    {
        $path = ini_get('session.save_path');

        if (!$path) {
            return sys_get_temp_dir();
        }

        // session.save_path puede tener el formato "N;/ruta" (con nivel de profundidad)
        if (str_contains($path, ';')) {
            $parts = explode(';', $path);
            $path  = end($parts);
        }

        return $path ?: sys_get_temp_dir();
    }
}