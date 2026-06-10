<?php
namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para mensajes.
 * Maneja la comunicación entre usuario y administración.
 */
class MessageController {
    private EntityManagerInterface $em;
    private MessageRepository $messageRepository;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        $this->messageRepository = $em->getRepository(Message::class);
    }

    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        match ($method) {
            'GET'    => $id ? $this->show($id) : $this->index(),
            'POST'   => $id ? $this->responder(405, 'error', 'Use PUT para responder a un mensaje') : $this->create(),
            'PUT'    => $id ? $this->update($id) : $this->responder(400, 'error', 'Se requiere un ID para actualizar.'),
            'DELETE' => $this->responder(405, 'error', 'No se permite eliminar mensajes.'),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    private function index(): void {
        $mensajes = $this->messageRepository->findAll();
        $data = array_map(fn(Message $m) => $this->serializeMessage($m), $mensajes);
        $this->responder(200, 'success', 'Mensajes listados.', $data);
    }

    private function show(int $id): void {
        $mensaje = $this->messageRepository->find($id);
        if (!$mensaje) {
            $this->responder(404, 'error', 'Mensaje no encontrado.');
            return;
        }
        $this->responder(200, 'success', 'Mensaje encontrado.', $this->serializeMessage($mensaje));
    }

    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['id_usuario']) || empty($data['contenido'])) {
            $this->responder(400, 'error', 'Faltan campos obligatorios: id_usuario y contenido.');
            return;
        }

        $usuario = $this->em->getRepository(User::class)->find((int)$data['id_usuario']);
        if (!$usuario) {
            $this->responder(404, 'error', 'Usuario no encontrado.');
            return;
        }

        $mensaje = new Message();
        $mensaje->setUser($usuario);
        $mensaje->setContenido($data['contenido']);
        $mensaje->setFecha(new \DateTimeImmutable());
        $mensaje->setEstado('Pendiente');

        $this->em->persist($mensaje);
        $this->em->flush();

        $this->responder(201, 'success', 'Mensaje creado.', $this->serializeMessage($mensaje));
    }

    private function update(int $id): void {
        // En este contexto, actualizar es "Responder" un mensaje por el administrador
        $mensaje = $this->messageRepository->find($id);
        if (!$mensaje) {
            $this->responder(404, 'error', 'Mensaje no encontrado.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['respuesta'])) {
            $this->responder(400, 'error', 'Debe enviar el contenido de la respuesta.');
            return;
        }

        $mensaje->setRespuesta($data['respuesta']);
        $mensaje->setFechaRespuesta(new \DateTimeImmutable());
        $mensaje->setEstado('Respondido');

        $this->em->flush();
        $this->responder(200, 'success', 'Mensaje respondido.', $this->serializeMessage($mensaje));
    }

    private function serializeMessage(Message $mensaje): array {
        return [
            'id' => $mensaje->getId(),
            'id_usuario' => $mensaje->getUser()->getId(),
            'nombre_usuario' => $mensaje->getUser()->getNombre() . ' ' . $mensaje->getUser()->getApellido(),
            'contenido' => $mensaje->getContenido(),
            'fecha' => $mensaje->getFecha()->format('Y-m-d H:i:s'),
            'respuesta' => $mensaje->getRespuesta(),
            'fecha_respuesta' => $mensaje->getFechaRespuesta() ? $mensaje->getFechaRespuesta()->format('Y-m-d H:i:s') : null,
            'estado' => $mensaje->getEstado()
        ];
    }

    private function responder(int $httpCode, string $status, string $message, array $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}