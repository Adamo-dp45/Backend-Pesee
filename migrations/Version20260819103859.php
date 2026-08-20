<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819103859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activite (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, libelle VARCHAR(500) NOT NULL, cible_type VARCHAR(50) DEFAULT NULL, cible_id INT DEFAULT NULL, auteur_id INT DEFAULT NULL, entreprise_id INT NOT NULL, INDEX IDX_B875551560BB6FE6 (auteur_id), INDEX IDX_B8755515A4AEAFEA (entreprise_id), INDEX idx_activite_entreprise_date (entreprise_id, created_at), INDEX idx_activite_type (type), INDEX idx_activite_cible (cible_type, cible_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activite ADD CONSTRAINT FK_B875551560BB6FE6 FOREIGN KEY (auteur_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activite ADD CONSTRAINT FK_B8755515A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activite DROP FOREIGN KEY FK_B875551560BB6FE6');
        $this->addSql('ALTER TABLE activite DROP FOREIGN KEY FK_B8755515A4AEAFEA');
        $this->addSql('DROP TABLE activite');
    }
}
