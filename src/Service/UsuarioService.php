<?php
declare(strict_types=1);
namespace ICB\Service;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Rol;
use ICB\Entity\Usuario;
use ICB\Exception\ValidationException;
use ICB\Exception\NotFoundException;
use ICB\Repository\UsuarioRepository;
use ICB\Validation\ValidationHelper;

/*
 * USUARIO SERVICE: Lógica de negocio de usuarios
 * ================================================
 * Este service encapsula TODA la lógica de negocio relacionada con usuarios.
 * Ningún controller debería manipular Usuario directamente; siempre pasan
 * por acá para garantizar que las reglas de negocio se cumplan siempre.
 *
 * FLUJO:
 *   1. AdminController recibe request HTTP
 *   2. Delega en UsuarioService
 *   3. UsuarioService valida reglas de negocio
 *   4. Persiste vía EntityManager
 *   5. Registra en historial de auditoría
 *
 * REGLAS DE NEGOCIO:
 *   - DNI, nombre de usuario y email deben ser únicos en toda la DB
 *   - Password se almacena SIEMPRE hasheada con bcrypt (costo 12)
 *   - Baja es lógica: estado pasa a 'Inactivo' (nunca DELETE FROM)
 *   - Restaurar: estado vuelve a 'Activo'
 *   - Todo cambio queda registrado en HistorialCambio para auditoría
 *
 * RELACIONES:
 *   - UsuarioRepository: búsquedas por DNI, usuario, email, search()
 *   - HistorialCambio: registra cada operación con valor anterior/nuevo
 *   - Rol: asigna roles al crear o actualizar usuario
 *
 * DECISIONES TÉCNICAS:
 *   - Usamos el repositorio directamente para las validaciones de unicidad
 *     en vez de queries DQL porque ya está encapsulado en UsuarioRepository
 *   - La serialización a array es privada para que nadie fuera del service
 *     pueda exponer datos sensibles como password_hash
 *   - El admin que realiza la operación se pasa como parámetro explícito
 *     (no se obtiene de sesión internamente) para mantener el service
 *     stateless y testeable
 */
class UsuarioService
{
    private EntityManagerInterface $em;
    private UsuarioRepository $repository;

    /*
     * Constructor: recibe el EntityManager y obtiene el repositorio tipado.
     * El tipado del repositorio es posible porque UsuarioRepository extiende
     * EntityRepository y la entidad declara repositoryClass en el atributo ORM.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->repository = $em->getRepository(Usuario::class);
    }

    /*
     * LISTAR: Devuelve todos los usuarios con filtros opcionales.
     * 
     * @param string|null $busqueda Texto para buscar en DNI, apellido o función
     * @param string|null $estado   Filtrar por 'Activo' o 'Inactivo'
     * @return array                Array serializado (NUNCA devolvemos entities)
     * 
     * Flujo:
     *   - Delega la query en UsuarioRepository::search() que usa QueryBuilder
     *   - Mapea cada Usuario a un array plano via serializar()
     *   - Esto evita que el controller reciba entities con lazy loading activo
     */
    public function listar(?string $busqueda = null, ?string $estado = null): array
    {
        $usuarios = $this->repository->search($busqueda, $estado);

        return array_map(fn(Usuario $u) => $this->serializar($u), $usuarios);
    }

    /*
     * OBTENER: Busca un usuario por su ID.
     * 
     * @throws NotFoundException si el ID no existe en la DB
     * 
     * Decisión técnica: devolvemos la entidad en lugar del array serializado
     * porque este método es usado internamente por otros métodos del service
     * (actualizar, eliminar, restaurar) que necesitan la entidad para persistir.
     * Para el controller existe obtenerSerializado() o se serializa afuera.
     */
    public function obtener(int $id): Usuario
    {
        $usuario = $this->em->find(Usuario::class, $id);
        if (!$usuario) {
            throw new NotFoundException('Usuario no encontrado');
        }
        return $usuario;
    }

