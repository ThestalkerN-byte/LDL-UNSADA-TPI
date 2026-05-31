<?php
namespace App\Controller;

use App\Service\CredentialService;
use App\Service\AlertService;
use App\Repository\CredentialRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controlador REST para credenciales.
 * Recibe las peticiones HTTP, llama a los servicios y devuelve JSON.
 */
class CredentialController {
    private CredentialService $credentialService;
    private AlertService $alertService;
    private CredentialRepository $credentialRepository;

    public function __construct(
        CredentialService $credentialService,
        AlertService $alertService,
        CredentialRepository $credentialRepository
    ) {
        $this->credentialService = $credentialService;
        $this->alertService = $alertService;
        $this->credentialRepository = $credentialRepository;
    }

    /**
     * GET /credentials
     * Devuelve todas las credenciales.
     */
    public function index(): JsonResponse {
        $credenciales = $this->credentialRepository->findAll();
        return new JsonResponse($credenciales);
    }

    /**
     * GET /credentials/alerts
     * Devuelve credenciales próximas a vencer.
     */
    public function alerts(): JsonResponse {
        $credenciales = $this->credentialRepository->findAll();
        $porVencer = $this->alertService->obtenerCredencialesPorVencer($credenciales);
        return new JsonResponse($porVencer);
    }

    /**
     * POST /credentials/renew/{id}
     * Renueva la credencial indicada.
     */
    public function renew(Request $request, int $id): JsonResponse {
        $credencial = $this->credentialRepository->find($id);
        if (!$credencial) {
            return new JsonResponse(['error' => 'Credencial no encontrada'], 404);
        }
        $nuevaFecha = $this->credentialService->renovar($credencial->getFechaVencimiento());
        $credencial->setFechaVencimiento($nuevaFecha);
        $this->credentialRepository->getEntityManager()->flush();

        return new JsonResponse(['message' => 'Credencial renovada', 'nuevaFecha' => $nuevaFecha->format('Y-m-d')]);
    }
}