<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add isRefusal to Call; add isActive, isOptedOut, optOutReason, optedOutAt to Organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `call` ADD is_refusal TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE organization ADD is_active TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE organization ADD is_opted_out TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE organization ADD opt_out_reason LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD opted_out_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `call` DROP is_refusal');
        $this->addSql('ALTER TABLE organization DROP is_active');
        $this->addSql('ALTER TABLE organization DROP is_opted_out');
        $this->addSql('ALTER TABLE organization DROP opt_out_reason');
        $this->addSql('ALTER TABLE organization DROP opted_out_at');
    }
}
