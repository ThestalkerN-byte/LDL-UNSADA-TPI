<?php
declare(strict_types=1);
namespace ICB\Service;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Entity\Sello;
use ICB\Exception\ValidationException;
use ICB\Exception\NotFoundException;

/*
 * SELLO SERVICE: Gestión de sellos institucionales
 * =================================================
 * Los sellos son imágenes (logos) que se muestran en las credenciales
 * digitales como elementos de autenticación visual.
 *
 * Ejemplos de uso:
 *   - Logo de la Iglesia Cristiana Bíblica (ICB)
 *   - Sello de validez oficial
 *   - Logo de organizaciones asociadas
 *
 * FLUJO:
 *   Admin CRUD básico: listar, crear, actualizar.
 *   No hay eliminación física: los sellos se activan/desactivan.
 *
 * REGLAS DE NEGOCIO:
 *   - nombre e imagen_url son obligatorios
 *   - Un sello inactivo no se muestra en las credenciales (soloActivos = true)
 *   - Los sellos se ordenan alfabéticamente por nombre
 *   - Las imágenes subidas viajan a public/images/sellos/ y solo se
 *     permiten formatos PNG, JPG y WEBP (max 2MB). SVG no se permite
 *     porque puede contener JavaScript embebido (riesgo de XSS).
 *
 * RELACIONES:
 *   - Sello: entidad independiente (no tiene relaciones foráneas)
 *
 * DECISIONES TÉCNICAS:
 *   - No tiene métodos registrarHistorial() porque los sellos son
 *     configuración del sistema, no datos de miembros. El impacto
 *     de un cambio es bajo y no justifica auditoría.
 *   - No hay método eliminar(): los sellos se desactivan con activo=false.
 *     Si se necesita purgar, se hace con una query directa en la DB.
 *   - SubirImagen() valida: extensión, MIME real (no confiar en la
 *     extensión sola), tamaño, genera nombre único con prefijo ICB-
 *     para evitar colisiones y path traversal.
 */
class SelloService
{
    // Formatos de imagen permitidos para upload.
    // SVG fue eliminado porque permite JavaScript embebido (XSS).
    // Solo imágenes raster: PNG, JPG, WEBP.
    private const EXTENSIONES_PERMITIDAS = ['png', 'jpg', 'jpeg', 'webp'];

    // MIME types reales que verificamos con mime_content_type()
    // (no nos quedamos solo con la extensión, verificamos el contenido)
    private const MIMES_PERMITIDOS = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    // Tamaño máximo: 2MB (en bytes)
    private const MAX_FILE_SIZE = 2 * 1024 * 1024;

    // Ruta relativa (URL pública que se guarda en la DB)
    private const UPLOAD_DIR = '/images/sellos/';

    // Ruta absoluta en el filesystem (para move_uploaded_file)
    private const UPLOAD_PATH = __DIR__ . '/../../public/images/sellos/';

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * LISTAR: Devuelve todos los sellos, opcionalmente solo los activos.
     *
     * @param bool $soloActivos Si true, filtra solo sellos con activo = true
     * @return array            Sellos serializados ordenados por nombre ASC
     *
     * El frontend de credenciales usa soloActivos=true para mostrar
     * los sellos en la credencial digital.
     */
    public function listar(bool $soloActivos = false): array
    {
        $criteria = $soloActivos ? ['activo' => true] : [];
        $sellos = $this->em->getRepository(Sello::class)->findBy($criteria, ['nombre' => 'ASC']);

        return array_map(fn(Sello $s) => $this->serializar($s), $sellos);
    }

    /*
     * CREAR: Agrega un nuevo sello institucional.
     *
     * @param array $datos {
     *     nombre: string (requerido)
     *     imagen_url: string (requerido, URL o ruta de la imagen)
     *     activo?: bool (por defecto true)
     * }
     * @return Sello Entidad persistida
     *
     * @throws ValidationException si falta nombre o imagen_url
     */
    public function crear(array $datos): Sello
    {
        ValidationHelper::acumular([
            ValidationHelper::requerido('nombre', $datos['nombre'] ?? null),
            ValidationHelper::requerido('imagen_url', $datos['imagen_url'] ?? null),
            ValidationHelper::maxLength('nombre', $datos['nombre'] ?? null, 100),
            ValidationHelper::maxLength('imagen_url', $datos['imagen_url'] ?? null, 255),
            ValidationHelper::url('imagen_url', $datos['imagen_url'] ?? null),
        ]);

        $sello = new Sello();
        $sello->setNombre(ValidationHelper::sanitizar($datos['nombre']))
              ->setImagenUrl($datos['imagen_url'])
              ->setActivo(ValidationHelper::boolean($datos['activo'] ?? null, true));

        $this->em->persist($sello);
        $this->em->flush();

        return $sello;
    }

