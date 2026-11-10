<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the dashboard column assigned to each space.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_space ADD column_index SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE neox_board_space SET column_index = MOD(position, 2)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_space DROP column_index');
    }
}
