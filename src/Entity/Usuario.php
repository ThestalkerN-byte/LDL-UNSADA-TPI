<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Usuario
 * =========================================================================
 *
 * Representa un usuario del sistema (miembro de la iglesia o administrador).
 * Mapea a la tabla 'usuarios' con mapeo por atributos PHP 8.
 *
 * RELACIONES:
 *   - Muchos-a-muchos con Rol (vía tabla pivote usuario_roles)
 *   - Uno-a-muchos con Credencial (un usuario puede tener varias credenciales)
 *   - Uno-a-muchos con Conversacion (un usuario puede abrir varias consultas)
 *   - Uno-a-muchos con Mensaje (como emisor)
 *   - Uno-a-muchos con HistorialCambio (como admin que realizó el cambio)
 *
 * CAMPOS EXTRA (no están en el schema original):
 *   - direccion: se agregó basado en los mockups (reverso de credencial)
 *   - refresh_token / refresh_token_expira: para JWT refresh tokens
 *   - reset_token / reset_token_expira: para recuperación de contraseña
 * =========================================================================
 */

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \ICB\Repository\UsuarioRepository::class)]
#[ORM\Table(name: 'usuarios')]
class Usuario
{
    // ─── ID (autoincremental) ───────────────────────────────────────────
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_usuario', type: 'integer')]
    private int $idUsuario;

    // ─── Datos de identificación ────────────────────────────────────────
    // DNI del usuario (único). Se usa junto con 'usuario' para login.
    #[ORM\Column(type: 'string', length: 20, unique: true)]
    private string $dni;

    // Nombre de usuario para login (único)
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $usuario;

    // Hash de contraseña (bcrypt via password_hash())
    // NUNCA se almacena la contraseña en texto plano
    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    // ─── Datos personales ───────────────────────────────────────────────
    #[ORM\Column(type: 'string', length: 100)]
    private string $nombre;

    #[ORM\Column(type: 'string', length: 100)]
    private string $apellido;

    // Email institucional o personal (único, usado para recuperación)
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $email;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    // Dirección física (visible en el reverso de la credencial)
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccion = null;

    // Rol o cargo dentro de la iglesia (Pastor, Ministro, Miembro, etc.)
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $funcion = null;

    // ─── Estado ─────────────────────────────────────────────────────────
    // 'Activo' o 'Inactivo'. Usamos string (VARCHAR) en vez de ENUM
    // para ser compatibles con múltiples motores de base de datos.
    #[ORM\Column(type: 'string', length: 20)]
    private string $estado = 'Activo';

    // ─── Fechas de control ──────────────────────────────────────────────
    #[ORM\Column(name: 'fecha_alta', type: 'datetime')]
    private \DateTimeInterface $fechaAlta;

    // ─── JWT Refresh Token ──────────────────────────────────────────────
    // Token de larga duración (30 días) para renovar el access token sin
    // pedir credenciales de nuevo. Almacenado en DB para poder revocarlo.
    #[ORM\Column(name: 'refresh_token', type: 'string', length: 512, nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(name: 'refresh_token_expira', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $refreshTokenExpira = null;

    // ─── Password Reset Token ───────────────────────────────────────────
    // Token para recuperación de contraseña (código de 6 dígitos o link)
    #[ORM\Column(name: 'reset_token', type: 'string', length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(name: 'reset_token_expira', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpira = null;

    // ─── Control de concurrencia (optimistic locking) ─────────────────────
    // Versión para optimistic locking: se incrementa automáticamente en cada
    // actualización. Si dos usuarios intentan modificar el mismo registro
    // simultáneamente, Doctrine lanza OptimisticLockException.
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    // ─── Relaciones ─────────────────────────────────────────────────────

    // Muchos-a-muchos con Roles (usuario_roles es la tabla pivote)
    #[ORM\ManyToMany(targetEntity: Rol::class, inversedBy: 'usuarios')]
    #[ORM\JoinTable(name: 'usuario_roles')]
    #[ORM\JoinColumn(name: 'id_usuario', referencedColumnName: 'id_usuario', onDelete: 'RESTRICT')]
    #[ORM\InverseJoinColumn(name: 'id_rol', referencedColumnName: 'id_rol', onDelete: 'RESTRICT')]
    private Collection $roles;

