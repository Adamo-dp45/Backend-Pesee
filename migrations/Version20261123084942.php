<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - UNE TRANCHE DEVIENT UN BARÈME. Le mode « par tranches » tenait une suite d'intervalles dans
      une table à part, et allait chercher sur la fiche du planteur une DISTANCE que personne ne
      saisissait : son transport valait alors zéro, en silence. Les bornes montent sur le barème,
      qui devient un forfait borné, et le planteur pointe dessus — il n'y a plus rien à aller
      chercher ailleurs.

    - LE CONVERTIR N'EST PAS LE SUPPRIMER, et c'est tout l'objet de ce fichier. Un « DROP TABLE »
      seul laisserait des barèmes au mode TRANCHES, qui n'existe plus dans l'énumération, et des
      planteurs rattachés à un barème qui ne sait plus rien payer. Chaque intervalle devient donc
      un barème à lui, et chaque planteur est repointé sur CELUI QUI CONTENAIT SA DISTANCE : ce
      qu'il touchait hier, il le touche encore demain.

    - UNE CATÉGORIE N'A PAS DE DISTANCE, elle. Celles qui suivaient un barème par tranches sont
      repointées sur son PREMIER intervalle, le plus court, et il n'y a pas de meilleur choix
      possible : la distance vivait sur les planteurs, pas sur le groupe. C'est le seul endroit où
      cette migration peut changer un montant, et elle le dit plutôt que de le taire.

    - LA COLONNE DE TRAVAIL 'tranche_source_id' ne survit pas à la migration. Elle sert à relier
      chaque barème neuf à l'intervalle dont il sort, le temps des deux 'UPDATE' — un rapprochement
      par montant et par bornes aurait confondu deux intervalles identiques de barèmes différents.
