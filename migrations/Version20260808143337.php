<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808143337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE operation (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codesecret VARCHAR(255) NOT NULL, ref_desktop VARCHAR(100) NOT NULL, codepesee VARCHAR(100) NOT NULL, numticket VARCHAR(100) DEFAULT NULL, mouvement VARCHAR(180) DEFAULT NULL, client VARCHAR(180) DEFAULT NULL, destination VARCHAR(180) DEFAULT NULL, provenance VARCHAR(180) DEFAULT NULL, transporteur VARCHAR(180) DEFAULT NULL, chauffeur VARCHAR(180) DEFAULT NULL, immatriculation VARCHAR(100) DEFAULT NULL, remorque VARCHAR(100) DEFAULT NULL, peseur VARCHAR(180) DEFAULT NULL, contact_pl VARCHAR(180) DEFAULT NULL, conteneur VARCHAR(100) DEFAULT NULL, plomb VARCHAR(100) DEFAULT NULL, poids1 INT DEFAULT NULL, poids2 INT DEFAULT NULL, poidsbrut INT DEFAULT NULL, poidsnet INT DEFAULT NULL, pesee_at1 DATETIME DEFAULT NULL, pesee_at2 DATETIME DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_COURS\' NOT NULL, prixunitaire INT DEFAULT NULL, montantdu INT DEFAULT NULL, montantpaye INT DEFAULT 0 NOT NULL, statut_reglement VARCHAR(255) DEFAULT \'NON_PAYE\' NOT NULL, sync_at DATETIME DEFAULT NULL, site_id INT NOT NULL, produit_id INT DEFAULT NULL, fournisseur_id INT DEFAULT NULL, INDEX IDX_1981A66DF6BD1646 (site_id), INDEX idx_operation_site_pesee2 (site_id, pesee_at2), INDEX idx_operation_site_statut (site_id, statut), INDEX idx_operation_site_reglement (site_id, statut_reglement), INDEX idx_operation_fournisseur (fournisseur_id), INDEX idx_operation_produit (produit_id), UNIQUE INDEX uniq_operation_codesecret (codesecret), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE synchronisation_lot (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, nb_recus INT DEFAULT 0 NOT NULL, nb_crees INT DEFAULT 0 NOT NULL, nb_modifiees INT DEFAULT 0 NOT NULL, nb_erreurs INT DEFAULT 0 NOT NULL, erreurs JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, duree_ms INT DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_8F20B48AF6BD1646 (site_id), INDEX idx_lot_site_date (site_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66DF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66DF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE synchronisation_lot ADD CONSTRAINT FK_8F20B48AF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66DF6BD1646');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66DF347EFB');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66D670C757F');
        $this->addSql('ALTER TABLE synchronisation_lot DROP FOREIGN KEY FK_8F20B48AF6BD1646');
        $this->addSql('DROP TABLE operation');
        $this->addSql('DROP TABLE synchronisation_lot');
    }
}
