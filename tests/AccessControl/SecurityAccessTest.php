<?php
namespace Tests\AccessControl;

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
 * Por qué integración con cURL en lugar de mocks:
 * -----------------------------------------------------------------------
 * Estas pruebas validan el sistema desde afuera, simulando peticiones HTTP 
 * reales para verificar que las reglas de negocio (autenticación vía JWT 
 * y autorización por roles) se cumplen estrictamente en el servidor.
 * * NOTA — Manejo de Estado y JWT:
 * Al ser una API stateless basada en JWT, cada petición autenticada requiere
 * la inyección del token en el encabezado HTTP (Authorization: Bearer).
 * La limpieza de datos (tearDown) se encarga de eliminar vía API a los usuarios
 * de prueba generados dinámicamente para no dejar basura en la base de datos.
 *
 * Mapeo con la planilla QA (Seguridad):
 * - SEGURIDAD_001 -> testUsuarioComunEnPanelAdminRetorna403()
 * - SEGURIDAD_002 -> testAccesoCredencialSinAutenticarRetorna401()
 * * Requisitos cubiertos: RNF02 (Control de Acceso, Validación de Roles).
 */
class SecurityAccessTest extends TestCase {

    private string $baseUrl = "http://127.0.0.1:8000/index.php";
    
    /** @var int|null ID del usuario de testing creado temporalmente */
    private ?int $usuarioCreadoId = null;

    /**
     * Limpieza (TearDown): Se ejecuta automáticamente al finalizar cada test.
     * Si un test creó un usuario de QA dinámico, inicia sesión como admin y 
     * envía una petición DELETE para eliminarlo, manteniendo el entorno limpio.
     */
    protected function tearDown(): void {
        if ($this->usuarioCreadoId !== null) {
            $adminToken = $this->login('admin', '123456');
            if ($adminToken) {
                $ch = curl_init($this->baseUrl . "?action=user&id=" . $this->usuarioCreadoId);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $adminToken"]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
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
        
        $response = curl_exec($ch);
        if ($response === false) {
            $this->fail("Error de conexión al intentar login: " . curl_error($ch));
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
            $
