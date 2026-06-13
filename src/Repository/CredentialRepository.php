<?php
namespace App\Repository;

use App\Entity\Credential;
use Doctrine\ORM\EntityRepository;

/**
 * Repositorio Doctrine para acceder a credenciales.
 * Extiende EntityRepository, lo que nos da métodos como findAll(), find(), findBy(), etc.
 */
class CredentialRepository extends EntityRepository {

    /**
     * Buscar credenciales activas.
     */
    public function findActivas(): array {
        return $this->createQueryBuilder('c')
            ->where('c.estado = :estado')
            ->andWhere('c.esActiva = true')
            ->setParameter('estado', 'ACTIVA')
            ->getQuery()
            ->getResult();
    }

    /**
     * Buscar credenciales próximas a vencer en X días.
     */
    public function findPorVencer(int $dias = 30): array {
        $fechaLimite = (new \DateTimeImmutable())->add(new \DateInterval("P{$dias}D"));
        return $this->createQueryBuilder('c')
            ->where('c.fechaVencimiento <= :fecha')
            ->andWhere('c.esActiva = true')
            ->setParameter('fecha', $fechaLimite)
            ->getQuery()
            ->getResult();
    }
}