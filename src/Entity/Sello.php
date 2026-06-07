<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Sello
 * =========================================================================
 *
 * Representa un sello o logotipo institucional que se muestra en las
 * credenciales digitales para garantizar su autenticidad visual (RF13).
 *
 * Ejemplos de sellos:
 *   - Logo de la Iglesia Cristiana Bíblica
 *   - Logo de alguna organización asociada
 *   - Sello de validez oficial
 *
 * El administrador puede activar/desactivar sellos sin eliminarlos,
 * y agregar nuevos cuando sea necesario.
 * =========================================================================
 */

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sellos')]
class Sello
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_sello', type: 'integer')]
    private int $idSello;

    // Nombre descriptivo del sello (ej: 'ICB', 'Ministerio de Culto')
    #[ORM\Column(type: 'string', length: 100)]
    private string $nombre;

    // URL o ruta de la imagen del sello
    #[ORM\Column(name: 'imagen_url', type: 'string', length: 255)]
    private string $imagenUrl;

    // Indica si el sello está visible actualmente en las credenciales
    #[ORM\Column(type: 'boolean')]
    private bool $activo = true;

    // Fecha de creación del registro
    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    // ─── Control de concurrencia (optimistic locking) ─────────────────────
    // Versión para optimistic locking: se incrementa automáticamente en cada
    // actualización. Si dos usuarios intentan modificar el mismo registro
    // simultáneamente, Doctrine lanza OptimisticLockException.
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getIdSello(): int
    {
        return $this->idSello;
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

    public function getImagenUrl(): string
    {
        return $this->imagenUrl;
    }

    public function setImagenUrl(string $imagenUrl): self
    {
        $this->imagenUrl = $imagenUrl;
        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    // ─── Optimistic locking ──────────────────────────────────────────────
    public function getVersion(): int
    {
        return $this->version;
    }
}
