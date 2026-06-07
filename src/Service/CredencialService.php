<?php
declare(strict_types=1);
namespace ICB\Service;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Credencial;
use ICB\Entity\Usuario;
use ICB\Exception\ValidationException;
use ICB\Validation\ValidationHelper;

/*
 * CREDENCIAL SERVICE: Emisión y renovación de credenciales digitales
 * ===================================================================
 * Gestiona el ciclo de vida completo de las credenciales digitales
 * que los miembros de la iglesia usan como identificación.
 *
 * FLUJO:
 *   1. Admin inicia emisión o renovación desde el panel
 *   2. CredencialService valida los datos
 *   3. Genera código QR único para la credencial
 *   4. La credencial queda activa desde su emisión hasta su vencimiento
 *   5. Al renovar, la credencial anterior se marca con fecha de baja
 *
 * REGLAS DE NEGOCIO:
 *   - Una credencial se emite con fecha de emisión (hoy) y fecha de vencimiento
 *   - Cada credencial tiene un código QR único (formato: ICB-{32 hex chars})
 *   - Renovar = crear nueva credencial + dar de baja la anterior (fecha_baja)
 *   - Una credencial está activa si: no tiene fecha_baja Y no está vencida
 *   - Varias credenciales pueden existir para un usuario (histórico),
 *     pero solo una está activa a la vez
 *
 * RELACIONES:
 *   - Credencial: entidad que persiste en tabla 'credenciales'
 *   - Usuario: cada credencial pertenece a un usuario (ManyToOne)
 *   - HistorialCambio: registra emisión y renovación
 *
 * DECISIONES TÉCNICAS:
 *   - El código QR es un identificador alfanumérico único (no una imagen).
 *     La generación de la imagen QR se delega al frontend.
 *   - Al renovar, la credencial anterior NO se elimina: se marca con
 *     fecha_baja para mantener el histórico de credenciales del usuario.
 *   - No hay un método 'revocar' explícito porque renovar() ya cumple
 *     esa función: la credencial anterior queda desactivada.
 */
class CredencialService
{
    private EntityManagerInterface $em;

    /*
     * Constructor: recibe el EntityManager de Doctrine.
     * No necesitamos un repositorio específico porque las operaciones
     * se resuelven con find() y findBy() estándar.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * LISTAR: Devuelve todas las credenciales, opcionalmente filtradas por usuario.
     *
     * @param int|null $idUsuario Filtra por usuario específico (opcional)
     * @return array              Credenciales serializadas, ordenadas por emisión DESC
     *
     * Si no se pasa idUsuario, devuelve TODAS las credenciales del sistema.
     * Útil para el panel de admin que necesita ver el histórico completo.
     */
    public function listar(?int $idUsuario = null): array
    {
        $criteria = $idUsuario ? ['usuario' => $idUsuario] : [];
        $credenciales = $this->em->getRepository(Credencial::class)->findBy($criteria, ['fechaEmision' => 'DESC']);

        return array_map(fn(Credencial $c) => $this->serializar($c), $credenciales);
    }

