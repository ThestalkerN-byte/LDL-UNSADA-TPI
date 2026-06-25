<?php
namespace App\Controller;

use App\Entity\User;
use App\Entity\History;
use App\Security\UserContext;
use App\Validation\ValidationHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para gestión de usuarios (Panel de Administración).
 *
 * Mapea:
 *   GET    ?action=user                → index()   Lista usuarios (filtrable)
 *   GET    ?action=user&id={id}        → show()    Detalle de un usuario
 *   POST   ?action=user                → create()  Crear un nuevo usuario
 *   PUT    ?action=user&id={id}        → update()  Editar un usuario
 *   DELETE ?action=user&id={id}        → delete()  Baja lógica de un usuario
 */
class UserController {

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
    }

    /**
     * Punto de entrada principal.
     */
    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        match ($method) {
            'GET'    => $id ? $this->show($id) : $this->index(),
            'POST'   => $this->create(),
            'PUT'    => $id ? $this->update($id) : $this->responder(400, 'error', 'Se requiere un ID para actualizar.'),
            'DELETE' => $id ? $this->delete($id) : $this->responder(400, 'error', 'Se requiere un ID para dar de baja.'),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    /**
     * GET ?action=user
     * Retorna la lista de usuarios. Permite buscar/filtrar por dni, apellido o rol (RF10 / CU3).
     */
    private function index(): void {
        $userRepo = $this->em->getRepository(User::class);
        $qb = $userRepo->createQueryBuilder('u')
            ->where('u.estado = true'); // Solo usuarios activos

        // Filtrado por DNI (búsqueda parcial)
        if (!empty($_GET['dni'])) {
            $qb->andWhere('u.dni LIKE :dni')
               ->setParameter('dni', '%' . trim($_GET['dni']) . '%');
        }

        // Filtrado por Apellido (búsqueda parcial)
        if (!empty($_GET['apellido'])) {
            $qb->andWhere('u.apellido LIKE :apellido')
               ->setParameter('apellido', '%' . trim($_GET['apellido']) . '%');
        }

        // Filtrado por Rol (exacto)
        if (!empty($_GET['rol'])) {
            $qb->andWhere('u.rol = :rol')
               ->setParameter('rol', trim($_GET['rol']));
        }

        $usuarios = $qb->getQuery()->getResult();

        $data = array_map(function(User $u) {
            return $this->serializeUser($u);
        }, $usuarios);

        $this->responder(200, 'success', 'Usuarios obtenidos correctamente.', $data);
    }

    /**
     * GET ?action=user&id={id}
     * Solo devuelve usuarios activos (estado = true).
     */
    private function show(int $id): void {
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findActiveById($id);

        if (!$user) {
            $this->responder(404, 'error', 'Usuario no encontrado o inactivo.');
            return;
        }

        $this->responder(200, 'success', 'Usuario encontrado.', $this->serializeUser($user));
    }

    /**
     * POST ?action=user
     * Registra un nuevo usuario con la contraseña hasheada (RF08 / CU3).
     */
    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);

        // Validaciones básicas de campos obligatorios
        $required = ['usuario', 'password', 'nombre', 'apellido', 'dni', 'email', 'rol'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->responder(400, 'error', "El campo '$field' es obligatorio.");
                return;
            }
        }

        // Validación de fortaleza de contraseña
        $passwordError = ValidationHelper::password('contraseña', $data['password']);
        if ($passwordError !== null) {
            $this->responder(400, 'error', $passwordError);
            return;
        }

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);

        // Validar unicidad solo contra usuarios activos (borrado lógico excluido)
        if ($userRepo->findActiveByUsuario(trim($data['usuario']))) {
            $this->responder(400, 'error', 'El nombre de usuario ya está registrado.');
            return;
        }
        if ($userRepo->findActiveByDni(trim($data['dni']))) {
            $this->responder(400, 'error', 'El DNI ya está registrado.');
            return;
        }
        if ($userRepo->findActiveByEmail(trim($data['email']))) {
            $this->responder(400, 'error', 'El correo electrónico ya está registrado.');
            return;
        }

        // Crear usuario
        $user = new User();
        $user->setUsuario(trim($data['usuario']));
        $user->setPassword(password_hash($data['password'], PASSWORD_BCRYPT));
        $user->setNombre(trim($data['nombre']));
        $user->setApellido(trim($data['apellido']));
        $user->setDni(trim($data['dni']));
        $user->setEmail(trim($data['email']));
        $user->setRol(trim($data['rol']));
        $user->setEstado(true);
        
        $fotoPath = $this->handleFotoPerfil($data['foto_perfil'] ?? null, trim($data['usuario']));
        $user->setFotoPerfil($fotoPath);

        $user->setTelefono($data['telefono'] ?? null);
        $user->setDireccion($data['direccion'] ?? null);

        $this->em->persist($user);

        // Registrar acción en Historial
        $this->registrarHistorial("Creación de usuario: " . $user->getUsuario());

        $this->em->flush();

        $this->responder(201, 'success', 'Usuario creado correctamente.', $this->serializeUser($user));
    }

    /**
     * PUT ?action=user&id={id}
     * Actualiza datos del usuario (RF08 / CU3).
     * Solo opera sobre usuarios activos; las validaciones de unicidad ignoran usuarios dados de baja.
     */
    private function update(int $id): void {
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findActiveById($id);

        if (!$user) {
            $this->responder(404, 'error', 'Usuario no encontrado o inactivo.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Validar unicidad solo contra otros usuarios activos
        if (!empty($data['usuario']) && $data['usuario'] !== $user->getUsuario()) {
            $existente = $userRepo->findActiveByUsuario(trim($data['usuario']));
            if ($existente && $existente->getId() !== $user->getId()) {
                $this->responder(400, 'error', 'El nombre de usuario ya está registrado.');
                return;
            }
            $user->setUsuario(trim($data['usuario']));
        }

        if (!empty($data['dni']) && $data['dni'] !== $user->getDni()) {
            $existente = $userRepo->findActiveByDni(trim($data['dni']));
            if ($existente && $existente->getId() !== $user->getId()) {
                $this->responder(400, 'error', 'El DNI ya está registrado.');
                return;
            }
            $user->setDni(trim($data['dni']));
        }

        if (!empty($data['email']) && $data['email'] !== $user->getEmail()) {
            $existente = $userRepo->findActiveByEmail(trim($data['email']));
            if ($existente && $existente->getId() !== $user->getId()) {
                $this->responder(400, 'error', 'El correo electrónico ya está registrado.');
                return;
            }
            $user->setEmail(trim($data['email']));
        }

        // Campos editables estándar
        if (isset($data['nombre'])) {
            $user->setNombre(trim($data['nombre']));
        }
        if (isset($data['apellido'])) {
            $user->setApellido(trim($data['apellido']));
        }
        if (isset($data['rol'])) {
            $user->setRol(trim($data['rol']));
        }
        if (isset($data['foto_perfil'])) {
            $fotoPath = $this->handleFotoPerfil($data['foto_perfil'], $user->getUsuario());
            $user->setFotoPerfil($fotoPath);
        }
        if (isset($data['telefono'])) {
            $user->setTelefono($data['telefono']);
        }
        if (isset($data['direccion'])) {
            $user->setDireccion($data['direccion']);
        }
        if (!empty($data['password'])) {
            $user->setPassword(password_hash($data['password'], PASSWORD_BCRYPT));
        }

        // Registrar acción en Historial
        $this->registrarHistorial("Modificación de usuario: " . $user->getUsuario());

        $this->em->flush();

        $this->responder(200, 'success', 'Usuario actualizado correctamente.', $this->serializeUser($user));
    }

    /**
     * DELETE ?action=user&id={id}
     * Realiza baja lógica del usuario (estado = false) (RF08 / CU3 A1).
     */
    private function delete(int $id): void {
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findActiveById($id);

        if (!$user) {
            $this->responder(404, 'error', 'Usuario no encontrado o ya inactivo.');
            return;
        }

        // Desvincular mensajes del usuario antes de la baja lógica.
        // Esto previene errores de FK en bases de datos con restricción RESTRICT activa.
        // Los mensajes se conservan en la tabla con id_usuario = NULL (trazabilidad).
        $mensajes = $this->em->getRepository(\App\Entity\Message::class)->findBy(['user' => $user]);
        foreach ($mensajes as $mensaje) {
            $mensaje->setUser(null);
        }

        // Baja lógica: no se elimina el registro, solo se marca como inactivo
        $user->setEstado(false);

        // Registrar acción en Historial
        $this->registrarHistorial("Baja lógica de usuario: " . $user->getUsuario());

        $this->em->flush();

        $this->responder(200, 'success', 'Usuario dado de baja correctamente.');
    }

    /**
     * Helper para registrar auditoría en la tabla Historial.
     *
     * MIGRACIÓN JWT: ahora usa UserContext en vez de $_SESSION['id_usuario'].
     * El AuthMiddleware setea UserContext antes de llegar al controlador.
     */
    private function registrarHistorial(string $accion): void {
        $adminId = UserContext::getId() ?? $_GET['admin_id'] ?? null;
        $admin = null;
        if ($adminId) {
            $admin = $this->em->getRepository(User::class)->find((int)$adminId);
        }
        if (!$admin) {
            // Fallback al primer administrador activo para pruebas locales
            $admin = $this->em->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.rol = :rol')
                ->andWhere('u.estado = true')
                ->setParameter('rol', 'admin')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($admin) {
            $historial = new History();
            $historial->setAccion($accion);
            $historial->setFecha(new \DateTime());
            $historial->setAdmin($admin);
            $this->em->persist($historial);
        }
    }

    /**
     * Serializa la entidad User en un array asociativo sin password.
     */
    private function serializeUser(User $u): array {
        return [
            'id'          => $u->getId(),
            'usuario'     => $u->getUsuario(),
            'nombre'      => $u->getNombre(),
            'apellido'    => $u->getApellido(),
            'dni'         => $u->getDni(),
            'email'       => $u->getEmail(),
            'rol'         => $u->getRol(),
            'estado'      => $u->isEstado(),
            'foto_perfil' => $u->getFotoPerfil(),
            'telefono'    => $u->getTelefono(),
            'direccion'  => $u->getDireccion(),
        ];
    }

    /**
     * Emite la respuesta JSON estandarizada.
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

    /**
     * Procesa la foto de perfil en base64, la guarda en frontend/images/ y retorna la ruta relativa.
     */
    private function handleFotoPerfil(?string $base64Data, string $username): ?string {
        if (empty($base64Data)) {
            return null;
        }
        
        // Si ya es una ruta relativa existente en el servidor, no es base64, se mantiene igual
        if (strpos($base64Data, 'data:image/') !== 0) {
            return $base64Data;
        }
        
        // Decodificar el base64 de la imagen
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $data = substr($base64Data, strpos($base64Data, ',') + 1);
            $ext = strtolower($type[1]);
            
            if (!in_array($ext, ['jpg', 'jpeg', 'gif', 'png'])) {
                return null;
            }
            $data = base64_decode($data);
            if ($data === false) {
                return null;
            }
        } else {
            return null;
        }

        $dir = __DIR__ . '/../../frontend/images';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Generar un nombre único basado en el usuario para evitar cacheo o colisiones
        $filename = 'perfil_' . $username . '_' . time() . '.' . $ext;
        $filepath = $dir . '/' . $filename;

        // Eliminar fotos de perfil anteriores de este mismo usuario
        $oldFiles = glob($dir . '/perfil_' . $username . '_*');
        if ($oldFiles) {
            foreach ($oldFiles as $oldFile) {
                @unlink($oldFile);
            }
        }

        if (file_put_contents($filepath, $data) !== false) {
            return 'images/' . $filename;
        }
        return null;
    }
}