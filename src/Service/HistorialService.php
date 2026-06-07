<?php
declare(strict_types=1);
namespace ICB\Service;

/*
 * =========================================================================
 * SERVICIO: HistorialService
 * =========================================================================
 *
 * Servicio de consulta de registros de auditoría (historial_cambios).
 * Permite filtrar por usuario (admin que realizó el cambio), tabla afectada,
 * acción, y rango de fechas, con paginación.
 *
 * Los registros son de SOLO LECTURA — nunca se modifican ni eliminan.
 * =========================================================================
 */

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\HistorialCambio;

class HistorialService
{
    /** Manejador de Doctrine ORM para consultas a la base de datos */
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * =====================================================================
     * listar — Consulta paginada del historial con filtros
     * =====================================================================
     *
     * Parámetros de filtro (todos opcionales):
     * - usuarioId (int|null):   Filtrar por admin que realizó el cambio
     * - tabla     (string|null): Nombre de tabla afectada (usuarios, credenciales)
     * - accion    (string|null): Tipo de acción (CREAR, EDITAR, BAJA, RENOVAR, RESTAURAR)
     * - fechaDesde (string|null): Fecha inicio en formato 'Y-m-d'
     * - fechaHasta (string|null): Fecha fin en formato 'Y-m-d'
     * - page      (int):         Número de página (default: 1)
     * - limit     (int):         Resultados por página (default: 50, max: 100)
     *
     * Retorna: ['data' => [...], 'total' => int, 'page' => int, 'limit' => int]
     * =====================================================================
     */
    public function listar(
        ?int $usuarioId = null,
        ?string $tabla = null,
        ?string $accion = null,
        ?string $fechaDesde = null,
        ?string $fechaHasta = null,
        int $page = 1,
        int $limit = 50
    ): array {
        $qb = $this->em->createQueryBuilder();
        $qb->select('h')
           ->from(HistorialCambio::class, 'h')
           ->leftJoin('h.admin', 'a')
           ->addSelect('a')
           ->orderBy('h.fecha', 'DESC');

        // --- Filtros ---
        if ($usuarioId !== null) {
            $qb->andWhere('a.id_usuario = :usuarioId')
               ->setParameter('usuarioId', $usuarioId);
        }

        if ($tabla !== null) {
            $qb->andWhere('h.tablaAfectada = :tabla')
               ->setParameter('tabla', $tabla);
        }

        if ($accion !== null) {
            $qb->andWhere('h.accion = :accion')
               ->setParameter('accion', $accion);
        }

        if ($fechaDesde !== null) {
            $qb->andWhere('h.fecha >= :fechaDesde')
               ->setParameter('fechaDesde', $fechaDesde . ' 00:00:00');
        }

        if ($fechaHasta !== null) {
            $qb->andWhere('h.fecha <= :fechaHasta')
               ->setParameter('fechaHasta', $fechaHasta . ' 23:59:59');
        }

        // --- Paginación ---
        $page = max(1, $page);
        $limit = min(max(1, $limit), 100);

        // Total sin paginación
        $countQb = clone $qb;
        $countQb->select('COUNT(h.idHistorial)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Resultados paginados
        $qb->setFirstResult(($page - 1) * $limit)
           ->setMaxResults($limit);

        /** @var HistorialCambio[] $resultados */
        $resultados = $qb->getQuery()->getResult();

        // --- Serialización ---
        $data = array_map(function (HistorialCambio $h): array {
            return [
                'id' => $h->getIdHistorial(),
                'admin' => [
                    'id' => $h->getAdmin()->getIdUsuario(),
                    'nombre' => $h->getAdmin()->getNombre(),
                ],
                'tabla' => $h->getTablaAfectada(),
                'registro_id' => $h->getRegistroId(),
                'accion' => $h->getAccion(),
                'valor_anterior' => $h->getValorAnterior(),
                'valor_nuevo' => $h->getValorNuevo(),
                'fecha' => $h->getFecha()->format('Y-m-d H:i:s'),
            ];
        }, $resultados);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /*
     * =====================================================================
     * listarTablas — Obtiene la lista de tablas con registros de historial
     * =====================================================================
     *
     * Útil para llenar un dropdown de filtros en el frontend.
     * Retorna un array de strings con los nombres de tablas únicos.
     * =====================================================================
     */
    /*
     * =====================================================================
     * purge — Elimina registros de historial más antiguos que N días
     * =====================================================================
     *
     * @param int $days  Antigüedad máxima en días (default: 365)
     * @return int       Cantidad de registros eliminados
     * =====================================================================
     */
    public function purge(int $days = 365): int
    {
        $fechaLimite = new \DateTimeImmutable("-{$days} days");

        $dql = 'DELETE FROM ICB\Entity\HistorialCambio h WHERE h.fecha < :fechaLimite';
        $query = $this->em->createQuery($dql);
        $query->setParameter('fechaLimite', $fechaLimite);

        $eliminados = $query->execute();

        error_log("[ICB] Historial purged: {$eliminados} records older than {$days} days");

        return $eliminados;
    }

    /*
     * =====================================================================
     * listarTablas — Obtiene la lista de tablas con registros de historial
     * =====================================================================
     */
    public function listarTablas(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('DISTINCT h.tablaAfectada')
           ->from(HistorialCambio::class, 'h')
           ->orderBy('h.tablaAfectada', 'ASC');

        return array_map('current', $qb->getQuery()->getScalarResult());
    }
}
