<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa un mensaje o ticket de consulta.
 */
#[ORM\Entity(repositoryClass: "App\Repository\MessageRepository")]
#[ORM\Table(name: "mensajes")]
class Message {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    /**
     * El usuario que creó la consulta.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "id_usuario", referencedColumnName: "id", nullable: false)]
    private User $user;

    #[ORM\Column(type: "text")]
    private string $contenido;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $fecha;

    /**
     * La respuesta del administrador (puede ser nula si aún no se responde).
     */
    #[ORM\Column(type: "text", nullable: true)]
    private ?string $respuesta = null;

    #[ORM\Column(name: "fecha_respuesta", type: "datetime", nullable: true)]
    private ?\DateTimeInterface $fechaRespuesta = null;

    /**
     * Estado del ticket (ej: 'Pendiente', 'Respondido').
     */
    #[ORM\Column(type: "string", length: 50, options: ["default" => "Pendiente"])]
    private string $estado = 'Pendiente';

    // --- Getters y setters ---

    public function getId(): int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): void { $this->user = $user; }

    public function getContenido(): string { return $this->contenido; }
    public function setContenido(string $contenido): void { $this->contenido = $contenido; }

    public function getFecha(): \DateTimeInterface { return $this->fecha; }
    public function setFecha(\DateTimeInterface $fecha): void { $this->fecha = $fecha; }

    public function getRespuesta(): ?string { return $this->respuesta; }
    public function setRespuesta(?string $respuesta): void { $this->respuesta = $respuesta; }

    public function getFechaRespuesta(): ?\DateTimeInterface { return $this->fechaRespuesta; }
    public function setFechaRespuesta(?\DateTimeInterface $fechaRespuesta): void { $this->fechaRespuesta = $fechaRespuesta; }

    public function getEstado(): string { return $this->estado; }
    public function setEstado(string $estado): void { $this->estado = $estado; }
}