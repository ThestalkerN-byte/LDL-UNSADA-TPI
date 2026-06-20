<?php
declare(strict_types=1);

namespace App\Validation;

/**
 * VALIDATION HELPER: Validaciones reutilizables
 * ==============================================
 *
 * Métodos estáticos de validación para usar en controllers.
 * Cada método devuelve un string con el mensaje de error si la validación
 * falla, o null si pasa. Se pueden acumular y mostrar todos juntos.
 *
 * Uso:
 *   $errores = array_filter([
 *       ValidationHelper::requerido('email', $email),
 *       ValidationHelper::email('email', $email),
 *   ]);
 *   if ($errores) {
 *       $this->responder(400, 'error', implode(' | ', $errores));
 *   }
 */
class ValidationHelper
{
    /**
     * Valida que el campo no esté vacío (post-trim).
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

    /**
     * Valida formato de email.
     */
    public static function email(string $campo, ?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return "El campo {$campo} no tiene un formato de email válido";
        }

        return null;
    }

    /**
     * Valida longitud máxima.
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

    /**
     * Valida longitud mínima.
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

    /**
     * Valida fortaleza mínima de contraseña:
 * - Mínimo 8 caracteres
 * - Al menos una mayúscula
 * - Al menos una minúscula
 * - Al menos un dígito
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

        return null;
    }
}
