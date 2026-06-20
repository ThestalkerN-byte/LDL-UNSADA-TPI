<?php
namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Entity\History;
use App\Security\UserContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para mensajería interna y consultas.
 *
 * Mapea:
 *   GET    ?action=message                → index()   Lista mensajes (filtrado por rol)
 *   POST   ?action=message                → create()  Crear una nueva consulta (usuario)
 *   PUT    ?action=message&id={id}        → reply()   Responder consulta (administrador)
 *   POST   ?action=message&sub=reply&id={id} → reply() Responder consulta alternativa
 */
class MessageController {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
    }

    /**
     * Punto de entrada principal.
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $sub    = $_GET['sub'] ?? null;
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($method === 'POST' && $sub === 'reply' && $id !== null) {
            $this->reply($id);
            return;
        }

        match ($method) {
            'GET'  => $this->index(),
            'POST' => $this->create(),
            'PUT'  => $id ? $this->reply($id) : $this->responder(400, 'error', 'Se requiere un ID para responder.'),
            default=> $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    /**
     * GET ?action=message
     * Devuelve los mensajes. Si el usuario es administrador ve todos; si es usuario regular, ve solo los propios.
     *
     * MIGRACIÓN JWT: ahora usa UserContext en vez de $_SESSION.
     * El AuthMiddleware setea UserContext antes de llegar al controlador.
     */
    private function index(): void {
        $userId = UserContext::getId() ?? $_GET['id_usuario'] ?? null;
        $rol    = UserContext::getRol() ?? $_GET['rol'] ?? null;

        $messageRepo = $this->em->getRepository(Message::class);

        if ($rol === 'admin') {
            $mensajes = $messageRepo->findAll();
        } else {
            if ($userId) {
                $user = $this->em->getRepository(User::class)->find((int)$userId);
                if ($user) {
                    $mensajes = $messageRepo->findByUser($user);
                } else {
                    $mensajes = [];
                }
            } else {
                $mensajes = [];
            }
        }

        $data = array_map(function(Message $m) {
            return $this->serializeMessage($m);
        }, $mensajes);

        $this->responder(200, 'success', 'Mensajes obtenidos correctamente.', $data);
    }

    /**
     * POST ?action=message
     * Permite a un usuario registrar una nueva consulta (RF07 / CU4).
     *
     * MIGRACIÓN JWT: ahora usa UserContext en vez de $_SESSION.
     */
    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['contenido'])) {
            $this->responder(400, 'error', 'El contenido de la consulta es obligatorio.');
            return;
        }

        // Buscar el usuario emisor desde el JWT (UserContext)
        $userId = UserContext::getId() ?? $data['id_usuario'] ?? null;
        if (!$userId) {
            $this->responder(400, 'error', 'No se ha detectado sesión de usuario activa.');
            return;
        }

        $user = $this->em->getRepository(User::class)->find((int)$userId);
        if (!$user || !$user->isEstado()) {
            $this->responder(404, 'error', 'Usuario remitente no encontrado o inactivo.');
            return;
        }

        $message = new Message();
        $message->setUser($user);
        $message->setContenido(trim($data['contenido']));
        $message->setFecha(new \DateTime());
        $message->setEstado('Pendiente');

        $this->em->persist($message);
        $this->em->flush();

        $this->responder(201, 'success', 'Consulta enviada correctamente.', $this->serializeMessage($message));
    }

    /**
     * PUT ?action=message&id={id}
     * Permite al administrador responder una consulta (RF11 / CU4).
     */
    private function reply(int $id): void {
        $message = $this->em->getRepository(Message::class)->find($id);

        if (!$message) {
            $this->responder(404, 'error', 'Consulta no encontrada.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['respuesta'])) {
            $this->responder(400, 'error', 'La respuesta no puede estar vacía.');
            return;
        }

        $message->setRespuesta(trim($data['respuesta']));
        $message->setFechaRespuesta(new \DateTime());
        $message->setEstado('Respondido');

        // Registrar en Historial de Auditoría
        $this->registrarHistorial("Consulta respondida (ID: " . $message->getId() . ") para el usuario: " . $message->getUser()->getUsuario());

        $this->em->flush();

        $this->responder(200, 'success', 'Consulta respondida exitosamente.', $this->serializeMessage($message));
    }

    /**
     * Helper para registrar auditoría.
     *
     * MIGRACIÓN JWT: ahora usa UserContext en vez de $_SESSION.
     */
    private function registrarHistorial(string $accion): void {
        $adminId = UserContext::getId() ?? $_GET['admin_id'] ?? null;
        $admin = null;
        if ($adminId) {
            $admin = $this->em->getRepository(User::class)->find((int)$adminId);
        }
        if (!$admin) {
            $admin = $this->em->getRepository(User::class)->findOneBy(['rol' => 'admin']);
        }

        if ($admin) {
            $historial = new History();
            $historial->setAccion($accion);
            $historial->setFecha(new \DateTime());
            $historial->setAdmin($admin);
            $this->em->persist($historial);
        }
    }

    /**
     * Serializa la entidad Message a un formato JSON friendly.
     */
    private function serializeMessage(Message $m): array {
        return [
            'id'              => $m->getId(),
            'id_usuario'      => $m->getUser()->getId(),
            'usuario_nombre'  => $m->getUser()->getNombre() . ' ' . $m->getUser()->getApellido(),
            'usuario_dni'     => $m->getUser()->getDni(),
            'contenido'       => $m->getContenido(),
            'fecha'           => $m->getFecha()->format('Y-m-d H:i:s'),
            'respuesta'       => $m->getRespuesta(),
            'fecha_respuesta' => $m->getFechaRespuesta() ? $m->getFechaRespuesta()->format('Y-m-d H:i:s') : null,
            'estado'          => $m->getEstado(),
        ];
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