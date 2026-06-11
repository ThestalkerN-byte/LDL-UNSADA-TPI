<?php
declare(strict_types=1);
namespace App\RateLimiting;

/*
 * RATE LIMITER: Control de tasa de requests con sliding window log
 * ================================================================
 * Propósito: limitar la cantidad de requests a endpoints sensibles
 * (login, refresh, recuperación de contraseña) para mitigar ataques
 * de fuerza bruta, DoS y enumeración de usuarios.
 *
 * Algoritmo: sliding window log con lock atómico
 *   - Abre el archivo con flock(LOCK_EX) desde el principio
 *   - Lee timestamps vigentes
 *   - Verifica límite
 *   - Agrega timestamp y escribe
 *   - Libera lock
 *   Esto elimina la race condition TOCTOU entre lectura y escritura.
 *
 * Almacenamiento: archivos en disco
 *   - Cada "key" (ej: IP + endpoint) tiene su propio archivo
 *   - Los archivos expiran solos (al limpiar timestamps viejos)
 *   - No requiere Redis, Memcache ni base de datos
 *
 * Limitaciones:
 *   - El storage es por archivo: si hay 1000 keys diferentes, son
 *     1000 archivos. En servidores compartidos, verificar límite de
 *     inodos si hay muchas IPs únicas atacando.
 *   - Para producción con mucho tráfico concurrente, migrar a Redis.
 *
 * Uso:
 *   $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 60);
 *   $resultado = $limiter->check('login:192.168.1.1');
 *   if ($resultado !== null) { ... rate limited }
 *
 * Como middleware (en index.php):
 *   $rateLimiter = new \ICB\RateLimiting\RateLimiter();
 *   $router->post('/api/auth/login', [AuthController::class, 'login'], [
 *       $rateLimiter->limit(5, 60, 'login'),
 *   ]);
 */

class RateLimiter
{
    // Directorio base para almacenar los logs de rate limiting
    // Usa /tmp para evitar llenar el disco del proyecto y porque
    // en servidores compartidos /tmp suele tener protección de escritura
    private string $storageDir;

    // Umbral de violaciones para banear una key
    private int $banThreshold;

    // Ventana de tiempo para contar violaciones (en segundos)
    private int $banWindow;

    // Duración del ban (en segundos)
    private int $banDuration;

    public function __construct(
        ?string $storageDir = null,
        int $banThreshold = 5,
        int $banWindow = 3600,
        int $banDuration = 86400
    ) {
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/icb-rate-limits';
        $this->banThreshold = $banThreshold;
        $this->banWindow = $banWindow;
        $this->banDuration = $banDuration;

        // Crear directorio si no existe (con permisos restringidos)
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
    }

    /*
     * CHECK: Verifica si un request debe ser limitado.
     *
     * @param string $key     Identificador único (ej: "login:192.168.1.1")
     * @param int    $max     Máximo de requests permitidos en la ventana
     * @param int    $window  Ventana de tiempo en segundos
     *
     * @return array|null   null si está dentro del límite,
     *                      array con error si excede el límite
     *
     * El key debe incluir el endpoint y la IP del cliente para
     * que cada combinación tenga su propio contador:
     *   "login:192.168.1.1"
     *   "refresh:10.0.0.5"
     *   "recuperar:2001:db8::1"
     */
    public function check(string $key, int $max, int $window): ?array
    {
        // Verificar si la key está baneada
        if ($this->isBanned($key)) {
            return [
                'error' => 'Demasiados intentos. Cuenta bloqueada temporalmente',
                'code'  => 429,
            ];
        }

        $filePath = $this->storageDir . '/' . $this->sanitizarKey($key) . '.log';
        $ahora = microtime(true);
        $limiteInferior = $ahora - $window;

        // Abrir el archivo con lock exclusivo desde el principio.
        // Esto cubre TODO el ciclo read-check-write de forma atómica:
        // dos procesos concurrentes se serializan en el flock().
        $handle = @fopen($filePath, 'c+');
        if (!$handle) {
            // Si no podemos abrir el archivo, permitir el request
            // (fail open es mejor que bloquear a todos los usuarios)
            return null;
        }

        // Lock exclusivo: el proceso espera hasta obtenerlo
        flock($handle, LOCK_EX);

        // Leer contenido actual
        $contenido = stream_get_contents($handle);
        $timestamps = [];
        if ($contenido !== false && $contenido !== '') {
            foreach (explode("\n", trim($contenido)) as $linea) {
                $ts = (float) $linea;
                if ($ts >= $limiteInferior) {
                    $timestamps[] = $ts;
                }
            }
        }

        // Verificar límite
        if (count($timestamps) >= $max) {
            $tiempoEspera = (int) ceil($window - ($ahora - $timestamps[0]));
            flock($handle, LOCK_UN);
            fclose($handle);

            $this->registerViolation($key);

            return [
                'error' => 'Demasiados intentos. Intente nuevamente en ' . $tiempoEspera . ' segundos',
                'code'  => 429,
                'retry_after' => $tiempoEspera,
            ];
        }

        // Agregar timestamp actual y guardar
        $timestamps[] = $ahora;
        $contenido = '';
        foreach ($timestamps as $ts) {
            $contenido .= number_format($ts, 6, '.', '') . "\n";
        }

        // Re-truncar el archivo y escribir desde el principio
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $contenido);

