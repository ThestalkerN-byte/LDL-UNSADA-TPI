<?php
namespace App\Controller;

use App\Repository\HistoryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador REST para historial de acciones.
 * Registra y devuelve las acciones realizadas por administradores.
 */
class HistoryController {
    private HistoryRepository $historyRepository;

    public function __construct(HistoryRepository $historyRepository) {
        $this->historyRepository = $historyRepository;
    }

    /**
     * GET /history
     * Devuelve todas las acciones registradas.
     */
    public function index(): JsonResponse {
        $historial = $this->historyRepository->findAll();
        return new JsonResponse($historial);
    }

    /**
     * POST /history
     * Registra una nueva acción.
     */
    public function create(Request $request): JsonResponse {
        $data = json_decode($request->getContent(), true);
        // Aquí se mapearía a la entidad History y se persistiría con Doctrine
        return new JsonResponse(['message' => 'Acción registrada'], 201);
    }
}