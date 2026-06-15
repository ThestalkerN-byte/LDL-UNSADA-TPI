<?php
namespace App\Service;

use App\DTO\CredentialDTO;

/**
 * Servicio encargado de determinar qué credenciales requieren alerta.
 * Se apoya en CredentialService, no accede a la base de datos.
 */
class AlertService {
    private CredentialService $credentialService;

    public function __construct(CredentialService $credentialService) {
        $this->credentialService = $credentialService;
    }

    /**
     * Reutiliza la lógica de CredentialService para saber si requiere alerta.
     */
    public function requiereAlerta(\DateTimeInterface $fechaVencimiento, int $dias = 30): bool {
        return $this->credentialService->requiereAlerta($fechaVencimiento, $dias);
    }

    /**
     * Filtra un array de credenciales y devuelve solo las que están próximas a vencer.
     */
    public function obtenerCredencialesPorVencer(array $credenciales, int $dias = 30): array {
        return array_filter($credenciales, function(CredentialDTO $cred) use ($dias) {
            return $this->requiereAlerta($cred->getFechaVencimiento(), $dias);
        });
    }
}