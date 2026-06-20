<?php

/**
 * BOOTSTRAP DE DOCTRINE ORM
 *
 * Este archivo centraliza la configuración del EntityManager.
 * Las credenciales de la base de datos se leen desde el archivo .env
 * (desarrollo) o variables de entorno del sistema (producción).
 *
 * ⚠️  Si no hay .env, el sistema falla con un error claro.
 *     Nunca escribir credenciales reales en este archivo.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

// --- Carga de variables de entorno ---
// Intenta cargar .env; si no existe (producción), usa las variables de entorno
// reales del sistema operativo o los valores por defecto definidos abajo.
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// --- PARÁMETROS DE CONEXIÓN - Base de Datos ---
// Los valores se leen desde .env (desarrollo) o variables de entorno (producción).
// Si faltan credenciales esenciales, se lanza una excepción con mensaje claro.
$connectionParams = [
    'driver'   => $_ENV['DB_DRIVER']       ?? 'pdo_mysql',
    'host'     => $_ENV['DB_HOST']         ?? '',
    'port'     => $_ENV['DB_PORT']         ?? '3306',
    'dbname'   => $_ENV['DB_NAME']         ?? '',
    'user'     => $_ENV['DB_USER']         ?? '',
    'password' => $_ENV['DB_PASS']         ?? '',
    'charset'  => $_ENV['DB_CHARSET']      ?? 'utf8mb4',
    'driverOptions' => [
        1014 => $_ENV['DB_SSL'] ?? 'REQUIRED',
    ],
];

// Valida que las credenciales esenciales estén configuradas
if (empty($connectionParams['host']) || empty($connectionParams['user'])) {
    throw new \RuntimeException(
        'Faltan credenciales de base de datos. ' .
        'Copiá .env.example a .env y completá los valores de DB_HOST, DB_USER y DB_PASS.'
    );
}
// ---------------------------------------------------------------------------------------

// Le indicamos a Doctrine dónde están las Entidades para leer los atributos PHP 8.
$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../src/Entity'],
    isDevMode: ($_ENV['APP_DEBUG'] ?? '') === 'true',
);

$connection    = DriverManager::getConnection($connectionParams, $config);
$entityManager = new EntityManager($connection, $config);

return $entityManager;
