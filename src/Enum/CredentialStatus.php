<?php
namespace App\Enum;

/**
 * Enum que define los posibles estados de una credencial.
 * Forma parte de la capa Enum (tipos seguros).
 */
enum CredentialStatus: string {
    case ACTIVA = 'ACTIVA';   // Credencial vigente
    case VENCIDA = 'VENCIDA'; // Credencial vencida por fecha
    case INACTIVA = 'INACTIVA'; // Credencial deshabilitada manualmente
}