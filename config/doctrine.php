<?php

declare(strict_types=1);

/*
 * =========================================================================
 * CONFIGURACIÓN DE DOCTRINE ORM
 * =========================================================================
 *
 * Este archivo crea y devuelve el EntityManager de Doctrine.
 * Es el corazón de la capa de persistencia: conecta la base de datos
 * con las entidades PHP usando los atributos (PHP 8 attributes) como
 * mapeo.
 *
 * El EntityManager se encarga de:
 *   - Mapear objetos PHP a filas de la base de datos (ORM)
 *   - Manejar transacciones y el Unit of Work
 *   - Proveer repositorios para consultas personalizadas
 *
 * Las credenciales se leen del .env cargado previamente.
 * =========================================================================
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMSetup;

// Crear configuración basada en atributos PHP 8
//   paths:    carpetas donde buscar entidades con atributos #[ORM\Entity]
//   isDevMode: true = Doctrine cachea en memoria (no requiere cache externo)
//             false = requiere configurar cache (APCu, Redis, etc.)
$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../src/Entity'],
    isDevMode: true,
);

// Habilitar lazy objects nativos de PHP 8.4
// Esto permite que Doctrine cree proxies sin necesitar symfony/var-exporter
$config->enableNativeLazyObjects(true);

// Configurar la conexión a la base de datos
// Los valores vienen del archivo .env cargado en bin/doctrine.php o index.php
$connection = DriverManager::getConnection([
    'driver'   => $_ENV['DB_DRIVER'],       // pdo_mysql, pdo_pgsql, pdo_sqlite, etc.
    'host'     => $_ENV['DB_HOST'],          // 127.0.0.1
    'port'     => $_ENV['DB_PORT'],          // 3306
    'dbname'   => $_ENV['DB_NAME'],          // credenciales_digitales
    'user'     => $_ENV['DB_USER'],          // root
    'password' => $_ENV['DB_PASS'],          // contraseña
    'charset'  => 'utf8mb4',                 // soporte completo de Unicode (emojis, acentos)
], $config);

// Crear y devolver el EntityManager
return new EntityManager($connection, $config);