    // Un usuario puede tener varias credenciales en el tiempo
    #[ORM\OneToMany(mappedBy: 'usuario', targetEntity: Credencial::class)]
    private Collection $credenciales;

    // Conversaciones de consulta que inició el usuario
    #[ORM\OneToMany(mappedBy: 'usuario', targetEntity: Conversacion::class)]
    private Collection $conversaciones;

    // Mensajes que envió el usuario (como emisor)
    #[ORM\OneToMany(mappedBy: 'emisor', targetEntity: Mensaje::class)]
    private Collection $mensajesEnviados;

    // Registros de cambios que realizó como administrador
    #[ORM\OneToMany(mappedBy: 'admin', targetEntity: HistorialCambio::class)]
    private Collection $historialCambios;

    // ─── Constructor ────────────────────────────────────────────────────
    public function __construct()
    {
        $this->fechaAlta = new \DateTime();
        $this->roles = new ArrayCollection();
        $this->credenciales = new ArrayCollection();
        $this->conversaciones = new ArrayCollection();
        $this->mensajesEnviados = new ArrayCollection();
        $this->historialCambios = new ArrayCollection();
    }

    // ─── Getters y Setters ──────────────────────────────────────────────

    public function getIdUsuario(): int
    {
        return $this->idUsuario;
    }

    public function getDni(): string
    {
        return $this->dni;
    }

    public function setDni(string $dni): self
    {
        $this->dni = $dni;
        return $this;
    }

    public function getUsuario(): string
    {
        return $this->usuario;
    }

    public function setUsuario(string $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): self
    {
        $this->passwordHash = $passwordHash;
        return $this;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getApellido(): string
    {
        return $this->apellido;
    }

    public function setApellido(string $apellido): self
    {
        $this->apellido = $apellido;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    public function setDireccion(?string $direccion): self
    {
        $this->direccion = $direccion;
        return $this;
    }

    public function getFuncion(): ?string
    {
        return $this->funcion;
    }

    public function setFuncion(?string $funcion): self
    {
        $this->funcion = $funcion;
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

    public function getFechaAlta(): \DateTimeInterface
    {
        return $this->fechaAlta;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getRefreshTokenExpira(): ?\DateTimeInterface
    {
        return $this->refreshTokenExpira;
    }

    public function setRefreshTokenExpira(?\DateTimeInterface $refreshTokenExpira): self
    {
        $this->refreshTokenExpira = $refreshTokenExpira;
        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function setResetTokenExpira(?\DateTimeInterface $resetTokenExpira): self
    {
        $this->resetTokenExpira = $resetTokenExpira;
        return $this;
    }

    public function getResetTokenExpira(): ?\DateTimeInterface
    {
        return $this->resetTokenExpira;
    }

    // ─── Getters de relaciones ──────────────────────────────────────────

    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRol(Rol $rol): self
    {
        // Evita duplicados en la colección
        if (!$this->roles->contains($rol)) {
            $this->roles->add($rol);
        }
        return $this;
    }

    public function removeRol(Rol $rol): self
    {
        $this->roles->removeElement($rol);
        return $this;
    }

    public function getCredenciales(): Collection
    {
        return $this->credenciales;
    }

    public function getConversaciones(): Collection
    {
        return $this->conversaciones;
    }

    public function getMensajesEnviados(): Collection
    {
        return $this->mensajesEnviados;
    }

    public function getHistorialCambios(): Collection
    {
        return $this->historialCambios;
    }

    // ─── Optimistic locking ──────────────────────────────────────────────
    public function getVersion(): int
    {
        return $this->version;
    }

    // ─── Métodos de negocio ─────────────────────────────────────────────
    // Verifica si el usuario tiene el rol 'Admin' en su colección de roles
    public function esAdmin(): bool
    {
        return $this->roles->exists(fn(int $i, Rol $rol) => $rol->getNombre() === 'Admin');
    }
}
