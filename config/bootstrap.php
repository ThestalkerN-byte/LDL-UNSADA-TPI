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

// Cargar variables de entorno desde el archivo .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// --- PARÁMETROS DE CONEXIÓN - Aiven Cloud MySQL ---
$connectionParams = [
    'driver'   => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    'host'     => $_ENV['DB_HOST'],
    'port'     => $_ENV['DB_PORT'],
    'dbname'   => $_ENV['DB_NAME'],
    'user'     => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8mb4',
];
// ---------------------------------------------------------------------------------------

// Le indicamos a Doctrine dónde están las Entidades para leer los atributos PHP 8.
$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../src/Entity'],
    isDevMode: true,
);

$connection    = DriverManager::getConnection($connectionParams, $config);
$entityManager = new EntityManager($connection, $config);

return $entityManager;
