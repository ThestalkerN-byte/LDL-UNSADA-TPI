<?php

declare(strict_types=1);

/*
 * =========================================================================
 * CONFIGURACIÓN DE DOCTRINE MIGRATIONS
 * =========================================================================
 *
 * Configura el sistema de migrations de Doctrine.
 * Las migrations permiten versionar los cambios en el esquema de la base
 * de datos (como Git pero para la estructura de tablas).
 *
 * Cada migration es una clase PHP que define qué cambia en el esquema
 * (método up()) y cómo revertirlo (método down()).
 *
 * Comandos útiles:
 *   php bin/doctrine.php migrations:diff    → Genera nueva migration
 *   php bin/doctrine.php migrations:migrate → Ejecuta migrations pendientes
 *   php bin/doctrine.php migrations:status  → Ver estado actual
 * =========================================================================
 */

return [
    // Nombre de la tabla que Doctrine usa para trackear migrations ejecutadas
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
    ],

    // Dónde encontrar las clases de migration y su namespace
    'migrations_paths' => [
        'App\Migrations' => __DIR__ . '/../migrations',
    ],

    // Si falla una query, se revierte toda la migration (no aplica cambios parciales)
    'all_or_nothing' => true,

    // Ejecuta cada migration dentro de una transacción SQL
    'transactional' => true,

    // Verifica que la plataforma de la DB sea compatible antes de ejecutar
    'check_database_platform' => true,
];
