<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore the legacy favicon filename column for rolling-deploy compatibility.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_link ADD COLUMN IF NOT EXISTS favicon_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Keep the compatibility column so a rollback to an older app release still works.
    }
}
