<?php
namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder a usuarios.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 */
class UserRepository extends EntityRepository {

    /**
     * Buscar usuario por nombre de usuario o DNI.
     */
    public function findByUsuarioODni(string $identificador): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.usuario = :identificador OR u.dni = :identificador')
            ->setParameter('identificador', $identificador)
            ->getQuery()
            ->getOneOrNullResult();
    }
}