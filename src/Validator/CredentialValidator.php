<?php
namespace App\Validator;

use App\Exception\InvalidCredentialException;

/**
 * Validador de credenciales.
 * Se asegura de que los datos cumplan las reglas de negocio.
 */
class CredentialValidator {
    public function validarFechaVencimiento(?\DateTimeInterface $fecha): void {
        if ($fecha === null) {
            throw new InvalidCredentialException("La fecha de vencimiento no puede ser nula.");
        }
        if (!$fecha instanceof \DateTimeInterface) {
            throw new InvalidCredentialException("La fecha de vencimiento no es válida.");
        }
    }
}