<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809002021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demande_solde (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, montant_demande INT NOT NULL, montant_accorde INT DEFAULT NULL, motif LONGTEXT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, traite_at DATETIME DEFAULT NULL, motif_rejet LONGTEXT DEFAULT NULL, site_id INT NOT NULL, demande_par_id INT DEFAULT NULL, traite_par_id INT DEFAULT NULL, INDEX IDX_904F004EF6BD1646 (site_id), INDEX IDX_904F004E4C0C045 (demande_par_id), INDEX IDX_904F004E167FABE8 (traite_par_id), INDEX idx_demande_site_statut (site_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004EF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004E4C0C045 FOREIGN KEY (demande_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_solde ADD CONSTRAINT FK_904F004E167FABE8 FOREIGN KEY (traite_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_caisse ADD demande_solde_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mouvement_caisse ADD CONSTRAINT FK_C8E3DDFEFA26396B FOREIGN KEY (demande_solde_id) REFERENCES demande_solde (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C8E3DDFEFA26396B ON mouvement_caisse (demande_solde_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004EF6BD1646');
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004E4C0C045');
        $this->addSql('ALTER TABLE demande_solde DROP FOREIGN KEY FK_904F004E167FABE8');
        $this->addSql('DROP TABLE demande_solde');
        $this->addSql('ALTER TABLE mouvement_caisse DROP FOREIGN KEY FK_C8E3DDFEFA26396B');
        $this->addSql('DROP INDEX IDX_C8E3DDFEFA26396B ON mouvement_caisse');
        $this->addSql('ALTER TABLE mouvement_caisse DROP demande_solde_id');
    }
}
