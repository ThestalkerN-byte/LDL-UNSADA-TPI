<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Credencial
 * =========================================================================
 *
 * Representa una credencial digital emitida para un usuario.
 * Cada credencial tiene una fecha de emisión y vencimiento, y puede
 * incluir foto y código QR.
 *
 * Un usuario puede tener múltiples credenciales en el tiempo (renovaciones),
 * pero solo una activa a la vez (la que no tiene fecha_baja y no está vencida).
 *
 * RELACIONES:
 *   - Muchos-a-uno con Usuario (dueño de la credencial)
 * =========================================================================
 */

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'credenciales')]

// Índices para búsquedas frecuentes
#[ORM\Index(name: 'idx_credenciales_usuario', columns: ['id_usuario'])]
#[ORM\Index(name: 'idx_credenciales_vencimiento', columns: ['fecha_vencimiento'])]
class Credencial
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_credencial', type: 'integer')]
    private int $idCredencial;

    // Usuario al que pertenece esta credencial
    #[ORM\ManyToOne(targetEntity: Usuario::class, inversedBy: 'credenciales')]
    #[ORM\JoinColumn(name: 'id_usuario', referencedColumnName: 'id_usuario', nullable: false, onDelete: 'RESTRICT')]
    private Usuario $usuario;

    // Fecha en que se emitió la credencial
    #[ORM\Column(name: 'fecha_emision', type: 'date')]
    private \DateTimeInterface $fechaEmision;

    // Fecha hasta la cual la credencial es válida
    #[ORM\Column(name: 'fecha_vencimiento', type: 'date')]
    private \DateTimeInterface $fechaVencimiento;

    // URL de la foto del usuario para la credencial
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $foto = null;

    // Código QR único para validación rápida de la credencial
    #[ORM\Column(name: 'codigo_qr', type: 'string', length: 255, unique: true, nullable: true)]
    private ?string $codigoQr = null;

    // Fecha en que se dio de baja la credencial (borrado lógico)
    #[ORM\Column(name: 'fecha_baja', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $fechaBaja = null;

    // ─── Control de concurrencia (optimistic locking) ─────────────────────
    // Versión para optimistic locking: se incrementa automáticamente en cada
    // actualización. Si dos usuarios intentan modificar el mismo registro
    // simultáneamente, Doctrine lanza OptimisticLockException.
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct()
    {
        // Por defecto, la fecha de emisión es la fecha actual
        $this->fechaEmision = new \DateTime();
    }

    public function getIdCredencial(): int
    {
        return $this->idCredencial;
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

    public function getFechaEmision(): \DateTimeInterface
    {
        return $this->fechaEmision;
    }

    public function setFechaEmision(\DateTimeInterface $fechaEmision): self
    {
        $this->fechaEmision = $fechaEmision;
        return $this;
    }

    public function getFechaVencimiento(): \DateTimeInterface
    {
        return $this->fechaVencimiento;
    }

    public function setFechaVencimiento(\DateTimeInterface $fechaVencimiento): self
    {
        $this->fechaVencimiento = $fechaVencimiento;
        return $this;
    }

    public function getFoto(): ?string
    {
        return $this->foto;
    }

    public function setFoto(?string $foto): self
    {
        $this->foto = $foto;
        return $this;
    }

    public function getCodigoQr(): ?string
    {
        return $this->codigoQr;
    }

    public function setCodigoQr(?string $codigoQr): self
    {
        $this->codigoQr = $codigoQr;
        return $this;
    }

    public function getFechaBaja(): ?\DateTimeInterface
    {
        return $this->fechaBaja;
    }

    public function setFechaBaja(?\DateTimeInterface $fechaBaja): self
    {
        $this->fechaBaja = $fechaBaja;
        return $this;
    }

    // ─── Optimistic locking ──────────────────────────────────────────────
    public function getVersion(): int
    {
        return $this->version;
    }

    // ─── Método de negocio ──────────────────────────────────────────────
    // Una credencial está activa si:
    //   1. No tiene fecha de baja (no fue revocada)
    //   2. Su fecha de vencimiento es hoy o en el futuro
    public function estaActiva(): bool
    {
        return $this->fechaBaja === null
            && $this->fechaVencimiento >= new \DateTime();
    }
}
