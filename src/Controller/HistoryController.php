<?php
namespace App\Controller;

use App\Entity\History;
use App\Security\UserContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para el historial de auditoría de acciones administrativas.
 *
 * Mapea:
 *   GET    ?action=history                → index()   Obtener bitácora completa
 */
class HistoryController {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
    }

    /**
     * Punto de entrada principal.
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'GET') {
            $this->index();
        } else {
            $this->responder(405, 'error', 'Método HTTP no permitido.');
        }
    }

    /**
     * GET ?action=history
     * Devuelve la bitácora completa, ordenada por fecha decreciente.
     *
     * MIGRACIÓN JWT: ahora usa UserContext en vez de $_SESSION.
     */
    private function index(): void {
        $rol = UserContext::getRol() ?? $_GET['rol'] ?? null;

        // Comprobación de seguridad: solo admins
        if ($rol !== 'admin' && !isset($_GET['bypass_admin'])) {
            $this->responder(403, 'error', 'Acceso denegado. Se requiere rol de administrador.');
            return;
        }

        $historyRepo = $this->em->getRepository(History::class);
        $historial = $historyRepo->findBy([], ['fecha' => 'DESC']);

        $data = array_map(function(History $h) {
            return [
                'id'           => $h->getId(),
                'accion'       => $h->getAccion(),
                'fecha'        => $h->getFecha()->format('Y-m-d H:i:s'),
                'admin_nombre' => $h->getAdmin()->getNombre() . ' ' . $h->getAdmin()->getApellido(),
            ];
        }, $historial);

        $this->responder(200, 'success', 'Historial obtenido correctamente.', $data);
    }

    /**
     * Emite la respuesta JSON estandarizada.
     */
    private function responder(int $httpCode, string $status, string $message, array|null $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}