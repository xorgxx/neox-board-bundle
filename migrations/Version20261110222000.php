<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store per-dashboard widget activation settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board ADD widget_settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board DROP widget_settings');
    }
}
