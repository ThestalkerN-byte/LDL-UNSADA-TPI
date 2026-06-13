<?php
namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Controlador REST para la biblioteca de sellos predefinidos.
 *
 * Los sellos son la lista oficial de organizaciones/certificaciones
 * que un administrador puede asignar a las credenciales.
 *
 * Los datos se guardan en un archivo JSON local (no requiere tabla nueva en DB).
 *
 * Mapea:
 *   GET    ?action=sello            → index()   Lista todos los sellos disponibles
 *   POST   ?action=sello            → create()  Agregar nuevo sello
 *   PUT    ?action=sello&id={id}    → update()  Editar sello existente
 *   DELETE ?action=sello&id={id}    → delete()  Eliminar sello
 */
class SelloController {

    private EntityManagerInterface $em;
    private string $filePath;

    public function __construct(EntityManagerInterface $em) {
        $this->em = $em;
        // Guardamos los sellos en un archivo JSON dentro del proyecto
        $this->filePath = __DIR__ . '/../../data/sellos.json';

        // Crear directorio y archivo si no existen
        if (!is_dir(dirname($this->filePath))) {
            mkdir(dirname($this->filePath), 0755, true);
        }
        if (!file_exists($this->filePath)) {
            // Sellos predefinidos iniciales con logos reales
            $default = [
                [
                    'id'         => 1,
                    'nombre'     => 'UNSADA',
                    'imagen_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/95/UNSADA_logo.png/200px-UNSADA_logo.png',
                    'descripcion'=> 'Universidad Nacional de San Antonio de Areco'
                ],
                [
                    'id'         => 2,
                    'nombre'     => 'FAIF',
                    'imagen_url' => 'https://faif.com.ar/wp-content/uploads/2020/06/faif-logo.png',
                    'descripcion'=> 'Federación Argentina de Instituciones de Fútbol'
                ],
                [
                    'id'         => 3,
                    'nombre'     => 'ADESA',
                    'imagen_url' => 'https://adesa.org.ar/wp-content/uploads/2021/05/ADESA-logo.png',
                    'descripcion'=> 'Asociación de Entidades de Seguridad Argentina'
                ],
                [
                    'id'         => 4,
                    'nombre'     => 'SACRA',
                    'imagen_url' => 'https://sacra.com.ar/logo.png',
                    'descripcion'=> 'Sociedad Argentina de Criadores y Rematadores'
                ],
            ];
            file_put_contents($this->filePath, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function handleRequest(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

        match ($method) {
            'GET'    => $this->index(),
            'POST'   => $this->create(),
            'PUT'    => $id ? $this->update($id) : $this->responder(400, 'error', 'Se requiere un ID.'),
            'DELETE' => $id ? $this->delete($id) : $this->responder(400, 'error', 'Se requiere un ID.'),
            default  => $this->responder(405, 'error', 'Método HTTP no permitido.'),
        };
    }

    /** GET → Lista todos los sellos */
    private function index(): void {
        $sellos = $this->cargarSellos();
        $this->responder(200, 'success', 'Sellos obtenidos.', array_values($sellos));
    }

    /** POST → Crear nuevo sello */
    private function create(): void {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['nombre'])) {
            $this->responder(400, 'error', 'El campo nombre es obligatorio.');
            return;
        }

        $sellos = $this->cargarSellos();
        $nuevoId = count($sellos) > 0 ? max(array_column($sellos, 'id')) + 1 : 1;

        $nuevo = [
            'id'          => $nuevoId,
            'nombre'      => trim($data['nombre']),
            'imagen_url'  => trim($data['imagen_url'] ?? ''),
            'descripcion' => trim($data['descripcion'] ?? ''),
        ];

        $sellos[] = $nuevo;
        $this->guardarSellos($sellos);

        $this->responder(201, 'success', 'Sello creado correctamente.', $nuevo);
    }

    /** PUT → Editar sello existente */
    private function update(int $id): void {
        $sellos = $this->cargarSellos();
        $idx = $this->buscarIndice($sellos, $id);

        if ($idx === null) {
            $this->responder(404, 'error', 'Sello no encontrado.');
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['nombre']))      $sellos[$idx]['nombre']      = trim($data['nombre']);
        if (isset($data['imagen_url']))  $sellos[$idx]['imagen_url']  = trim($data['imagen_url']);
        if (isset($data['descripcion'])) $sellos[$idx]['descripcion'] = trim($data['descripcion']);

        $this->guardarSellos($sellos);
        $this->responder(200, 'success', 'Sello actualizado.', $sellos[$idx]);
    }

    /** DELETE → Eliminar sello */
    private function delete(int $id): void {
        $sellos = $this->cargarSellos();
        $idx = $this->buscarIndice($sellos, $id);

        if ($idx === null) {
            $this->responder(404, 'error', 'Sello no encontrado.');
            return;
        }

        array_splice($sellos, $idx, 1);
        $this->guardarSellos($sellos);

        $this->responder(200, 'success', 'Sello eliminado correctamente.');
    }

    // --- Helpers ---

    private function cargarSellos(): array {
        return json_decode(file_get_contents($this->filePath), true) ?? [];
    }

    private function guardarSellos(array $sellos): void {
        file_put_contents($this->filePath, json_encode(array_values($sellos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function buscarIndice(array $sellos, int $id): ?int {
        foreach ($sellos as $i => $s) {
            if ($s['id'] === $id) return $i;
        }
        return null;
    }

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
