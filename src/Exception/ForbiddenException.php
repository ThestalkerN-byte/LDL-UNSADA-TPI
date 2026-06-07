<?php
declare(strict_types=1);

namespace ICB\Exception;

/**
 * FORBIDDEN EXCEPTION: Acceso denegado por permisos
 * ===================================================
 * Propósito: representar errores cuando el usuario está autenticado
 * pero NO tiene el rol o permiso suficiente para acceder al recurso.
 *
 * Flujo:
 *   Middleware de rol (o controller) verifica permisos → no alcanza
 *   → lanza ForbiddenException → GlobalExceptionHandler captura
 *   → devuelve JSON con status 403
 *
 * Relaciones:
 *   - GlobalExceptionHandler: lo captura y serializa
 *   - RoleMiddleware: lo lanza si el rol no tiene acceso
 *   - Controllers: lo lanzan para validaciones de pertenencia
 *
 * Decisión técnica:
 *   Diferencia clave con AuthException:
 *   - 401 (AuthException): no estás autenticado, no sabemos quién sos
 *   - 403 (ForbiddenException): estás autenticado pero no tenés acceso
 *
 *   Ejemplo concreto:
 *   - Token expirado → 401
 *   - Usuario logueado intenta borrar un post de otro usuario → 403
 */
class ForbiddenException extends \RuntimeException
{
    /* Código HTTP que se devuelve al cliente */
    private int $statusCode;

    /**
     * @param string $message    Mensaje legible para el cliente
     * @param int    $statusCode HTTP status (por defecto 403)
     */
    public function __construct(
        string $message = 'Acceso denegado',
        int $statusCode = 403
    ) {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }

    /**
     * Devuelve el código HTTP para la respuesta.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