    /*
     * EMITIR: Crea una nueva credencial digital para un usuario.
     *
     * @param array   $datos {
     *     id_usuario: int (requerido)
     *     fecha_vencimiento: string (requerido, formato YYYY-MM-DD)
     *     foto?: string (URL opcional de la foto)
     * }
     * @param Usuario $admin Admin que ejecuta la acción
     * @return Credencial    Nueva credencial emitida
     *
     * @throws ValidationException si faltan campos requeridos
     *
     * Flujo:
     *   1. Validar id_usuario y fecha_vencimiento
     *   2. Verificar que el usuario existe
     *   3. Crear Credencial con fecha_emision = today, fecha_vencimiento = la indicada
     *   4. Generar código QR único
     *   5. Persistir y registrar en historial
     */
    public function emitir(array $datos, Usuario $admin): Credencial
    {
        // --- Validación estricta de datos de entrada ---
        ValidationHelper::acumular([
            ValidationHelper::requerido('id_usuario', $datos['id_usuario'] ?? null),
            ValidationHelper::enteroPositivo('id_usuario', $datos['id_usuario'] ?? null),
            ValidationHelper::requerido('fecha_vencimiento', $datos['fecha_vencimiento'] ?? null),
            ValidationHelper::fecha('fecha_vencimiento', $datos['fecha_vencimiento'] ?? null),
            ValidationHelper::fechaFutura('fecha_vencimiento', $datos['fecha_vencimiento'] ?? null),
        ]);

        // Validar foto si se proporcionó
        if (isset($datos['foto'])) {
            ValidationHelper::acumular([
                ValidationHelper::url('foto', $datos['foto']),
            ]);
        }

        $idUsuario = $datos['id_usuario'];
        $usuario = $this->em->find(Usuario::class, $idUsuario);
        if (!$usuario) {
            throw new ValidationException('Usuario no encontrado');
        }

        // Verificar que el usuario no tenga ya una credencial activa
        $activa = $this->em->getRepository(\ICB\Entity\Credencial::class)->findOneBy([
            'usuario' => $idUsuario,
            'fechaBaja' => null,
        ]);
        if ($activa !== null) {
            throw new ValidationException('El usuario ya tiene una credencial activa');
        }

        $fechaVencimiento = $datos['fecha_vencimiento'];

        $credencial = new Credencial();
        $credencial->setUsuario($usuario)
                   ->setFechaEmision(new \DateTime())
                   ->setFechaVencimiento(new \DateTime($fechaVencimiento))
                   ->setFoto($datos['foto'] ?? null)
                   ->setCodigoQr($this->generarCodigoQr());

        $this->em->persist($credencial);
        $this->em->flush();

        $this->registrarHistorial($admin, 'credenciales', $credencial->getIdCredencial(), 'CREAR', null, $this->serializar($credencial));

        return $credencial;
    }

    /*
     * RENOVAR: Renueva una credencial existente.
     *
     * La credencial anterior se desactiva (fecha_baja = now) y se crea
     * una nueva con nuevas fechas. Esto mantiene el histórico completo
     * de credenciales del usuario para trazabilidad.
     *
     * @param int     $idCredencial ID de la credencial a renovar
     * @param array   $datos {
     *     fecha_vencimiento: string (nueva fecha, opcional, default '+1 year')
     *     foto?: string (nueva foto, opcional, conserva la anterior si no se envía)
     * }
     * @param Usuario $admin Admin que ejecuta la acción
     * @return Credencial    Nueva credencial emitida
     *
     * @throws ValidationException si la credencial original no existe
     *
     * Decisión técnica: la nueva credencial es un registro completamente
     * nuevo con su propio ID y código QR. No reutilizamos el ID anterior
     * porque cada emisión debe ser un evento único en el tiempo.
     */
    public function renovar(int $idCredencial, array $datos, Usuario $admin): Credencial
    {
        // --- Validar fecha_vencimiento si se proporcionó ---
        if (array_key_exists('fecha_vencimiento', $datos) && $datos['fecha_vencimiento'] !== null) {
            ValidationHelper::acumular([
                ValidationHelper::fecha('fecha_vencimiento', $datos['fecha_vencimiento']),
                ValidationHelper::fechaFutura('fecha_vencimiento', $datos['fecha_vencimiento']),
            ]);
        }

        // Validar foto si se proporcionó
        if (isset($datos['foto'])) {
            ValidationHelper::acumular([
                ValidationHelper::url('foto', $datos['foto']),
            ]);
        }

        $credencialAnterior = $this->em->find(Credencial::class, $idCredencial);
        if (!$credencialAnterior) {
            throw new ValidationException('Credencial no encontrada');
        }

        // Desactivar la anterior
        $credencialAnterior->setFechaBaja(new \DateTime());

        // Crear nueva credencial con datos de la anterior + los nuevos
        $nuevaCredencial = new Credencial();
        $nuevaCredencial->setUsuario($credencialAnterior->getUsuario())
                        ->setFechaEmision(new \DateTime())
                        ->setFechaVencimiento(new \DateTime($datos['fecha_vencimiento'] ?? '+1 year'))
                        ->setFoto($datos['foto'] ?? $credencialAnterior->getFoto())
                        ->setCodigoQr($this->generarCodigoQr());

        $this->em->persist($nuevaCredencial);
        $this->em->flush();

        $this->registrarHistorial($admin, 'credenciales', $nuevaCredencial->getIdCredencial(), 'RENOVAR',
            $this->serializar($credencialAnterior), $this->serializar($nuevaCredencial));

        return $nuevaCredencial;
    }

