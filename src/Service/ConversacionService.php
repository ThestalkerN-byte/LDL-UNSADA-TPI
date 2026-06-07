<?php
declare(strict_types=1);
namespace ICB\Service;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Conversacion;
use ICB\Entity\Mensaje;
use ICB\Entity\Usuario;
use ICB\Exception\ForbiddenException;
use ICB\Exception\NotFoundException;
use ICB\Exception\ValidationException;

/*
 * CONVERSACION SERVICE: Lógica de consultas y mensajería
 * =======================================================
 * Tanto usuarios como administradores pueden participar en conversaciones.
 * Un usuario crea una consulta (conversación + primer mensaje) y tanto él
 * como los admins pueden agregar mensajes.
 *
 * REGLAS DE NEGOCIO:
 *   - Un usuario solo ve SUS propias conversaciones (ownership check)
 *   - Un admin ve TODAS las conversaciones
 *   - No se pueden agregar mensajes a una conversación cerrada
 *   - Una conversación se crea SIEMPRE con un primer mensaje (no existe
 *     conversación vacía)
 *   - Al cerrar una conversación, ya no se pueden agregar más mensajes
 *
 * FLUJO TÍPICO:
 *   1. Usuario crea consulta → POST /api/conversaciones { contenido }
 *   2. Admin/s ven la conversación en la bandeja
 *   3. Admin responde → POST /api/admin/conversaciones/{id}/mensajes
 *   4. Usuario responde → POST /api/conversaciones/{id}/mensajes
 *   5. Admin cierra → POST /api/admin/conversaciones/{id}/cerrar
 */
class ConversacionService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * LISTAR MIS CONVERSACIONES: Devuelve las conversaciones del usuario.
     * Cada usuario solo ve las suyas (filtro en el repository).
     */
    public function listarMisConversaciones(Usuario $usuario): array
    {
        $conversaciones = $this->em->getRepository(Conversacion::class)->findPorUsuario($usuario);
        return array_map(fn(Conversacion $c) => $this->serializarConversacion($c), $conversaciones);
    }

    /*
     * CREAR: Crea una nueva conversación con un primer mensaje.
     * No existen conversaciones vacías — el contenido es obligatorio.
     */
    public function crear(Usuario $usuario, string $contenido): Conversacion
    {
        $conversacion = new Conversacion();
        $conversacion->setUsuario($usuario);
        $this->em->persist($conversacion);

        // Crear el primer mensaje automáticamente
        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion)
                ->setEmisor($usuario)
                ->setContenido(htmlspecialchars($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->em->persist($mensaje);

        $this->em->flush();

        return $conversacion;
    }

    /*
     * OBTENER MENSAJES: Devuelve los mensajes de una conversación.
     * Si NO es admin, verifica que el usuario sea el dueño (ownership check).
     *
     * @throws NotFoundException  si la conversación no existe
     * @throws ForbiddenException si el usuario no es el dueño
     */
    public function obtenerMensajes(int $idConversacion, Usuario $usuario, bool $esAdmin = false): array
    {
        $conversacion = $this->obtener($idConversacion);

        // Ownership check: si no es admin, solo el dueño puede ver
        if (!$esAdmin && $conversacion->getUsuario()->getIdUsuario() !== $usuario->getIdUsuario()) {
            throw new ForbiddenException('No tenés acceso a esta conversación');
        }

        $mensajes = $conversacion->getMensajes()->toArray();
        return array_map(fn(Mensaje $m) => $this->serializarMensaje($m), $mensajes);
    }

    /*
     * AGREGAR MENSAJE: Agrega un mensaje a una conversación existente.
     * Valida que la conversación esté abierta.
     *
     * @throws ValidationException si la conversación está cerrada
     */
    public function agregarMensaje(int $idConversacion, Usuario $emisor, string $contenido): Mensaje
    {
        $conversacion = $this->obtener($idConversacion);

        if (!$conversacion->estaAbierta()) {
            throw new ValidationException('La conversación está cerrada');
        }

        $mensaje = new Mensaje();
        $mensaje->setConversacion($conversacion)
                ->setEmisor($emisor)
                ->setContenido(htmlspecialchars($contenido, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->em->persist($mensaje);
        $this->em->flush();

        return $mensaje;
    }

    /*
     * LISTAR TODAS (admin): Devuelve todas las conversaciones del sistema.
     * Opcionalmente filtra por estado (Abierta/Cerrada).
     */
    public function listarTodas(?string $estado = null): array
    {
        if ($estado) {
            $conversaciones = $this->em->getRepository(Conversacion::class)->findBy(
                ['estado' => $estado],
                ['fechaCreacion' => 'DESC']
            );
        } else {
            $conversaciones = $this->em->getRepository(Conversacion::class)->findTodasOrdenadas();
        }

        return array_map(fn(Conversacion $c) => $this->serializarConversacion($c), $conversaciones);
    }

    /*
     * OBTENER: Busca una conversación por ID.
     * @throws NotFoundException
     */
    public function obtener(int $id): Conversacion
    {
        $conversacion = $this->em->find(Conversacion::class, $id);
        if (!$conversacion) {
            throw new NotFoundException('Conversación no encontrada');
        }
        return $conversacion;
    }

    /*
     * CERRAR (admin): Cambia el estado de la conversación a 'Cerrada'.
     * No se pueden agregar más mensajes después de cerrar.
     *
     * @throws ValidationException si ya está cerrada
     */
    public function cerrar(int $id): Conversacion
    {
        $conversacion = $this->obtener($id);
        
        if (!$conversacion->estaAbierta()) {
            throw new ValidationException('La conversación ya está cerrada');
        }

        $conversacion->setEstado('Cerrada');
        $this->em->flush();

        return $conversacion;
    }

    // ─── SERIALIZADORES ─────────────────────────────────────────

    private function serializarConversacion(Conversacion $c): array
    {
        $ultimoMensaje = $c->getMensajes()->last();
        
        return [
            'id'             => $c->getIdConversacion(),
            'usuario'        => [
                'id'     => $c->getUsuario()->getIdUsuario(),
                'nombre' => $c->getUsuario()->getNombre() . ' ' . $c->getUsuario()->getApellido(),
            ],
            'estado'         => $c->getEstado(),
            'fecha_creacion' => $c->getFechaCreacion()->format('Y-m-d H:i:s'),
            'ultimo_mensaje' => $ultimoMensaje ? $ultimoMensaje->getContenido() : null,
            'total_mensajes' => $c->getMensajes()->count(),
        ];
    }

    private function serializarMensaje(Mensaje $m): array
    {
        return [
            'id'         => $m->getIdMensaje(),
            'contenido'  => $m->getContenido(),
            'emisor'     => [
                'id'       => $m->getEmisor()->getIdUsuario(),
                'nombre'   => $m->getEmisor()->getNombre() . ' ' . $m->getEmisor()->getApellido(),
                'es_admin' => $m->getEmisor()->esAdmin(),
            ],
            'fecha_envio' => $m->getFechaEnvio()->format('Y-m-d H:i:s'),
        ];
    }
}
