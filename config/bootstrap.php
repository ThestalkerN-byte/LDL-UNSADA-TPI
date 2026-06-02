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

// --- PARÁMETROS DE CONEXIÓN (Completar cuando el equipo de DB entregue el servidor) ---
$connectionParams = [
    'driver'   => 'pdo_mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'credenciales_db',
    'user'     => 'root',       // TODO: reemplazar con el usuario real
    'password' => 'root',      // TODO: reemplazar con la contraseña real
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
