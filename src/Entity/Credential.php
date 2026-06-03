<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa la tabla de credenciales.
 */
#[ORM\Entity(repositoryClass: "App\Repository\CredentialRepository")]
#[ORM\Table(name: "credenciales")]
class Credential {
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id_credential", type: "integer")]
    private int $id;

    /**
     * Relación con la entidad User.
     * Mapea a la columna "id_usuario" en la base de datos.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "id_usuario", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private User $usuario;

    #[ORM\Column(name: "fecha_emision", type: "date")]
    private \DateTimeInterface $fechaEmision;

    #[ORM\Column(name: "fecha_vencimiento", type: "date")]
    private \DateTimeInterface $fechaVencimiento;

    #[ORM\Column(type: "json", nullable: true)]
    private ?array $sellos = null;

    // --- Getters y Setters ---

    public function getId(): int {
        return $this->id;
    }

    public function getUsuario(): User {
        return $this->usuario;
    }

    public function setUsuario(User $usuario): void {
        $this->usuario = $usuario;
    }

    public function getFechaEmision(): \DateTimeInterface {
        return $this->fechaEmision;
    }

    public function setFechaEmision(\DateTimeInterface $fechaEmision): void {
        $this->fechaEmision = $fechaEmision;
    }

    public function getFechaVencimiento(): \DateTimeInterface {
        return $this->fechaVencimiento;
    }

    public function setFechaVencimiento(\DateTimeInterface $fechaVencimiento): void {
        $this->fechaVencimiento = $fechaVencimiento;
    }

    public function getSellos(): ?array {
        return $this->sellos;
    }

    public function setSellos(?array $sellos): void {
        $this->sellos = $sellos;
    }

    /**
     * Método de la Regla B: El estado no se lee de la BD, se calcula dinámicamente.
     */
    public function getEstado(): string {
        $hoy = new \DateTimeImmutable('today');
        // Si hoy es mayor estricto que la fecha de vencimiento, está vencida.
        if ($hoy > $this->fechaVencimiento) {
            return 'VENCIDA';
        }
        return 'ACTIVA';
    }
}