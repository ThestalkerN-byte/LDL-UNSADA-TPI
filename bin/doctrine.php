<?php

declare(strict_types=1);

/*
 * =========================================================================
 * CLI DE DOCTRINE — Punto de entrada para comandos de consola
 * =========================================================================
 *
 * Este script es la puerta de entrada para ejecutar comandos de Doctrine
 * desde la terminal. Se usa principalmente para gestionar migrations.
 *
 * Uso:
 *   php bin/doctrine.php migrations:status    → Ver estado de migrations
 *   php bin/doctrine.php migrations:diff      → Generar migration desde entidades
 *   php bin/doctrine.php migrations:migrate   → Ejecutar migrations pendientes
 *   php bin/doctrine.php migrations:list      → Listar comandos disponibles
 *
 * Flujo:
 *   1. Carga Composer
 *   2. Carga .env
 *   3. Obtiene el EntityManager (doctrine.php)
 *   4. Lee la config de migrations (migrations.php)
 *   5. Registra todos los comandos de migration en Symfony Console
 *   6. Ejecuta el comando solicitado
 * =========================================================================
 */

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command;
use Symfony\Component\Console\Application;

// Autoload de Composer
require_once __DIR__ . '/../vendor/autoload.php';

// ─── Variables de entorno ────────────────────────────────────────────────
// Se cargan antes que nada porque EntityManager las necesita
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ─── EntityManager ───────────────────────────────────────────────────────
// Se crea a partir de la configuración en config/doctrine.php
$entityManager = require __DIR__ . '/../config/doctrine.php';

// ─── Configuración de Migrations ─────────────────────────────────────────
// Lee el archivo config/migrations.php que define rutas y opciones
$migrationsConfig = new PhpFile(__DIR__ . '/../config/migrations.php');

// Factory que conecta Doctrine ORM con el sistema de migrations
$dependencyFactory = DependencyFactory::fromEntityManager(
    $migrationsConfig,
    new ExistingEntityManager($entityManager),
);

// ─── Aplicación CLI ──────────────────────────────────────────────────────
// Usa Symfony Console para manejar argumentos y opciones de terminal
$cli = new Application('ICB - Doctrine Migrations', '1.0.0');
$cli->setCatchExceptions(true);

// Registrar todos los comandos de migrations
$cli->addCommands([
    new Command\DumpSchemaCommand($dependencyFactory),   // Muestra el SQL del schema actual
    new Command\ExecuteCommand($dependencyFactory),       // Ejecuta una migration específica
    new Command\GenerateCommand($dependencyFactory),      // Genera una clase migration vacía
    new Command\LatestCommand($dependencyFactory),        // Muestra la última migration disponible
    new Command\ListCommand($dependencyFactory),          // Lista todas las migrations
    new Command\MigrateCommand($dependencyFactory),       // Ejecuta migrations pendientes
    new Command\DiffCommand($dependencyFactory),          // Genera migration desde diferencias
    new Command\SyncMetadataCommand($dependencyFactory),  // Sincroniza metadatos
    new Command\VersionCommand($dependencyFactory),       // Información de una versión
    new Command\StatusCommand($dependencyFactory),        // Estado actual del sistema
    new Command\UpToDateCommand($dependencyFactory),      // Verifica si está al día
]);

// Ejecutar el comando solicitado
$cli->run();
