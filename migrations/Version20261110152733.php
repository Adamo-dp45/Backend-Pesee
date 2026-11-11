<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - La mise en compte : l'épargne d'un planteur, ce qu'on lui retient sur chaque pesée, et le livre
      qui tient la chaîne des soldes.
*/
final class Version20261110152733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mise en compte : le compte du planteur, son livre et la retenue sur les pesées';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE compte_epargne (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, solde BIGINT DEFAULT 0 NOT NULL, fournisseur_id INT NOT NULL, entreprise_id INT NOT NULL, site_id INT NOT NULL, INDEX IDX_19FDB51AA4AEAFEA (entreprise_id), INDEX IDX_19FDB51AF6BD1646 (site_id), UNIQUE INDEX uniq_compte_epargne_fournisseur (fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_epargne (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) NOT NULL, sens VARCHAR(255) NOT NULL, montant INT NOT NULL, solde_avant INT NOT NULL, solde_apres INT NOT NULL, motif VARCHAR(255) DEFAULT NULL, compte_id INT NOT NULL, operation_id INT DEFAULT NULL, entreprise_id INT NOT NULL, site_id INT NOT NULL, effectue_par_id INT DEFAULT NULL, INDEX IDX_6E5A5C5BF2C56620 (compte_id), INDEX IDX_6E5A5C5BA4AEAFEA (entreprise_id), INDEX IDX_6E5A5C5BF6BD1646 (site_id), INDEX IDX_6E5A5C5BFAE26142 (effectue_par_id), INDEX idx_mouvement_epargne_compte_date (compte_id, created_at), UNIQUE INDEX uniq_mouvement_epargne_operation (operation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compte_epargne ADD CONSTRAINT FK_19FDB51A670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE compte_epargne ADD CONSTRAINT FK_19FDB51AA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE compte_epargne ADD CONSTRAINT FK_19FDB51AF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE mouvement_epargne ADD CONSTRAINT FK_6E5A5C5BF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_epargne (id)');
        $this->addSql('ALTER TABLE mouvement_epargne ADD CONSTRAINT FK_6E5A5C5B44AC3583 FOREIGN KEY (operation_id) REFERENCES operation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_epargne ADD CONSTRAINT FK_6E5A5C5BA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE mouvement_epargne ADD CONSTRAINT FK_6E5A5C5BF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE mouvement_epargne ADD CONSTRAINT FK_6E5A5C5BFAE26142 FOREIGN KEY (effectue_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fournisseur ADD taux_epargne INT DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD taux_retenue INT DEFAULT NULL, ADD retenue INT DEFAULT NULL');

        /*
            - REPRISE, et ce n'est pas un détail de confort : toutes les pesées déjà en base sont
              arbitrées à zéro. 'NULL' veut dire « jamais arbitrée », et une pesée laissée à NULL
              serait reprise par le premier prélèvement ouvert ensuite — alors que la règle est que
              seules les pesées À VENIR sont prélevées. Une ligne de SQL remplace ici toute
              comparaison de dates dans le code.
        */
        $this->addSql('UPDATE operation SET retenue = 0 WHERE retenue IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE compte_epargne DROP FOREIGN KEY FK_19FDB51A670C757F');
        $this->addSql('ALTER TABLE compte_epargne DROP FOREIGN KEY FK_19FDB51AA4AEAFEA');
        $this->addSql('ALTER TABLE compte_epargne DROP FOREIGN KEY FK_19FDB51AF6BD1646');
        $this->addSql('ALTER TABLE mouvement_epargne DROP FOREIGN KEY FK_6E5A5C5BF2C56620');
        $this->addSql('ALTER TABLE mouvement_epargne DROP FOREIGN KEY FK_6E5A5C5B44AC3583');
        $this->addSql('ALTER TABLE mouvement_epargne DROP FOREIGN KEY FK_6E5A5C5BA4AEAFEA');
        $this->addSql('ALTER TABLE mouvement_epargne DROP FOREIGN KEY FK_6E5A5C5BF6BD1646');
        $this->addSql('ALTER TABLE mouvement_epargne DROP FOREIGN KEY FK_6E5A5C5BFAE26142');
        $this->addSql('DROP TABLE compte_epargne');
        $this->addSql('DROP TABLE mouvement_epargne');
        $this->addSql('ALTER TABLE fournisseur DROP taux_epargne');
        $this->addSql('ALTER TABLE operation DROP taux_retenue, DROP retenue');
    }
}
