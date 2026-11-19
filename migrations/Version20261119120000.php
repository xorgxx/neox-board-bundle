<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store resized favicon thumbnails on dashboard links.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_link ADD favicon_data TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE neox_board_link DROP favicon_filename');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE neox_board_link ADD favicon_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE neox_board_link DROP favicon_data');
    }
}
