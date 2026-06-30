<?php

declare(strict_types=1);

namespace App\Tests\AlertManagement;

use App\DTO\CredentialDTO;
use App\Enum\CredentialStatus;
use App\Service\AlertService;
use App\Service\CredentialService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de integración para alertas de vencimiento de credenciales.
 *
 * Mapeo con planilla QA:
 * - ALERTA_001 -> testRetornaCredencialesQueVencenEnMenosDeTreintaDias()
 * - ALERTA_002 -> testExcluyeCredencialesConMayorTiempoDeVigencia()
 *
 * Requisito cubierto: RF12
 */
#[CoversClass(AlertService::class)]
#[CoversClass(CredentialService::class)]
final class AlertManagementTest extends TestCase
{
    private AlertService $alertService;

    protected function setUp(): void
    {
        $credentialService = new CredentialService();
        $this->alertService = new AlertService($credentialService);
    }

    private function makeCredentialDTO(
        \DateTimeInterface $fechaVencimiento,
        int $id = 1
    ): CredentialDTO {
        return new CredentialDTO(
            $id,
            $id,
            'Juan',
            'Perez',
            '30111222',
            'socio',
            $fechaVencimiento,
            CredentialStatus::ACTIVA,
            ['APTO_MEDICO', 'CUOTA_AL_DIA']
        );
    }

    // -----------------------------------------------------------------
    // ALERTA_001 - Retorno de credenciales que vencen en menos de 30 días
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('ALERTA_001 - Retorna credenciales que vencen en menos de 30 días')]
    public function testRetornaCredencialesQueVencenEnMenosDeTreintaDias(): void
    {
        $credencialPorVencer = $this->makeCredentialDTO(new \DateTimeImmutable('+10 days'), 1);
        $credencialTambienPorVencer = $this->makeCredentialDTO(new \DateTimeImmutable('+29 days'), 2);

        $resultado = $this->alertService->obtenerCredencialesPorVencer([
            $credencialPorVencer,
            $credencialTambienPorVencer,
        ]);

        $this->assertCount(2, $resultado);
        $this->assertContains($credencialPorVencer, $resultado);
        $this->assertContains($credencialTambienPorVencer, $resultado);
    }

    // -----------------------------------------------------------------
    // ALERTA_002 - Exclusión de credenciales con mayor tiempo de vigencia
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('ALERTA_002 - Excluye credenciales con mayor tiempo de vigencia')]
    public function testExcluyeCredencialesConMayorTiempoDeVigencia(): void
    {
        $credencialPorVencer = $this->makeCredentialDTO(new \DateTimeImmutable('+15 days'), 1);
        $credencialMayorA30Dias = $this->makeCredentialDTO(new \DateTimeImmutable('+60 days'), 2);
        $credencialVencida = $this->makeCredentialDTO(new \DateTimeImmutable('-1 day'), 3);

        $resultado = $this->alertService->obtenerCredencialesPorVencer([
            $credencialPorVencer,
            $credencialMayorA30Dias,
            $credencialVencida,
        ]);

        $this->assertCount(1, $resultado);
        $this->assertContains($credencialPorVencer, $resultado);
        $this->assertNotContains($credencialMayorA30Dias, $resultado);
        $this->assertNotContains($credencialVencida, $resultado);
    }
}