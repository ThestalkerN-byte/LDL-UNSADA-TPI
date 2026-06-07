<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Mensaje
 * =========================================================================
 *
 * Representa un mensaje individual dentro de una conversación.
 * Puede ser enviado por un usuario (consulta) o por un administrador (respuesta).
 *
 * RELACIONES:
 *   - Muchos-a-uno con Conversacion (el hilo al que pertenece)
 *   - Muchos-a-uno con Usuario (quien envió el mensaje, como emisor)
 * =========================================================================
 */

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mensajes')]

// Índices para buscar mensajes por conversación y por emisor
#[ORM\Index(name: 'idx_mensajes_conversacion', columns: ['id_conversacion'])]
#[ORM\Index(name: 'idx_mensajes_emisor', columns: ['id_emisor'])]
class Mensaje
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_mensaje', type: 'integer')]
    private int $idMensaje;

    // Conversación a la que pertenece este mensaje
    #[ORM\ManyToOne(targetEntity: Conversacion::class, inversedBy: 'mensajes')]
    #[ORM\JoinColumn(name: 'id_conversacion', referencedColumnName: 'id_conversacion', nullable: false, onDelete: 'RESTRICT')]
    private Conversacion $conversacion;

    // Usuario que envió el mensaje (puede ser el dueño de la consulta o un admin)
    // Se identifica por id_emisor en la tabla
    #[ORM\ManyToOne(targetEntity: Usuario::class, inversedBy: 'mensajesEnviados')]
    #[ORM\JoinColumn(name: 'id_emisor', referencedColumnName: 'id_usuario', nullable: false, onDelete: 'RESTRICT')]
    private Usuario $emisor;

    // Contenido del mensaje (texto libre)
    #[ORM\Column(type: 'text')]
    private string $contenido;

    // Fecha y hora de envío del mensaje
    #[ORM\Column(name: 'fecha_envio', type: 'datetime')]
    private \DateTimeInterface $fechaEnvio;

    public function __construct()
    {
        $this->fechaEnvio = new \DateTime();
    }

    public function getIdMensaje(): int
    {
        return $this->idMensaje;
    }

    public function getConversacion(): Conversacion
    {
        return $this->conversacion;
    }

    public function setConversacion(Conversacion $conversacion): self
    {
        $this->conversacion = $conversacion;
        return $this;
    }

    public function getEmisor(): Usuario
    {
        return $this->emisor;
    }

    public function setEmisor(Usuario $emisor): self
    {
        $this->emisor = $emisor;
        return $this;
    }

    public function getContenido(): string
    {
        return $this->contenido;
    }

    public function setContenido(string $contenido): self
    {
        $this->contenido = $contenido;
        return $this;
    }

    public function getFechaEnvio(): \DateTimeInterface
    {
        return $this->fechaEnvio;
    }
}
