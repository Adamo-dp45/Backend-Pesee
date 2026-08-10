<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808150840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fournisseur DROP INDEX uniq_fournisseur_site_cle, ADD INDEX idx_fournisseur_site_cle (site_id, cle)');
        $this->addSql('ALTER TABLE fournisseur DROP prixspeciale_poste');
        $this->addSql('ALTER TABLE fournisseur RENAME INDEX idx_fournisseur_site TO IDX_369ECA32F6BD1646');
        $this->addSql('ALTER TABLE produit DROP INDEX uniq_produit_site_cle, ADD INDEX idx_produit_site_cle (site_id, cle)');
        $this->addSql('ALTER TABLE produit DROP prix_poste');
        $this->addSql('ALTER TABLE produit RENAME INDEX idx_produit_site TO IDX_29A5EC27F6BD1646');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fournisseur DROP INDEX idx_fournisseur_site_cle, ADD UNIQUE INDEX uniq_fournisseur_site_cle (site_id, cle)');
        $this->addSql('ALTER TABLE fournisseur ADD prixspeciale_poste INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur RENAME INDEX idx_369eca32f6bd1646 TO idx_fournisseur_site');
        $this->addSql('ALTER TABLE produit DROP INDEX idx_produit_site_cle, ADD UNIQUE INDEX uniq_produit_site_cle (site_id, cle)');
        $this->addSql('ALTER TABLE produit ADD prix_poste INT DEFAULT NULL');
        $this->addSql('ALTER TABLE produit RENAME INDEX idx_29a5ec27f6bd1646 TO idx_produit_site');
    }
}
