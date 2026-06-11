<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\RateLimiting\RateLimiter;
use App\Request\Request;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/rate-limiter-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->eliminarDirectorio($this->storageDir);
    }

    public function testDentroDelLimiteRetornaNull(): void
    {
        $limiter = new RateLimiter($this->storageDir);
        $key = 'test:127.0.0.1';

        $this->assertNull($limiter->check($key, 3, 60));
        $this->assertNull($limiter->check($key, 3, 60));
        $this->assertNull($limiter->check($key, 3, 60));
    }

    public function testAlExcederLimiteRetorna429(): void
    {
        $limiter = new RateLimiter($this->storageDir);
        $key = 'test:192.168.1.1';

        $this->assertNull($limiter->check($key, 2, 60));
        $this->assertNull($limiter->check($key, 2, 60));

        $result = $limiter->check($key, 2, 60);

        $this->assertIsArray($result);
        $this->assertSame(429, $result['code']);
        $this->assertStringContainsString('Demasiados intentos', $result['error']);
        $this->assertArrayHasKey('retry_after', $result);
    }

    public function testLimitComoCallableMiddleware(): void
    {
        $limiter = new RateLimiter($this->storageDir);
        $middleware = $limiter->limit(2, 60, 'login');
        $request = new Request(['REMOTE_ADDR' => '10.0.0.5']);

        $this->assertNull($middleware($request));
        $this->assertNull($middleware($request));

        $result = $middleware($request);

        $this->assertIsArray($result);
        $this->assertSame(429, $result['code']);
        $this->assertStringContainsString('Demasiados intentos', $result['error']);
    }

    private function eliminarDirectorio(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $archivos = scandir($dir);
        if ($archivos === false) {
            return;
        }

        foreach ($archivos as $archivo) {
            if ($archivo === '.' || $archivo === '..') {
                continue;
            }
            $ruta = $dir . '/' . $archivo;
            if (is_dir($ruta)) {
                $this->eliminarDirectorio($ruta);
            } else {
                @unlink($ruta);
            }
        }

        @rmdir($dir);
    }
}
