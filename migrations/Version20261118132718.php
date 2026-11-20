<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - DES CLASSES DE PLANTEURS, ET LEUR PRIX. Tous les planteurs d'une entreprise étaient payés au
      même prix pour un même produit. Ceux qui livrent dix tonnes par passage et ceux qui en livrent
      deux cents kilos ne négocient pourtant pas la même chose, et cette différence se saisissait
      jusqu'ici planteur par planteur, ou pas du tout.

    - TROIS SAUTS, dans cet ordre : le tarif propre au planteur, celui de sa catégorie, puis le prix
      du produit. Chacun est un PRIX COMPLET et non un écart — un écart oblige à relire le prix de
      base pour savoir ce qu'on paie, et se périme en silence le jour où ce prix bouge.

    - 'operation.origine_tarif' EST LA CONTREPARTIE INDISPENSABLE. Sans elle, deux pesées valorisées
      à 470 F le kilo sont indiscernables, et « pourquoi mon voisin touche 500 » n'a aucune réponse
      dans les données. Le prix retenu est un fait daté ; le chemin qui y a mené aussi.

    - LA REPRISE EST DÉMONTRABLE, et c'est la seule de cette migration : avant elle, aucun tarif
      n'existe. Toute pesée déjà valorisée l'a donc forcément été au prix du produit, et peut être
      marquée 'PRODUIT' sans rien supposer. Les pesées sans prix restent à 'null' — elles n'ont pas
      d'origine parce qu'elles n'ont pas encore de prix.

    - 'produit.prix_modifie_at' NE SE REPREND PAS. 'updatedAt' bouge aussi quand on corrige un
      libellé : s'en servir daterait de faux changements de prix, ce qui est pire que l'absence de
      date. Les produits existants l'auront à leur prochaine tarification.

    - 'fournisseur.categorie_id' en 'SET NULL' : la purge définitive d'une catégorie rend ses
      planteurs au prix du produit, ce qui est visible au guichet. Les emporter avec elle serait une
      catastrophe muette — leurs pesées, leur épargne et leurs versements pendent d'eux.

    - Le 'down()' rend le schéma, pas l'information : les tarifs saisis disparaissent, et les
      montants déjà figés sur les pesées ne bougent pas — ils resteront justes mais inexplicables.
*/
final class Version20261118132718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categories de planteurs, tarifs par categorie et par planteur';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE categorie_fournisseur (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, cle VARCHAR(255) NOT NULL, entreprise_id INT NOT NULL, INDEX IDX_E960EC3BA4AEAFEA (entreprise_id), INDEX idx_categorie_entreprise_cle (entreprise_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tarif_categorie (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, prix INT NOT NULL, id INT AUTO_INCREMENT NOT NULL, produit_id INT NOT NULL, categorie_id INT NOT NULL, INDEX IDX_7A780E05F347EFB (produit_id), INDEX IDX_7A780E05BCF5E72D (categorie_id), INDEX idx_tarif_categorie_produit (categorie_id, produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tarif_fournisseur (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, prix INT NOT NULL, id INT AUTO_INCREMENT NOT NULL, produit_id INT NOT NULL, fournisseur_id INT NOT NULL, INDEX IDX_78CBA463F347EFB (produit_id), INDEX IDX_78CBA463670C757F (fournisseur_id), INDEX idx_tarif_fournisseur_produit (fournisseur_id, produit_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE categorie_fournisseur ADD CONSTRAINT FK_E960EC3BA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE tarif_categorie ADD CONSTRAINT FK_7A780E05F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE tarif_categorie ADD CONSTRAINT FK_7A780E05BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_fournisseur (id)');
        $this->addSql('ALTER TABLE tarif_fournisseur ADD CONSTRAINT FK_78CBA463F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id)');
        $this->addSql('ALTER TABLE tarif_fournisseur ADD CONSTRAINT FK_78CBA463670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE fournisseur ADD categorie_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fournisseur ADD CONSTRAINT FK_369ECA32BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_fournisseur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_369ECA32BCF5E72D ON fournisseur (categorie_id)');
        $this->addSql('ALTER TABLE operation ADD origine_tarif VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE produit ADD prix_modifie_at DATETIME DEFAULT NULL');

        /*
            - Aucun tarif n'existait avant cette migration : une pesée qui porte un prix l'a donc
              reçu du produit. On ne devine rien ici, on écrit ce qui était déjà vrai.
        */
        $this->addSql(<<<'SQL'
            UPDATE operation
            SET origine_tarif = 'PRODUIT'
            WHERE prixunitaire IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categorie_fournisseur DROP FOREIGN KEY FK_E960EC3BA4AEAFEA');
        $this->addSql('ALTER TABLE tarif_categorie DROP FOREIGN KEY FK_7A780E05F347EFB');
        $this->addSql('ALTER TABLE tarif_categorie DROP FOREIGN KEY FK_7A780E05BCF5E72D');
        $this->addSql('ALTER TABLE tarif_fournisseur DROP FOREIGN KEY FK_78CBA463F347EFB');
        $this->addSql('ALTER TABLE tarif_fournisseur DROP FOREIGN KEY FK_78CBA463670C757F');
        $this->addSql('DROP TABLE categorie_fournisseur');
        $this->addSql('DROP TABLE tarif_categorie');
        $this->addSql('DROP TABLE tarif_fournisseur');
        $this->addSql('ALTER TABLE fournisseur DROP FOREIGN KEY FK_369ECA32BCF5E72D');
        $this->addSql('DROP INDEX IDX_369ECA32BCF5E72D ON fournisseur');
        $this->addSql('ALTER TABLE fournisseur DROP categorie_id');
        $this->addSql('ALTER TABLE operation DROP origine_tarif');
        $this->addSql('ALTER TABLE produit DROP prix_modifie_at');
    }
}