    /*
     * SUBIR IMAGEN: Valida y guarda un archivo de imagen en el servidor.
     *
     * Flujo de validación:
     *   1. Verifica que $_FILES no tenga error (UPLOAD_ERR_OK)
     *   2. Verifica tamaño máximo (2MB)
     *   3. Verifica extensión contra whitelist
     *   4. Verifica MIME type real con mime_content_type()
     *      (esto evita que suban un .exe renombrado como .png)
     *   5. Genera nombre único: ICB-{16-char-hex}.{ext}
     *   6. Mueve el archivo a public/images/sellos/
     *
     * @param array $archivo Elemento de $_FILES (name, type, tmp_name, error, size)
     * @return string        URL pública relativa (ej: /images/sellos/ICB-abc123.png)
     *
     * @throws ValidationException si el archivo no pasa las validaciones
     */
    public function subirImagen(array $archivo): string
    {
        // 1. Verificar que se haya subido correctamente
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $mensajes = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño máximo permitido por el servidor',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo del formulario',
                UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'No se encontró el directorio temporal del servidor',
                UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en el servidor',
            ];
            throw new ValidationException(
                $mensajes[$archivo['error']] ?? 'Error desconocido al subir el archivo'
            );
        }

        // 2. Validar tamaño
        if ($archivo['size'] > self::MAX_FILE_SIZE) {
            $maxMB = self::MAX_FILE_SIZE / 1024 / 1024;
            throw new ValidationException(
                "La imagen no puede superar los {$maxMB}MB"
            );
        }

        // 3. Validar extensión
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONES_PERMITIDAS, true)) {
            throw new ValidationException(
                'Formato de imagen no permitido. Usar: ' . implode(', ', self::EXTENSIONES_PERMITIDAS)
            );
        }

        // 4. Validar MIME type real (no confiar en la extensión ni en el type del cliente)
        $mime = mime_content_type($archivo['tmp_name']);
        if (!in_array($mime, self::MIMES_PERMITIDOS, true)) {
            throw new ValidationException('El archivo no es una imagen válida');
        }

        // 5. Generar nombre único (prefijo ICB- + 16 chars hex + extensión)
        $nombreArchivo = 'ICB-' . strtoupper(bin2hex(random_bytes(8))) . '.' . $extension;
        $rutaCompleta  = self::UPLOAD_PATH . $nombreArchivo;

        // 6. Mover archivo del temporal a la carpeta de imágenes
        if (!move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
            throw new ValidationException('Error al guardar la imagen en el servidor');
        }

        // Devolver la URL pública relativa (lo que se guarda en imagen_url)
        return self::UPLOAD_DIR . $nombreArchivo;
    }

    /*
     * ELIMINAR: Baja lógica de un sello (activo = false).
     *
     * No elimina el registro de la base de datos — solo desactiva el sello
     * para que no se muestre en las credenciales. Consistente con el patrón
     * de baja lógica usado en UsuarioService.
     *
     * @throws NotFoundException si el sello no existe
     * @throws ValidationException si el sello ya está inactivo
     */
    public function eliminar(int $id): void
    {
        $sello = $this->em->find(Sello::class, $id);
        if (!$sello) {
            throw new NotFoundException('Sello no encontrado');
        }

        if (!$sello->isActivo()) {
            throw new ValidationException('El sello ya está inactivo');
        }

        $sello->setActivo(false);
        $this->em->flush();
    }

    /*
     * OBTENER: Busca un sello por ID.
     *
     * @throws NotFoundException si el sello no existe
     */
    public function obtener(int $id): array
    {
        $sello = $this->em->find(Sello::class, $id);
        if (!$sello) {
            throw new NotFoundException('Sello no encontrado');
        }

        return $this->serializar($sello);
    }

    /*
     * ACTUALIZAR: Modifica un sello existente.
     * Solo actualiza los campos presentes en $datos.
     *
     * @throws NotFoundException si el sello no existe
     */
    public function actualizar(int $id, array $datos): Sello
    {
        $sello = $this->em->find(Sello::class, $id);
        if (!$sello) {
            throw new NotFoundException('Sello no encontrado');
        }

        if (isset($datos['nombre'])) $sello->setNombre($datos['nombre']);
        if (isset($datos['imagen_url'])) $sello->setImagenUrl($datos['imagen_url']);
        if (array_key_exists('activo', $datos)) {
            $sello->setActivo(ValidationHelper::boolean($datos['activo']));
        }

        $this->em->flush();

        return $sello;
    }

    /*
     * SERIALIZAR: Convierte entidad Sello a array plano.
     */
    private function serializar(Sello $s): array
    {
        return [
            'id'         => $s->getIdSello(),
            'nombre'     => $s->getNombre(),
            'imagen_url' => $s->getImagenUrl(),
            'activo'     => $s->isActivo(),
            'created_at' => $s->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
