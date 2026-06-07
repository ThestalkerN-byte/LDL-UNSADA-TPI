<?php
declare(strict_types=1);
namespace ICB\Controller;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Usuario;
use ICB\Exception\ValidationException;
use ICB\Exception\NotFoundException;
use ICB\Request\Request;
use ICB\Service\CredencialService;
use ICB\Service\ConversacionService;
use ICB\Service\HistorialService;
use ICB\Service\SelloService;
use ICB\Service\UsuarioService;
use ICB\Validation\ValidationHelper;

/*
 * ADMIN CONTROLLER: Endpoints de administración del sistema
 * ==========================================================
 * Agrupa todos los endpoints que requieren permisos de administrador.
 * Cada método recibe (Request, array $params) para que el Router pueda
 * hacer dispatch de forma uniforme.
 *
 * FLUJO DE EJECUCIÓN:
 *   Router -> AuthMiddleware::admin() (valida JWT + rol Admin) ->
 *   AdminController -> Service -> EntityManager -> DB
 *
 * MIDDLEWARE REQUERIDO:
 *   Todos los endpoints requieren pasar por AuthMiddleware::admin().
 *   El middleware inyecta el Usuario autenticado via $request->setAttribute().
 *
 * MÓDULOS:
 *   - Usuarios:     CRUD completo + restaurar (baja lógica)
 *   - Credenciales: listar, emitir, renovar
 *   - Sellos:       listar, crear, actualizar
 *
 * FORMATO DE RESPUESTA:
 *   Éxito:  { "data": {...}, "message": "..." }
 *   Error:  { "error": "...", "code": 400|404 }
 *   Lista:  { "data": [...], "total": N }
 *
 * DECISIONES TÉCNICAS:
 *   - Cada método es autónomo (no depende de estado del controller)
 *   - Los services se instancian en el constructor por conveniencia.
 *     En una app más grande inyectaríamos con DI Container.
 *   - Capturamos excepciones acá en vez de usar un handler global
 *     porque queremos control explícito del formato de respuesta.
 *     Si después migramos a un framework con ExceptionHandler,
 *     podemos refactorizar.
 *   - No exponemos entities directamente: todo pasa por serialización
 *     o por services que devuelven arrays.
 */
class AdminController
{
    private EntityManagerInterface $em;
    private UsuarioService $usuarioService;
    private CredencialService $credencialService;
    private ConversacionService $conversacionService;
    private SelloService $selloService;
    private HistorialService $historialService;

    /*
     * Constructor: recibe el EntityManager y crea los services.
     *
     * Decisión técnica: instanciamos los services directamente
     * en vez de recibirlos por inyección porque este proyecto no
     * tiene un DI container. Si en el futuro agregamos uno (PHP-DI,
     * Symfony DI), cambiamos el constructor para recibir los services.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->usuarioService = new UsuarioService($em);
        $this->credencialService = new CredencialService($em);
        $this->selloService = new SelloService($em);
        $this->conversacionService = new ConversacionService($em);
        $this->historialService = new HistorialService($em);
    }

    // ═══════════════════════════════════════════════════════════════
    //  USUARIOS
    // ═══════════════════════════════════════════════════════════════

    /*
     * GET /api/admin/usuarios
     *
     * Lista usuarios con filtros opcionales de búsqueda y estado.
     * Query params: ?busqueda=&estado=
     *
     * La búsqueda es textual parcial sobre DNI, apellido y función.
     * El filtro de estado permite ver solo activos, inactivos o todos.
     */
    public function listarUsuarios(Request $request, array $params): array
    {
        $busqueda = $request->query('busqueda');
        $estado = $request->query('estado');

        if ($estado !== null) {
            $errorEstado = ValidationHelper::enum('estado', $estado, ['Activo', 'Inactivo']);
            if ($errorEstado) {
                return ['error' => $errorEstado, 'code' => 400];
            }
        }

        $usuarios = $this->usuarioService->listar($busqueda, $estado);
        return ['data' => $usuarios, 'total' => count($usuarios)];
    }

