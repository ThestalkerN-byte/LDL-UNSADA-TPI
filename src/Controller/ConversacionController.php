<?php
declare(strict_types=1);
namespace ICB\Controller;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Exception\ForbiddenException;
use ICB\Exception\NotFoundException;
use ICB\Exception\ValidationException;
use ICB\Request\Request;
use ICB\Service\ConversacionService;
use ICB\Validation\ValidationHelper;

/*
 * CONVERSACION CONTROLLER: Endpoints de conversaciones para usuarios
 * ===================================================================
 * Todos requieren autenticación (AuthMiddleware::autenticado).
 * El usuario autenticado se obtiene de $request->getAttribute('usuario').
 *
 * ENDPOINTS:
 *   GET  /api/conversaciones                → Listar mis consultas
 *   POST /api/conversaciones                → Crear nueva consulta
 *   GET  /api/conversaciones/{id}/mensajes  → Ver mensajes de mi consulta
 *   POST /api/conversaciones/{id}/mensajes  → Enviar mensaje en mi consulta
 *
 * MIDDLEWARE:
 *   Todos requieren AuthMiddleware::autenticado() (no admin, cualquier rol sirve)
 */
class ConversacionController
{
    private ConversacionService $conversacionService;

    public function __construct(EntityManagerInterface $em)
    {
        $this->conversacionService = new ConversacionService($em);
    }

    /*
     * GET /api/conversaciones
     * Lista las conversaciones del usuario autenticado.
     * Cada usuario solo ve las suyas.
     */
    public function listar(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');
        $conversaciones = $this->conversacionService->listarMisConversaciones($usuario);
        return ['data' => $conversaciones, 'total' => count($conversaciones)];
    }

    /*
     * POST /api/conversaciones
     * Crea una nueva conversación con el primer mensaje.
     * Body: { "contenido": "..." }
     */
    public function crear(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');
        $contenido = $request->body('contenido');

        if (!$contenido) {
            return ['error' => 'El contenido del mensaje es requerido', 'code' => 400];
        }

        $errorContenido = ValidationHelper::maxLength('contenido', $contenido, 5000);
        if ($errorContenido) {
            return ['error' => $errorContenido, 'code' => 400];
        }

        $this->conversacionService->crear($usuario, $contenido);
        
        // Devolver la lista actualizada
        $conversaciones = $this->conversacionService->listarMisConversaciones($usuario);
        return [
            'data'    => $conversaciones,
            'message' => 'Consulta creada exitosamente',
        ];
    }

    /*
     * GET /api/conversaciones/{id}/mensajes
     * Obtiene los mensajes de una conversación específica.
     * Verifica que el usuario sea el dueño (ownership check en Service).
     */
    public function mensajes(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $mensajes = $this->conversacionService->obtenerMensajes(
                (int)$params['id'], $usuario, false
            );
            return ['data' => $mensajes, 'total' => count($mensajes)];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        } catch (ForbiddenException $e) {
            return ['error' => $e->getMessage(), 'code' => 403];
        }
    }

    /*
     * POST /api/conversaciones/{id}/mensajes
     * Agrega un mensaje a una conversación existente.
     * Body: { "contenido": "..." }
     * No se puede mensajear en conversaciones cerradas.
     */
    public function enviarMensaje(Request $request, array $params): array
    {
        $usuario = $request->getAttribute('usuario');
        $contenido = $request->body('contenido');

        if (!$contenido) {
            return ['error' => 'El contenido del mensaje es requerido', 'code' => 400];
        }

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
                ValidationHelper::maxLength('contenido', $contenido, 5000),
            ]);
            $this->conversacionService->agregarMensaje((int)$params['id'], $usuario, $contenido);
            
            // Devolver mensajes actualizados
            $mensajes = $this->conversacionService->obtenerMensajes(
                (int)$params['id'], $usuario, false
            );
            return ['data' => $mensajes, 'message' => 'Mensaje enviado'];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        } catch (ForbiddenException $e) {
            return ['error' => $e->getMessage(), 'code' => 403];
        }
    }
}
