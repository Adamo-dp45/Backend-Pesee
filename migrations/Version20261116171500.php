<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - LE POSTE NE TARIFE PLUS. Le logiciel de pesée pèse ; le prix du produit et le prix du
      transport se fixent sur le web, qui est aussi l'endroit où l'on paie. Les deux colonnes
      supprimées ici n'étaient que l'arbitre entre deux sources : elles mémorisaient la dernière
      valeur reçue d'un poste pour savoir si elle avait bougé depuis.

    - RIEN NE SE PERD DU PRIX EN VIGUEUR. 'produit.prix' et 'fournisseur.prixtransport' ne sont pas
      touchés : ce qui s'applique aujourd'hui continue de s'appliquer, qu'il soit venu d'un poste
      ou du bureau. Seule la mémoire de ce que le poste avait dit s'en va, et plus rien ne la lit.

    - Le 'down()' remet les colonnes, vides. Il ne peut pas rendre ce qu'elles contenaient, et c'est
      sans conséquence : repeuplées à nul, elles feront simplement appliquer le premier prix reçu
      d'un poste — exactement ce que faisait un référentiel neuf.
*/
final class Version20261116171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Le poste ne tarife plus : suppression de l arbitrage des prix poste/web';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit DROP prix_poste');
        $this->addSql('ALTER TABLE fournisseur DROP prixtransport_poste');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit ADD prix_poste INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur ADD prixtransport_poste INT DEFAULT NULL');
    }
}
