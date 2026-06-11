<?php
namespace App\Controller;

use App\Repository\MessageRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador REST para mensajes.
 * Maneja la comunicación entre usuario y administración.
 */
class MessageController {
    private MessageRepository $messageRepository;

    public function __construct(MessageRepository $messageRepository) {
        $this->messageRepository = $messageRepository;
    }

    /**
     * GET /messages
     * Devuelve todos los mensajes.
     */
    public function index(): JsonResponse {
        $mensajes = $this->messageRepository->findAll();
        return new JsonResponse($mensajes);
    }

    /**
     * POST /messages
     * Crea un nuevo mensaje.
     */
    public function create(Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        // Aquí se mapearía a la entidad Message y se persistiría con Doctrine
        return new JsonResponse(['message' => 'Mensaje creado'], 201);
    }
}