        // Liberar lock y cerrar
        flock($handle, LOCK_UN);
        fclose($handle);

        return null;
    }

    /*
     * LIMIT: Devuelve un closure middleware configurable.
     *
     * Sigue el contrato de middleware del Router:
     *   - Recibe Request
     *   - Devuelve null si ok, array con error si rate limited
     *
     * @param int    $max       Máximo de requests (default: 10)
     * @param int    $window    Ventana en segundos (default: 60)
     * @param string $prefix    Prefijo para la key (ej: "login", "refresh")
     *
     * Uso:
     *   $router->post('/api/auth/login', [Handler], [
     *       $rateLimiter->limit(5, 60, 'login'),
     *   ]);
     */
    public function limit(int $max = 10, int $window = 60, string $prefix = 'default'): callable
    {
        return function (\App\Request\Request $request) use ($max, $window, $prefix): ?array {
            // Obtener IP del cliente (considerando proxies)
            $ip = $this->getClientIp($request);

            // Key única: prefijo + IP
            $key = $prefix . ':' . $ip;

            return $this->check($key, $max, $window);
        };
    }

    /*
     * Verifica si una key está baneada temporalmente.
     * El ban expira después de la duración configurada.
     */
    private function isBanned(string $key): bool
    {
        $banFile = $this->storageDir . '/' . $this->sanitizarKey($key) . '.ban';

        if (!file_exists($banFile)) {
            return false;
        }

        $banExpires = (int) file_get_contents($banFile);
        if (time() >= $banExpires) {
            @unlink($banFile);
            return false;
        }

        return true;
    }

    /*
     * Registra una violación de rate limiting.
     * Si se excede el umbral de violaciones en la ventana, se banea la key.
     */
    private function registerViolation(string $key): void
    {
        $violationsFile = $this->storageDir . '/' . $this->sanitizarKey($key) . '.violations';
        $ahora = time();
        $limite = $ahora - $this->banWindow;

        // Leer violaciones vigentes
        $violations = [];
        if (file_exists($violationsFile)) {
            $content = file_get_contents($violationsFile);
            foreach (explode("\n", trim($content)) as $line) {
                $ts = (int) $line;
                if ($ts >= $limite) {
                    $violations[] = $ts;
                }
            }
        }

        $violations[] = $ahora;

        // Si se excede el umbral, banear
        if (count($violations) >= $this->banThreshold) {
            $banFile = $this->storageDir . '/' . $this->sanitizarKey($key) . '.ban';
            file_put_contents($banFile, (string) (time() + $this->banDuration));
            @unlink($violationsFile);
            error_log('[ICB SECURITY] Rate limit ban for key: ' . $key);
            return;
        }

        // Guardar violaciones actualizadas
        file_put_contents($violationsFile, implode("\n", $violations));
    }

    /*
     * Obtiene la IP del cliente para rate limiting.
     *
     * Solo usa REMOTE_ADDR (la IP real de la conexión TCP) para evitar
     * IP spoofing via headers X-Forwarded-For / X-Real-IP.
     *
     * Si hay un proxy inverso confiable (Cloudflare, AWS ALB, etc.),
     * activar TRUSTED_PROXIES en .env con una lista de IPs separadas
     * por coma. La IP real vendrá en X-Forwarded-For.
     *
     * Seguridad: confiar en headers de proxy sin validación permite
     * que un atacante falsifique su IP y evite el rate limiting.
     */
    private function getClientIp(\App\Request\Request $request): string
    {
        $trustedProxies = $_ENV['TRUSTED_PROXIES'] ?? '';

        if ($trustedProxies !== '') {
            $proxies = array_map('trim', explode(',', $trustedProxies));
            $remoteAddr = $request->ip() ?? '';

            // Solo confiar en X-Forwarded-For si REMOTE_ADDR está en la whitelist
            if (in_array($remoteAddr, $proxies, true)) {
                $forwarded = $request->header('X_FORWARDED_FOR');
                if ($forwarded) {
                    $ips = explode(',', $forwarded);
                    return trim($ips[0]);
                }
            }
        }

        // Por defecto: solo la IP de conexión directa
        return $request->ip() ?? '0.0.0.0';
    }

    /*
     * Sanitiza la key para usarla como nombre de archivo.
     * Elimina caracteres peligrosos (path traversal, etc.).
     */
    private function sanitizarKey(string $key): string
    {
        // Solo permitir: letras, números, guiones, puntos y dos puntos
        // Reemplazar cualquier otro caracter por guión bajo
        return preg_replace('/[^a-zA-Z0-9\-\.\:]/', '_', $key);
    }
}
