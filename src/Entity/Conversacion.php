<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Conversacion
 * =========================================================================
 *
 * Representa un hilo de conversación entre un usuario y la administración.
 * Cuando un usuario tiene una consulta, se crea una conversación y dentro
 * de ella se agregan mensajes (tanto del usuario como del admin).
 *
 * RELACIONES:
 *   - Muchos-a-uno con Usuario (quien inició la conversación)
 *   - Uno-a-muchos con Mensaje (los mensajes del hilo)
 *
 * Una conversación puede estar:
 *   - 'Abierta': el usuario espera respuesta o el diálogo continúa
 *   - 'Cerrada': la consulta fue resuelta y cerrada por el admin
 * =========================================================================
 */

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \ICB\Repository\ConversacionRepository::class)]
#[ORM\Table(name: 'conversaciones')]
#[ORM\Index(name: 'idx_conversaciones_usuario', columns: ['id_usuario'])]
class Conversacion
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_conversacion', type: 'integer')]
    private int $idConversacion;

    // Usuario que inició la conversación (el que tiene la consulta)
    #[ORM\ManyToOne(targetEntity: Usuario::class, inversedBy: 'conversaciones')]
    #[ORM\JoinColumn(name: 'id_usuario', referencedColumnName: 'id_usuario', nullable: false, onDelete: 'RESTRICT')]
    private Usuario $usuario;

    // Estado: 'Abierta' o 'Cerrada'
    #[ORM\Column(type: 'string', length: 20)]
    private string $estado = 'Abierta';

    // Fecha en que se creó la conversación
    #[ORM\Column(name: 'fecha_creacion', type: 'datetime')]
    private \DateTimeInterface $fechaCreacion;

    // Mensajes de esta conversación, ordenados por fecha ascendente
    #[ORM\OneToMany(mappedBy: 'conversacion', targetEntity: Mensaje::class)]
    #[ORM\OrderBy(['fechaEnvio' => 'ASC'])]
    private Collection $mensajes;

    public function __construct()
    {
        $this->fechaCreacion = new \DateTime();
        $this->mensajes = new ArrayCollection();
    }

    public function getIdConversacion(): int
    {
        return $this->idConversacion;
    }

    public function getUsuario(): Usuario
    {
        return $this->usuario;
    }

    public function setUsuario(Usuario $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function setEstado(string $estado): self
    {
        $this->estado = $estado;
        return $this;
    }

    public function getFechaCreacion(): \DateTimeInterface
    {
        return $this->fechaCreacion;
    }

    public function getMensajes(): Collection
    {
        return $this->mensajes;
    }

    public function estaAbierta(): bool
    {
        return $this->estado === 'Abierta';
    }
}
