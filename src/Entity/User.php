<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entidad Doctrine que representa la tabla de usuarios.
 */
#[ORM\Entity(repositoryClass: "App\Repository\UserRepository")]
#[ORM\Table(name: "usuarios")]
class User {
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(type: "string", length: 50, unique: true)]
    private string $usuario;

    #[ORM\Column(type: "string", length: 255)] // Aumentado a 255 para soportar el hash bcrypt
    private string $password;

    #[ORM\Column(type: "string", length: 100)]
    private string $nombre;

    #[ORM\Column(type: "string", length: 100)]
    private string $apellido;

    #[ORM\Column(type: "string", length: 20, unique: true)]
    private string $dni;

    #[ORM\Column(type: "string", length: 100, unique: true)]
    private string $email;

    #[ORM\Column(type: "string", length: 20)]
    private string $rol;

    #[ORM\Column(type: "boolean", options: ["default" => 1])]
    private bool $estado = true; // Para el borrado lógico

    #[ORM\Column(name: "foto_perfil", type: "string", length: 255, nullable: true)]
    private ?string $fotoPerfil = null;

    #[ORM\Column(name: "refresh_token", type: "string", length: 512, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(name: "refresh_token_expira", type: "datetime", nullable: true)]
    private ?\DateTimeInterface $refreshTokenExpira = null;

    // --- Getters y setters ---
    public function getId(): int { return $this->id; }

    public function getUsuario(): string { return $this->usuario; }
    public function setUsuario(string $usuario): void { $this->usuario = $usuario; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): void { $this->nombre = $nombre; }

    public function getApellido(): string { return $this->apellido; }
    public function setApellido(string $apellido): void { $this->apellido = $apellido; }

    public function getDni(): string { return $this->dni; }
    public function setDni(string $dni): void { $this->dni = $dni; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getRol(): string { return $this->rol; }
    public function setRol(string $rol): void { $this->rol = $rol; }

    public function isEstado(): bool { return $this->estado; }
    public function setEstado(bool $estado): void { $this->estado = $estado; }

    public function getFotoPerfil(): ?string { return $this->fotoPerfil; }
    public function setFotoPerfil(?string $fotoPerfil): void { $this->fotoPerfil = $fotoPerfil; }

    public function getRefreshToken(): ?string { return $this->refreshToken; }
    public function setRefreshToken(?string $refreshToken): void { $this->refreshToken = $refreshToken; }

    public function getRefreshTokenExpira(): ?\DateTimeInterface { return $this->refreshTokenExpira; }
    public function setRefreshTokenExpira(?\DateTimeInterface $refreshTokenExpira): void { $this->refreshTokenExpira = $refreshTokenExpira; }

    public function isAdmin(): bool
    {
        return $this->rol === 'admin';
    }
}