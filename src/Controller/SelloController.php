<?php
declare(strict_types=1);
namespace ICB\Controller;

/*
 * =========================================================================
 * CONTROLADOR: SelloController
 * =========================================================================
 *
 * Endpoints públicos para consultar sellos institucionales.
 * No requieren autenticación porque los sellos se muestran en las
 * credenciales digitales publicadas (no son datos sensibles).
 *
 * FLUJO DE EJECUCIÓN:
 *   Router -> SelloController -> SelloService -> EntityManager -> DB
 *
 * DIFERENCIA CON ADMIN:
 *   AdminController::listarSellos() devuelve TODOS los sellos (activos
 *   e inactivos). Este controller público devuelve SOLO los activos.
 *
 * FORMATO DE RESPUESTA:
 *   { "data": [...], "total": N }
 * =========================================================================
 */

use Doctrine\ORM\EntityManagerInterface;
use ICB\Request\Request;
use ICB\Service\SelloService;

class SelloController
{
    private SelloService $selloService;

    /*
     * Constructor: recibe el EntityManager y crea el servicio de sellos.
     *
     * Sigue el mismo patrón que los demás controladores del proyecto:
     * recibe EntityManagerInterface en lugar de los services directamente,
     * porque el proyecto no tiene un DI container.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->selloService = new SelloService($em);
    }

    /*
     * GET /api/sellos — Lista sellos activos
     *
     * Retorna solo los sellos que están marcados como activos.
     * Es un endpoint público (sin middleware) porque las credenciales
     * digitales se muestran en sitios públicos y necesitan los sellos.
     *
     * @param Request $request
     * @param array   $params  Parámetros de la ruta (vacíos para esta ruta)
     * @return array           { data: Sello[], total: int }
     *
     * Decisión técnica: el method recibe array $params por contrato del
     * Router (Router.php:171 llama con $request y $params siempre).
     * Aunque esta ruta no tenga parámetros, mantenemos la firma uniforme.
     */
    public function listarActivos(Request $request, array $params): array
    {
        $sellos = $this->selloService->listar(true);

        return [
            'data' => $sellos,
            'total' => count($sellos),
        ];
    }
}
