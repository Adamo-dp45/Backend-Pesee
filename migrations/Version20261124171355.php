<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - LA TABLE DES PIÈCES JOINTES : le justificatif d'une ligne d'argent — reçu signé, bordereau de
      banque, talon de chèque, pièce qui motive une demande.

    - AUCUNE TABLE EXISTANTE NE CHANGE, et c'est voulu. La pièce porte les clés vers ses cinq
      cibles possibles ; mettre la clé de l'autre côté aurait ajouté une colonne à 'paiement' et à
      'mouvement_caisse', deux tables d'argent qu'on ne touche pas pour un besoin d'affichage.

    - CINQ INDEX UNIQUES, UN PAR CIBLE : une ligne ne porte qu'une pièce, et un second dépôt
      remplace le premier plutôt que d'en empiler un autre. MySQL accepte autant de NULL qu'on veut
      dans un index unique, donc une pièce rattachée à un versement laisse les quatre autres
      colonnes libres sans gêner personne.

    - LA CONTRAINTE 'CHECK' EST LA PIÈCE MAÎTRESSE. Sans elle, deux états impossibles passent :
      une pièce SANS cible, orpheline, que plus aucun écran ne montre et que le contrôle d'accès ne
      sait pas juger — faute de ligne à interroger, il faudrait inventer une règle ; et une pièce à
      DEUX cibles, que deux lignes revendiquent, où la plus permissive des deux déciderait qui peut
      la lire. Les deux se produiraient par un simple oubli de code, et aucun des deux ne lève.

    - LE FICHIER LUI-MÊME N'EST PAS EN BASE. Il vit dans 'var/uploads/pieces', hors de la racine
      web, sous un nom aléatoire. Ce dossier doit être SAUVEGARDÉ : une pièce comptable perdue ne
      se refait pas, et aucune migration ne la rendra.
*/
final class Version20261124171355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Les pièces jointes : un justificatif par ligne d\'argent, et exactement une cible par pièce';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE piece_jointe (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, chemin VARCHAR(255) DEFAULT NULL, nom_origine VARCHAR(255) DEFAULT NULL, type_mime VARCHAR(100) DEFAULT NULL, taille INT DEFAULT 0 NOT NULL, reference VARCHAR(60) DEFAULT NULL, libelle VARCHAR(255) DEFAULT NULL, entreprise_id INT NOT NULL, depose_par_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, mouvement_caisse_id INT DEFAULT NULL, demande_solde_id INT DEFAULT NULL, demande_prelevement_id INT DEFAULT NULL, demande_retrait_id INT DEFAULT NULL, INDEX IDX_AB5111D4DCFF0FC4 (depose_par_id), INDEX idx_piece_entreprise (entreprise_id), UNIQUE INDEX uniq_piece_paiement (paiement_id), UNIQUE INDEX uniq_piece_mouvement (mouvement_caisse_id), UNIQUE INDEX uniq_piece_demande_solde (demande_solde_id), UNIQUE INDEX uniq_piece_demande_prelevement (demande_prelevement_id), UNIQUE INDEX uniq_piece_demande_retrait (demande_retrait_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D4A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D4DCFF0FC4 FOREIGN KEY (depose_par_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D42A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D4D48E93B2 FOREIGN KEY (mouvement_caisse_id) REFERENCES mouvement_caisse (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D4FA26396B FOREIGN KEY (demande_solde_id) REFERENCES demande_solde (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D45E9A2CA FOREIGN KEY (demande_prelevement_id) REFERENCES demande_prelevement (id)');
        $this->addSql('ALTER TABLE piece_jointe ADD CONSTRAINT FK_AB5111D488344FDB FOREIGN KEY (demande_retrait_id) REFERENCES demande_retrait (id)');

        /*
            - EXACTEMENT UNE CIBLE. En MySQL, « colonne IS NOT NULL » vaut 1 ou 0 : la somme des
              cinq doit faire 1. Ni zéro — une pièce orpheline —, ni deux — une pièce que deux
              lignes revendiquent.
            - Doctrine ne sait pas exprimer ça en attribut : la contrainte s'écrit ici, et c'est
              pour ça que ce fichier n'est pas qu'un 'diff' recopié.
        */
        $this->addSql(<<<'SQL'
            ALTER TABLE piece_jointe ADD CONSTRAINT chk_piece_une_seule_cible CHECK (
                (paiement_id IS NOT NULL)
                + (mouvement_caisse_id IS NOT NULL)
                + (demande_solde_id IS NOT NULL)
                + (demande_prelevement_id IS NOT NULL)
                + (demande_retrait_id IS NOT NULL) = 1
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE piece_jointe DROP CONSTRAINT chk_piece_une_seule_cible');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D4A4AEAFEA');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D4DCFF0FC4');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D42A4C4478');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D4D48E93B2');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D4FA26396B');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D45E9A2CA');
        $this->addSql('ALTER TABLE piece_jointe DROP FOREIGN KEY FK_AB5111D488344FDB');
        $this->addSql('DROP TABLE piece_jointe');
    }
}
