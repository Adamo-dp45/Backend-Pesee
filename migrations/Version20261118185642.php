<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - TROIS FAÇONS DE PAYER UN TRAJET, LÀ OÙ IL N'Y EN AVAIT QU'UNE. Le transport se lisait sur la
      fiche du planteur, en francs par kilo. C'est juste pour qui livre dix tonnes, absurde pour qui
      en apporte deux cents kilos depuis le même village — le camion a fait la même route.

    - UN BARÈME SE PARTAGE, un prix au kilo ne se partageait pas : vingt planteurs du même village
      donnaient vingt saisies à tenir d'accord à la main. Il porte son MODE — au kilo, au forfait, ou
      par tranches de kilomètres — et seuls les champs de ce mode-là sont remplis.

    - LA REPRISE NE DEVINE RIEN, ET ELLE NE RENOMME SURTOUT PAS. Doctrine proposait de transformer la
      colonne 'prixtransport' en 'distance_km' : un planteur à 15 F le kilo serait devenu un planteur
      à 15 kilomètres, chiffre parfaitement plausible et parfaitement faux. On crée donc un barème
      « au kilo » par valeur distincte rencontrée, on y rattache les planteurs concernés, et on
      supprime la colonne. La formule est identique — prix × poids net — donc aucun montant ne bouge.

    - 'distance_km' NAÎT VIDE, et c'est voulu : personne ne l'a jamais saisie. Elle ne sert qu'aux
      barèmes par tranches, et rattacher un tel barème à un planteur sans distance est refusé.

    - LA REPRISE DES PESÉES EST DÉMONTRABLE : avant cette migration, le seul transport possible venait
      du prix au kilo du planteur lui-même. Toute pesée qui en porte un a donc le mode 'AU_KILO' et
      l'origine 'PLANTEUR', sans rien supposer.

    - Le 'down()' rend le schéma, pas l'information : les tranches disparaissent, et un barème au
      forfait n'a pas de prix au kilo à rendre à la colonne qu'on recrée.
*/
final class Version20261118185642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baremes de transport : au kilo, au forfait, par tranches de kilometres';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bareme_transport (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(255) NOT NULL, cle VARCHAR(255) NOT NULL, mode VARCHAR(255) NOT NULL, prix_au_kilo INT DEFAULT NULL, montant INT DEFAULT NULL, entreprise_id INT NOT NULL, INDEX IDX_EE81DCC6A4AEAFEA (entreprise_id), INDEX idx_bareme_entreprise_cle (entreprise_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tranche (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, km_min INT NOT NULL, km_max INT DEFAULT NULL, montant INT NOT NULL, bareme_id INT NOT NULL, INDEX IDX_666758405F49EAAD (bareme_id), INDEX idx_tranche_bareme_min (bareme_id, km_min), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bareme_transport ADD CONSTRAINT FK_EE81DCC6A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE tranche ADD CONSTRAINT FK_666758405F49EAAD FOREIGN KEY (bareme_id) REFERENCES bareme_transport (id)');
        $this->addSql('ALTER TABLE categorie_fournisseur ADD bareme_transport_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE categorie_fournisseur ADD CONSTRAINT FK_E960EC3B44511689 FOREIGN KEY (bareme_transport_id) REFERENCES bareme_transport (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E960EC3B44511689 ON categorie_fournisseur (bareme_transport_id)');
        /*
            - DEUX COLONNES DISTINCTES, et non un renommage : 'prixtransport' et 'distance_km' ne
              parlent pas de la même chose. Les confondre transformerait « 15 F le kilo » en
              « 15 kilomètres », une valeur que rien ne signalerait comme fausse.
        */
        $this->addSql('ALTER TABLE fournisseur ADD bareme_transport_id INT DEFAULT NULL, ADD distance_km INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA3244511689 FOREIGN KEY (bareme_transport_id) REFERENCES bareme_transport (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_369ECA3244511689 ON fournisseur (bareme_transport_id)');
        $this->addSql('ALTER TABLE operation ADD mode_transport VARCHAR(255) DEFAULT NULL, ADD origine_transport VARCHAR(255) DEFAULT NULL, ADD distance_transport INT DEFAULT NULL, ADD tranche_transport VARCHAR(100) DEFAULT NULL');

        /*
            - UN BARÈME PAR VALEUR DISTINCTE, et par entreprise : deux clients qui payaient tous
              deux 15 F le kilo ont chacun le sien, puisqu'un barème ne se partage pas entre
              clients. Le libellé porte le montant, ce qui le rend reconnaissable sans l'ouvrir.
            - La clé suit la règle de 'Cle::depuis()' : majuscules, sans rien d'autre que des
              lettres et des chiffres.
        */
        $this->addSql(<<<'SQL'
            INSERT INTO bareme_transport (libelle, cle, mode, prix_au_kilo, montant, entreprise_id, created_at)
            SELECT DISTINCT
                CONCAT('Transport au kilo ', f.prixtransport, ' F'),
                CONCAT('TRANSPORTAUKILO', f.prixtransport, 'F'),
                'AU_KILO',
                f.prixtransport,
                NULL,
                s.entreprise_id,
                NOW()
            FROM fournisseur f
            INNER JOIN site s ON s.id = f.site_id
            WHERE f.prixtransport IS NOT NULL AND f.prixtransport > 0
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE fournisseur f
            INNER JOIN site s ON s.id = f.site_id
            INNER JOIN bareme_transport b
                ON b.entreprise_id = s.entreprise_id AND b.prix_au_kilo = f.prixtransport
            SET f.bareme_transport_id = b.id
            WHERE f.prixtransport IS NOT NULL AND f.prixtransport > 0
        SQL);

        $this->addSql('ALTER TABLE fournisseur DROP prixtransport');

        /*
            - Avant cette migration, un transport ne pouvait venir que du prix au kilo du planteur
              lui-même : toute pesée qui en porte un était donc « au kilo », et le barème qui la
              couvre est celui du planteur. On écrit ce qui était déjà vrai.
        */
        $this->addSql(<<<'SQL'
            UPDATE operation
            SET mode_transport = 'AU_KILO', origine_transport = 'PLANTEUR'
            WHERE prixtransport IS NOT NULL
        SQL);

        /* Les autres pesées terminées n'avaient aucun transport, et c'est une information, pas un vide. */
        $this->addSql(<<<'SQL'
            UPDATE operation
            SET origine_transport = 'AUCUN'
            WHERE prixtransport IS NULL AND poids2 IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bareme_transport DROP FOREIGN KEY FK_EE81DCC6A4AEAFEA');
        $this->addSql('ALTER TABLE tranche DROP FOREIGN KEY FK_666758405F49EAAD');
        $this->addSql('DROP TABLE bareme_transport');
        $this->addSql('DROP TABLE tranche');
        $this->addSql('ALTER TABLE categorie_fournisseur DROP FOREIGN KEY FK_E960EC3B44511689');
        $this->addSql('DROP INDEX IDX_E960EC3B44511689 ON categorie_fournisseur');
        $this->addSql('ALTER TABLE categorie_fournisseur DROP bareme_transport_id');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA3244511689');
        $this->addSql('DROP INDEX IDX_369ECA3244511689 ON fournisseur');
        $this->addSql('ALTER TABLE fournisseur ADD prixtransport INT DEFAULT NULL, DROP distance_km, DROP bareme_transport_id');
        $this->addSql('ALTER TABLE operation DROP mode_transport, DROP origine_transport, DROP distance_transport, DROP tranche_transport');
    }
}
