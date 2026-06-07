<?php
declare(strict_types=1);

namespace ICB\Router;

use Doctrine\ORM\EntityManagerInterface;
use ICB\Request\Request;

/*
 * ROUTER: Registro y dispatch de rutas REST
 * ==========================================
 * Propósito: mapear método HTTP + patrón de URI a un controller.
 * Soporta parámetros dinámicos como {id} en la ruta.
 *
 * Uso:
 *   $router = new Router();
 *   $router->get('/api/usuarios/{id}', [UsuarioController::class, 'obtener']);
 *   $router->post('/api/auth/login', [AuthController::class, 'login']);
 *   $resultado = $router->dispatch($request);
 *
 * El dispatch devuelve un array asociativo que index.php convierte a JSON.
 * Si el handler es un array [Clase, método], se instancia la clase automáticamente
 * con el EntityManager (inyección por constructor).
 *
 * Decisión técnica:
 *   Router propio en lugar de un framework como Slim porque el proyecto
 *   es chico y no necesitamos middleware PSR-15 ni contenedor de DI complejo.
 *   Si el proyecto crece, se migra a Slim o Symfony Router.
 *
 *   Parámetros {id}: uso regex con named groups para extraerlos limpios.
 *   No uso Reflection porque el handler siempre recibe (Request, array $params).
 *
 * Flujo de dispatch:
 *   1. Recibe Request
 *   2. Itera rutas registradas
 *   3. Match por método HTTP + regex de URI
 *   4. Extrae parámetros de la URL
 *   5. Ejecuta middlewares (si alguno devuelve algo ≠ null, corta con error)
 *   6. Resuelve el controller con sus dependencias
 *   7. Llama al método del controller con Request + params
 *   8. Si no hay match, devuelve array 404
 */
class Router
{
    /*
     * EntityManager compartido con el front controller (index.php).
     * Se pasa por constructor para que middleware y controllers
     * usen la MISMA instancia. Si cada uno creara la suya, las
     * entidades cargadas en el middleware quedarían detached para
     * el controller y causarían errores de persistencia.
     */
    private EntityManagerInterface $em;

    /*
     * Rutas registradas. Cada entrada:
     *   - method: POST|GET|PUT|DELETE
     *   - pattern: regex generado desde el patrón original
     *   - paramNames: nombres de parámetros {algo} extraídos
     *   - handler: [Clase, método]
     *   - middleware: callables a ejecutar antes del handler
     */
    private array $routes = [];

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /*
     * Registro de rutas por método HTTP.
     * El parámetro $middleware permite pasar funciones de verificación
     * específicas para cada ruta (auth, roles, etc.).
     */
    public function get(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }

    /*
     * addRoute: convierte un patrón como /api/usuarios/{id} en una regex.
     *
     * Ejemplo:
     *   Entrada: /api/usuarios/{id}
     *   paramNames: ['id']
     *   regex: #^/api/usuarios/([^/]+)$#
     *
     * Después, en dispatch, $matches[1] es el valor de {id}.
     */
    private function addRoute(string $method, string $pattern, array|callable $handler, array $middleware): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $regex,
            'paramNames' => $paramNames,
            'handler'    => $handler,
            'middleware'  => $middleware,
        ];
    }

    /*
     * Dispatch: busca la ruta que coincide con método + URI,
     * ejecuta middlewares, y llama al handler.
     *
     * Devuelve array para serializar como JSON.
     * Si no hay match, devuelve array 404.
     *
     * Contrato de middleware:
     *   - Recibe Request
     *   - Si todo ok → devuelve null (sigue el flujo)
     *   - Si error → devuelve array con 'error' y 'code'
     */
    public function dispatch(Request $request): array
    {
        $method = $request->method();
        $uri    = $request->uri();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extraer parámetros nombrados de la URL
                $params = [];
                foreach ($route['paramNames'] as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }

                // Ejecutar middlewares de la ruta
                foreach ($route['middleware'] as $middleware) {
                    $result = $middleware($request);
                    // Si el middleware devuelve algo (no null), es un error
                    if ($result !== null) {
                        return $result;
                    }
                }

                // Ejecutar handler: puede ser closure o [ControllerClass, método]
                if (is_callable($route['handler'])) {
                    // Closure/función: recibe (Request, array $params)
                    return ($route['handler'])($request, $params);
                }

                // [ControllerClass::class, 'method']: instanciar y llamar
                [$controllerClass, $action] = $route['handler'];
                $controller = $this->resolveController($controllerClass);

                // Pasar request y parámetros de la URL
                return $controller->$action($request, $params);
            }
        }

        // No hay ruta que coincida
        return [
            'error' => 'Ruta no encontrada',
            'code'  => 404,
        ];
    }

    /*
     * Resuelve el controller con sus dependencias.
     *
     * Por ahora:
     *   - Carga Doctrine desde config/doctrine.php
     *   - Pasa EntityManager al constructor del controller
     *
     * Si después necesitamos más dependencias, refactorizamos con
     * un contenedor DI tipo PHP-DI o un Service Container simple.
     *
     * Contrato de controllers:
     *   - Constructor recibe EntityManager
     *   - Método handler recibe (Request, array $params) → devuelve array
     */
    /*
     * Resuelve el controller con sus dependencias.
     * Usa el EntityManager compartido (el mismo que usa el middleware)
     * para evitar entidades detached entre request y controller.
     *
     * Contrato de controllers:
     *   - Constructor recibe EntityManagerInterface
     *   - Método handler recibe (Request, array $params) → devuelve array
     */
    private function resolveController(string $class): object
    {
        return new $class($this->em);
    }
}
