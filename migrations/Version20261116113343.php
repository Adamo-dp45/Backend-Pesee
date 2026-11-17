<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - Deux chantiers d'un coup, parce qu'ils touchent la même table.

    - LE RETRAIT DE L'ÉPARGNE. 'demande_retrait' porte le circuit demande → accord → versement, et
      'paiement.operation_id' devient NULLABLE : un retrait est un versement qui ne pointe sur
      aucune pesée. C'est ce qui a évité d'écrire une seconde machinerie de versements pour une
      poignée de retraits.

    - LE RÈGLEMENT PAR CHÈQUE. 'entreprise.solde_bancaire' est la troisième caisse, et elle n'existe
      QU'À CE NIVEAU : un pont bascule ne tient pas de carnet de chèques. 'paiement.numero_cheque'
      est la seule référence qui permettra le rapprochement avec le relevé bancaire.

    - Rien à reprendre : les colonnes naissent à zéro ou à nul, et aucune ligne existante ne change
      de sens. Le 'down()' rend 'operation_id' obligatoire, ce qui échouera si des retraits ont été
      versés entre-temps — c'est voulu, ils n'ont pas de pesée à retrouver.
*/
final class Version20261116113343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retrait de l epargne, et caisse bancaire pour le reglement par cheque';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demande_retrait (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, montant_demande INT NOT NULL, montant_accorde INT DEFAULT NULL, motif LONGTEXT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, traite_at DATETIME DEFAULT NULL, motif_rejet LONGTEXT DEFAULT NULL, fournisseur_id INT NOT NULL, site_id INT NOT NULL, demande_par_id INT DEFAULT NULL, traite_par_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, INDEX IDX_E973D1FE670C757F (fournisseur_id), INDEX IDX_E973D1FEF6BD1646 (site_id), INDEX IDX_E973D1FE4C0C045 (demande_par_id), INDEX IDX_E973D1FE167FABE8 (traite_par_id), UNIQUE INDEX UNIQ_E973D1FE2A4C4478 (paiement_id), INDEX idx_demande_retrait_statut (fournisseur_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_retrait ADD CONSTRAINT FK_E973D1FE670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE demande_retrait ADD CONSTRAINT FK_E973D1FEF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE demande_retrait ADD CONSTRAINT FK_E973D1FE4C0C045 FOREIGN KEY (demande_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_retrait ADD CONSTRAINT FK_E973D1FE167FABE8 FOREIGN KEY (traite_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_retrait ADD CONSTRAINT FK_E973D1FE2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE entreprise ADD solde_bancaire BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE paiement ADD numero_cheque VARCHAR(50) DEFAULT NULL, CHANGE operation_id operation_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_retrait DROP FOREIGN KEY FK_E973D1FE670C757F');
        $this->addSql('ALTER TABLE demande_retrait DROP FOREIGN KEY FK_E973D1FEF6BD1646');
        $this->addSql('ALTER TABLE demande_retrait DROP FOREIGN KEY FK_E973D1FE4C0C045');
        $this->addSql('ALTER TABLE demande_retrait DROP FOREIGN KEY FK_E973D1FE167FABE8');
        $this->addSql('ALTER TABLE demande_retrait DROP FOREIGN KEY FK_E973D1FE2A4C4478');
        $this->addSql('DROP TABLE demande_retrait');
        $this->addSql('ALTER TABLE entreprise DROP solde_bancaire');
        $this->addSql('ALTER TABLE paiement DROP numero_cheque, CHANGE operation_id operation_id INT NOT NULL');
    }
}
