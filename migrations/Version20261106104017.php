<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - Les deux caisses, physique et numérique, à chaque niveau : entreprise et pont bascule. Et le
      mode de règlement sur le versement, qui désigne celle des deux qui se vide.

    - LE SOLDE EXISTANT DEVIENT LE NUMÉRIQUE, pas le physique, et ce n'est pas un détail : c'est lui
      qui payait le mobile money jusqu'ici. Toutes les lignes de grand livre déjà écrites décrivent
      donc des mouvements de flotte, et tous les versements en vol y ont leur contrepartie. Le
      verser dans la caisse physique aurait laissé les versements en cours sans provision et rendu
      la chaîne 'soldeAvant'/'soldeApres' incohérente dès la première relecture.

    - Pour la même raison, les mouvements et les versements déjà en base prennent la valeur par
      défaut 'NUMERIQUE' / 'MOBILE_MONEY' : c'est ce qu'ils étaient, faute d'alternative.

    - 'numero_destinataire' devient facultatif : des espèces ne vont à aucun numéro.
*/
final class Version20261106104017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deux caisses (physique et numérique) sur l\'entreprise et les sites, et mode de règlement sur les paiements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD solde_physique BIGINT DEFAULT 0 NOT NULL, CHANGE solde solde_numerique BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE site ADD solde_physique BIGINT DEFAULT 0 NOT NULL, CHANGE solde solde_numerique BIGINT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE mouvement_caisse ADD caisse VARCHAR(255) DEFAULT \'NUMERIQUE\' NOT NULL');
        $this->addSql('ALTER TABLE demande_solde ADD caisse VARCHAR(255) DEFAULT \'NUMERIQUE\' NOT NULL');

        $this->addSql('ALTER TABLE paiement ADD mode_reglement VARCHAR(255) DEFAULT \'MOBILE_MONEY\' NOT NULL, CHANGE numero_destinataire numero_destinataire VARCHAR(30) DEFAULT NULL');
    }

    /*
        - Le retour en arrière REND LE NUMÉRIQUE et abandonne le physique : c'est la symétrie exacte
          de l'aller. Les espèces déjà saisies seraient perdues — un 'down()' sur une base vivante
          n'est pas une opération anodine, et celui-ci ne prétend pas l'être.
    */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement DROP mode_reglement, CHANGE numero_destinataire numero_destinataire VARCHAR(30) NOT NULL');

        $this->addSql('ALTER TABLE demande_solde DROP caisse');
        $this->addSql('ALTER TABLE mouvement_caisse DROP caisse');

        $this->addSql('ALTER TABLE site DROP solde_physique, CHANGE solde_numerique solde BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE entreprise DROP solde_physique, CHANGE solde_numerique solde BIGINT DEFAULT 0 NOT NULL');
    }
}
