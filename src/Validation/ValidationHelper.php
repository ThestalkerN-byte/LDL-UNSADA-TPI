<?php

declare(strict_types=1);

namespace ICB\Validation;

/*
 * =========================================================================
 * HELPER: ValidationHelper
 * =========================================================================
 *
 * Métodos estáticos de validación reutilizables en services y controllers.
 *
 * Cada método devuelve un string con el mensaje de error si la validación
 * falla, o null si pasa. Esto permite acumular errores campo por campo.
 *
 * USO:
 *   $errores = array_filter([
 *       ValidationHelper::requerido('email', $email),
 *       ValidationHelper::email($email),
 *       ValidationHelper::maxLength('email', $email, 100),
 *   ]);
 *   if ($errores) { throw new ValidationException(implode(...)); }
 * =========================================================================
 */

class ValidationHelper
{
    /*
     * ===================================================================
     * requerido — Valida que el campo no esté vacío (post-trim)
     * ===================================================================
     *
     * DIFERENCIA con empty(): "0" es considerado válido aquí.
     * Usamos strlen(trim()) para detectar strings de solo espacios.
     * ===================================================================
     */
    public static function requerido(string $campo, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return "El campo {$campo} es requerido";
        }

        if (is_string($valor) && strlen(trim($valor)) === 0) {
            return "El campo {$campo} no puede estar vacío";
        }

        return null;
    }

    /*
     * ===================================================================
     * email — Valida formato de email
     * ===================================================================
     */
    public static function email(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null; // No requerido, pasar por requerido()
        }

        if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return "El campo {$campo} no tiene un formato de email válido";
        }

        return null;
    }

    /*
     * ===================================================================
     * maxLength — Valida longitud máxima
     * ===================================================================
     */
    public static function maxLength(string $campo, ?string $valor, int $max): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (mb_strlen($valor) > $max) {
            return "El campo {$campo} no puede tener más de {$max} caracteres";
        }

        return null;
    }

    /*
     * ===================================================================
     * minLength — Valida longitud mínima
     * ===================================================================
     */
    public static function minLength(string $campo, ?string $valor, int $min): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (mb_strlen(trim($valor)) < $min) {
            return "El campo {$campo} debe tener al menos {$min} caracteres";
        }

        return null;
    }

    /*
     * ===================================================================
     * enteroPositivo — Valida que el valor sea un entero positivo
     * ===================================================================
     *
     * Acepta tanto int como string numérico. Rechaza 0, negativos, float.
     */
    public static function enteroPositivo(string $campo, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        // Si es float, rechazar directamente (ctype_digit lanza deprecation con float)
        if (is_float($valor)) {
            return "El campo {$campo} debe ser un número entero positivo";
        }

        $intVal = is_int($valor) ? $valor : (is_string($valor) && ctype_digit($valor) ? (int) $valor : -1);

        if ($intVal <= 0) {
            return "El campo {$campo} debe ser un número entero positivo";
        }

        return null;
    }

    /*
     * ===================================================================
     * fecha — Valida formato de fecha Y-m-d
     * ===================================================================
     */
    public static function fecha(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $valor);
        if (!$dt || $dt->format('Y-m-d') !== $valor) {
            return "El campo {$campo} debe tener formato YYYY-MM-DD";
        }

        return null;
    }

    /*
     * ===================================================================
     * fechaFutura — Valida que la fecha sea hoy o en el futuro
     * ===================================================================
     */
    public static function fechaFutura(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $valor);
        if (!$dt || $dt->format('Y-m-d') !== $valor) {
            return "El campo {$campo} debe tener formato YYYY-MM-DD";
        }

        $hoy = new \DateTime('today');
        if ($dt < $hoy) {
            return "El campo {$campo} debe ser una fecha futura";
        }

        return null;
    }

    /*
     * ===================================================================
     * url — Valida formato de URL
     * ===================================================================
     */
    public static function url(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!filter_var($valor, FILTER_VALIDATE_URL)) {
            return "El campo {$campo} no tiene un formato de URL válido";
        }

        return null;
    }

    /*
     * ===================================================================
     * enum — Valida que el valor esté dentro de una lista permitida
     * ===================================================================
     */
    public static function enum(string $campo, mixed $valor, array $permitidos): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!in_array($valor, $permitidos, true)) {
            return "El campo {$campo} debe ser uno de: " . implode(', ', $permitidos);
        }

        return null;
    }

    /*
     * ===================================================================
     * boolean — Normaliza un valor a booleano real
     * ===================================================================
     *
     * Convierte strings "true"/"false"/"1"/"0" y enteros 1/0 a bool.
     * Si el valor no es reconocido, retorna el default.
     *
     * Útil para endpoints que reciben datos de formularios (multipart)
     * donde todo llega como string.
     * ===================================================================
     */
    public static function boolean(mixed $valor, bool $default = false): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_int($valor)) {
            return $valor === 1;
        }

        if (is_string($valor)) {
            $lower = strtolower(trim($valor));
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0' || $lower === '') {
                return false;
            }
        }

        return $default;
    }

    /*
     * ===================================================================
     * sanitizar — Recorta espacios y elimina caracteres de control
     * ===================================================================
     */
    public static function sanitizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);
        // Elimina caracteres de control (tabs, newlines, etc.) excepto espacios
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor);

        return $valor === '' ? null : $valor;
    }

    /*
     * ===================================================================
     * acumular — Helper para juntar errores y lanzar excepción
     * ===================================================================
     *
     * Uso:
     *   ValidationHelper::acumular([
     *       ValidationHelper::requerido('email', $email),
     *       ValidationHelper::email($email),
     *       ValidationHelper::maxLength('email', $email, 100),
     *   ]);
     * ===================================================================
     *
     * @throws ValidationException
     */
    public static function acumular(array $validaciones): void
    {
        $errores = array_values(array_filter($validaciones));

        if (!empty($errores)) {
            throw new \ICB\Exception\ValidationException(implode('; ', $errores));
        }
    }

    /*
     * ===================================================================
     * password — Valida fortaleza mínima de contraseña
     * ===================================================================
     *
     * - Mínimo 8 caracteres
     * - Requiere al menos una mayúscula
     * - Requiere al menos una minúscula
     * - Requiere al menos un dígito
     * - Requiere al menos un carácter especial
     * ===================================================================
     */
    public static function password(string $campo, ?string $valor, int $min = 8): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (strlen($valor) < $min) {
            return "El campo {$campo} debe tener al menos {$min} caracteres";
        }

        if (!preg_match('/[A-Z]/', $valor)) {
            return "El campo {$campo} debe contener al menos una letra mayúscula";
        }

        if (!preg_match('/[a-z]/', $valor)) {
            return "El campo {$campo} debe contener al menos una letra minúscula";
        }

        if (!preg_match('/[0-9]/', $valor)) {
            return "El campo {$campo} debe contener al menos un dígito";
        }

        if (!preg_match('/[^a-zA-Z0-9]/', $valor)) {
            return "El campo {$campo} debe contener al menos un carácter especial";
        }

        return null;
    }
}
