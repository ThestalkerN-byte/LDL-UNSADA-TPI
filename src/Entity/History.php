<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa el historial de acciones de administración.
 */
#[ORM\Entity(repositoryClass: "App\Repository\HistoryRepository")]
class History {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 255)]
    private string $accion;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $fecha;

    #[ORM\Column(type: "string", length: 50)]
    private string $usuarioAdmin;

    // Getters y setters
    public function getId(): int { return $this->id; }
    public function getAccion(): string { return $this->accion; }
    public function setAccion(string $accion): void { $this->accion = $accion; }
    public function getFecha(): \DateTimeInterface { return $this->fecha; }
    public function setFecha(\DateTimeInterface $fecha): void { $this->fecha = $fecha; }
    public function getUsuarioAdmin(): string { return $this->usuarioAdmin; }
    public function setUsuarioAdmin(string $usuarioAdmin): void { $this->usuarioAdmin = $usuarioAdmin; }
}