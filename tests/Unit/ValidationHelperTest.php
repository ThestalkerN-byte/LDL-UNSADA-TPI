<?php

declare(strict_types=1);

namespace App\Tests\Unit;

/*
 * =========================================================================
 * TEST: ValidationHelper
 * =========================================================================
 *
 * Tests unitarios para el helper de validación.
 * No requieren base de datos — son puramente lógicos.
 * =========================================================================
 */

use App\Exception\ValidationException;
use App\Validation\ValidationHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidationHelperTest extends TestCase
{
    // ─── requerido() ──────────────────────────────────────────────────────

    public function testRequeridoConValorValido(): void
    {
        $this->assertNull(ValidationHelper::requerido('test', 'hola'));
        $this->assertNull(ValidationHelper::requerido('test', '0'));
        $this->assertNull(ValidationHelper::requerido('test', 0));
    }

    public function testRequeridoConValorVacio(): void
    {
        $this->assertNotNull(ValidationHelper::requerido('test', ''));
        $this->assertNotNull(ValidationHelper::requerido('test', null));
        $this->assertNotNull(ValidationHelper::requerido('test', '   '));
    }

    // ─── email() ──────────────────────────────────────────────────────────

    public function testEmailValido(): void
    {
        $this->assertNull(ValidationHelper::email('email', 'user@example.com'));
        $this->assertNull(ValidationHelper::email('email', 'user+tag@example.co.uk'));
        $this->assertNull(ValidationHelper::email('email', null));  // no requerido
        $this->assertNull(ValidationHelper::email('email', ''));     // no requerido
    }

    public function testEmailInvalido(): void
    {
        $this->assertNotNull(ValidationHelper::email('email', 'invalido'));
        $this->assertNotNull(ValidationHelper::email('email', 'user@'));
        $this->assertNotNull(ValidationHelper::email('email', '@example.com'));
    }

    // ─── maxLength() ──────────────────────────────────────────────────────

    public function testMaxLengthValido(): void
    {
        $this->assertNull(ValidationHelper::maxLength('campo', 'hola', 10));
        $this->assertNull(ValidationHelper::maxLength('campo', '', 10));
        $this->assertNull(ValidationHelper::maxLength('campo', null, 10));
    }

    public function testMaxLengthExcedido(): void
    {
        $this->assertNotNull(ValidationHelper::maxLength('campo', 'hola mundo!', 10));
    }

    // ─── minLength() ──────────────────────────────────────────────────────

    public function testMinLengthValido(): void
    {
        $this->assertNull(ValidationHelper::minLength('campo', 'hola mundo', 5));
        $this->assertNull(ValidationHelper::minLength('campo', '', 5));
        $this->assertNull(ValidationHelper::minLength('campo', null, 5));
    }

    public function testMinLengthInsuficiente(): void
    {
        $this->assertNotNull(ValidationHelper::minLength('campo', 'abc', 5));
    }

    // ─── password() ───────────────────────────────────────────────────────

    public function testPasswordValido(): void
    {
        $this->assertNull(ValidationHelper::password('password', 'Abcdef1!'));
        $this->assertNull(ValidationHelper::password('password', 'una muy larga', 3));
    }

    public function testPasswordInvalido(): void
    {
        $this->assertNotNull(ValidationHelper::password('password', '12345'));
    }

    // ─── boolean() ────────────────────────────────────────────────────────

    #[DataProvider('booleanProvider')]
    public function testBoolean(mixed $input, bool $default, bool $expected): void
    {
        $this->assertSame($expected, ValidationHelper::boolean($input, $default));
    }

    public static function booleanProvider(): array
    {
        return [
            'true bool'          => [true, false, true],
            'false bool'         => [false, true, false],
            'string "true"'      => ['true', false, true],
            'string "false"'     => ['false', true, false],
            'string "1"'         => ['1', false, true],
            'string "0"'         => ['0', true, false],
            'int 1'              => [1, false, true],
            'int 0'              => [0, true, false],
            'string vacío'       => ['', true, false],
            'valor desconocido'  => ['xyz', false, false],
            'valor desconocido 2'=> ['xyz', true, true],
        ];
    }

    // ─── enteroPositivo() ─────────────────────────────────────────────────

    public function testEnteroPositivoValido(): void
    {
        $this->assertNull(ValidationHelper::enteroPositivo('id', 1));
        $this->assertNull(ValidationHelper::enteroPositivo('id', '5'));
        $this->assertNull(ValidationHelper::enteroPositivo('id', null));
    }

    public function testEnteroPositivoInvalido(): void
    {
        $this->assertNotNull(ValidationHelper::enteroPositivo('id', 0));
        $this->assertNotNull(ValidationHelper::enteroPositivo('id', -1));
        $this->assertNotNull(ValidationHelper::enteroPositivo('id', 'abc'));
        $this->assertNotNull(ValidationHelper::enteroPositivo('id', 1.5));
    }

    // ─── fecha() ──────────────────────────────────────────────────────────

    public function testFechaValida(): void
    {
        $this->assertNull(ValidationHelper::fecha('fecha', '2024-01-15'));
        $this->assertNull(ValidationHelper::fecha('fecha', null));
        $this->assertNull(ValidationHelper::fecha('fecha', ''));
    }

    public function testFechaInvalida(): void
    {
        $this->assertNotNull(ValidationHelper::fecha('fecha', '15-01-2024'));
        $this->assertNotNull(ValidationHelper::fecha('fecha', '2024/01/15'));
        $this->assertNotNull(ValidationHelper::fecha('fecha', 'no-es-fecha'));
        $this->assertNotNull(ValidationHelper::fecha('fecha', '2024-13-01')); // mes inválido
    }

    // ─── url() ────────────────────────────────────────────────────────────

    public function testUrlValida(): void
    {
        $this->assertNull(ValidationHelper::url('url', 'https://ejemplo.com/imagen.png'));
        $this->assertNull(ValidationHelper::url('url', 'http://ejemplo.com'));
        $this->assertNull(ValidationHelper::url('url', null));
    }

    public function testUrlInvalida(): void
    {
        $this->assertNotNull(ValidationHelper::url('url', 'no-es-url'));
        // Vacío no da error porque no es requerido
        $this->assertNull(ValidationHelper::url('url', ''));
    }

    // ─── enum() ───────────────────────────────────────────────────────────

    public function testEnumValido(): void
    {
        $this->assertNull(ValidationHelper::enum('estado', 'Activo', ['Activo', 'Inactivo']));
        $this->assertNull(ValidationHelper::enum('estado', null, ['Activo', 'Inactivo']));
    }

    public function testEnumInvalido(): void
    {
        $this->assertNotNull(ValidationHelper::enum('estado', 'Pendiente', ['Activo', 'Inactivo']));
    }

    // ─── sanitizar() ──────────────────────────────────────────────────────

    public function testSanitizar(): void
    {
        $this->assertSame('hola', ValidationHelper::sanitizar('  hola  '));
        $this->assertNull(ValidationHelper::sanitizar('   '));
        $this->assertNull(ValidationHelper::sanitizar(''));
        $this->assertNull(ValidationHelper::sanitizar(null));
    }

    // ─── acumular() ───────────────────────────────────────────────────────

    public function testAcumularSinErrores(): void
    {
        // No debe lanzar excepción
        ValidationHelper::acumular([
            ValidationHelper::requerido('nombre', 'Juan'),
            ValidationHelper::email('email', 'juan@ejemplo.com'),
        ]);
        $this->assertTrue(true); // Si llegamos acá, OK
    }

    public function testAcumularConErrores(): void
    {
        $this->expectException(ValidationException::class);
        ValidationHelper::acumular([
            ValidationHelper::requerido('nombre', ''),
            ValidationHelper::email('email', 'email-invalido'),
        ]);
    }

    // ─── fechaFutura() ────────────────────────────────────────────────────

    public function testFechaFuturaValida(): void
    {
        $this->assertNull(ValidationHelper::fechaFutura('fecha', date('Y-m-d', strtotime('+1 day'))));
        $this->assertNull(ValidationHelper::fechaFutura('fecha', null));
    }

    public function testFechaFuturaPasada(): void
    {
        $this->assertNotNull(ValidationHelper::fechaFutura('fecha', '2020-01-01'));
    }
}
