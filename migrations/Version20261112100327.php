<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - La mise en compte devient une affaire de PRODUIT. Un planteur n'a plus un taux unique : il a
      autant de prélèvements que de cultures concernées — 20 F par kilo de cacao, 50 F par kilo
      d'hévéa — chacun ouvert et arrêté séparément, et chacun borné par le prix de son produit.

    - 'fournisseur.taux_epargne' disparaît au profit de la table 'prelevement', et
      'demande_prelevement' gagne son produit.

    - REPRISE IMPOSSIBLE, et la migration s'arrête plutôt que d'inventer : un taux unique ne dit pas
      sur quelle culture il portait, et le choisir à la place du patron ouvrirait un prélèvement que
      personne n'a décidé. L'abandon est donc volontairement bruyant, avec la marche à suivre.
*/
final class Version20261112100327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mise en compte par produit : table prelevement, produit sur la demande';
    }

    public function up(Schema $schema): void
    {
        $this->reprisesImpossibles();

        $this->addSql('CREATE TABLE prelevement (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, taux INT NOT NULL, arrete_at DATETIME DEFAULT NULL, fournisseur_id INT NOT NULL, produit_id INT NOT NULL, arrete_par_id INT DEFAULT NULL, INDEX IDX_88C8671F670C757F (fournisseur_id), INDEX IDX_88C8671FF347EFB (produit_id), INDEX IDX_88C8671F927FFC65 (arrete_par_id), INDEX idx_prelevement_fournisseur_produit (fournisseur_id, produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prelevement ADD CONSTRAINT FK_88C8671F670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE prelevement ADD CONSTRAINT FK_88C8671FF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE prelevement ADD CONSTRAINT FK_88C8671F927FFC65 FOREIGN KEY (arrete_par_id) REFERENCES user (id) ON DELETE SET NULL');

        /*
            - Pas d'index UNIQUE sur (fournisseur, produit) : la table garde les prélèvements
              ARRÊTÉS, qui sont l'historique. L'unicité porte sur les seuls actifs, ce qu'un index ne
              sait pas exprimer — elle est tenue par les processors, comme l'unicité des libellés
              l'est par un validateur qui ignore la corbeille.
        */
        $this->addSql('DROP INDEX idx_demande_prelevement_statut ON demande_prelevement');
        $this->addSql('ALTER TABLE demande_prelevement ADD produit_id INT NOT NULL');
        $this->addSql('ALTER TABLE demande_prelevement ADD CONSTRAINT FK_29EF386BF347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('CREATE INDEX IDX_29EF386BF347EFB ON demande_prelevement (produit_id)');
        $this->addSql('CREATE INDEX idx_demande_prelevement_statut ON demande_prelevement (fournisseur_id, produit_id, statut)');

        $this->addSql('ALTER TABLE fournisseur DROP taux_epargne');
    }

    /*
        - S'arrête AVANT la première écriture si des données existantes ne peuvent pas être reprises.
          Un prélèvement ouvert sans produit et une demande en attente sans produit sont tous deux
          intraduisibles : il faut les rouvrir à la main, culture par culture, après la migration.

        - Les demandes déjà traitées et les planteurs sans prélèvement ne gênent pas : les premières
          recevront un produit nul qu'aucun écran ne lit, les seconds n'avaient rien à reprendre.
          Seules les lignes VIVANTES bloquent.
    */
    private function reprisesImpossibles(): void
    {
        $prelevements = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM fournisseur WHERE taux_epargne IS NOT NULL AND deleted_at IS NULL'
        );

        $demandes = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM demande_prelevement WHERE statut = 'EN_ATTENTE' AND deleted_at IS NULL"
        );

        $this->abortIf(
            $prelevements > 0 || $demandes > 0,
            sprintf(
                'Migration interrompue : %d prélèvement(s) en cours et %d demande(s) en attente ne visent aucun produit, '
                . 'et rien ne permet de deviner lequel. Notez-les, annulez les demandes, arrêtez les prélèvements '
                . '(UPDATE fournisseur SET taux_epargne = NULL), relancez la migration, puis rouvrez-les culture par culture.',
                $prelevements,
                $demandes
            )
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prelevement DROP FOREIGN KEY FK_88C8671F670C757F');
        $this->addSql('ALTER TABLE prelevement DROP FOREIGN KEY FK_88C8671FF347EFB');
        $this->addSql('ALTER TABLE prelevement DROP FOREIGN KEY FK_88C8671F927FFC65');
        $this->addSql('DROP TABLE prelevement');

        $this->addSql('ALTER TABLE demande_prelevement DROP FOREIGN KEY FK_29EF386BF347EFB');
        $this->addSql('DROP INDEX IDX_29EF386BF347EFB ON demande_prelevement');
        $this->addSql('DROP INDEX idx_demande_prelevement_statut ON demande_prelevement');
        $this->addSql('ALTER TABLE demande_prelevement DROP produit_id');
        $this->addSql('CREATE INDEX idx_demande_prelevement_statut ON demande_prelevement (fournisseur_id, statut)');

        $this->addSql('ALTER TABLE fournisseur ADD taux_epargne INT DEFAULT NULL');
    }
}