    /*
     * GET /api/admin/usuarios/{id}
     *
     * Obtiene un usuario por ID con todos sus datos.
     * Incluye roles y fecha de alta.
     */
    public function obtenerUsuario(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $usuario = $this->usuarioService->obtener((int)$params['id']);
            return [
                'data'    => $this->serializarUsuario($usuario),
                'message' => 'Usuario obtenido correctamente',
            ];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/usuarios
     *
     * Crea un nuevo usuario en el sistema.
     *
     * Body: {
     *   dni, usuario, password, nombre, apellido, email (requeridos)
     *   telefono?, direccion?, funcion?, roles? (opcionales)
     * }
     *
     * Los roles por defecto son ['Usuario'] si no se especifican.
     */
    public function crearUsuario(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            $usuario = $this->usuarioService->crear($request->body(), $admin);
            return [
                'data' => $this->serializarUsuario($usuario),
                'message' => 'Usuario creado exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * PUT /api/admin/usuarios/{id}
     *
     * Actualiza datos de un usuario existente.
     * Todos los campos del body son opcionales.
     * Solo se actualizan los campos presentes en la request.
     *
     * Body: { nombre?, apellido?, email?, dni?, usuario?, ... }
     */
    public function actualizarUsuario(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $usuario = $this->usuarioService->actualizar((int)$params['id'], $request->body(), $admin);
            return [
                'data' => $this->serializarUsuario($usuario),
                'message' => 'Usuario actualizado exitosamente',
            ];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * DELETE /api/admin/usuarios/{id}
     *
     * Baja lógica de usuario: cambia estado a 'Inactivo'.
     * El registro no se elimina de la base de datos.
     *
     * Para reactivar, usar POST /api/admin/usuarios/{id}/restaurar.
     */
    public function eliminarUsuario(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            // Seguridad: evitar que un admin se desactive a sí mismo
            if ((int)$params['id'] === $admin->getIdUsuario()) {
                return ['error' => 'No podés desactivar tu propio usuario', 'code' => 400];
            }
            $this->usuarioService->eliminar((int)$params['id'], $admin);
            return ['message' => 'Usuario dado de baja exitosamente'];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/usuarios/{id}/restaurar
     *
     * Restaura un usuario inactivo a activo.
     * Es la operación inversa a DELETE (baja lógica).
     */
    public function restaurarUsuario(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $usuario = $this->usuarioService->restaurar((int)$params['id'], $admin);
            return [
                'data' => $this->serializarUsuario($usuario),
                'message' => 'Usuario restaurado exitosamente',
            ];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * GET /api/admin/usuarios/{id}/historial
     *
     * Obtiene el historial de cambios de un usuario específico.
     * Útil para auditar qué cambios se hicieron sobre un usuario.
     */
    public function historialUsuario(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $query = $request->query();
            return $this->historialService->listar(
                usuarioId: (int)$params['id'],
                tabla: $query['tabla'] ?? null,
                accion: $query['accion'] ?? null,
                fechaDesde: $query['fecha_desde'] ?? null,
                fechaHasta: $query['fecha_hasta'] ?? null,
                page: isset($query['page']) ? (int) $query['page'] : 1,
                limit: isset($query['limit']) ? (int) $query['limit'] : 50,
            );
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => 400];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  CREDENCIALES
    // ═══════════════════════════════════════════════════════════════

    /*
     * GET /api/admin/credenciales
     *
     * Lista todas las credenciales. Opcionalmente filtradas por usuario.
     *
     * Query: ?usuario_id=123 (opcional)
     *
     * Incluye datos del usuario (nombre, DNI) y estado de la credencial.
     */
    public function listarCredenciales(Request $request, array $params): array
    {
        $usuarioId = $request->query('usuario_id');
        $credenciales = $this->credencialService->listar($usuarioId ? (int)$usuarioId : null);
        return ['data' => $credenciales, 'total' => count($credenciales)];
    }

    /*
     * POST /api/admin/credenciales
     *
     * Emite una nueva credencial digital para un usuario.
     *
     * Body: {
     *   id_usuario: int (requerido),
     *   fecha_vencimiento: string (requerido, YYYY-MM-DD),
     *   foto?: string (URL opcional)
     * }
     *
     * La credencial se crea con fecha_emision = today y un código QR único.
     */
    public function emitirCredencial(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            $credencial = $this->credencialService->emitir($request->body(), $admin);
            return [
                'data' => [
                    'id'                => $credencial->getIdCredencial(),
                    'id_usuario'        => $credencial->getUsuario()->getIdUsuario(),
                    'fecha_emision'     => $credencial->getFechaEmision()->format('Y-m-d'),
                    'fecha_vencimiento' => $credencial->getFechaVencimiento()->format('Y-m-d'),
                    'codigo_qr'         => $credencial->getCodigoQr(),
                    'activa'            => $credencial->estaActiva(),
                ],
                'message' => 'Credencial emitida exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/credenciales/{id}/renovar
     *
     * Renueva una credencial: la anterior se desactiva y se crea una nueva.
     *
     * Body: {
     *   fecha_vencimiento?: string (default: +1 year),
     *   foto?: string (opcional, conserva la anterior si no se envía)
     * }
     */
    public function renovarCredencial(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $credencial = $this->credencialService->renovar((int)$params['id'], $request->body(), $admin);
            return [
                'data' => [
                    'id'                => $credencial->getIdCredencial(),
                    'id_usuario'        => $credencial->getUsuario()->getIdUsuario(),
                    'fecha_emision'     => $credencial->getFechaEmision()->format('Y-m-d'),
                    'fecha_vencimiento' => $credencial->getFechaVencimiento()->format('Y-m-d'),
                    'codigo_qr'         => $credencial->getCodigoQr(),
                    'activa'            => $credencial->estaActiva(),
                ],
                'message' => 'Credencial renovada exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  SELLOS
    // ═══════════════════════════════════════════════════════════════

    /*
     * GET /api/admin/sellos
     *
     * Lista todos los sellos institucionales (activos e inactivos).
     * Para obtener solo activos, el frontend puede filtrar del lado cliente.
     */
    public function listarSellos(Request $request, array $params): array
    {
        $sellos = $this->selloService->listar();
        return ['data' => $sellos, 'total' => count($sellos)];
    }

    /*
     * GET /api/admin/sellos/{id}
     *
     * Obtiene un sello individual por ID.
     */
    public function obtenerSello(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $sello = $this->selloService->obtener((int)$params['id']);
            return [
                'data'    => $sello,
                'message' => 'Sello obtenido correctamente',
            ];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        }
    }

    /*
     * POST /api/admin/sellos
     *
     * Crea un nuevo sello institucional.
     *
     * Body: {
     *   nombre: string (requerido),
     *   imagen_url: string (requerido),
     *   activo?: bool (default: true)
     * }
     */
    public function crearSello(Request $request, array $params): array
    {
        try {
            $sello = $this->selloService->crear($request->body());
            return [
                'data' => [
                    'id'         => $sello->getIdSello(),
                    'nombre'     => $sello->getNombre(),
                    'imagen_url' => $sello->getImagenUrl(),
                    'activo'     => $sello->isActivo(),
                ],
                'message' => 'Sello creado exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/sellos/upload
     *
     * Crea un sello subiendo la imagen directamente.
     * Acepta multipart/form-data (no JSON).
     *
     * Form fields: nombre (requerido), activo (opcional)
     * File field:  imagen (requerido, PNG/JPG/WEBP/SVG, max 2MB)
     *
     * La imagen se guarda en public/images/sellos/ con nombre único
     * y la URL se almacena automáticamente en imagen_url.
     */
    public function subirSello(Request $request, array $params): array
    {
        try {
            $nombre = $request->body('nombre');
            if (!$nombre) {
                return ['error' => 'nombre es requerido', 'code' => 400];
            }

            // Verificar que venga el archivo
            if (!$request->hasFile('imagen')) {
                return ['error' => 'El archivo imagen es requerido', 'code' => 400];
            }

            // Subir imagen → nos devuelve la URL pública
            $imagenUrl = $this->selloService->subirImagen($request->file('imagen'));

            // Crear el sello con la URL generada
            // activo puede venir como string ("true"/"false") en multipart
            $activo = $request->body('activo');
            if (is_string($activo)) {
                $activo = $activo === 'true' || $activo === '1';
            } elseif ($activo === null) {
                $activo = true;
            }

            $sello = $this->selloService->crear([
                'nombre'     => $nombre,
                'imagen_url' => $imagenUrl,
                'activo'     => $activo,
            ]);

            return [
                'data' => [
                    'id'         => $sello->getIdSello(),
                    'nombre'     => $sello->getNombre(),
                    'imagen_url' => $sello->getImagenUrl(),
                    'activo'     => $sello->isActivo(),
                ],
                'message' => 'Sello creado exitosamente',
            ];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        } catch (\TypeError $e) {
            error_log('[ICB ERROR] AdminController::subirSello: ' . $e->getMessage());
            return ['error' => 'Datos inválidos en la solicitud', 'code' => 400];
        }
    }

    /*
     * PUT /api/admin/sellos/{id}
     *
     * Actualiza un sello existente.
     * Todos los campos del body son opcionales.
     *
     * Body: { nombre?, imagen_url?, activo? }
     */
    public function actualizarSello(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $sello = $this->selloService->actualizar((int)$params['id'], $request->body());
            return [
                'data' => [
                    'id'         => $sello->getIdSello(),
                    'nombre'     => $sello->getNombre(),
                    'imagen_url' => $sello->getImagenUrl(),
                    'activo'     => $sello->isActivo(),
                ],
                'message' => 'Sello actualizado exitosamente',
            ];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * DELETE /api/admin/sellos/{id}
     *
     * Baja lógica de un sello: cambia activo a false.
     * El registro no se elimina de la base de datos.
     * Consistente con el patrón de baja lógica de usuarios (DELETE).
     *
     * Para reactivar, usar PUT /api/admin/sellos/{id} con activo: true.
     */
    public function eliminarSello(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $this->selloService->eliminar((int)$params['id']);
            return ['message' => 'Sello desactivado exitosamente'];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONVERSACIONES
    // ═══════════════════════════════════════════════════════════════

    /*
     * GET /api/admin/conversaciones
     *
     * Bandeja de administración: lista todas las conversaciones.
     * Query param: ?estado=Abierta|Cerrada para filtrar.
     */
    public function listarConversaciones(Request $request, array $params): array
    {
        $estado = $request->query('estado');

        if ($estado !== null) {
            $errorEstado = ValidationHelper::enum('estado', $estado, ['Abierta', 'Cerrada']);
            if ($errorEstado) {
                return ['error' => $errorEstado, 'code' => 400];
            }
        }

        try {
            $conversaciones = $this->conversacionService->listarTodas($estado);
            return ['data' => $conversaciones, 'total' => count($conversaciones)];
        } catch (\Exception $e) {
            error_log('[ICB ERROR] AdminController::listarConversaciones: ' . $e->getMessage());
            return ['error' => 'Error interno del servidor', 'code' => 500];
        }
    }

    /*
     * GET /api/admin/conversaciones/{id}/mensajes
     *
     * Admin ve todos los mensajes de una conversación específica.
     * No tiene ownership check (a diferencia del endpoint de usuario).
     */
    public function verMensajesConversacion(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $mensajes = $this->conversacionService->obtenerMensajes(
                (int)$params['id'], $admin, true // esAdmin = true → sin ownership check
            );
            return ['data' => $mensajes, 'total' => count($mensajes)];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/conversaciones/{id}/mensajes
     *
     * Admin responde en una conversación.
     * Body: { "contenido": "..." }
     */
    public function responderConversacion(Request $request, array $params): array
    {
        $admin = $request->getAttribute('usuario');
        $contenido = $request->body('contenido');

        if (!$contenido) {
            return ['error' => 'El contenido del mensaje es requerido', 'code' => 400];
        }

        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $this->conversacionService->agregarMensaje((int)$params['id'], $admin, $contenido);
            $mensajes = $this->conversacionService->obtenerMensajes(
                (int)$params['id'], $admin, true
            );
            return ['data' => $mensajes, 'message' => 'Respuesta enviada'];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    /*
     * POST /api/admin/conversaciones/{id}/cerrar
     *
     * Cierra una conversación. Una vez cerrada, no se pueden agregar
     * más mensajes. Los mensajes existentes siguen siendo visibles.
     */
    public function cerrarConversacion(Request $request, array $params): array
    {
        try {
            ValidationHelper::acumular([
                ValidationHelper::enteroPositivo('id', $params['id'] ?? null),
            ]);
            $this->conversacionService->cerrar((int)$params['id']);
            return ['message' => 'Conversación cerrada exitosamente'];
        } catch (NotFoundException $e) {
            return ['error' => $e->getMessage(), 'code' => 404];
        } catch (ValidationException $e) {
            return ['error' => $e->getMessage(), 'code' => $e->getStatusCode()];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  HISTORIAL (AUDITORÍA)
    // ═══════════════════════════════════════════════════════════════

    /*
     * GET /api/admin/historial — Lista el historial de cambios
     *
     * Endpoint de auditoría que permite consultar todos los cambios
     * realizados en el sistema con filtros opcionales:
     * - usuario_id: Filtrar por admin
     * - tabla:      Filtrar por tabla afectada
     * - accion:     Filtrar por tipo de acción
     * - fecha_desde / fecha_hasta: Rango de fechas
     * - page / limit: Paginación
     */
    public function listarHistorial(Request $request, array $params): array
    {
        $query = $request->query();

        if (isset($query['tabla'])) {
            $errorTabla = ValidationHelper::enum('tabla', $query['tabla'], ['usuarios', 'credenciales', 'sellos', 'conversaciones']);
            if ($errorTabla) {
                return ['error' => $errorTabla, 'code' => 400];
            }
        }

        if (isset($query['accion'])) {
            $errorAccion = ValidationHelper::enum('accion', $query['accion'], ['CREAR', 'EDITAR', 'BAJA', 'RESTAURAR', 'EMITIR', 'RENOVAR']);
            if ($errorAccion) {
                return ['error' => $errorAccion, 'code' => 400];
            }
        }

        if (isset($query['fecha_desde'])) {
            $errorFecha = ValidationHelper::fecha('fecha_desde', $query['fecha_desde']);
            if ($errorFecha) {
                return ['error' => $errorFecha, 'code' => 400];
            }
        }

        if (isset($query['fecha_hasta'])) {
            $errorFecha = ValidationHelper::fecha('fecha_hasta', $query['fecha_hasta']);
            if ($errorFecha) {
                return ['error' => $errorFecha, 'code' => 400];
            }
        }

        return $this->historialService->listar(
            usuarioId: isset($query['usuario_id']) ? (int) $query['usuario_id'] : null,
            tabla: $query['tabla'] ?? null,
            accion: $query['accion'] ?? null,
            fechaDesde: $query['fecha_desde'] ?? null,
            fechaHasta: $query['fecha_hasta'] ?? null,
            page: isset($query['page']) ? (int) $query['page'] : 1,
            limit: isset($query['limit']) ? (int) $query['limit'] : 50,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  UTILIDADES
    // ═══════════════════════════════════════════════════════════════

    /*
     * SERIALIZAR USUARIO: Convierte entidad a array para respuesta JSON.
     *
     * Está duplicada de UsuarioService::serializar() intencionalmente.
     * El controller necesita su propia serialización porque:
     *   - UsuarioService es privado y no deberíamos exponer sus internos
     *   - El controller puede querer un formato diferente al del service
     *   - Mantiene la separación de capas (controller decide qué devolver)
     *
     * Decisión técnica: en lugar de exponer serializar() como público en
     * UsuarioService (lo que rompería encapsulamiento), preferimos esta
     * duplicación controlada. Si los formatos crecen, creamos un
     * UsuarioNormalizer o un SerializerInterface.
     */
    private function serializarUsuario(Usuario $usuario): array
    {
        $roles = [];
        foreach ($usuario->getRoles() as $rol) {
            $roles[] = $rol->getNombre();
        }
        return [
            'id'         => $usuario->getIdUsuario(),
            'usuario'    => $usuario->getUsuario(),
            'dni'        => $usuario->getDni(),
            'nombre'     => htmlspecialchars($usuario->getNombre() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'apellido'   => htmlspecialchars($usuario->getApellido() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'email'      => $usuario->getEmail(),
            'telefono'   => htmlspecialchars($usuario->getTelefono() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'direccion'  => htmlspecialchars($usuario->getDireccion() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'funcion'    => htmlspecialchars($usuario->getFuncion() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'estado'     => $usuario->getEstado(),
            'roles'      => $roles,
            'fecha_alta' => $usuario->getFechaAlta()->format('Y-m-d H:i:s'),
        ];
    }
}
