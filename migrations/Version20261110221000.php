<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Match the space column index type to the Doctrine integer mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_space ALTER column_index TYPE INT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_space ALTER column_index TYPE SMALLINT');
    }
}
