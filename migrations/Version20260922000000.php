<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UNP, free-text coursesAttended, created_by FK to organization; reorder columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization CHANGE COLUMN has_used_services courses_attended VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD unp VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD created_by BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_ORG_CREATED_BY FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE organization MODIFY created_at DATETIME NOT NULL AFTER created_by');
        $this->addSql('ALTER TABLE organization MODIFY updated_at DATETIME NOT NULL AFTER created_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP FOREIGN KEY FK_ORG_CREATED_BY');
        $this->addSql('ALTER TABLE organization DROP created_by, DROP unp');
        $this->addSql('ALTER TABLE organization CHANGE COLUMN courses_attended has_used_services TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE organization MODIFY updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE organization MODIFY created_at DATETIME NOT NULL');
    }
}
