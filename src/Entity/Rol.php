<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: Rol
 * =========================================================================
 *
 * Representa un rol dentro del sistema (Admin, Usuario, etc.).
 * Los roles se asignan a los usuarios mediante una relación
 * muchos-a-muchos (tabla pivote usuario_roles).
 *
 * Esto permite que un usuario tenga múltiples roles y que un rol
 * pueda estar asignado a múltiples usuarios.
 * =========================================================================
 */

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Rol
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_rol', type: 'integer')]
    private int $idRol;

    // Nombre del rol (ej: 'Admin', 'Usuario'). Es único.
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $nombre;

    // Usuarios que tienen este rol (lado inverso de la relación ManyToMany)
    #[ORM\ManyToMany(targetEntity: Usuario::class, mappedBy: 'roles')]
    private Collection $usuarios;

    public function __construct()
    {
        $this->usuarios = new ArrayCollection();
    }

    public function getIdRol(): int
    {
        return $this->idRol;
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

    public function getUsuarios(): Collection
    {
        return $this->usuarios;
    }
}
