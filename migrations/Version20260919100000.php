<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add login, make email nullable, reorder columns for login-by-username';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD login VARCHAR(180) NOT NULL AFTER id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649AA08CB10 ON `user` (login)');
        $this->addSql('UPDATE `user` SET login = email');
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` MODIFY name VARCHAR(255) DEFAULT NULL AFTER email');
        $this->addSql('ALTER TABLE `user` MODIFY surname VARCHAR(255) DEFAULT NULL AFTER name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP INDEX UNIQ_8D93D649AA08CB10');
        $this->addSql('ALTER TABLE `user` DROP login');
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE `user` MODIFY name VARCHAR(255) DEFAULT NULL AFTER created_at');
        $this->addSql('ALTER TABLE `user` MODIFY surname VARCHAR(255) DEFAULT NULL AFTER name');
    }
}
