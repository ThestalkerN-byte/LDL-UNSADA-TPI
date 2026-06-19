<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder a usuarios.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 *
 * Convención de borrado lógico:
 *   estado = true  → usuario activo (visible y válido para unicidad)
 *   estado = false → usuario dado de baja (excluido de listados y validaciones)
 */
class UserRepository extends EntityRepository {

    /**
     * Buscar usuario ACTIVO por nombre de usuario o DNI.
     * Usado en login y recuperación de contraseña.
     */
    public function findByUsuarioODni(string $identificador): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.estado = true')
            ->andWhere('u.usuario = :identificador OR u.dni = :identificador')
            ->setParameter('identificador', $identificador)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Buscar usuario ACTIVO por ID.
     * Usado en show, update y delete del panel admin.
     */
    public function findActiveById(int $id): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.id = :id')
            ->andWhere('u.estado = true')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Buscar usuario ACTIVO por nombre de usuario.
     * Usado en validaciones de unicidad al crear/editar.
     */
    public function findActiveByUsuario(string $usuario): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.estado = true')
            ->andWhere('u.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Buscar usuario ACTIVO por DNI.
     * Usado en validaciones de unicidad al crear/editar.
     */
    public function findActiveByDni(string $dni): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.estado = true')
            ->andWhere('u.dni = :dni')
            ->setParameter('dni', $dni)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Buscar usuario ACTIVO por email.
     * Usado en validaciones de unicidad al crear/editar.
     */
    public function findActiveByEmail(string $email): ?User {
        return $this->createQueryBuilder('u')
            ->where('u.estado = true')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
