<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110225000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the last availability check result for each dashboard link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_link ADD COLUMN IF NOT EXISTS availability_status BOOLEAN DEFAULT NULL, ADD COLUMN IF NOT EXISTS availability_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_link DROP COLUMN IF EXISTS availability_status, DROP COLUMN IF EXISTS availability_checked_at');
    }
}
