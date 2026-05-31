<?php
namespace App\Exception;

/**
 * Excepción lanzada cuando se intenta usar una credencial vencida.
 */
class CredentialExpiredException extends \RuntimeException {}