    /*
     * CREAR: Registra un nuevo usuario en el sistema.
     * 
     * @param array $datos {
     *     dni: string (requerido, único)
     *     usuario: string (requerido, único)
     *     password: string (requerido, se hashea antes de almacenar)
     *     nombre: string (requerido)
     *     apellido: string (requerido)
     *     email: string (requerido, único)
     *     telefono?: string
     *     direccion?: string
     *     funcion?: string
     *     roles?: string[] (por defecto ['Usuario'])
     * }
     * @param Usuario $admin El administrador que ejecuta la acción (para historial)
     * @return Usuario       La entidad persistida (con ID generado)
     * 
     * @throws ValidationException si faltan campos o hay duplicados
     * 
     * Flujo:
     *   1. Validar campos requeridos (early return si faltan)
     *   2. Validar unicidad de DNI, usuario y email
     *   3. Crear entidad Usuario con datos
     *   4. Hashear password con bcrypt
     *   5. Asignar roles (por defecto 'Usuario' si no se especifica)
     *   6. Persistir y flush
     *   7. Registrar en historial de auditoría
     */
    public function crear(array $datos, Usuario $admin): Usuario
    {
        // Sanitizar password ANTES de validarlo para evitar mismatch
        // (si contiene caracteres de control, la validación pasaría
        //  pero sanitizar los eliminaría, causando login fallido)
        $datos['password'] = ValidationHelper::sanitizar($datos['password'] ?? '');

        // Paso 1: validar campos requeridos, formato y longitudes máximas con ValidationHelper
        ValidationHelper::acumular([
            // Validaciones de campos requeridos
            ValidationHelper::requerido('dni', $datos['dni'] ?? null),
            ValidationHelper::requerido('usuario', $datos['usuario'] ?? null),
            ValidationHelper::requerido('password', $datos['password'] ?? null),
            ValidationHelper::requerido('nombre', $datos['nombre'] ?? null),
            ValidationHelper::requerido('apellido', $datos['apellido'] ?? null),
            ValidationHelper::requerido('email', $datos['email'] ?? null),

            // Validaciones de formato
            ValidationHelper::email('email', $datos['email'] ?? null),
            ValidationHelper::password('password', $datos['password'] ?? null),

            // Longitudes máximas (coinciden con las restricciones de la DB)
            ValidationHelper::maxLength('dni', $datos['dni'] ?? null, 20),
            ValidationHelper::maxLength('usuario', $datos['usuario'] ?? null, 50),
            ValidationHelper::maxLength('nombre', $datos['nombre'] ?? null, 100),
            ValidationHelper::maxLength('apellido', $datos['apellido'] ?? null, 100),
            ValidationHelper::maxLength('email', $datos['email'] ?? null, 100),
            ValidationHelper::maxLength('telefono', $datos['telefono'] ?? null, 30),
            ValidationHelper::maxLength('funcion', $datos['funcion'] ?? null, 100),
            ValidationHelper::maxLength('direccion', $datos['direccion'] ?? null, 255),
        ]);

        // Sanitizar todos los campos string después de validar
        $datos['dni'] = ValidationHelper::sanitizar($datos['dni']);
        $datos['usuario'] = ValidationHelper::sanitizar($datos['usuario']);
        $datos['nombre'] = ValidationHelper::sanitizar($datos['nombre']);
        $datos['apellido'] = ValidationHelper::sanitizar($datos['apellido']);
        $datos['email'] = ValidationHelper::sanitizar($datos['email']);
        $datos['telefono'] = ValidationHelper::sanitizar($datos['telefono'] ?? null);
        $datos['funcion'] = ValidationHelper::sanitizar($datos['funcion'] ?? null);
        $datos['direccion'] = ValidationHelper::sanitizar($datos['direccion'] ?? null);

        // Paso 2: validar unicidad
        if ($this->repository->findByDni($datos['dni'])) {
            throw new ValidationException('El DNI ya está registrado');
        }
        if ($this->repository->findByUsuario($datos['usuario'])) {
            throw new ValidationException('El nombre de usuario ya existe');
        }
        if ($this->repository->findByEmail($datos['email'])) {
            throw new ValidationException('El email ya está registrado');
        }

        // Paso 3: crear y poblar la entidad
        $usuario = new Usuario();
        $usuario->setDni($datos['dni'])
                ->setUsuario($datos['usuario'])
                ->setPasswordHash(password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]))
                ->setNombre($datos['nombre'])
                ->setApellido($datos['apellido'])
                ->setEmail($datos['email'])
                ->setTelefono($datos['telefono'] ?? null)
                ->setDireccion($datos['direccion'] ?? null)
                ->setFuncion($datos['funcion'] ?? null);

        // Paso 4: asignar roles
        $rolesAsignar = $datos['roles'] ?? ['Usuario'];
        foreach ($rolesAsignar as $nombreRol) {
            $rol = $this->em->getRepository(Rol::class)->findOneBy(['nombre' => $nombreRol]);
            if ($rol) {
                $usuario->addRol($rol);
            }
        }

        // Paso 5: persistir con protección TOCTOU (race condition)
        $this->em->persist($usuario);
        try {
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Ocurrió una violación de unique constraint (race condition).
            // Determinar cuál campo causó el conflicto.
            $message = $e->getMessage();
            if (str_contains($message, 'dni')) {
                throw new \ICB\Validation\ValidationException('El DNI ya está registrado');
            }
            if (str_contains($message, 'usuario')) {
                throw new \ICB\Validation\ValidationException('El nombre de usuario ya existe');
            }
            if (str_contains($message, 'email')) {
                throw new \ICB\Validation\ValidationException('El email ya está registrado');
            }
            throw new \ICB\Validation\ValidationException('Error de concurrencia: el registro ya existe');
        }

        // Paso 6: registrar en historial (filtrando datos sensibles)
        $this->registrarHistorial($admin, 'usuarios', $usuario->getIdUsuario(), 'CREAR', null, $this->filtrarSensibles($datos));

        return $usuario;
    }

    /*
     * ACTUALIZAR: Modifica datos de un usuario existente.
     * 
     * @param int   $id    ID del usuario a modificar
     * @param array $datos Campos a actualizar (todos opcionales, solo los presentes)
     * @param Usuario $admin Admin que ejecuta la acción
     * @return Usuario       Entidad modificada
     * 
     * @throws NotFoundException si el usuario no existe
     * @throws ValidationException si hay duplicados en campos únicos
     * 
     * Flujo:
     *   1. Obtener usuario (lanza NotFound si no existe)
     *   2. Serializar valor anterior para el historial
     *   3. Actualizar cada campo SOLO si viene en $datos
     *   4. Validar unicidad ANTES de setear campos únicos modificados
     *   5. Si viene 'roles', reemplazar completamente la colección
     *   6. Flush
     *   7. Registrar en historial con valor anterior y nuevo
     * 
     * Decisión técnica: usamos isset() en vez de empty() para permitir
     * valores como false, 0 o string vacío si algún día se necesitan.
     * La validación de contenido vacío se hace en cada setter if aplica.
     */
    public function actualizar(int $id, array $datos, Usuario $admin): Usuario
    {
        $usuario = $this->obtener($id);

        // Guardar snapshot antes de modificar
        $valorAnterior = $this->serializar($usuario);

        // Validar campos presentes con ValidationHelper antes de actualizar
        $validaciones = [];

        if (isset($datos['dni'])) {
            $validaciones[] = ValidationHelper::requerido('dni', $datos['dni']);
            $validaciones[] = ValidationHelper::maxLength('dni', $datos['dni'], 20);
            $datos['dni'] = ValidationHelper::sanitizar($datos['dni']);
        }
        if (isset($datos['email'])) {
            $validaciones[] = ValidationHelper::requerido('email', $datos['email']);
            $validaciones[] = ValidationHelper::email('email', $datos['email']);
            $validaciones[] = ValidationHelper::maxLength('email', $datos['email'], 100);
            $datos['email'] = ValidationHelper::sanitizar($datos['email']);
        }
        if (isset($datos['password'])) {
            $validaciones[] = ValidationHelper::password('password', $datos['password']);
            if (trim($datos['password']) === '') {
                unset($datos['password']);
            }
        }
        if (isset($datos['nombre'])) {
            $validaciones[] = ValidationHelper::requerido('nombre', $datos['nombre']);
            $validaciones[] = ValidationHelper::maxLength('nombre', $datos['nombre'], 100);
            $datos['nombre'] = ValidationHelper::sanitizar($datos['nombre']);
        }
        if (isset($datos['apellido'])) {
            $validaciones[] = ValidationHelper::requerido('apellido', $datos['apellido']);
            $validaciones[] = ValidationHelper::maxLength('apellido', $datos['apellido'], 100);
            $datos['apellido'] = ValidationHelper::sanitizar($datos['apellido']);
        }
        if (isset($datos['telefono'])) {
            $validaciones[] = ValidationHelper::maxLength('telefono', $datos['telefono'], 30);
            $datos['telefono'] = ValidationHelper::sanitizar($datos['telefono']);
        }
        if (isset($datos['funcion'])) {
            $validaciones[] = ValidationHelper::maxLength('funcion', $datos['funcion'], 100);
            $datos['funcion'] = ValidationHelper::sanitizar($datos['funcion']);
        }
        if (isset($datos['direccion'])) {
            $validaciones[] = ValidationHelper::maxLength('direccion', $datos['direccion'], 255);
            $datos['direccion'] = ValidationHelper::sanitizar($datos['direccion']);
        }
        if (isset($datos['usuario'])) {
            $validaciones[] = ValidationHelper::requerido('usuario', $datos['usuario']);
            $validaciones[] = ValidationHelper::maxLength('usuario', $datos['usuario'], 50);
            $datos['usuario'] = ValidationHelper::sanitizar($datos['usuario']);
        }

        ValidationHelper::acumular($validaciones);

        // Actualizar campos únicos con validación de duplicados
        if (isset($datos['dni']) && $datos['dni'] !== $usuario->getDni()) {
            if ($this->repository->findByDni($datos['dni'])) {
                throw new ValidationException('El DNI ya está registrado');
            }
            $usuario->setDni($datos['dni']);
        }
        if (isset($datos['usuario']) && $datos['usuario'] !== $usuario->getUsuario()) {
            if ($this->repository->findByUsuario($datos['usuario'])) {
                throw new ValidationException('El nombre de usuario ya existe');
            }
            $usuario->setUsuario($datos['usuario']);
        }
        if (isset($datos['email']) && $datos['email'] !== $usuario->getEmail()) {
            if ($this->repository->findByEmail($datos['email'])) {
                throw new ValidationException('El email ya está registrado');
            }
            $usuario->setEmail($datos['email']);
        }

        // Actualizar campos simples
        if (isset($datos['nombre'])) $usuario->setNombre($datos['nombre']);
        if (isset($datos['apellido'])) $usuario->setApellido($datos['apellido']);
        if (isset($datos['telefono'])) $usuario->setTelefono($datos['telefono']);
        if (isset($datos['direccion'])) $usuario->setDireccion($datos['direccion']);
        if (isset($datos['funcion'])) $usuario->setFuncion($datos['funcion']);

        // Password: solo se actualiza si viene en el request
        if (isset($datos['password'])) {
            $usuario->setPasswordHash(password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]));
        }

        // Roles: reemplazo completo de la colección
        if (isset($datos['roles']) && is_array($datos['roles'])) {
            // Seguridad: evitar que un admin se quite el rol Admin a sí mismo
            if ($id === $admin->getIdUsuario() && !in_array('Admin', $datos['roles'], true)) {
                $rolesActuales = $usuario->getRoles()->map(fn(Rol $r) => $r->getNombre())->toArray();
                if (in_array('Admin', $rolesActuales, true)) {
                    throw new ValidationException('No podés quitarte el rol Admin a vos mismo');
                }
            }

            // Restricción de seguridad: solo usuarios con rol Admin pueden
            // asignar el rol Admin a otros. Esto evita que un usuario con
            // roles limitados pueda escalar privilegios.
            if (in_array('Admin', $datos['roles'], true)) {
                $adminRoles = $admin->getRoles()->map(fn(Rol $r) => $r->getNombre())->toArray();
                if (!in_array('Admin', $adminRoles, true)) {
                    throw new ValidationException('No tenés permisos para asignar el rol Admin');
                }
            }

            $usuario->getRoles()->clear();
            foreach ($datos['roles'] as $nombreRol) {
                $rol = $this->em->getRepository(Rol::class)->findOneBy(['nombre' => $nombreRol]);
                if ($rol) {
                    $usuario->addRol($rol);
                }
            }
        }

        $this->em->flush();

        $this->registrarHistorial($admin, 'usuarios', $id, 'EDITAR', $valorAnterior, $this->serializar($usuario));

        return $usuario;
    }

    /*
     * ELIMINAR: Baja lógica de usuario.
     * 
     * El registro NO se elimina de la base de datos. Simplemente
     * cambia el estado a 'Inactivo'. Esto permite:
     *   - Mantener integridad referencial con credenciales, historial, etc.
     *   - Poder restaurar el usuario después si fue un error
     *   - Cumplir con requisitos de auditoría (nada se borra)
     * 
     * @throws ValidationException si el usuario ya está inactivo (idempotencia)
     */
    public function eliminar(int $id, Usuario $admin): void
    {
        $usuario = $this->obtener($id);

        if ($usuario->getEstado() === 'Inactivo') {
            throw new ValidationException('El usuario ya está inactivo');
        }

        $valorAnterior = $this->serializar($usuario);
        $usuario->setEstado('Inactivo');
        $this->em->flush();

        $this->registrarHistorial($admin, 'usuarios', $id, 'BAJA', $valorAnterior, $this->serializar($usuario));
    }

    /*
     * RESTAURAR: Reactiva un usuario que estaba inactivo.
     * Es la operación inversa a eliminar().
     * 
     * @throws ValidationException si el usuario ya está activo
     */
    public function restaurar(int $id, Usuario $admin): Usuario
    {
        $usuario = $this->obtener($id);

        if ($usuario->getEstado() === 'Activo') {
            throw new ValidationException('El usuario ya está activo');
        }

        $valorAnterior = $this->serializar($usuario);
        $usuario->setEstado('Activo');
        $this->em->flush();

        $this->registrarHistorial($admin, 'usuarios', $id, 'RESTAURAR', $valorAnterior, $this->serializar($usuario));

        return $usuario;
    }

    /*
     * SERIALIZAR: Convierte una entidad Usuario a array plano.
     * 
     * Es PRIVADA para garantizar que NADIE fuera de este service
     * pueda exponer datos sensibles (password_hash, tokens).
     * 
     * Decisión técnica: no usamos JSON serialization ni JMS Serializer
     * porque queremos control EXPLÍCITO de qué se expone.
     * Si en el futuro necesitamos más formatos, creamos un normalizer.
     */
    private function serializar(Usuario $usuario): array
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

    /*
     * FILTRAR SENSIBLES: Elimina campos que no deben quedar en el historial.
     *
     * Remueve password (texto plano), password_hash (hash bcrypt), y cualquier
     * otro campo sensible antes de persistir en la tabla de auditoría.
     */
    private function filtrarSensibles(array $datos): array
    {
        unset($datos['password'], $datos['password_hash']);
        return $datos;
    }

    /*
     * REGISTRAR HISTORIAL: Persiste un registro de auditoría.
     * 
     * Cada operación administrativa (CREAR, EDITAR, BAJA, RESTAURAR)
     * queda registrada con:
     *   - Quién hizo el cambio (admin)
     *   - Sobre qué tabla y registro
     *   - Qué acción
     *   - El valor anterior y nuevo en JSON
     * 
     * Decisión técnica: los valores se serializan a JSON con
     * JSON_UNESCAPED_UNICODE para preservar caracteres UTF-8
     * (tildes, eñes, etc.) en lugar de escaparlos como \uXXXX.
     * 
     * @param Usuario  $admin        Admin que ejecutó la acción
     * @param string   $tabla        Nombre de la tabla afectada
     * @param int      $registroId   ID del registro en esa tabla
     * @param string   $accion       Tipo de acción (CREAR, EDITAR, etc.)
     * @param array|null $valorAnterior Snapshot antes del cambio (null si es CREAR)
     * @param array|null $valorNuevo    Snapshot después del cambio
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
