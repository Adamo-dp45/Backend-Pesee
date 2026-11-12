<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - Le prix du transport : certains planteurs amènent eux-mêmes leur récolte au pont bascule, et ce
      trajet leur est payé EN PLUS d'elle. Un prix au kilo sur la fiche du planteur, figé sur chaque
      pesée comme l'est déjà le prix du produit.

    - Et la fin du prix spécial, qui remplaçait le prix du produit pour un planteur donné. ATTENTION :
      les valeurs saisies sont PERDUES, le 'down()' ne rend que les colonnes vides. S'il y en a en
      production, les exporter avant de migrer — la notion doit revenir sous une autre forme.
*/
final class Version20261112090805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prix du transport du planteur, en remplacement du prix special';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur ADD prixtransport INT DEFAULT NULL, ADD prixtransport_poste INT DEFAULT NULL, DROP prixspeciale, DROP prixspeciale_poste');

        /*
            - 'montanttransport' naît à 0 et n'est pas nullable, contrairement à 'montantdu' : zéro et
              « pas de transport » sont la même chose, il n'y a aucun « jamais calculé » à distinguer.
              Les pesées déjà en base sont donc justes sans reprise — aucune ne portait de transport.
        */
        $this->addSql('ALTER TABLE operation ADD prixtransport INT DEFAULT NULL, ADD montanttransport INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseur ADD prixspeciale INT DEFAULT NULL, ADD prixspeciale_poste INT DEFAULT NULL, DROP prixtransport, DROP prixtransport_poste');
        $this->addSql('ALTER TABLE operation DROP prixtransport, DROP montanttransport');
    }
}
