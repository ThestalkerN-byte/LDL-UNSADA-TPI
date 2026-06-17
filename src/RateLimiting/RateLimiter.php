<?php
declare(strict_types=1);
namespace App\RateLimiting;

/**
 * RATE LIMITER: Control de tasa de requests con sliding window log
 * ================================================================
 *
 * Propósito: Limitar la cantidad de requests a endpoints sensibles
 * (login, recuperación de contraseña) para mitigar ataques de fuerza
 * bruta, DoS y enumeración de usuarios.
 *
 * Algoritmo: Sliding window log con lock atómico (flock).
 *   - Abre el archivo con LOCK_EX desde el principio
 *   - Lee timestamps vigentes (dentro de la ventana)
 *   - Verifica si excede el límite
 *   - Agrega timestamp y escribe
 *   - Libera lock
 *
 * Almacenamiento: Archivos en disco ( /tmp/ldl-rate-limits/ ).
 *   - Cada key (IP + endpoint) tiene su propio archivo
 *   - No requiere Redis, Memcache ni base de datos
 *
 * Uso:
 *   $limiter = new RateLimiter();
 *   $resultado = $limiter->check('login:192.168.1.1', max: 5, window: 60);
 *   if ($resultado !== null) {
 *       // rate limited - devolver 429
 *   }
 */
class RateLimiter
{
    /** Directorio base para los archivos de rate limiting */
    private string $storageDir;

    /** Umbral de violaciones antes de banear una key */
    private int $banThreshold;

    /** Ventana para contar violaciones (segundos) */
    private int $banWindow;

    /** Duración del ban (segundos) */
    private int $banDuration;

    public function __construct(
        ?string $storageDir = null,
        int $banThreshold = 5,
        int $banWindow = 3600,
        int $banDuration = 86400
    ) {
        $this->storageDir   = $storageDir ?? sys_get_temp_dir() . '/ldl-rate-limits';
        $this->banThreshold = $banThreshold;
        $this->banWindow    = $banWindow;
        $this->banDuration  = $banDuration;

        // Crear directorio si no existe (permisos restringidos)
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
    }

    /**
     * CHECK: Verifica si un request debe ser limitado.
     *
     * @param string $key    Identificador único (ej: "login:192.168.1.1")
     * @param int    $max    Máximo de requests permitidos en la ventana
     * @param int    $window Ventana de tiempo en segundos
     *
     * @return array|null null si está dentro del límite,
     *                    array con datos del error si excede
     */
    public function check(string $key, int $max, int $window): ?array
    {
        // Verificar si la key está baneada
        if ($this->isBanned($key)) {
            return [
                'error' => 'Demasiados intentos. Cuenta bloqueada temporalmente.',
                'code'  => 429,
            ];
        }

        $filePath      = $this->storageDir . '/' . $this->sanitizarKey($key) . '.log';
        $ahora         = microtime(true);
        $limiteInferior = $ahora - $window;

        // Abrir con lock exclusivo desde el principio (atómico)
        $handle = @fopen($filePath, 'c+');
        if (!$handle) {
            // Fail open: si no podemos leer, permitimos el request
            return null;
        }

        flock($handle, LOCK_EX);

        // Leer timestamps vigentes
        $contenido  = stream_get_contents($handle);
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
                'error'       => 'Demasiados intentos. Intente nuevamente en ' . $tiempoEspera . ' segundos.',
                'code'        => 429,
                'retry_after' => $tiempoEspera,
            ];
        }

        // Agregar timestamp actual y escribir
        $timestamps[] = $ahora;
        $contenido = '';
        foreach ($timestamps as $ts) {
            $contenido .= number_format($ts, 6, '.', '') . "\n";
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $contenido);

        flock($handle, LOCK_UN);
        fclose($handle);

        return null;
    }

    /**
     * Obtiene la IP real del cliente.
     * Usa SOLO REMOTE_ADDR para evitar spoofing via headers.
     */
    public function getClientIp(): string
    {
        // En producción, si hay un proxy inverso confiable (Cloudflare, AWS ALB, etc.),
        // activar TRUSTED_PROXIES en .env y usar X-Forwarded-For.
        $trustedProxies = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '';

        if ($trustedProxies !== '') {
            $proxies    = array_map('trim', explode(',', $trustedProxies));
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

            if (in_array($remoteAddr, $proxies, true)) {
                $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
                if ($forwarded !== '') {
                    $ips = explode(',', $forwarded);
                    return trim($ips[0]);
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Verifica si una key está baneada temporalmente.
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

    /**
     * Registra una violación de rate limiting.
     * Si se excede el umbral en la ventana, se banea la key.
     */
    private function registerViolation(string $key): void
    {
        $violationsFile = $this->storageDir . '/' . $this->sanitizarKey($key) . '.violations';
        $ahora  = time();
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

        // Si excede el umbral, banear
        if (count($violations) >= $this->banThreshold) {
            $banFile = $this->storageDir . '/' . $this->sanitizarKey($key) . '.ban';
            file_put_contents($banFile, (string) (time() + $this->banDuration));
            @unlink($violationsFile);
            error_log('[LDL SECURITY] Rate limit ban for key: ' . $key);
            return;
        }

        // Guardar violaciones actualizadas
        file_put_contents($violationsFile, implode("\n", $violations));
    }

    /**
     * Sanitiza la key para usarla como nombre de archivo.
     */
    private function sanitizarKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9\-\.\:]/', '_', $key);
    }
}
