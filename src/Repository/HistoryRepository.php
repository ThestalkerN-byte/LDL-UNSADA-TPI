<?php
namespace App\Repository;

use App\Entity\History;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder al historial de acciones.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 */
class HistoryRepository extends EntityRepository {

    /**
     * Buscar historial por usuario administrador.
     */
    public function findByAdmin(\App\Entity\User $admin): array {
        return $this->createQueryBuilder('h')
            ->where('h.admin = :admin')
            ->setParameter('admin', $admin)
            ->getQuery()
            ->getResult();
    }
}