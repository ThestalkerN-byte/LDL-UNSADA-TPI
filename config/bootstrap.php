<?php

/**
 * BOOTSTRAP DE DOCTRINE ORM
 *
 * Este archivo centraliza la configuración del EntityManager.
 * Solo necesita ser requerido una vez desde index.php.
 * Cuando el equipo de Base de Datos entregue el servidor, solo hay que
 * actualizar las constantes de conexión de abajo.
 *
 * ⚠️  STOP: La conexión a la BD está en pausa. Se completan los parámetros
 *           cuando el equipo de DB haga entrega del servidor.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env if present
if (class_exists(\Dotenv\Dotenv::class)) {
    try {
        \Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
    } catch (\Throwable $e) {
        // ignore dotenv loading errors in constrained environments
    }
}

// --- PARÁMETROS DE CONEXIÓN (desde .env o valores por defecto) ---
$dbHost = $_ENV['DB_HOST'] ?? 'mysql-35e2006e-ldl-52b5.h.aivencloud.com';
$dbPort = (int) ($_ENV['DB_PORT'] ?? 26065);
$dbName = $_ENV['DB_NAME'] ?? 'defaultdb';
$dbUser = $_ENV['DB_USER'] ?? 'avnadmin';
$dbPass = $_ENV['DB_PASSWORD'] ?? 'AVNS_5akJIflVWGJfNNO7dxU';
$dbCharset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
$dbSslMode = $_ENV['DB_SSL_MODE'] ?? ($_ENV['DATABASE_URL'] ? null : 'REQUIRED');
$dbSslCa = $_ENV['DB_SSL_CA'] ?? null;

$driverOptions = [];
if ($dbSslMode !== null && defined('PDO::MYSQL_ATTR_SSL_MODE')) {
    $driverOptions[\PDO::MYSQL_ATTR_SSL_MODE] = $dbSslMode;
}
if ($dbSslCa !== null && $dbSslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
    $driverOptions[\PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
}

$connectionParams = [
    'driver'   => 'pdo_mysql',
    'host'     => $dbHost,
    'port'     => $dbPort,
    'dbname'   => $dbName,
    'user'     => $dbUser,
    'password' => $dbPass,
    'charset'  => $dbCharset,
];

if (!empty($driverOptions)) {
    $connectionParams['driverOptions'] = $driverOptions;
}
// ---------------------------------------------------------------------------------------

// Le indicamos a Doctrine dónde están las Entidades para leer los atributos PHP 8.
$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../src/Entity'],
    isDevMode: true,
);

$connection    = DriverManager::getConnection($connectionParams, $config);
$entityManager = new EntityManager($connection, $config);

return $entityManager;
