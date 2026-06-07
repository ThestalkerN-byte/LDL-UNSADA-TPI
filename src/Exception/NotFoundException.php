<?php
declare(strict_types=1);

namespace ICB\Exception;

/**
 * NOT FOUND EXCEPTION: Recurso no encontrado
 * ============================================
 * Propósito: representar errores cuando un recurso solicitado no existe
 * en la base de datos (ej: usuario con ID X, categoría con slug Y).
 *
 * Flujo:
 *   Repository busca entidad → no la encuentra → lanza NotFoundException
 *   → GlobalExceptionHandler lo captura → devuelve JSON con status 404
 *
 * Relaciones:
 *   - GlobalExceptionHandler: lo captura y serializa
 *   - Repositories (UsuarioRepository, etc.): lo lanzan al no encontrar
 *   - Controllers: también pueden lanzarlo si detectan recurso faltante
 *
 * Decisión técnica:
 *   Separada de Router porque no encontrar un recurso NO es lo mismo
 *   que no encontrar una ruta. Router devuelve 404 inline.
 *   NotFoundException es para lógica de negocio (ej: GET /api/usuarios/999).
 */
class NotFoundException extends \RuntimeException
{
    /* Código HTTP que se devuelve al cliente */
    private int $statusCode;

    /**
     * @param string $message    Mensaje legible para el cliente
     * @param int    $statusCode HTTP status (por defecto 404)
     */
    public function __construct(
        string $message = 'Recurso no encontrado',
        int $statusCode = 404
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
