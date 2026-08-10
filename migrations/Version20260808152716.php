<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808152716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE paiement (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(40) NOT NULL, montant INT NOT NULL, numero_destinataire VARCHAR(30) NOT NULL, reseau VARCHAR(255) DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, gateway VARCHAR(50) NOT NULL, gateway_transaction_id VARCHAR(255) DEFAULT NULL, gateway_payload JSON DEFAULT NULL, message_erreur VARCHAR(500) DEFAULT NULL, confirme_at DATETIME DEFAULT NULL, operation_id INT NOT NULL, fournisseur_id INT NOT NULL, site_id INT NOT NULL, initie_par_id INT DEFAULT NULL, INDEX IDX_B1DC7A1EF6BD1646 (site_id), INDEX IDX_B1DC7A1ED95A830A (initie_par_id), INDEX idx_paiement_site_statut (site_id, statut), INDEX idx_paiement_operation (operation_id), INDEX idx_paiement_fournisseur (fournisseur_id), UNIQUE INDEX uniq_paiement_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E44AC3583 FOREIGN KEY (operation_id) REFERENCES operation (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1ED95A830A FOREIGN KEY (initie_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_caisse ADD paiement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFE2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id)');
        $this->addSql('CREATE INDEX IDX_C8E3DDFE2A4C4478 ON mouvement_caisse (paiement_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E44AC3583');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E670C757F');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EF6BD1646');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1ED95A830A');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFE2A4C4478');
        $this->addSql('DROP INDEX IDX_C8E3DDFE2A4C4478 ON mouvement_caisse');
        $this->addSql('ALTER TABLE mouvement_caisse DROP paiement_id');
    }
}
