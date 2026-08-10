<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808141415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fournisseur (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codefournisseur VARCHAR(100) DEFAULT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) DEFAULT NULL, cle VARCHAR(255) NOT NULL, contact1 VARCHAR(30) DEFAULT NULL, contact2 VARCHAR(30) DEFAULT NULL, reseau VARCHAR(255) DEFAULT NULL, prixspeciale INT DEFAULT NULL, prixspeciale_poste INT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, site_id INT NOT NULL, INDEX idx_fournisseur_site (site_id), UNIQUE INDEX uniq_fournisseur_site_cle (site_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE produit (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, codeproduit VARCHAR(100) DEFAULT NULL, libelle VARCHAR(255) NOT NULL, cle VARCHAR(255) NOT NULL, prix INT DEFAULT 0 NOT NULL, prix_poste INT DEFAULT NULL, actif TINYINT DEFAULT 1 NOT NULL, site_id INT NOT NULL, INDEX idx_produit_site (site_id), UNIQUE INDEX uniq_produit_site_cle (site_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32F6BD1646');
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27F6BD1646');
        $this->addSql('DROP TABLE fournisseur');
        $this->addSql('DROP TABLE produit');
    }
}
