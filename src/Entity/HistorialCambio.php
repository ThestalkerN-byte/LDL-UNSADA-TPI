<?php

declare(strict_types=1);

namespace ICB\Entity;

/*
 * =========================================================================
 * ENTIDAD: HistorialCambio
 * =========================================================================
 *
 * Registro de auditoría para todas las acciones que realiza un administrador
 * sobre el sistema. Cada vez que un admin crea, modifica o elimina un registro,
 * se guarda un historial con los valores anteriores y nuevos.
 *
 * Esto permite:
 *   - Saber QUIÉN hizo QUÉ, CUÁNDO y SOBRE QUÉ registro
 *   - Revertir cambios manualmente si es necesario
 *   - Cumplir con requisitos de auditoría y transparencia
 *
 * RELACIONES:
 *   - Muchos-a-uno con Usuario (el admin que realizó la acción)
 * =========================================================================
 */

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'historial_cambios')]
#[ORM\Index(name: 'idx_historial_admin', columns: ['id_admin'])]
class HistorialCambio
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id_historial', type: 'integer')]
    private int $idHistorial;

    // Administrador que realizó el cambio
    #[ORM\ManyToOne(targetEntity: Usuario::class, inversedBy: 'historialCambios')]
    #[ORM\JoinColumn(name: 'id_admin', referencedColumnName: 'id_usuario', nullable: false, onDelete: 'RESTRICT')]
    private Usuario $admin;

    // Nombre de la tabla afectada (ej: 'usuarios', 'credenciales')
    #[ORM\Column(name: 'tabla_afectada', type: 'string', length: 100)]
    private string $tablaAfectada;

    // ID del registro que fue modificado en esa tabla
    #[ORM\Column(name: 'registro_id', type: 'integer')]
    private int $registroId;

    // Acción realizada (ej: 'CREAR', 'EDITAR', 'BAJA', 'RENOVAR')
    #[ORM\Column(type: 'string', length: 100)]
    private string $accion;

    // Valor anterior del registro (en JSON o texto plano)
    #[ORM\Column(name: 'valor_anterior', type: 'text', nullable: true)]
    private ?string $valorAnterior = null;

    // Valor nuevo del registro (en JSON o texto plano)
    #[ORM\Column(name: 'valor_nuevo', type: 'text', nullable: true)]
    private ?string $valorNuevo = null;

    // Fecha y hora del cambio
    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $fecha;

    public function __construct()
    {
        $this->fecha = new \DateTime();
    }

    public function getIdHistorial(): int
    {
        return $this->idHistorial;
    }

    public function getAdmin(): Usuario
    {
        return $this->admin;
    }

    public function setAdmin(Usuario $admin): self
    {
        $this->admin = $admin;
        return $this;
    }

    public function getTablaAfectada(): string
    {
        return $this->tablaAfectada;
    }

    public function setTablaAfectada(string $tablaAfectada): self
    {
        $this->tablaAfectada = $tablaAfectada;
        return $this;
    }

    public function getRegistroId(): int
    {
        return $this->registroId;
    }

    public function setRegistroId(int $registroId): self
    {
        $this->registroId = $registroId;
        return $this;
    }

    public function getAccion(): string
    {
        return $this->accion;
    }

    public function setAccion(string $accion): self
    {
        $this->accion = $accion;
        return $this;
    }

    public function getValorAnterior(): ?string
    {
        return $this->valorAnterior;
    }

    public function setValorAnterior(?string $valorAnterior): self
    {
        $this->valorAnterior = $valorAnterior;
        return $this;
    }

    public function getValorNuevo(): ?string
    {
        return $this->valorNuevo;
    }

    public function setValorNuevo(?string $valorNuevo): self
    {
        $this->valorNuevo = $valorNuevo;
        return $this;
    }

    public function getFecha(): \DateTimeInterface
    {
        return $this->fecha;
    }
}
