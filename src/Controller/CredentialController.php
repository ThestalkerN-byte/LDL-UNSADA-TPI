<?php
namespace App\Controller;

use App\Service\CredentialService;
use App\Service\AlertService;
use App\Repository\CredentialRepository;
use App\Entity\Credential;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para credenciales.
 *
 * Gestiona el ciclo de vida completo de las credenciales institucionales:
 * listado, detalle, alta, edición, baja lógica, renovación y alertas.
 *
 * Rutas disponibles (via ?action=credential):
 *   GET    ?action=credential                → index()   Lista credenciales activas
 *   GET    ?action=credential&id={id}        → show()    Detalle de una credencial
 *   POST   ?action=credential                → create()  Emitir nueva credencial
 *   PUT    ?action=credential&id={id}        → update()  Editar credencial existente
 *   DELETE ?action=credential&id={id}        → delete()  Dar de baja una credencial
 *   POST   ?action=credential&sub=renew&id={id} → renew() Renovar con historial
 *   GET    ?action=credential&sub=alerts     → alerts()  Credenciales por vencer
 */
class CredentialController {

    private CredentialService $credentialService;
    private AlertService $alertService;
    private CredentialRepository $credentialRepository;
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        $this->credentialRepository = $em->getRepository(Credential::class);
        $credentialService = new CredentialService();
        $this->credentialService = $credentialService;
        $this->alertService = new AlertService($credentialService);
    }

    // =========================================================================
    // Router interno: despacha según método HTTP y parámetro ?sub=
    // =========================================================================

    /**
     * Punto de entrada único desde index.php.
     * Lee el método HTTP y el parámetro ?sub= para delegar al método correcto.
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $sub    = $_GET['sub'] ?? null;
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        // Sub-rutas especiales
        if ($method === 'GET' && $sub === 'alerts') {
            $this->alerts();
            return;
        }
        if ($method === 'POST' && $sub === 'renew' && $id !== null) {
            $this->renew($id);
            return;
        }

        // CRUD estándar
        match ($method) {
            'GET'    => $id ? $this->show($id) : $this->index(),
            'POST'   => $this->create(),
            'PUT'    => $id ? $this->update($id) : $this->responder(400, 'error', 'Se requiere un ID para actualizar.'),
            'DELETE' => $id ? $this->delete($id) : $this->responder(400, 'error', 'Se requiere un ID para eliminar.'),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    // =========================================================================
    // GET ?action=credential → Lista todas las credenciales activas
    // =========================================================================

    /**
     * Devuelve la lista de todas las credenciales activas (es_activa = true),
     * mapeadas al DTO (con datos sensibles ocultados si están vencidas).
     */
    private function index(): void {
        $credenciales = $this->credentialRepository->findActivas();

        $dtos = array_map(
            fn($cred) => $this->serializeDTO($this->credentialService->mapToDTO($cred)),
            $credenciales
        );

        $this->responder(200, 'success', 'Credenciales obtenidas.', $dtos);
    }

    // =========================================================================
    // GET ?action=credential&id={id} → Detalle de una credencial
    // =========================================================================

    /**
     * Devuelve el detalle de una credencial activa por su ID.
     * Si está vencida, los campos sensibles (DNI, sellos) llegan como null.
     */
    private function show(int $id): void {
        $credencial = $this->credentialRepository->find($id);

        if (!$credencial || !$credencial->isEsActiva()) {
            $this->responder(404, 'error', 'Credencial no encontrada.');
            return;
        }

        $dto = $this->serializeDTO($this->credentialService->mapToDTO($credencial));
        $this->responder(200, 'success', 'Credencial encontrada.', $dto);
    }

    // =========================================================================
    // POST ?action=credential → Emitir nueva credencial a un usuario (RF08)
    // =========================================================================

    /**
     * Crea y emite una credencial nueva para un usuario.
     *
     * Body JSON esperado:
     * {
     *   "id_usuario": 5,
     *   "fecha_vencimiento": "2027-06-01",
     *   "sellos": ["UNSADA", "SACRA"]   (opcional)
     * }
     */
    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validación mínima de campos requeridos
        if (empty($data['id_usuario']) || empty($data['fecha_vencimiento'])) {
            $this->responder(400, 'error', 'Los campos id_usuario y fecha_vencimiento son obligatorios.');
            return;
        }

        // Buscar el usuario al que se le emite la credencial
        $usuario = $this->em->getRepository(User::class)->find((int)$data['id_usuario']);
        if (!$usuario || !$usuario->isEstado()) {
            $this->responder(404, 'error', 'Usuario no encontrado o inactivo.');
            return;
        }

        // Parsear la fecha de vencimiento
        $fechaVencimiento = \DateTime::createFromFormat('Y-m-d', $data['fecha_vencimiento']);
        if (!$fechaVencimiento) {
            $this->responder(400, 'error', 'Formato de fecha inválido. Use YYYY-MM-DD.');
            return;
        }

        // Desactivar cualquier credencial activa previa del usuario
        $activaAnterior = $this->credentialRepository->findOneBy(['usuario' => $usuario, 'esActiva' => true]);
        if ($activaAnterior) {
            $activaAnterior->setEsActiva(false);
        }

        // Crear la nueva credencial
        $credencial = new Credential();
        $credencial->setUsuario($usuario);
        $credencial->setFechaEmision(new \DateTime('today'));
        $credencial->setFechaVencimiento($fechaVencimiento);
        $credencial->setSellos($data['sellos'] ?? []);
        $credencial->setEsActiva(true);

        $this->em->persist($credencial);
        $this->em->flush();
        $this->registrarHistorial("Emisión de credencial ID: " . $credencial->getId() . " para el usuario: " . $usuario->getUsuario());
        $this->em->flush();

        $this->responder(201, 'success', 'Credencial emitida correctamente.', [
            'id'               => $credencial->getId(),
            'fecha_emision'    => $credencial->getFechaEmision()->format('Y-m-d'),
            'fecha_vencimiento'=> $credencial->getFechaVencimiento()->format('Y-m-d'),
        ]);
    }

    // =========================================================================
    // PUT ?action=credential&id={id} → Editar datos de la credencial (RF08)
    // =========================================================================

    /**
     * Actualiza campos editables de una credencial activa existente.
     * Solo el admin puede modificar: sellos y fecha de vencimiento.
     *
     * Body JSON (todos los campos son opcionales):
     * {
     *   "fecha_vencimiento": "2028-01-15",
     *   "sellos": ["UNSADA"]
     * }
     */
    private function update(int $id): void {
        $credencial = $this->credentialRepository->find($id);

        if (!$credencial || !$credencial->isEsActiva()) {
            $this->responder(404, 'error', 'Credencial no encontrada o ya inactiva.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Actualizar fecha de vencimiento si viene en el body
        if (!empty($data['fecha_vencimiento'])) {
            $nuevaFecha = \DateTime::createFromFormat('Y-m-d', $data['fecha_vencimiento']);
            if (!$nuevaFecha) {
                $this->responder(400, 'error', 'Formato de fecha inválido. Use YYYY-MM-DD.');
                return;
            }
            $credencial->setFechaVencimiento($nuevaFecha);
        }

        // Actualizar sellos si vienen en el body
        if (array_key_exists('sellos', $data)) {
            $credencial->setSellos($data['sellos']);
        }

        $this->registrarHistorial("Modificación de credencial ID: " . $credencial->getId() . " para el usuario: " . $credencial->getUsuario()->getUsuario());
        $this->em->flush();

        $this->responder(200, 'success', 'Credencial actualizada correctamente.', [
            'id'               => $credencial->getId(),
            'fecha_vencimiento'=> $credencial->getFechaVencimiento()->format('Y-m-d'),
        ]);
    }

    // =========================================================================
    // DELETE ?action=credential&id={id} → Dar de baja una credencial (RF08)
    // =========================================================================

    /**
     * Realiza una baja lógica de la credencial: setea esActiva = false.
     * No borra el registro físicamente para conservar el historial.
     */
    private function delete(int $id): void {
        $credencial = $this->credentialRepository->find($id);

        if (!$credencial || !$credencial->isEsActiva()) {
            $this->responder(404, 'error', 'Credencial no encontrada o ya inactiva.');
            return;
        }

        $credencial->setEsActiva(false);
        $this->registrarHistorial("Baja de credencial ID: " . $credencial->getId() . " para el usuario: " . $credencial->getUsuario()->getUsuario());
        $this->em->flush();

        $this->responder(200, 'success', 'Credencial dada de baja correctamente.');
    }

    // =========================================================================
    // POST ?action=credential&sub=renew&id={id} → Renovar credencial (RF09)
    // =========================================================================

    /**
     * Renueva la credencial indicada conservando el historial (CU2/RF09).
     * - Marca la credencial actual como esActiva = false.
     * - Crea una nueva credencial activa con fecha recalculada.
     * - Si la vieja estaba vencida: suma desde hoy.
     * - Si todavía vigente: suma desde su fecha de vencimiento (no penaliza).
     */
    private function renew(int $id): void {
        $credencial = $this->credentialRepository->find($id);

        if (!$credencial || !$credencial->isEsActiva()) {
            $this->responder(404, 'error', 'Credencial no encontrada o ya inactiva.');
            return;
        }

        // 1. Marcar la credencial actual como histórica
        $credencial->setEsActiva(false);

        // 2. Crear la nueva credencial copiando el usuario y sellos
        $nuevaCred = new Credential();
        $nuevaCred->setUsuario($credencial->getUsuario());
        $nuevaCred->setSellos($credencial->getSellos());
        $nuevaCred->setFechaEmision(new \DateTime('today'));
        $nuevaCred->setEsActiva(true);

        // 3. Calcular nueva fecha de vencimiento con la lógica inteligente
        $nuevaFecha = $this->credentialService->renovar($credencial->getFechaVencimiento());
        $nuevaCred->setFechaVencimiento($nuevaFecha);

        // 4. Persistir y guardar
        $this->em->persist($nuevaCred);
        $this->em->flush();
        $this->registrarHistorial("Renovación de credencial (ID previa: " . $id . ", ID nueva: " . $nuevaCred->getId() . ") para el usuario: " . $nuevaCred->getUsuario()->getUsuario());
        $this->em->flush();

        $this->responder(200, 'success', 'Credencial renovada exitosamente.', [
            'nueva_id'         => $nuevaCred->getId(),
            'fecha_emision'    => $nuevaCred->getFechaEmision()->format('Y-m-d'),
            'fecha_vencimiento'=> $nuevaFecha->format('Y-m-d'),
        ]);
    }

    // =========================================================================
    // GET ?action=credential&sub=alerts → Motor de alertas (RF12/CU5)
    // =========================================================================

    /**
     * Devuelve las credenciales activas próximas a vencer en los próximos 30 días.
     * Usa la query optimizada del repositorio (no filtra en memoria).
     */
    private function alerts(): void {
        $porVencer = $this->credentialRepository->findPorVencer();

        $dtos = array_map(
            fn($cred) => $this->serializeDTO($this->credentialService->mapToDTO($cred)),
            $porVencer
        );

        $this->responder(200, 'success', count($dtos) . ' credencial/es próxima/s a vencer.', $dtos);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Helper para registrar auditoría.
     */
    private function registrarHistorial(string $accion): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $adminId = $_SESSION['id_usuario'] ?? $_GET['admin_id'] ?? null;
        $admin = null;
        if ($adminId) {
            $admin = $this->em->getRepository(User::class)->find((int)$adminId);
        }
        if (!$admin) {
            $admin = $this->em->getRepository(User::class)->findOneBy(['rol' => 'admin']);
        }

        if ($admin) {
            $historial = new \App\Entity\History();
            $historial->setAccion($accion);
            $historial->setFecha(new \DateTime());
            $historial->setAdmin($admin);
            $this->em->persist($historial);
        }
    }

    /**
     * Convierte un CredentialDTO en un array serializable para JSON.
     */
    private function serializeDTO(\App\DTO\CredentialDTO $dto): array {
        return [
            'id'               => $dto->getId(),
            'nombre'           => $dto->getNombre(),
            'apellido'         => $dto->getApellido(),
            'dni'              => $dto->getDni(),
            'rol'              => $dto->getRol(),
            'fecha_vencimiento'=> $dto->getFechaVencimiento()->format('Y-m-d'),
            'estado'           => $dto->getEstado()->value,
            'sellos'           => $dto->getSellos(),
        ];
    }

    /**
     * Emite la respuesta JSON estandarizada { status, message, data } y termina.
     */
    private function responder(int $httpCode, string $status, string $message, array|null $data = []): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data ?? [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}