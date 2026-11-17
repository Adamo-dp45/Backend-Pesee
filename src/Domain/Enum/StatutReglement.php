<?php

namespace App\Domain\Enum;

/*
    - État du règlement d'une pesée vis-à-vis du planteur. Le patron a demandé que le paiement puisse se faire « en un temps ou en plusieurs temps » : c'est ce qui justifie l'état intermédiaire 'PARTIEL'.
    - 'RETENU' est le cas des pesées dont l'épargne a tout absorbé : il n'y a rien à verser au guichet, et ce n'est pourtant ni un impayé ni un solde. Sans lui, ces pesées resteraient 'NON_PAYE' pour toujours et s'accumuleraient dans la file du guichet sans que rien ne soit à corriger.
*/
enum StatutReglement: string
{
    case NON_PAYE = 'NON_PAYE';
    case PARTIEL = 'PARTIEL';
    case SOLDE = 'SOLDE';
    case RETENU = 'RETENU';

    public function libelle(): string
    {
        return match($this) {
            self::NON_PAYE => 'Non payée',
            self::PARTIEL => 'Partiellement payée',
            self::SOLDE => 'Soldée',
            self::RETENU => 'Retenue sur épargne',
        };
    }

    /*
        - La règle de transition vit ici plutôt que dans un service : elle est appelée depuis la synchronisation (recalcul du montant dû) et depuis la confirmation d'un paiement, et les deux doivent trancher exactement pareil.

        - DEUX PARTS, ET NON PLUS UNE. Une pesée se règle désormais par la récolte, par le transport, ou par les deux, chacune en plusieurs fois. Un seul couple (payable, payé) ne peut plus décider : une pesée dont la récolte est soldée et le transport impayé affichait le même chiffre qu'une pesée à moitié réglée sur chaque part.

        - La méthode s'appelait 'pour(montantdu, montantpayable, montantpaye)', et avant cela 'depuis()'. Le changement de nom est délibéré, pour la même raison que la fois précédente : il fait casser les appelants au lieu de les laisser passer un total là où deux parts sont attendues. Un total passe la compilation et rend 'SOLDE' sur une pesée dont le transport n'a jamais été versé.

        - Elle se compare aux montants PAYABLES et non aux montants dus : une part de la récolte peut être retenue sur l'épargne du planteur, et elle ne lui sera pas versée au guichet. La pesée est soldée quand on a versé ce qu'on devait VERSER, pas ce qu'elle valait.

        - '>=' et non '===' : si un tarif baisse après un paiement, le montant déjà versé peut dépasser le payable. La pesée est alors soldée, pas bloquée dans un état incohérent.

        - 'RETENU' exige que la récolte soit entièrement retenue ET qu'il n'y ait rien à verser au titre du transport : sinon il reste de l'argent à sortir, et la pesée doit rester dans la file du guichet. La garde 'montantdu > 0' n'est pas décorative non plus — sans elle, une pesée dont le produit n'est pas tarifé basculerait en 'RETENU' et ferait croire à une épargne là où il n'y a qu'un prix manquant.

        - UNE PESÉE NON TARIFÉE N'EST JAMAIS 'SOLDE', même transport entièrement versé. C'est ce qui reste de l'ancienne règle « un montant nul n'est pas une pesée soldée », et c'est ce qui empêche une récolte sans prix de disparaître de la file du guichet parce qu'on a payé son carburant.
    */
    public static function pourLesParts(
        ?int $montantdu,
        int $payableProduit,
        int $payeProduit,
        int $payableTransport,
        int $payeTransport,
    ): self
    {
        $tarifee = $montantdu !== null && $montantdu > 0;

        if($payeProduit + $payeTransport <= 0) {
            return $tarifee && $payableProduit <= 0 && $payableTransport <= 0
                ? self::RETENU
                : self::NON_PAYE;
        }

        $couverte = $payeProduit >= $payableProduit && $payeTransport >= $payableTransport;

        return $couverte && $tarifee ? self::SOLDE : self::PARTIEL;
    }
}
