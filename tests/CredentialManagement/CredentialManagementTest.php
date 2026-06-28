<?php

use PHPUnit\Framework\TestCase;

/**
 * Pruebas del modulo CREDADMIN - Gestion de credenciales (Admin)
 *
 * Mapeo con la planilla QA:
 *
 *   - ADMIN_005 -> testModificacionCorrectaDeFechaYSellos()
 *   - ADMIN_006 -> testRenovacionDeCredencialVigente()
 *   - ADMIN_006 -> testRenovacionDeCredencialVencida()
 *
 * Requisito cubierto: RF09
 */
final class CredentialManagementTest extends TestCase
{
    private string $baseUrl = 'http://127.0.0.1:8000/index.php';

    private static ?string $adminToken = null;

    private array $usuariosCreados = [];
    private array $credencialesCreadas = [];

    protected function tearDown(): void
    {
        foreach (array_unique(array_reverse($this->credencialesCreadas)) as $id) {
            $this->requestJson(
                'DELETE',
                '?action=credential&id=' . $id,
                [],
                $this->authHeaders()
            );
        }

        foreach (array_unique(array_reverse($this->usuariosCreados)) as $id) {
            $this->requestJson(
                'DELETE',
                '?action=user&id=' . $id,
                [],
                $this->authHeaders()
            );
        }
    }

    // =====================================================================
    // ADMIN_005 - Modificacion correcta de fecha y sellos
    // =====================================================================
    public function testModificacionCorrectaDeFechaYSellos(): void
    {
        $usuario = $this->crearUsuarioDeTesting();

        $credencial = $this->crearCredencialDeTesting(
            $usuario['id'],
            (new DateTimeImmutable('today +30 days'))->format('Y-m-d'),
            ['Sello Inicial']
        );

        $nuevaFecha = (new DateTimeImmutable('today +180 days'))->format('Y-m-d');
        $nuevosSellos = ['Sello A', 'Sello B'];

        $response = $this->requestJson(
            'PUT',
            '?action=credential&id=' . $credencial['id'],
            [
                'fecha_vencimiento' => $nuevaFecha,
                'sellos' => $nuevosSellos,
            ],
            $this->authHeaders()
        );

        $this->assertSame(200, $response['status'], $response['body'] ?? '');
        $this->assertNotNull($response['json']);
        $this->assertSame('success', $response['json']['status']);

        $consulta = $this->consultarCredencial($credencial['id']);

        $this->assertSame(200, $consulta['status'], $consulta['body'] ?? '');
        $this->assertNotNull($consulta['json']);
        $this->assertSame('success', $consulta['json']['status']);
        $this->assertSame($nuevaFecha, $consulta['json']['data']['fecha_vencimiento']);

        if (isset($consulta['json']['data']['sellos'])) {
            $sellosNormalizados = $this->normalizarSellos($consulta['json']['data']['sellos']);
            $this->assertEqualsCanonicalizing($nuevosSellos, $sellosNormalizados);
        }
    }

    // =====================================================================
    // ADMIN_006 - Renovacion de credencial vigente
    // =====================================================================
    public function testRenovacionDeCredencialVigente(): void
    {
        $usuario = $this->crearUsuarioDeTesting();

        $fechaOriginal = (new DateTimeImmutable('today +60 days'))->format('Y-m-d');

        $credencial = $this->crearCredencialDeTesting(
            $usuario['id'],
            $fechaOriginal,
            ['Sello Vigente']
        );

        $response = $this->requestJson(
            'POST',
            '?action=credential&sub=renew&id=' . $credencial['id'],
            [],
            $this->authHeaders()
        );

        $this->assertSame(200, $response['status'], $response['body'] ?? '');
        $this->assertNotNull($response['json']);
        $this->assertSame('success', $response['json']['status']);

        $idRenovada = $this->obtenerIdCredencialRenovada($response, $credencial['id']);
        $this->credencialesCreadas[] = $idRenovada;

        $fechaRenovada = $this->obtenerFechaCredencialDesdeRespuestaOConsulta($response, $idRenovada);

        $this->assertNotEmpty($fechaRenovada, 'No se pudo obtener la fecha de vencimiento renovada.');

        $this->assertGreaterThan(
            strtotime($fechaOriginal),
            strtotime($fechaRenovada),
            'La credencial vigente renovada deberia tener una fecha posterior a la original.'
        );
    }

    // =====================================================================
    // ADMIN_006 - Renovacion de credencial vencida
    // =====================================================================
    public function testRenovacionDeCredencialVencida(): void
    {
        $usuario = $this->crearUsuarioDeTesting();

        $fechaVencida = (new DateTimeImmutable('today -60 days'))->format('Y-m-d');

        $credencial = $this->crearCredencialDeTesting(
            $usuario['id'],
            $fechaVencida,
            ['Sello Vencido']
        );

        $response = $this->requestJson(
            'POST',
            '?action=credential&sub=renew&id=' . $credencial['id'],
            [],
            $this->authHeaders()
        );

        $this->assertSame(200, $response['status'], $response['body'] ?? '');
        $this->assertNotNull($response['json']);
        $this->assertSame('success', $response['json']['status']);

        $idRenovada = $this->obtenerIdCredencialRenovada($response, $credencial['id']);
        $this->credencialesCreadas[] = $idRenovada;

        $fechaRenovada = $this->obtenerFechaCredencialDesdeRespuestaOConsulta($response, $idRenovada);

        $fechaEsperada = (new DateTimeImmutable('today +1 year'))->format('Y-m-d');

        $this->assertSame(
            $fechaEsperada,
            $fechaRenovada,
            'Una credencial vencida debe renovarse sumando un año desde la fecha actual.'
        );
    }

