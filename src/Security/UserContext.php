<?php
declare(strict_types=1);

namespace App\Security;

/**
 * USER CONTEXT: Acceso estático al usuario autenticado
 * ======================================================
 *
 * Propósito: reemplazar el acceso a $_SESSION['id_usuario'] y $_SESSION['rol']
 * por un mecanismo stateless. El AuthMiddleware valida el JWT y setea el
 * contexto ANTES de que el controlador ejecute.
 *
 * Uso en controladores (reemplaza $_SESSION):
 *   $userId = UserContext::getId();       // antes: $_SESSION['id_usuario']
 *   $rol    = UserContext::getRol();      // antes: $_SESSION['rol']
 *   $admin  = UserContext::getUsuario();  // nombre de usuario
 *
 * DECISIÓN TÉCNICA:
 *   Usamos Singleton/estático en vez de inyección porque los controladores
 *   actuales acceden a $_SESSION directamente. Es el cambio mínimo invasivo.
 *   Si después se migra a un framework con DI, se reemplaza fácil.
 *
 * @see \App\Security\AuthMiddleware
 */
class UserContext
{
    private static ?array $currentUser = null;

    /**
     * Setea el usuario autenticado (llamado por AuthMiddleware).
     */
    public static function set(array $user): void
    {
        self::$currentUser = $user;
    }

    /**
     * Limpia el contexto (fin de request o logout).
     */
    public static function clear(): void
    {
        self::$currentUser = null;
    }

    /**
     * Devuelve todos los datos del usuario autenticado.
     */
    public static function get(): ?array
    {
        return self::$currentUser;
    }

    /**
     * Devuelve el ID del usuario autenticado.
     */
    public static function getId(): ?int
    {
        return self::$currentUser['id'] ?? null;
    }

    /**
     * Devuelve el nombre de usuario.
     */
    public static function getUsuario(): ?string
    {
        return self::$currentUser['usuario'] ?? null;
    }

    /**
     * Devuelve el rol del usuario ('admin' o 'user').
     */
    public static function getRol(): ?string
    {
        return self::$currentUser['rol'] ?? null;
    }

    /**
     * Verifica si el usuario autenticado es administrador.
     */
    public static function isAdmin(): bool
    {
        return self::$currentUser['rol'] ?? '' === 'admin';
    }

    /**
     * Verifica si hay un usuario autenticado.
     */
    public static function isAuthenticated(): bool
    {
        return self::$currentUser !== null;
    }
}
