<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810151458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demande_solde (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, montant_demande INT NOT NULL, montant_accorde INT DEFAULT NULL, motif LONGTEXT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, traite_at DATETIME DEFAULT NULL, motif_rejet LONGTEXT DEFAULT NULL, site_id INT NOT NULL, demande_par_id INT DEFAULT NULL, traite_par_id INT DEFAULT NULL, INDEX IDX_904F004EF6BD1646 (site_id), INDEX IDX_904F004E4C0C045 (demande_par_id), INDEX IDX_904F004E167FABE8 (traite_par_id), INDEX idx_demande_site_statut (site_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entreprise (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, codeentreprise VARCHAR(20) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, contact1 VARCHAR(30) NOT NULL, contact2 VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, solde BIGINT DEFAULT 0 NOT NULL, statut VARCHAR(255) DEFAULT \'ACTIVE\' NOT NULL, UNIQUE INDEX uniq_entreprise_code (codeentreprise), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fournisseur (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codefournisseur VARCHAR(100) DEFAULT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) DEFAULT NULL, cle VARCHAR(255) NOT NULL, contact1 VARCHAR(30) DEFAULT NULL, contact2 VARCHAR(30) DEFAULT NULL, reseau VARCHAR(255) DEFAULT NULL, prixspeciale INT DEFAULT NULL, prixspeciale_poste INT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, site_id INT NOT NULL, INDEX IDX_369ECA32F6BD1646 (site_id), INDEX idx_fournisseur_site_cle (site_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_caisse (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, compte VARCHAR(255) NOT NULL, sens VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, montant BIGINT NOT NULL, solde_avant BIGINT NOT NULL, solde_apres BIGINT NOT NULL, motif VARCHAR(500) DEFAULT NULL, entreprise_id INT NOT NULL, site_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, demande_solde_id INT DEFAULT NULL, effectue_par_id INT DEFAULT NULL, INDEX IDX_C8E3DDFEA4AEAFEA (entreprise_id), INDEX IDX_C8E3DDFEF6BD1646 (site_id), INDEX IDX_C8E3DDFE2A4C4478 (paiement_id), INDEX IDX_C8E3DDFEFA26396B (demande_solde_id), INDEX IDX_C8E3DDFEFAE26142 (effectue_par_id), INDEX idx_mouvement_entreprise_date (entreprise_id, created_at), INDEX idx_mouvement_site_date (site_id, created_at), INDEX idx_mouvement_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE operation (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codesecret VARCHAR(255) NOT NULL, ref_desktop VARCHAR(100) NOT NULL, codepesee VARCHAR(100) NOT NULL, numticket VARCHAR(100) DEFAULT NULL, mouvement VARCHAR(180) DEFAULT NULL, client VARCHAR(180) DEFAULT NULL, destination VARCHAR(180) DEFAULT NULL, provenance VARCHAR(180) DEFAULT NULL, transporteur VARCHAR(180) DEFAULT NULL, chauffeur VARCHAR(180) DEFAULT NULL, immatriculation VARCHAR(100) DEFAULT NULL, remorque VARCHAR(100) DEFAULT NULL, peseur VARCHAR(180) DEFAULT NULL, contact_pl VARCHAR(180) DEFAULT NULL, conteneur VARCHAR(100) DEFAULT NULL, plomb VARCHAR(100) DEFAULT NULL, poids1 INT DEFAULT NULL, poids2 INT DEFAULT NULL, poidsbrut INT DEFAULT NULL, poidsnet INT DEFAULT NULL, pesee_at1 DATETIME DEFAULT NULL, pesee_at2 DATETIME DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_COURS\' NOT NULL, prixunitaire INT DEFAULT NULL, montantdu INT DEFAULT NULL, montantpaye INT DEFAULT 0 NOT NULL, statut_reglement VARCHAR(255) DEFAULT \'NON_PAYE\' NOT NULL, sync_at DATETIME DEFAULT NULL, site_id INT NOT NULL, produit_id INT DEFAULT NULL, fournisseur_id INT DEFAULT NULL, INDEX IDX_1981A66DF6BD1646 (site_id), INDEX idx_operation_site_pesee2 (site_id, pesee_at2), INDEX idx_operation_site_statut (site_id, statut), INDEX idx_operation_site_reglement (site_id, statut_reglement), INDEX idx_operation_fournisseur (fournisseur_id), INDEX idx_operation_produit (produit_id), UNIQUE INDEX uniq_operation_codesecret (codesecret), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paiement (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(40) NOT NULL, montant INT NOT NULL, numero_destinataire VARCHAR(30) NOT NULL, reseau VARCHAR(255) DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, gateway VARCHAR(50) NOT NULL, gateway_transaction_id VARCHAR(255) DEFAULT NULL, gateway_payload JSON DEFAULT NULL, message_erreur VARCHAR(500) DEFAULT NULL, confirme_at DATETIME DEFAULT NULL, operation_id INT NOT NULL, fournisseur_id INT NOT NULL, site_id INT NOT NULL, initie_par_id INT DEFAULT NULL, INDEX IDX_B1DC7A1EF6BD1646 (site_id), INDEX IDX_B1DC7A1ED95A830A (initie_par_id), INDEX idx_paiement_site_statut (site_id, statut), INDEX idx_paiement_operation (operation_id), INDEX idx_paiement_fournisseur (fournisseur_id), UNIQUE INDEX uniq_paiement_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE password_reset_token (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_6B7BA4B6A76ED395 (user_id), INDEX idx_reset_token (token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE produit (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codeproduit VARCHAR(100) DEFAULT NULL, libelle VARCHAR(255) NOT NULL, cle VARCHAR(255) NOT NULL, prix INT DEFAULT 0 NOT NULL, prix_poste INT DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, site_id INT NOT NULL, INDEX IDX_29A5EC27F6BD1646 (site_id), INDEX idx_produit_site_cle (site_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, family VARCHAR(32) DEFAULT NULL, family_valid DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, libellesite VARCHAR(255) NOT NULL, localite VARCHAR(255) DEFAULT NULL, solde BIGINT DEFAULT 0 NOT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, entreprise_id INT NOT NULL, operateur_id INT DEFAULT NULL, INDEX IDX_694309E43F192FC (operateur_id), INDEX idx_site_entreprise (entreprise_id), UNIQUE INDEX uniq_site_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE synchronisation_lot (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, nb_recus INT DEFAULT 0 NOT NULL, nb_crees INT DEFAULT 0 NOT NULL, nb_modifiees INT DEFAULT 0 NOT NULL, nb_erreurs INT DEFAULT 0 NOT NULL, erreurs JSON DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, duree_ms INT DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_8F20B48AF6BD1646 (site_id), INDEX idx_lot_site_date (site_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, fondateur TINYINT DEFAULT 0 NOT NULL, entreprise_id INT DEFAULT NULL, site_machine_id INT DEFAULT NULL, INDEX IDX_8D93D649A4AEAFEA (entreprise_id), UNIQUE INDEX UNIQ_8D93D649D6BED4E3 (site_machine_id), UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004EF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004E4C0C045 FOREIGN KEY (demande_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004E167FABE8 FOREIGN KEY (traite_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFE2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id)');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFA26396B FOREIGN KEY (demande_solde_id) REFERENCES demande_solde (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFAE26142 FOREIGN KEY (effectue_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66DF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66DF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT FK_1981A66D670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E44AC3583 FOREIGN KEY (operation_id) REFERENCES operation (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1ED95A830A FOREIGN KEY (initie_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E4A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E43F192FC FOREIGN KEY (operateur_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE synchronisation_lot ADD CONSTRAINT FK_8F20B48AF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649D6BED4E3 FOREIGN KEY (site_machine_id) REFERENCES site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004EF6BD1646');
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004E4C0C045');
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004E167FABE8');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32F6BD1646');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEA4AEAFEA');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEF6BD1646');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFE2A4C4478');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFA26396B');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFAE26142');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66DF6BD1646');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66DF347EFB');
        $this->addSql('ALTER TABLE operation DROP FOREIGN KEY FK_1981A66D670C757F');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E44AC3583');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E670C757F');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EF6BD1646');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1ED95A830A');
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY FK_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27F6BD1646');
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E4A4AEAFEA');
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E43F192FC');
        $this->addSql('ALTER TABLE synchronisation_lot DROP FOREIGN KEY FK_8F20B48AF6BD1646');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649A4AEAFEA');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649D6BED4E3');
        $this->addSql('DROP TABLE demande_solde');
        $this->addSql('DROP TABLE entreprise');
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('DROP TABLE mouvement_caisse');
        $this->addSql('DROP TABLE operation');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE produit');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE site');
        $this->addSql('DROP TABLE synchronisation_lot');
        $this->addSql('DROP TABLE user');
    }
}
