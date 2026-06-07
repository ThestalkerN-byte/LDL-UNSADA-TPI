<?php

declare(strict_types=1);

namespace ICB\Repository;

/*
 * =========================================================================
 * REPOSITORIO: ConversacionRepository
 * =========================================================================
 *
 * Repositorio personalizado para la entidad Conversacion.
 * Proporciona métodos de consulta específicos para gestionar
 * la bandeja de conversaciones entre usuarios y administración.
 * =========================================================================
 */

use Doctrine\ORM\EntityRepository;
use ICB\Entity\Conversacion;
use ICB\Entity\Usuario;

class ConversacionRepository extends EntityRepository
{
    /**
     * Devuelve todas las conversaciones abiertas (pendientes de respuesta),
     * ordenadas de la más reciente a la más antigua.
     * Útil para la bandeja de entrada del administrador.
     *
     * @return Conversacion[]
     */
    public function findAbiertas(): array
    {
        return $this->findBy(['estado' => 'Abierta'], ['fechaCreacion' => 'DESC']);
    }

    /**
     * Devuelve todas las conversaciones cerradas (consultas resueltas).
     *
     * @return Conversacion[]
     */
    public function findCerradas(): array
    {
        return $this->findBy(['estado' => 'Cerrada'], ['fechaCreacion' => 'DESC']);
    }

    /**
     * Devuelve las conversaciones de un usuario específico.
     * Cada usuario solo puede ver sus propias conversaciones.
     *
     * @return Conversacion[]
     */
    public function findPorUsuario(Usuario $usuario): array
    {
        return $this->findBy(
            ['usuario' => $usuario],
            ['fechaCreacion' => 'DESC']
        );
    }

    /**
     * Devuelve todas las conversaciones ordenadas por fecha descendente.
     * Para el panel del administrador que necesita ver todo.
     *
     * @return Conversacion[]
     */
    public function findTodasOrdenadas(): array
    {
        return $this->findBy([], ['fechaCreacion' => 'DESC']);
    }
}
