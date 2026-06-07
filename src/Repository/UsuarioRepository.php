<?php

declare(strict_types=1);

namespace ICB\Repository;

/*
 * =========================================================================
 * REPOSITORIO: UsuarioRepository
 * =========================================================================
 *
 * Repositorio personalizado para la entidad Usuario.
 * Extiende EntityRepository, lo que ya nos da métodos básicos como:
 *   - findAll(), findBy(), findOneBy(), find($id)
 *   - count(), createQueryBuilder()
 *
 * Acá agregamos métodos de búsqueda específicos para el negocio.
 * =========================================================================
 */

use Doctrine\ORM\EntityRepository;
use ICB\Entity\Usuario;

class UsuarioRepository extends EntityRepository
{
    /**
     * Busca un usuario por su número de DNI.
     */
    public function findByDni(string $dni): ?Usuario
    {
        return $this->findOneBy(['dni' => $dni]);
    }

    /**
     * Busca un usuario por su nombre de usuario (login).
     */
    public function findByUsuario(string $usuario): ?Usuario
    {
        return $this->findOneBy(['usuario' => $usuario]);
    }

    /**
     * Busca un usuario por su correo electrónico.
     */
    public function findByEmail(string $email): ?Usuario
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Busca un usuario por identificador de login.
     * Acepta tanto nombre de usuario como DNI.
     * Esto permite que el login funcione con cualquiera de los dos campos.
     *
     * Ejemplo: findByIdentifier('admin') o findByIdentifier('12345678')
     */
    public function findByIdentifier(string $identifier): ?Usuario
    {
        $dql = 'SELECT u FROM ICB\Entity\Usuario u WHERE u.usuario = :identifier OR u.dni = :identifier';
        return $this->getEntityManager()->createQuery($dql)
            ->setParameter('identifier', $identifier)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * Busca usuarios aplicando filtros de búsqueda.
     * Soporta búsqueda por texto parcial (DNI, apellido, función)
     * y filtrado por estado (Activo/Inactivo).
     *
     * Usa QueryBuilder de Doctrine para construir la consulta
     * de forma dinámica y segura (SQL injection safe).
     *
     * @param string|null $query  Texto a buscar (DNI, apellido o función)
     * @param string|null $estado Filtrar por estado ('Activo' o 'Inactivo')
     * @return Usuario[]
     */
    public function search(?string $query = null, ?string $estado = null): array
    {
        $qb = $this->createQueryBuilder('u');

        if ($query) {
            // Búsqueda parcial con LIKE (no exacta)
            $qb->andWhere('u.dni LIKE :query OR u.apellido LIKE :query OR u.funcion LIKE :query')
               ->setParameter('query', "%{$query}%");
        }

        if ($estado) {
            $qb->andWhere('u.estado = :estado')
               ->setParameter('estado', $estado);
        }

        return $qb
            ->orderBy('u.apellido', 'ASC')
            ->addOrderBy('u.nombre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Devuelve todos los usuarios que tienen el rol 'Admin'.
     * Útil para notificaciones internas o validaciones.
     *
     * @return Usuario[]
     */
    public function findAdminUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.roles', 'r')   // JOIN con la tabla roles
            ->where('r.nombre = :rol')
            ->setParameter('rol', 'Admin')
            ->getQuery()
            ->getResult();
    }
}
