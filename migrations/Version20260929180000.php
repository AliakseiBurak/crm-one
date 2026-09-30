<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR-0015: справочные поля сайта и города организации; таблица прогонов
 * импорта организаций (design D2).
 *
 * Колонка `source_format` создаётся уже сейчас со значением по умолчанию
 * `csv`, чтобы второму формату источника (change
 * `add-organizations-json-import`) миграция не понадобилась.
 */
final class Version20260929180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add website and city to organization; add import_run table for the organizations import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD website VARCHAR(255) DEFAULT NULL AFTER annual_plan');
        $this->addSql('ALTER TABLE organization ADD city VARCHAR(255) DEFAULT NULL AFTER website');
        $this->addSql('CREATE TABLE import_run (id BIGINT AUTO_INCREMENT NOT NULL, filename VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, source_format VARCHAR(32) DEFAULT \'csv\' NOT NULL, total_rows INT NOT NULL, processed_rows INT NOT NULL, created_at DATETIME NOT NULL, last_processed_at DATETIME DEFAULT NULL, created_by BIGINT DEFAULT NULL, INDEX IDX_IMPORT_RUN_CREATED_BY (created_by), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE import_run ADD CONSTRAINT FK_IMPORT_RUN_CREATED_BY FOREIGN KEY (created_by) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run DROP FOREIGN KEY FK_IMPORT_RUN_CREATED_BY');
        $this->addSql('DROP TABLE import_run');
        $this->addSql('ALTER TABLE organization DROP city, DROP website');
    }
}
