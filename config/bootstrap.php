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

// --- PARÁMETROS DE CONEXIÓN - Aiven Cloud MySQL ---
$connectionParams = [
    'driver'   => 'pdo_mysql',
    'host'     => 'mysql-35e2006e-ldl-52b5.h.aivencloud.com',
    'port'     => 26065,
    'dbname'   => 'defaultdb',
    'user'     => 'avnadmin',
    'password' => 'AVNS_5akJIflVWGJfNNO7dxU',
    'charset'  => 'utf8mb4',
    'driverOptions' => [
        1014 => 'REQUIRED',
    ],
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
