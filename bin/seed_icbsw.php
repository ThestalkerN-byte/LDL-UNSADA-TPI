<?php

declare(strict_types=1);

/*
 * =========================================================================
 * SEED — Reemplazar usuario admin por icbSw
 * =========================================================================
 *
 * Este script:
 *   1. Busca el usuario "admin" y lo elimina permanentemente junto con
 *      todos sus registros asociados (credenciales, conversaciones,
 *      mensajes, historial, tokens de recuperación).
 *   2. Crea el usuario "icbSw" con contraseña "Unsa@_26" y roles
 *      Admin + Usuario.
 *
 * Usa SQL directo con desactivación temporal de FOREIGN_KEY_CHECKS para
 * evitar errores por dependencias (funciona tanto en local con datos de
 * prueba como en producción con DB vacía).
 *
 * Uso:
 *   php bin/seed_icbsw.php
 * =========================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$em = require __DIR__ . '/../config/doctrine.php';

use Doctrine\ORM\EntityManager;
use ICB\Entity\Rol;
use ICB\Entity\Usuario;

/** @var EntityManager $em */
$conn = $em->getConnection();

// ─── 1. Eliminar usuario admin ─────────────────────────────────────────────
$admin = $em->getRepository(Usuario::class)->findOneBy(['usuario' => 'admin']);

if ($admin === null) {
    echo "[INFO] No se encontró un usuario 'admin'. Se omitió la eliminación.\n";
} else {
    $adminId = (int) $admin->getIdUsuario();
    echo "[DELETE] Eliminando usuario 'admin' (ID: {$adminId})...\n";

    // Desactivar FK checks temporalmente para borrar todas las dependencias
    $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

    $conn->executeStatement('DELETE FROM usuario_roles WHERE id_usuario = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM historial_cambios WHERE id_admin = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM recuperacion_tokens WHERE id_usuario = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM mensajes WHERE id_emisor = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM conversaciones WHERE id_usuario = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM credenciales WHERE id_usuario = :id', ['id' => $adminId]);
    $conn->executeStatement('DELETE FROM usuarios WHERE id_usuario = :id', ['id' => $adminId]);

    $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

    echo "[OK] Usuario 'admin' y todos sus datos asociados eliminados.\n";

    // Limpiar el EntityManager para evitar referencias obsoletas
    $em->clear();
}

// ─── 2. Verificar que los roles existan ────────────────────────────────────
$rolRepo = $em->getRepository(Rol::class);

$rolAdmin = $rolRepo->findOneBy(['nombre' => 'Admin']);
if ($rolAdmin === null) {
    $rolAdmin = new Rol();
    $rolAdmin->setNombre('Admin');
    $em->persist($rolAdmin);
    echo "[CREATE] Rol 'Admin' creado.\n";
}

$rolUsuario = $rolRepo->findOneBy(['nombre' => 'Usuario']);
if ($rolUsuario === null) {
    $rolUsuario = new Rol();
    $rolUsuario->setNombre('Usuario');
    $em->persist($rolUsuario);
    echo "[CREATE] Rol 'Usuario' creado.\n";
}

$em->flush();

// ─── 3. Verificar que icbSw no exista ya ───────────────────────────────────
$icbSw = $em->getRepository(Usuario::class)->findOneBy(['usuario' => 'icbSw']);
if ($icbSw !== null) {
    echo "[INFO] El usuario 'icbSw' ya existe (ID: {$icbSw->getIdUsuario()}). No se creó duplicado.\n";
    exit(0);
}

// ─── 4. Crear usuario icbSw ────────────────────────────────────────────────
$icbSw = new Usuario();
$icbSw->setUsuario('icbSw');
$icbSw->setDni('00000000');
$icbSw->setNombre('ICB');
$icbSw->setApellido('Swagger');
$icbSw->setEmail('icbsw@icb.com');
$icbSw->setPasswordHash(password_hash('Unsa@_26', PASSWORD_DEFAULT));
$icbSw->setEstado('Activo');
$icbSw->addRol($rolAdmin);
$icbSw->addRol($rolUsuario);

$em->persist($icbSw);
$em->flush();

echo "[OK] Usuario 'icbSw' creado con ID: {$icbSw->getIdUsuario()}\n";
echo "[OK] Roles asignados: Admin, Usuario\n";
echo "\n";
echo "=== Resumen ===\n";
echo "  Usuario: icbSw\n";
echo "  Contraseña: Unsa@_26\n";
echo "  Roles: Admin, Usuario\n";
echo "  Acceso Swagger: https://tutallerenlinea.com/icb/swagger.php\n";
