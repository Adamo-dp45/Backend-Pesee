<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - LA RÉCOLTE ET LE TRANSPORT SE RÈGLENT SÉPARÉMENT. Une pesée vaut deux choses distinctes, et
      jusqu'ici elles se confondaient au guichet dans un seul chiffre : on ne pouvait pas payer le
      transport d'un planteur sans payer aussi sa récolte. Chaque part se règle désormais seule, et
      en plusieurs fois.

    - 'paiement.montant' NE CHANGE PAS DE SENS : il reste le total, celui que voient la caisse, la
      passerelle et le grand livre. Les deux colonnes ajoutées sont sa ventilation, et l'invariant
      'montant = montantproduit + montanttransport' vaut pour TOUTE ligne, retrait d'épargne compris
      — un retrait porte tout sur la part produit. C'est ce qui permet de le figer par un 'CHECK'
      plutôt que par un commentaire.

    - LA REPRISE DES DONNÉES est le seul vrai travail de cette migration. L'historique ne dit pas ce
      que chaque versement réglait : il faut en décider. La règle retenue est celle qu'un humain
      appliquerait en relisant un relevé — la récolte d'abord, jusqu'à son payable, le surplus au
      transport — et elle s'écrit en une passe grâce à la somme cumulée par pesée.

      · Ordonnée par 'id' et non par 'created_at' : cette colonne est nullable, ses NULL remonteraient
        en tête et ses ex æquo sont indéterminés. L'auto-incrément, lui, est chronologique et total.
      · Les versements ÉCHOUÉS et ANNULÉS sortent de la cascade : ils n'ont jamais consommé de
        payable, et les y inclure décalerait tous les suivants. Ils prennent tout sur la part produit,
        comme les retraits — l'invariant doit rester vrai pour eux aussi, et aucune somme ne les lit.

    - Le risque en production est faible : 'operation.montanttransport' est née le 12/11/2026, et
      aucune pesée antérieure ne porte de transport. Toute ligne plus ancienne se réduit donc à
      « tout sur la récolte », et la cascade ne concerne que les pesées postérieures.

    - Le 'down()' rend les colonnes. Il ne peut pas rendre l'information : une fois les parts
      supprimées, on ne sait plus ce que chaque versement réglait. C'est sans conséquence, le total
      n'ayant jamais bougé.
*/
final class Version20261117094500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reglement separe de la recolte et du transport : ventilation des versements';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement ADD montantproduit INT DEFAULT 0 NOT NULL, ADD montanttransport INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE operation ADD montantpayeproduit INT DEFAULT 0 NOT NULL, ADD montantpayetransport INT DEFAULT 0 NOT NULL');

        // 1. Ce qui ne se ventile pas : les retraits d'épargne, et les versements qu'aucune somme ne lit
        $this->addSql(<<<'SQL'
            UPDATE paiement
            SET montantproduit = montant, montanttransport = 0
            WHERE operation_id IS NULL OR statut IN ('ECHOUE', 'ANNULE')
        SQL);

        // 2. La cascade : la récolte d'abord jusqu'à son payable, le surplus au transport
        $this->addSql(<<<'SQL'
            UPDATE paiement p
            JOIN (
                SELECT c.id,
                       GREATEST(0, LEAST(c.montant, c.payableproduit - c.cumulAvant)) AS surLaRecolte
                FROM (
                    SELECT p2.id,
                           p2.montant,
                           GREATEST(0, COALESCE(o.montantdu, 0) - COALESCE(o.retenue, 0)) AS payableproduit,
                           SUM(p2.montant) OVER (PARTITION BY p2.operation_id ORDER BY p2.id) - p2.montant AS cumulAvant
                    FROM paiement p2
                    JOIN operation o ON o.id = p2.operation_id
                    WHERE p2.operation_id IS NOT NULL AND p2.statut NOT IN ('ECHOUE', 'ANNULE')
                ) c
            ) r ON r.id = p.id
            SET p.montantproduit = r.surLaRecolte,
                p.montanttransport = p.montant - r.surLaRecolte
        SQL);

        // 3. Les cumuls de la pesée : la somme des parts CONFIRMÉES, exactement comme le fait le service
        $this->addSql(<<<'SQL'
            UPDATE operation o
            LEFT JOIN (
                SELECT operation_id,
                       COALESCE(SUM(montantproduit), 0) AS surLaRecolte,
                       COALESCE(SUM(montanttransport), 0) AS surLeTransport
                FROM paiement
                WHERE statut = 'REUSSI' AND operation_id IS NOT NULL
                GROUP BY operation_id
            ) v ON v.operation_id = o.id
            SET o.montantpayeproduit = COALESCE(v.surLaRecolte, 0),
                o.montantpayetransport = COALESCE(v.surLeTransport, 0)
        SQL);

        // 4. L'invariant, en base et non en commentaire
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT chk_paiement_parts CHECK (montant = montantproduit + montanttransport)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement DROP CONSTRAINT chk_paiement_parts');
        $this->addSql('ALTER TABLE paiement DROP montantproduit, DROP montanttransport');
        $this->addSql('ALTER TABLE operation DROP montantpayeproduit, DROP montantpayetransport');
    }
}
