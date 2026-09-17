<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add annual_plan, description, has_used_services to organization; make industry nullable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD annual_plan VARCHAR(255) DEFAULT NULL, ADD description LONGTEXT DEFAULT NULL, ADD has_used_services TINYINT(1) DEFAULT 0 NOT NULL, MODIFY industry VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP annual_plan, DROP description, DROP has_used_services, MODIFY industry VARCHAR(255) NOT NULL');
    }
}
