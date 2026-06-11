<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega columnas refresh_token y refresh_token_expira a usuarios para JWT';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuarios ADD refresh_token VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE usuarios ADD refresh_token_expira DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE usuarios DROP refresh_token');
        $this->addSql('ALTER TABLE usuarios DROP refresh_token_expira');
    }
}
