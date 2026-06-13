<?php
namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder a mensajes.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 */
class MessageRepository extends EntityRepository {

    /**
     * Buscar mensajes por usuario (ID o Instancia).
     */
    public function findByUser(User $user): array {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}