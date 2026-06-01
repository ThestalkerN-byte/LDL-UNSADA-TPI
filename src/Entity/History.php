<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa el historial de acciones de administración.
 */
#[ORM\Entity(repositoryClass: "App\Repository\HistoryRepository")]
#[ORM\Table(name: "historial")]
class History {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 255)]
    private string $accion;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $fecha;

    /**
     * El administrador que realizó la acción.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "id_admin", referencedColumnName: "id", nullable: false)]
    private User $admin;

    // --- Getters y setters ---

    public function getId(): int { return $this->id; }

    public function getAccion(): string { return $this->accion; }
    public function setAccion(string $accion): void { $this->accion = $accion; }

    public function getFecha(): \DateTimeInterface { return $this->fecha; }
    public function setFecha(\DateTimeInterface $fecha): void { $this->fecha = $fecha; }

    public function getAdmin(): User { return $this->admin; }
    public function setAdmin(User $admin): void { $this->admin = $admin; }
}