    private function crearUsuarioDeTesting(): array
    {
        $random = random_int(10000, 99999);

        $payload = [
            'usuario' => 'credadmin_test_' . $random,
            'password' => 'Test123456',
            'nombre' => 'Credencial',
            'apellido' => 'Admin',
            'dni' => (string) random_int(50000000, 59999999),
            'email' => 'credadmin_test_' . $random . '@mail.com',
            'rol' => 'usuario',
        ];

        $response = $this->requestJson(
            'POST',
            '?action=user',
            $payload,
            $this->authHeaders()
        );

        $this->assertSame(201, $response['status'], 'No se pudo crear el usuario de testing. ' . ($response['body'] ?? ''));
        $this->assertNotNull($response['json']);

        $id = $response['json']['data']['id'] ?? null;

        $this->assertNotNull($id, 'No se obtuvo el ID del usuario creado.');

        $this->usuariosCreados[] = $id;

        return [
            'id' => $id,
            'usuario' => $payload['usuario'],
            'email' => $payload['email'],
        ];
    }

    private function crearCredencialDeTesting(int|string $idUsuario, string $fechaVencimiento, array $sellos): array
    {
        $response = $this->requestJson(
            'POST',
            '?action=credential',
            [
                'id_usuario' => $idUsuario,
                'fecha_vencimiento' => $fechaVencimiento,
                'sellos' => $sellos,
            ],
            $this->authHeaders()
        );

        $this->assertContains(
            $response['status'],
            [200, 201],
            'No se pudo crear la credencial de testing. ' . ($response['body'] ?? '')
        );

        $this->assertNotNull($response['json']);

        $id = $response['json']['data']['id'] ?? null;

        $this->assertNotNull($id, 'No se obtuvo el ID de la credencial creada.');

        $this->credencialesCreadas[] = $id;

        return [
            'id' => $id,
            'fecha_vencimiento' => $fechaVencimiento,
            'sellos' => $sellos,
        ];
    }

    private function consultarCredencial(int|string $id): array
    {
        return $this->requestJson(
            'GET',
            '?action=credential&id=' . $id,
            [],
            $this->authHeaders()
        );
    }

    private function obtenerIdCredencialRenovada(array $response, int|string $idOriginal): int|string
    {
        $data = $response['json']['data'] ?? [];

        return $data['nueva_id']
            ?? $data['id_nueva']
            ?? $data['id']
            ?? $idOriginal;
    }

    private function obtenerFechaCredencialDesdeRespuestaOConsulta(array $response, int|string $idCredencial): string
    {
        $fecha = $response['json']['data']['fecha_vencimiento'] ?? null;

        if (!empty($fecha)) {
            return $fecha;
        }

        $consulta = $this->consultarCredencial($idCredencial);

        $this->assertSame(200, $consulta['status'], $consulta['body'] ?? '');
        $this->assertNotNull($consulta['json']);

        return $consulta['json']['data']['fecha_vencimiento'] ?? '';
    }

    private function normalizarSellos(array $sellos): array
    {
        return array_map(function ($sello) {
            if (is_array($sello)) {
                return $sello['nombre']
                    ?? $sello['name']
                    ?? $sello['descripcion']
                    ?? '';
            }

            return (string) $sello;
        }, $sellos);
    }

    private function authHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->getAdminToken(),
        ];
    }

    private function getAdminToken(): string
    {
        if (self::$adminToken !== null) {
            return self::$adminToken;
        }

        $response = $this->requestJson(
            'POST',
            '?action=login',
            [
                'identificador' => 'admin',
                'password' => '123456',
            ]
        );

        $this->assertSame(200, $response['status'], 'No se pudo iniciar sesion como admin. ' . ($response['body'] ?? ''));
        $this->assertNotNull($response['json']);

        $token = $response['json']['data']['token'] ?? null;

        $this->assertNotEmpty($token, 'No se obtuvo token JWT en el login admin.');

        self::$adminToken = $token;

        return self::$adminToken;
    }

    private function requestJson(
        string $method,
        string $query,
        array $payload = [],
        array $extraHeaders = []
    ): array {
        $ch = curl_init($this->baseUrl . $query);

        $headers = array_merge(
            ['Content-Type: application/json'],
            $extraHeaders
        );

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
        ];

        if (!empty($payload) || in_array($method, ['POST', 'PUT'], true)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $json = null;

        if (is_string($body) && $body !== '') {
            $json = json_decode($body, true);
        }

        return [
            'status' => $status,
            'body' => $body,
            'json' => $json,
            'error' => $error,
        ];
    }
}