*/
final class Version20261123084942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Les bornes de kilometrage montent sur le bareme, la table tranche et la distance du planteur disparaissent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bareme_transport ADD km_min INT DEFAULT NULL, ADD km_max INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bareme_transport ADD tranche_source_id INT DEFAULT NULL');

        /*
            - UN BARÈME PAR INTERVALLE VIVANT. Le libellé reprend celui du barème d'origine suivi de
              ses bornes : « Par distance — de 20 à 50 km ». La clé de rapprochement suit la même
              règle que 'Cle::depuis()' — minuscules, espaces compactés — sans les accents, que
              cette migration n'a pas les moyens de retirer en SQL. Un doublon de clé ne bloque
              rien : l'unicité est tenue par un validateur, jamais par un index.
        */
        $this->addSql(<<<'SQL'
            INSERT INTO bareme_transport (libelle, cle, mode, prix_au_kilo, montant, km_min, km_max, entreprise_id, tranche_source_id, created_at)
            SELECT
                CONCAT(b.libelle, ' - ', CASE
                    WHEN t.km_max IS NULL THEN CONCAT('au-dela de ', t.km_min, ' km')
                    ELSE CONCAT('de ', t.km_min, ' a ', t.km_max, ' km')
                END),
                LOWER(CONCAT(b.cle, '-', t.km_min, '-', COALESCE(t.km_max, 'plus'))),
                'FORFAIT',
                NULL,
                t.montant,
                t.km_min,
                t.km_max,
                b.entreprise_id,
                t.id,
                NOW()
            FROM tranche t
            INNER JOIN bareme_transport b ON b.id = t.bareme_id
            WHERE b.mode = 'TRANCHES' AND t.deleted_at IS NULL
        SQL);

        /*
            - CHAQUE PLANTEUR SUR L'INTERVALLE QUI CONTENAIT SA DISTANCE. Les bornes sont
              semi-ouvertes — '>= km_min' et '< km_max' — exactement comme l'était 'Tranche::contient()' :
              20 appartenait à « 20 à 50 », et à elle seule.
        */
        $this->addSql(<<<'SQL'
            UPDATE fournisseur f
            INNER JOIN bareme_transport ancien ON ancien.id = f.bareme_transport_id AND ancien.mode = 'TRANCHES'
            INNER JOIN tranche t ON t.bareme_id = ancien.id AND t.deleted_at IS NULL
                AND f.distance_km >= t.km_min AND (t.km_max IS NULL OR f.distance_km < t.km_max)
            INNER JOIN bareme_transport neuf ON neuf.tranche_source_id = t.id
            SET f.bareme_transport_id = neuf.id
            WHERE f.distance_km IS NOT NULL
        SQL);

        /* Faute de distance sur le groupe, la catégorie suit le premier intervalle : le plus court. */
        $this->addSql(<<<'SQL'
            UPDATE categorie_fournisseur c
            INNER JOIN bareme_transport ancien ON ancien.id = c.bareme_transport_id AND ancien.mode = 'TRANCHES'
            INNER JOIN bareme_transport neuf ON neuf.tranche_source_id = (
                SELECT t.id FROM tranche t
                WHERE t.bareme_id = ancien.id AND t.deleted_at IS NULL
                ORDER BY t.km_min ASC LIMIT 1
            )
            SET c.bareme_transport_id = neuf.id
        SQL);

        /*
            - LES ANCIENS BARÈMES PARTENT À LA CORBEILLE, ils ne sont pas détruits : des pesées
              déjà valorisées portent leur nom figé, et le journal des décisions les cite. Leur mode
              passe à 'FORFAIT' pour que l'énumération se relise sans lever, avec un montant nul qui
              ne paiera jamais rien — ils ne sont plus rattachés à personne.
        */
        $this->addSql("UPDATE bareme_transport SET mode = 'FORFAIT', montant = 0, deleted_at = NOW() WHERE mode = 'TRANCHES'");

        /*
            - LES PESÉES DÉJÀ VALORISÉES ONT FIGÉ LE MODE « TRANCHES » à côté de leur montant, et
              l'énumération ne le connaît plus : le relire lève, et c'est TOUTE la file du guichet
              qui tombe en 502 — pas seulement les pesées concernées. Un mode oublié en base n'est
              pas une donnée dormante, c'est une exception à la première lecture.

            - 'FORFAIT' est la relecture fidèle : le montant était fixe pour une bande de kilomètres,
              c'est-à-dire un forfait borné. Les bornes retenues, elles, sont déjà figées en toutes
              lettres dans 'tranche_transport' — la fiche de la pesée se relit sans rien perdre.
        */
        $this->addSql("UPDATE operation SET mode_transport = 'FORFAIT' WHERE mode_transport = 'TRANCHES'");

        $this->addSql('ALTER TABLE bareme_transport DROP tranche_source_id');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY `FK_666758405F49EAAD`');
        $this->addSql('DROP TABLE tranche');
        $this->addSql('ALTER TABLE fournisseur DROP distance_km');
        $this->addSql('ALTER TABLE operation DROP distance_transport');
    }

    /*
        - LA DESCENTE REFAIT LES COLONNES ET LA TABLE, pas leur contenu. Les intervalles sont
          devenus des barèmes à part entière entre-temps, et personne ne saurait dire lesquels
          recoller ensemble ni quelle distance rendre à quel planteur. Le dire ici vaut mieux que
          de laisser croire à un aller-retour sans perte.
    */
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tranche (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, km_min INT NOT NULL, km_max INT DEFAULT NULL, montant INT NOT NULL, bareme_id INT NOT NULL, INDEX IDX_666758405F49EAAD (bareme_id), INDEX idx_tranche_bareme_min (bareme_id, km_min), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT `FK_666758405F49EAAD` FOREIGN KEY (bareme_id) REFERENCES bareme_transport (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE bareme_transport DROP km_min, DROP km_max');
        $this->addSql('ALTER TABLE fournisseur ADD distance_km INT DEFAULT NULL');
        $this->addSql('ALTER TABLE operation ADD distance_transport INT DEFAULT NULL');
    }
}