    /*
     * =====================================================================
     * obtenerActiva — Busca la credencial activa de un usuario
     * =====================================================================
     *
     * Una credencial está activa si no tiene fecha de baja y no está vencida.
     * Cada usuario puede tener solo una credencial activa a la vez.
     *
     * Retorna el array serializado de la credencial activa, o null si
     * el usuario no tiene ninguna.
     * =====================================================================
     */
    public function obtenerActiva(int $idUsuario): ?array
    {
        $credencial = $this->em->getRepository(Credencial::class)->findOneBy([
            'usuario' => $idUsuario,
            'fechaBaja' => null,
        ]);

        if (!$credencial) {
            return null;
        }

        return $this->serializar($credencial);
    }

    /*
     * GENERAR CÓDIGO QR: Produce un identificador único por credencial.
     *
     * Formato: ICB-{32 caracteres hexadecimales mayúsculas}
     * Ejemplo: ICB-A1B2C3D4E5F6789012345678ABCDEF01
     *
     * Decisión técnica:
     *   - Usamos random_bytes(16) que genera 16 bytes criptográficamente seguros
     *   - bin2hex convierte a 32 caracteres hex (128 bits de entropía)
     *   - Prefijo 'ICB-' para identificar visualmente el origen
     *   - No guardamos una imagen QR en la DB, solo el identificador.
     *     La imagen QR se genera en el frontend con una librería como qrcode.js
     *   - La unicidad la garantiza la combinación de 128 bits de entropía +
     *     el unique constraint en la columna codigo_qr de la DB
     */
    private function generarCodigoQr(): string
    {
        return 'ICB-' . strtoupper(bin2hex(random_bytes(16)));
    }

    /*
     * SERIALIZAR: Convierte una entidad Credencial a array plano.
     *
     * Incluye datos del usuario asociado (nombre, apellido, DNI) para
     * evitar N+1 queries cuando el frontend necesita mostrar la lista.
     * Como la relación es ManyToOne, Doctrine ya hizo el JOIN automáticamente
     * al hacer findBy(), así que no hay penalidad de rendimiento.
     */
    private function serializar(Credencial $c): array
    {
        return [
            'id'                => $c->getIdCredencial(),
            'id_usuario'        => $c->getUsuario()->getIdUsuario(),
            'usuario_nombre'    => $c->getUsuario()->getNombre() . ' ' . $c->getUsuario()->getApellido(),
            'usuario_dni'       => $c->getUsuario()->getDni(),
            'fecha_emision'     => $c->getFechaEmision()->format('Y-m-d'),
            'fecha_vencimiento' => $c->getFechaVencimiento()->format('Y-m-d'),
            'foto'              => $c->getFoto(),
            'codigo_qr'         => $c->getCodigoQr(),
            'activa'            => $c->estaActiva(),
            'fecha_baja'        => $c->getFechaBaja()?->format('Y-m-d H:i:s'),
        ];
    }

    /*
     * REGISTRAR HISTORIAL: Persiste un registro de auditoría.
     * Misma implementación que en UsuarioService para mantener consistencia.
     *
     * Decisión técnica: podríamos extraer esto a un trait compartido,
     * pero preferimos la duplicación controlada por ahora porque:
     *   1. Solo dos services lo usan
     *   2. Un trait añadiría una dependencia oculta
     *   3. Si después cambia la lógica de historial, es fácil refactorizar
     */
    private function registrarHistorial(Usuario $admin, string $tabla, int $registroId, string $accion, ?array $valorAnterior, ?array $valorNuevo): void
    {
        $historial = new \ICB\Entity\HistorialCambio();
        $historial->setAdmin($admin)
                  ->setTablaAfectada($tabla)
                  ->setRegistroId($registroId)
                  ->setAccion($accion)
                  ->setValorAnterior($valorAnterior ? json_encode($valorAnterior, JSON_UNESCAPED_UNICODE) : null)
                  ->setValorNuevo($valorNuevo ? json_encode($valorNuevo, JSON_UNESCAPED_UNICODE) : null);
        $this->em->persist($historial);
        $this->em->flush();
    }
}
