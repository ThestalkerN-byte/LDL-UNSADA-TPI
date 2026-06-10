<?php
namespace App\Controller;

use App\Entity\History;
use App\Entity\User;
use App\Repository\HistoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para historial de acciones.
 * Registra y devuelve las acciones realizadas por administradores.
 */
class HistoryController {
    private EntityManagerInterface $em;
    private HistoryRepository $historyRepository;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        $this->historyRepository = $em->getRepository(History::class);
    }

    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        match ($method) {
            'GET'    => $id ? $this->show($id) : $this->index(),
            'POST'   => $this->create(),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido. Solo GET o POST.'),
        };
    }

    private function index(): void {
        $historial = $this->historyRepository->findAll();
        $data = array_map(fn(History $h) => $this->serializeHistory($h), $historial);
        $this->responder(200, 'success', 'Historial obtenido.', $data);
    }

    private function show(int $id): void {
        $registro = $this->historyRepository->find($id);
        if (!$registro) {
            $this->responder(404, 'error', 'Registro no encontrado.');
            return;
        }
        $this->responder(200, 'success', 'Registro obtenido.', $this->serializeHistory($registro));
    }

    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['id_admin']) || empty($data['accion'])) {
            $this->responder(400, 'error', 'Faltan campos obligatorios: id_admin y accion.');
            return;
        }

        $admin = $this->em->getRepository(User::class)->find((int)$data['id_admin']);
        if (!$admin || !$admin->isEstado() === false) {
            $this->responder(404, 'error', 'Administrador no encontrado.');
            return;
        }

        $registro = new History();
        $registro->setAdmin($admin);
        $registro->setAccion($data['accion']);
        $registro->setFecha(new \DateTimeImmutable());

        $this->em->persist($registro);
        $this->em->flush();

        $this->responder(201, 'success', 'Acción registrada.', $this->serializeHistory($registro));
    }

    private function serializeHistory(History $registro): array {
        return [
            'id' => $registro->getId(),
            'id_admin' => $registro->getAdmin()->getId(),
            'nombre_admin' => $registro->getAdmin()->getNombre() . ' ' . $registro->getAdmin()->getApellido(),
            'accion' => $registro->getAccion(),
            'fecha' => $registro->getFecha()->format('Y-m-d H:i:s')
        ];
    }

    private function responder(int $httpCode, string $status, string $message, array $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}