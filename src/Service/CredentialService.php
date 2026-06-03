<?php
namespace App\Service;

use App\Enum\CredentialStatus;

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
     * Renueva la credencial extendiendo la fecha de vencimiento.
     * Convierte siempre a DateTimeImmutable para poder usar add().
     */
    public function renovar(\DateTimeInterface $fechaActual, int $anios = 1): \DateTimeImmutable {
        $immutable = \DateTimeImmutable::createFromInterface($fechaActual);
        return $immutable->add(new \DateInterval("P{$anios}Y"));
    }
}