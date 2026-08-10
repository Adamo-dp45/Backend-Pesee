<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808025439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mouvement_caisse (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, compte VARCHAR(255) NOT NULL, sens VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, montant BIGINT NOT NULL, solde_avant BIGINT NOT NULL, solde_apres BIGINT NOT NULL, motif VARCHAR(500) DEFAULT NULL, entreprise_id INT NOT NULL, site_id INT DEFAULT NULL, effectue_par_id INT DEFAULT NULL, INDEX IDX_C8E3DDFEA4AEAFEA (entreprise_id), INDEX IDX_C8E3DDFEF6BD1646 (site_id), INDEX IDX_C8E3DDFEFAE26142 (effectue_par_id), INDEX idx_mouvement_entreprise_date (entreprise_id, created_at), INDEX idx_mouvement_site_date (site_id, created_at), INDEX idx_mouvement_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFAE26142 FOREIGN KEY (effectue_par_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEA4AEAFEA');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEF6BD1646');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFAE26142');
        $this->addSql('DROP TABLE mouvement_caisse');
    }
}
