<?php
namespace App\DTO;

use App\Enum\CredentialStatus;

/**
 * DTO (Data Transfer Object) para transportar datos de credenciales
 * entre capas sin exponer directamente las entidades Doctrine.
 */
class CredentialDTO {
    private int $id;
    private string $nombre;
    private string $apellido;
    private string $dni;
    private string $rol;
    private \DateTimeInterface $fechaVencimiento;
    private CredentialStatus $estado;
    private array $sellos;

    public function __construct(
        int $id,
        string $nombre,
        string $apellido,
        string $dni,
        string $rol,
        \DateTimeInterface $fechaVencimiento,
        CredentialStatus $estado,
        array $sellos = []
    ) {
        $this->id = $id;
        $this->nombre = $nombre;
        $this->apellido = $apellido;
        $this->dni = $dni;
        $this->rol = $rol;
        $this->fechaVencimiento = $fechaVencimiento;
        $this->estado = $estado;
        $this->sellos = $sellos;
    }

    // Getters y setters para acceder a los datos
    public function getId(): int { return $this->id; }
    public function getNombre(): string { return $this->nombre; }
    public function getApellido(): string { return $this->apellido; }
    public function getDni(): string { return $this->dni; }
    public function getRol(): string { return $this->rol; }
    public function getFechaVencimiento(): \DateTimeInterface { return $this->fechaVencimiento; }
    public function getEstado(): CredentialStatus { return $this->estado; }
    public function getSellos(): array { return $this->sellos; }

    public function setEstado(CredentialStatus $estado): void { $this->estado = $estado; }
}