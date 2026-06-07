<?php
declare(strict_types=1);

namespace ICB\Exception;

/**
 * VALIDATION EXCEPTION: Error de validación de datos
 * ====================================================
 * Propósito: representar errores cuando los datos de entrada no cumplen
 * las reglas de negocio (campos faltantes, formato inválido, duplicados).
 *
 * Flujo:
 *   Service o Repository valida datos → si falla lanza ValidationException
 *   → GlobalExceptionHandler lo captura → devuelve JSON con status 400
 *   y la lista de errores detallada.
 *
 * Relaciones:
 *   - GlobalExceptionHandler: serializa errores campo por campo
 *   - Services (UsuarioService, etc.): validan reglas de negocio
 *
 * Decisión técnica:
 *   El array $errors permite devolver errores por campo específico,
 *   siguiendo el formato que esperan clientes frontend.
 *   Ejemplo: ['email' => 'El email ya está registrado', 'password' => 'Muy corta']
 *
 * Diferencia con AuthException:
 *   AuthException → credenciales inválidas (401)
 *   ValidationException → datos mal formados (400)
 *   Ambos extienden RuntimeException pero ValidationException lleva
 *   errores estructurados para que el frontend los muestre campo por campo.
 */
class ValidationException extends \RuntimeException
{
    /* Código HTTP que se devuelve al cliente */
    private int $statusCode;

    /* Errores estructurados por campo */
    private array $errors;

    /**
     * @param string $message    Mensaje general del error
     * @param array  $errors     Array asociativo campo => mensaje
     * @param int    $statusCode HTTP status (por defecto 400)
     */
    public function __construct(
        string $message = 'Datos inválidos',
        array $errors = [],
        int $statusCode = 400
    ) {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
        $this->errors = $errors;
    }

    /**
     * Devuelve el código HTTP para la respuesta.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Devuelve los errores estructurados campo => mensaje.
     * El GlobalExceptionHandler incluye esto en la respuesta JSON
     * para que el frontend pueda mostrar errores específicos.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
