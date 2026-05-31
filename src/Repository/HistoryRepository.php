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
    public function findByAdmin(string $usuarioAdmin): array {
        return $this->createQueryBuilder('h')
            ->where('h.usuarioAdmin = :admin')
            ->setParameter('admin', $usuarioAdmin)
            ->getQuery()
            ->getResult();
    }
}