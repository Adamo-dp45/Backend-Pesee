<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - LE PRODUIT APPARTIENT À L'ENTREPRISE, PLUS AU PONT BASCULE. Jusqu'ici chaque poste tenait son
      propre référentiel : le cacao de Soubré et celui de Gagnoa étaient deux lignes sans rapport,
      avec chacune son prix. C'était tenable tant qu'un prix se saisissait sur le produit ; ça cesse
      de l'être dès qu'un tarif se saisit par catégorie de planteurs, puisqu'il faudrait alors le
      saisir autant de fois qu'il y a de postes — la duplication même que la catégorie supprime.

    - Le planteur, lui, RESTE LOCAL : deux « Kouassi Yao » sur deux ponts bascules sont deux hommes
      différents, et rien ne permet d'en dire autant de deux cacaos.

    - La reprise est une TRADUCTION EXACTE, pas une reconstruction : le poste portait déjà son
      entreprise, chaque produit suit la sienne. Aucune ligne n'est inventée ni perdue, et les
      pesées ne sont pas touchées — elles pointent sur le produit, jamais sur son périmètre.

    - CE QU'ELLE NE FAIT PAS : fusionner les doublons. Deux postes d'une même entreprise qui
      pesaient chacun « leur » cacao donnent deux produits identiques sur l'entreprise. Rien en base
      ne s'y oppose, l'unicité étant portée par 'UniqueParEntreprise', qui ne juge que les saisies.
      Les fusionner demanderait de choisir lequel des deux prix fait foi et de déplacer les pesées
      de l'autre : une décision de gestion, pas une migration. L'application n'étant pas en
      production, la base se vide et les fixtures rechargent.

    - Le 'down()' rend le schéma, pas l'information : une entreprise a plusieurs postes et rien ne
      dit duquel un produit venait. Il les rattache tous au plus ancien poste de leur entreprise, et
      suppose donc que chaque entreprise en a un.
*/
final class Version20261118080553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Le produit passe du pont bascule a l'entreprise";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY `FK_29A5EC27F6BD1646`');
        $this->addSql('DROP INDEX IDX_29A5EC27F6BD1646 ON produit');
        $this->addSql('DROP INDEX idx_produit_site_cle ON produit');

        // Nullable le temps de la reprise : la colonne naît vide, et 'NOT NULL' refuserait une table peuplée
        $this->addSql('ALTER TABLE produit ADD entreprise_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE produit p
            INNER JOIN site s ON s.id = p.site_id
            SET p.entreprise_id = s.entreprise_id
        SQL);
        $this->addSql('ALTER TABLE produit CHANGE entreprise_id entreprise_id INT NOT NULL');
        $this->addSql('ALTER TABLE produit DROP site_id');

        $this->addSql('ALTER TABLE produit ADD CONSTRAINT FK_29A5EC27A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('CREATE INDEX IDX_29A5EC27A4AEAFEA ON produit (entreprise_id)');
        $this->addSql('CREATE INDEX idx_produit_entreprise_cle ON produit (entreprise_id, cle)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit DROP FOREIGN KEY FK_29A5EC27A4AEAFEA');
        $this->addSql('DROP INDEX IDX_29A5EC27A4AEAFEA ON produit');
        $this->addSql('DROP INDEX idx_produit_entreprise_cle ON produit');

        $this->addSql('ALTER TABLE produit ADD site_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE produit p
            SET p.site_id = (SELECT MIN(s.id) FROM site s WHERE s.entreprise_id = p.entreprise_id)
        SQL);
        $this->addSql('ALTER TABLE produit CHANGE site_id site_id INT NOT NULL');
        $this->addSql('ALTER TABLE produit DROP entreprise_id');

        $this->addSql('ALTER TABLE produit ADD CONSTRAINT `FK_29A5EC27F6BD1646` FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('CREATE INDEX IDX_29A5EC27F6BD1646 ON produit (site_id)');
        $this->addSql('CREATE INDEX idx_produit_site_cle ON produit (site_id, cle)');
    }
}
