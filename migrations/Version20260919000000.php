<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add isMain to Contact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD is_main TINYINT(1) NOT NULL DEFAULT 0 AFTER position');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP is_main');
    }
}
