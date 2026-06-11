<?php

declare(strict_types=1);

/*
 * =========================================================================
 * SEED — Usuario administrador de prueba
 * =========================================================================
 *
 * Crea el usuario "admin" si aún no existe en la base de datos.
 * Idempotente: si el usuario ya está registrado, no hace cambios.
 *
 * Requisitos:
 *   - Archivo .env con DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASS
 *     (ver .env.example)
 *   - Tabla usuarios creada (ejecutar migrations antes si aplica)
 *
 * Uso:
 *   php bin/seed.php
 *
 * Credenciales de prueba creadas:
 *   Usuario:    admin
 *   Contraseña: Admin@123
 *   Rol:        admin
 * =========================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Entity\User;
use Doctrine\ORM\EntityManager;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

/** @var EntityManager $em */
$em = require __DIR__ . '/../config/doctrine.php';

const SEED_USERNAME = 'admin';
const SEED_PASSWORD = 'Admin@123';

$existing = $em->getRepository(User::class)->findOneBy(['usuario' => SEED_USERNAME]);

if ($existing !== null) {
    echo "[INFO] El usuario '" . SEED_USERNAME . "' ya existe (ID: {$existing->getId()}). No se creó duplicado.\n";
    exit(0);
}

$admin = new User();
$admin->setUsuario(SEED_USERNAME);
$admin->setPassword(password_hash(SEED_PASSWORD, PASSWORD_DEFAULT));
$admin->setNombre('Administrador');
$admin->setApellido('Sistema');
$admin->setDni('00000000');
$admin->setEmail('admin@ldl.unsada.edu.ar');
$admin->setRol('admin');
$admin->setEstado(true);

$em->persist($admin);
$em->flush();

echo "[OK] Usuario '" . SEED_USERNAME . "' creado con ID: {$admin->getId()}\n";
echo "\n";
echo "=== Resumen ===\n";
echo "  Usuario:    " . SEED_USERNAME . "\n";
echo "  Contraseña: " . SEED_PASSWORD . "\n";
echo "  Rol:        admin\n";
