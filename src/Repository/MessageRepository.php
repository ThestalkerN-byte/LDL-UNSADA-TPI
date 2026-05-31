<?php
namespace App\Repository;

use App\Entity\Message;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder a mensajes.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 */
class MessageRepository extends EntityRepository {

    /**
     * Buscar mensajes por remitente.
     */
    public function findByRemitente(string $remitente): array {
        return $this->createQueryBuilder('m')
            ->where('m.remitente = :remitente')
            ->setParameter('remitente', $remitente)
            ->getQuery()
            ->getResult();
    }
}