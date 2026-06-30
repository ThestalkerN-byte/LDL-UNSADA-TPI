<?php

declare(strict_types=1);

namespace App\Tests\CredentialDisplay;

use App\DTO\CredentialDTO;
use App\Entity\Credential;
use App\Entity\User;
use App\Enum\CredentialStatus;
use App\Service\CredentialService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de visualización de credencial digital.
 *
 * Mapeo con planilla QA:
 * - CREDENCIAL_001 -> testObtencionCredencialActiva()
 * - CREDENCIAL_002 -> testCredencialVencidaOcultaInformacionSensible()
 * - CREDENCIAL_003 -> testFechaVencimientoIgualAlDiaActual()
 * - CREDENCIAL_004 -> testValidacionDatosDelUsuario()
 * - CREDENCIAL_005 -> testValidacionSellosActivos()
 *
 * Requisitos cubiertos: RF04, RF05, RF06, RF13, RF14
 */
#[CoversClass(CredentialService::class)]
#[CoversClass(CredentialDTO::class)]
final class CredentialDisplayTest extends TestCase
{
    private CredentialService $credentialService;

    protected function setUp(): void
    {
        $this->credentialService = new CredentialService();
    }

    private function makeUser(int $id = 1): User
    {
        $user = new User();
        $user->setUsuario('jperez');
        $user->setPassword('123456');
        $user->setNombre('Juan');
        $user->setApellido('Perez');
        $user->setDni('30111222');
        $user->setEmail('jperez@example.com');
        $user->setRol('socio');
        $user->setEstado(true);

        $this->setPrivateId($user, User::class, $id);

        return $user;
    }

    private function makeCredential(
        \DateTimeInterface $fechaVencimiento,
        ?array $sellos = ['APTO_MEDICO', 'CUOTA_AL_DIA'],
        int $id = 1
    ): Credential {
        $credential = new Credential();
        $credential->setUsuario($this->makeUser());
        $credential->setFechaEmision(new \DateTimeImmutable('today'));
        $credential->setFechaVencimiento($fechaVencimiento);
        $credential->setSellos($sellos);
        $credential->setEsActiva(true);

        $this->setPrivateId($credential, Credential::class, $id);

        return $credential;
    }

    private function setPrivateId(object $object, string $class, int $id): void
    {
        $reflection = new \ReflectionProperty($class, 'id');
        $reflection->setValue($object, $id);
    }

    // -----------------------------------------------------------------
    // CREDENCIAL_001 - Obtención de credencial activa
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('CREDENCIAL_001 - Obtención de credencial activa')]
    public function testObtencionCredencialActiva(): void
    {
        $credential = $this->makeCredential(new \DateTimeImmutable('+1 year'));

        $dto = $this->credentialService->mapToDTO($credential);

        $this->assertSame(1, $dto->getId());
        $this->assertSame(CredentialStatus::ACTIVA, $dto->getEstado());
        $this->assertSame('30111222', $dto->getDni());
        $this->assertSame(['APTO_MEDICO', 'CUOTA_AL_DIA'], $dto->getSellos());
    }

    // -----------------------------------------------------------------
    // CREDENCIAL_002 - Credencial vencida y ocultamiento de información sensible
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('CREDENCIAL_002 - Credencial vencida oculta información sensible')]
    public function testCredencialVencidaOcultaInformacionSensible(): void
    {
        $credential = $this->makeCredential(new \DateTimeImmutable('-1 day'));

        $dto = $this->credentialService->mapToDTO($credential);

        $this->assertSame(CredentialStatus::VENCIDA, $dto->getEstado());
        $this->assertNull($dto->getDni());
        $this->assertNull($dto->getSellos());
    }

    // -----------------------------------------------------------------
    // CREDENCIAL_003 - Fecha de vencimiento igual al día actual
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('CREDENCIAL_003 - Fecha de vencimiento igual al día actual')]
    public function testFechaVencimientoIgualAlDiaActual(): void
    {
        $fechaHoy = new \DateTimeImmutable('today 23:59:59');

        $estado = $this->credentialService->calcularEstado($fechaHoy);

        $this->assertSame(CredentialStatus::ACTIVA, $estado);
        $this->assertFalse($this->credentialService->estaVencida($fechaHoy));
    }

    // -----------------------------------------------------------------
    // CREDENCIAL_004 - Validación de datos del usuario
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('CREDENCIAL_004 - Validación de datos del usuario')]
    public function testValidacionDatosDelUsuario(): void
    {
        $credential = $this->makeCredential(new \DateTimeImmutable('+1 year'));

        $dto = $this->credentialService->mapToDTO($credential);

        $this->assertSame(1, $dto->getIdUsuario());
        $this->assertSame('Juan', $dto->getNombre());
        $this->assertSame('Perez', $dto->getApellido());
        $this->assertSame('socio', $dto->getRol());
    }

    // -----------------------------------------------------------------
    // CREDENCIAL_005 - Validación de sellos activos
    // -----------------------------------------------------------------
    #[Test]
    #[TestDox('CREDENCIAL_005 - Validación de sellos activos')]
    public function testValidacionSellosActivos(): void
    {
        $sellos = ['APTO_MEDICO', 'CUOTA_AL_DIA', 'ACCESO_HABILITADO'];

        $credential = $this->makeCredential(new \DateTimeImmutable('+1 year'), $sellos);

        $dto = $this->credentialService->mapToDTO($credential);

        $this->assertSame($sellos, $dto->getSellos());
        $this->assertContains('APTO_MEDICO', $dto->getSellos());
        $this->assertContains('CUOTA_AL_DIA', $dto->getSellos());
        $this->assertContains('ACCESO_HABILITADO', $dto->getSellos());
    }
}