<?php

declare(strict_types=1);

namespace ICB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604011946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE recuperacion_tokens (id_token INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, usado TINYINT NOT NULL, created_at DATETIME NOT NULL, id_usuario INT NOT NULL, INDEX IDX_5B047DD6FCF8192D (id_usuario), PRIMARY KEY (id_token)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE recuperacion_tokens ADD CONSTRAINT FK_5B047DD6FCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversaciones DROP FOREIGN KEY `FK_D33DD86EFCF8192D`');
        $this->addSql('ALTER TABLE conversaciones ADD CONSTRAINT FK_D33DD86EFCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE credenciales DROP FOREIGN KEY `FK_FE0760D0FCF8192D`');
        $this->addSql('ALTER TABLE credenciales ADD CONSTRAINT FK_FE0760D0FCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE historial_cambios DROP FOREIGN KEY `FK_7BC90364668B4C46`');
        $this->addSql('ALTER TABLE historial_cambios ADD CONSTRAINT FK_7BC90364668B4C46 FOREIGN KEY (id_admin) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY `FK_6C929C8064813CBD`');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY `FK_6C929C80E29930A3`');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT FK_6C929C8064813CBD FOREIGN KEY (id_conversacion) REFERENCES conversaciones (id_conversacion) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT FK_6C929C80E29930A3 FOREIGN KEY (id_emisor) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY `FK_ABE044D990F1D76D`');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY `FK_ABE044D9FCF8192D`');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT FK_ABE044D990F1D76D FOREIGN KEY (id_rol) REFERENCES roles (id_rol) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT FK_ABE044D9FCF8192D FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE recuperacion_tokens DROP FOREIGN KEY FK_5B047DD6FCF8192D');
        $this->addSql('DROP TABLE recuperacion_tokens');
        $this->addSql('ALTER TABLE conversaciones DROP FOREIGN KEY FK_D33DD86EFCF8192D');
        $this->addSql('ALTER TABLE conversaciones ADD CONSTRAINT `FK_D33DD86EFCF8192D` FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE credenciales DROP FOREIGN KEY FK_FE0760D0FCF8192D');
        $this->addSql('ALTER TABLE credenciales ADD CONSTRAINT `FK_FE0760D0FCF8192D` FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE historial_cambios DROP FOREIGN KEY FK_7BC90364668B4C46');
        $this->addSql('ALTER TABLE historial_cambios ADD CONSTRAINT `FK_7BC90364668B4C46` FOREIGN KEY (id_admin) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY FK_6C929C8064813CBD');
        $this->addSql('ALTER TABLE mensajes DROP FOREIGN KEY FK_6C929C80E29930A3');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT `FK_6C929C8064813CBD` FOREIGN KEY (id_conversacion) REFERENCES conversaciones (id_conversacion) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE mensajes ADD CONSTRAINT `FK_6C929C80E29930A3` FOREIGN KEY (id_emisor) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY FK_ABE044D9FCF8192D');
        $this->addSql('ALTER TABLE usuario_roles DROP FOREIGN KEY FK_ABE044D990F1D76D');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT `FK_ABE044D9FCF8192D` FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON UPDATE CASCADE');
        $this->addSql('ALTER TABLE usuario_roles ADD CONSTRAINT `FK_ABE044D990F1D76D` FOREIGN KEY (id_rol) REFERENCES roles (id_rol) ON UPDATE CASCADE');
    }
}
