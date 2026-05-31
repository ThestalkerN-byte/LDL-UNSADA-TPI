<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\CredentialStatus;

/**
 * Entidad Doctrine que representa la tabla de credenciales en la base de datos.
 * Aquí solo se definen atributos y mapeos, sin lógica de negocio.
 */
#[ORM\Entity(repositoryClass: "App\Repository\CredentialRepository")]
class Credential {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 100)]
    private string $nombre;

    #[ORM\Column(type: "string", length: 100)]
    private string $apellido;

    #[ORM\Column(type: "string", length: 20)]
    private string $dni;

    #[ORM\Column(type: "string", length: 50)]
    private string $rol;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $fechaVencimiento;

    #[ORM\Column(type: "string", enumType: CredentialStatus::class)]
    private CredentialStatus $estado;

    #[ORM\Column(type: "json", nullable: true)]
    private array $sellos = [];

    // Getters y setters (solo acceso a datos, sin lógica)
    public function getId(): int { return $this->id; }
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): void { $this->nombre = $nombre; }
    public function getApellido(): string { return $this->apellido; }
    public function setApellido(string $apellido): void { $this->apellido = $apellido; }
    public function getDni(): string { return $this->dni; }
    public function setDni(string $dni): void { $this->dni = $dni; }
    public function getRol(): string { return $this->rol; }
    public function setRol(string $rol): void { $this->rol = $rol; }
    public function getFechaVencimiento(): \DateTimeInterface { return $this->fechaVencimiento; }
    public function setFechaVencimiento(\DateTimeInterface $fecha): void { $this->fechaVencimiento = $fecha; }
    public function getEstado(): CredentialStatus { return $this->estado; }
    public function setEstado(CredentialStatus $estado): void { $this->estado = $estado; }
    public function getSellos(): array { return $this->sellos; }
    public function setSellos(array $sellos): void { $this->sellos = $sellos; }
}