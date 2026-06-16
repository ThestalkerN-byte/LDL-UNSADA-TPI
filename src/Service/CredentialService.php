<?php
namespace App\Service;

use App\Enum\CredentialStatus;
use App\Entity\Credential;
use App\DTO\CredentialDTO;

/**
 * Servicio de negocio para credenciales.
 * Aquí va la lógica de negocio, nunca acceso a base de datos.
 */
class CredentialService {

    /**
     * Calcula el estado de la credencial según la fecha de vencimiento.
     */
    public function calcularEstado(\DateTimeInterface $fechaVencimiento): CredentialStatus {
        $hoy = new \DateTimeImmutable();
        if ($fechaVencimiento < $hoy) {
            return CredentialStatus::VENCIDA;
        }
        return CredentialStatus::ACTIVA;
    }

    /**
     * Determina si la credencial está vencida.
     */
    public function estaVencida(\DateTimeInterface $fechaVencimiento): bool {
        return $fechaVencimiento < new \DateTimeImmutable();
    }

    /**
     * Calcula cuántos días faltan para el vencimiento.
     */
    public function diasParaVencer(\DateTimeInterface $fechaVencimiento): int {
        $hoy = new \DateTimeImmutable();
        return (int)$hoy->diff($fechaVencimiento)->days;
    }

    /**
     * Determina si la credencial requiere alerta (por defecto 30 días antes).
     */
    public function requiereAlerta(\DateTimeInterface $fechaVencimiento, int $dias = 30): bool {
        return !$this->estaVencida($fechaVencimiento) 
            && $this->diasParaVencer($fechaVencimiento) <= $dias;
    }

    /**
     * Renueva la credencial extendiendo la fecha de vencimiento según la regla de negocio.
     */
    public function renovar(\DateTimeInterface $fechaActual, int $anios = 1): \DateTime {
        $hoy = new \DateTime('today');
        
        // Si ya venció, sumamos desde hoy. Si no, extendemos desde su vencimiento previo.
        $baseParaCalculo = ($fechaActual < $hoy) ? $hoy : \DateTime::createFromInterface($fechaActual);
        return $baseParaCalculo->add(new \DateInterval("P{$anios}Y"));
    }

    /**
     * Mapea una entidad Credential a un CredentialDTO aplicando las reglas de privacidad (CU2).
     */
    public function mapToDTO(Credential $credencial): CredentialDTO {
        $user = $credencial->getUsuario();
        $fechaVencimiento = $credencial->getFechaVencimiento();
        $estado = $this->calcularEstado($fechaVencimiento);
        $estaVencida = ($estado === CredentialStatus::VENCIDA);

        return new CredentialDTO(
            $credencial->getId(),
            $user->getId(),
            $user->getNombre(),
            $user->getApellido(),
            $estaVencida ? null : $user->getDni(),
            $user->getRol(),
            $fechaVencimiento,
            $estado,
            $estaVencida ? null : $credencial->getSellos()
        );
    }
}