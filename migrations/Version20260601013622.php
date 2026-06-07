<?php

declare(strict_types=1);

namespace ICB\Migrations;

/*
 * =========================================================================
 * MIGRATION: Version inicial del esquema
 * =========================================================================
 *
 * Crea todas las tablas del sistema de Credenciales Digitales ICB.
 * Esta migration fue GENERADA automáticamente desde las entidades PHP
 * usando el comando:
 *
 *   php bin/doctrine.php migrations:diff
 *
 * Luego se editó manualmente para agregar ON UPDATE CASCADE en las FKs
 * (Doctrine ORM no soporta onUpdate en el atributo JoinColumn).
 *
 * TABLAS CREADAS (9):
 *   - usuarios           → Miembros y administradores
 *   - roles              → Roles del sistema (Admin, Usuario)
 *   - usuario_roles      → Relación muchos-a-muchos (tabla pivote)
 *   - credenciales       → Credenciales digitales emitidas
 *   - conversaciones     → Hilos de consulta con administración
 *   - mensajes           → Mensajes individuales en cada conversación
 *   - historial_cambios  → Auditoría de acciones de administradores
 *   - sellos             → Sellos institucionales para credenciales
 *   - doctrine_migration_versions → Control de versions de migrations
 * =========================================================================
 */

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601013622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version inicial: crea todas las tablas del sistema ICB';
    }

    public function up(Schema $schema): void
    {
        // ─── Conversaciones (hilos de consulta) ─────────────────────────
        $this->addSql('CREATE TABLE conversaciones (
            id_conversacion INT AUTO_INCREMENT NOT NULL,
            estado VARCHAR(20) NOT NULL,
            fecha_creacion DATETIME NOT NULL,
            id_usuario INT NOT NULL,
            INDEX idx_conversaciones_usuario (id_usuario),
            PRIMARY KEY (id_conversacion)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Credenciales digitales ─────────────────────────────────────
        $this->addSql('CREATE TABLE credenciales (
            id_credencial INT AUTO_INCREMENT NOT NULL,
            fecha_emision DATE NOT NULL,
            fecha_vencimiento DATE NOT NULL,
            foto VARCHAR(255) DEFAULT NULL,
            codigo_qr VARCHAR(255) DEFAULT NULL,
            fecha_baja DATETIME DEFAULT NULL,
            id_usuario INT NOT NULL,
            UNIQUE INDEX UNIQ_FE0760D07DB6509E (codigo_qr),
            INDEX idx_credenciales_usuario (id_usuario),
            INDEX idx_credenciales_vencimiento (fecha_vencimiento),
            PRIMARY KEY (id_credencial)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Historial de cambios (auditoría) ───────────────────────────
        $this->addSql('CREATE TABLE historial_cambios (
            id_historial INT AUTO_INCREMENT NOT NULL,
            tabla_afectada VARCHAR(100) NOT NULL,
            registro_id INT NOT NULL,
            accion VARCHAR(100) NOT NULL,
            valor_anterior LONGTEXT DEFAULT NULL,
            valor_nuevo LONGTEXT DEFAULT NULL,
            fecha DATETIME NOT NULL,
            id_admin INT NOT NULL,
            INDEX idx_historial_admin (id_admin),
            PRIMARY KEY (id_historial)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Mensajes de conversaciones ─────────────────────────────────
        $this->addSql('CREATE TABLE mensajes (
            id_mensaje INT AUTO_INCREMENT NOT NULL,
            contenido LONGTEXT NOT NULL,
            fecha_envio DATETIME NOT NULL,
            id_conversacion INT NOT NULL,
            id_emisor INT NOT NULL,
            INDEX idx_mensajes_conversacion (id_conversacion),
            INDEX idx_mensajes_emisor (id_emisor),
            PRIMARY KEY (id_mensaje)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Roles ──────────────────────────────────────────────────────
        $this->addSql('CREATE TABLE roles (
            id_rol INT AUTO_INCREMENT NOT NULL,
            nombre VARCHAR(50) NOT NULL,
            UNIQUE INDEX UNIQ_B63E2EC73A909126 (nombre),
            PRIMARY KEY (id_rol)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Sellos institucionales ─────────────────────────────────────
        $this->addSql('CREATE TABLE sellos (
            id_sello INT AUTO_INCREMENT NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            imagen_url VARCHAR(255) NOT NULL,
            activo TINYINT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id_sello)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Usuarios ───────────────────────────────────────────────────
        $this->addSql('CREATE TABLE usuarios (
            id_usuario INT AUTO_INCREMENT NOT NULL,
            dni VARCHAR(20) NOT NULL,
            usuario VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            apellido VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            telefono VARCHAR(30) DEFAULT NULL,
            direccion VARCHAR(255) DEFAULT NULL,
            funcion VARCHAR(100) DEFAULT NULL,
            estado VARCHAR(20) NOT NULL,
            fecha_alta DATETIME NOT NULL,
            refresh_token VARCHAR(512) DEFAULT NULL,
            refresh_token_expira DATETIME DEFAULT NULL,
            reset_token VARCHAR(255) DEFAULT NULL,
            reset_token_expira DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_EF687F27F8F253B (dni),
            UNIQUE INDEX UNIQ_EF687F22265B05D (usuario),
            UNIQUE INDEX UNIQ_EF687F2E7927C74 (email),
            PRIMARY KEY (id_usuario)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Relación usuarios ↔ roles (tabla pivote) ───────────────────
        $this->addSql('CREATE TABLE usuario_roles (
            id_usuario INT NOT NULL,
            id_rol INT NOT NULL,
            INDEX IDX_ABE044D9FCF8192D (id_usuario),
            INDEX IDX_ABE044D990F1D76D (id_rol),
            PRIMARY KEY (id_usuario, id_rol)
        ) DEFAULT CHARACTER SET utf8mb4');

        // ─── Foreign Keys ───────────────────────────────────────────────
        // Todas las FKs usan ON DELETE RESTRICT (no se puede eliminar
        // un registro si hay dependencias) y ON UPDATE CASCADE
        // (si cambia el PK, se actualiza automáticamente en las tablas hijas).
        // Estas restricciones mantienen la integridad referencial de los datos.
        $this->addSql('ALTER TABLE conversaciones ADD CONSTRAINT FK_D33DD86EFCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE credenciales ADD CONSTRAINT FK_FE0760D0FCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE historial_cambios ADD CONSTRAINT FK_7BC90364668B4C46 FOREIGN KEY (id_admin) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT FK_6C929C8064813CBD FOREIGN KEY (id_conversacion) REFERENCES conversaciones (id_conversacion) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT FK_6C929C80E29930A3 FOREIGN KEY (id_emisor) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT FK_ABE044D9FCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT FK_ABE044D990F1D76D FOREIGN KEY (id_rol) REFERENCES roles (id_rol) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Eliminar FKs antes de dropear tablas (para evitar errores de foreign key)
        $this->addSql('ALTER TABLE conversaciones DROP FOREIGN KEY FK_D33DD86EFCF8192D');
        $this->addSql('ALTER TABLE credenciales DROP FOREIGN KEY FK_FE0760D0FCF8192D');
        $this->addSql('ALTER TABLE historial_cambios DROP FOREIGN KEY FK_7BC90364668B4C46');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY FK_6C929C8064813CBD');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY FK_6C929C80E29930A3');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY FK_ABE044D9FCF8192D');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY FK_ABE044D990F1D76D');

        // Eliminar tablas en orden inverso (primero las hijas, después las padres)
        $this->addSql('DROP TABLE conversaciones');
        $this->addSql('DROP TABLE credenciales');
        $this->addSql('DROP TABLE historial_cambios');
        $this->addSql('DROP TABLE mensajes');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE sellos');
        $this->addSql('DROP TABLE usuarios');
        $this->addSql('DROP TABLE usuario_roles');
    }
}
