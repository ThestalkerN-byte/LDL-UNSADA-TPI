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
            ->select('c', 'u')
            ->join('c.usuario', 'u')
            ->where('c.esActiva = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Buscar credenciales activas de un usuario específico.
     */
    public function findActivasByUsuario(int $idUsuario): array {
        return $this->createQueryBuilder('c')
            ->select('c', 'u')
            ->join('c.usuario', 'u')
            ->where('c.esActiva = true')
            ->andWhere('u.id = :idUsuario')
            ->setParameter('idUsuario', $idUsuario)
            ->getQuery()
            ->getResult();
    }

    /**
     * Buscar credenciales próximas a vencer en X días (y que no estén ya vencidas).
     */
    public function findPorVencer(int $dias = 30): array {
        $hoy = new \DateTimeImmutable('today');
        $fechaLimite = $hoy->add(new \DateInterval("P{$dias}D"));
        return $this->createQueryBuilder('c')
            ->select('c', 'u')
            ->join('c.usuario', 'u')
            ->where('c.fechaVencimiento >= :hoy')
            ->andWhere('c.fechaVencimiento <= :fecha')
            ->andWhere('c.esActiva = true')
            ->setParameter('hoy', $hoy)
            ->setParameter('fecha', $fechaLimite)
            ->getQuery()
            ->getResult();
    }
}