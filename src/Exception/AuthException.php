<?php

declare(strict_types=1);

namespace App\Exception;

/*
 * AUTH EXCEPTION: Error de autenticación
 * ========================================
 * Propósito: representar errores de login inválido, token expirado,
 * o cualquier fallo de autenticación que deba responder con HTTP 401.
 *
 * Flujo:
 *   AuthService lanza AuthException cuando las credenciales fallan
 *   → AuthController lo captura → devuelve JSON con status error
 *
 * Relaciones:
 *   - AuthController: lo captura y serializa en respuesta JSON
 *   - AuthService: lo lanza en login, refresh y validateToken
 *   - AuthMiddleware: captura errores de validateToken indirectamente
 *
 * Decisión técnica:
 *   Extiende RuntimeException porque son errores en tiempo de ejecución
 *   que NO deberían requerir declaración (unchecked).
 *   No extiende Exception (la checked) porque PHP no obliga a catch.
 */
class AuthException extends \RuntimeException
{
    /** Código HTTP que se devuelve al cliente */
    private int $statusCode;

    /**
     * @param string $message    Mensaje legible para el cliente
     * @param int    $statusCode HTTP status (por defecto 401)
     */
    public function __construct(
        string $message = 'No autenticado',
        int $statusCode = 401
    ) {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }

    /**
     * Devuelve el código HTTP para la respuesta.
     * Lo usa AuthController para setear http_response_code().
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
