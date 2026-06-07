<?php

declare(strict_types=1);

namespace ICB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
 * =========================================================================
 * MIGRATION: Agrega columna version para optimistic locking
 * =========================================================================
 *
 * Agrega la columna `version` (INT, DEFAULT 1, NOT NULL) a las tablas
 * usuarios, credenciales y sellos para soportar optimistic locking
 * vía el atributo #[ORM\Version] de Doctrine.
 *
 * El optimistic locking previene pérdidas de actualización cuando dos
 * usuarios modifican el mismo registro simultáneamente: Doctrine lanza
 * OptimisticLockException si la versión en DB no coincide con la de la
 * entidad al hacer flush().
 * =========================================================================
 */
final class Version20260607165149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega columna version para optimistic locking en usuarios, credenciales y sellos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credenciales ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE sellos ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE usuarios ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE credenciales DROP version');
        $this->addSql('ALTER TABLE sellos DROP version');
        $this->addSql('ALTER TABLE usuarios DROP version');
    }
}
