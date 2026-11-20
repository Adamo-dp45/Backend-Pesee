<?php

namespace App\Domain\Support;

use App\Domain\Enum\OrigineTarif;
use App\Entity\Fournisseur;
use App\Entity\Produit;

/*
    - LA règle du taux de mise en compte, et le seul endroit où elle est écrite : un taux doit être
      strictement INFÉRIEUR au prix du kilo. Égal, le planteur repartirait de chaque livraison avec
      exactement zéro ; au-dessus, il la laisserait entièrement sur son épargne.

    - Elle vit ici et non dans une contrainte de validation parce qu'elle se vérifie à QUATRE
      moments et que le message doit nommer le produit et son prix à chaque fois : au dépôt de la
      demande, à sa correction, à son approbation — le prix a pu bouger entre-temps — et à
      l'ouverture directe par l'administrateur.

    - LE PRIX QUI BORNE EST CELUI DU PLANTEUR, pas celui du produit, et c'est pour ça que cette
      fonction a besoin de lui. Avec le prix du produit :

      · un planteur dont la catégorie paie PLUS cher se voit refuser un taux pourtant légitime — on
        lui interdit d'épargner 420 F sur un hévéa qui lui est payé 450 ;
      · un planteur dont la catégorie paie MOINS cher se voit ACCEPTER un taux qui absorbera toute
        sa récolte. Il repart du guichet avec zéro, et il n'y a pas d'exception possible :
        'figerLaRetenue()' est irréversible.

      Le second est le cas grave, et il est silencieux — rien dans l'application ne le signalerait.

    - Un produit non tarifé POUR CE PLANTEUR n'accepte aucun taux : ses pesées ne valent rien, un
      prélèvement dessus ne mettrait jamais un franc de côté, et le message renvoie l'opérateur là
      où il y a quelque chose à corriger.
*/
final class TauxPrelevement
{
    /** @return string|null le motif du refus, ou null si le taux est acceptable */
    public static function motifDuRefus(?Fournisseur $planteur, ?Produit $produit, int $taux): ?string
    {
        if($produit === null) {
            return 'Un prélèvement vise toujours un produit : c\'est son prix au kilo qui borne le taux.';
        }

        if($taux <= 0) {
            return 'Le taux de prélèvement doit être supérieur à zéro.';
        }

        $applicable = TarifApplicable::pour($planteur, $produit);
        $prix = (int) $applicable->prix;

        if($prix <= 0) {
            return sprintf(
                'Le produit « %s » n\'a pas de prix : tarifez-le avant d\'ouvrir un prélèvement dessus, sinon rien ne sera jamais mis de côté.',
                (string) $produit->getLibelle()
            );
        }

        if($taux >= $prix) {
            return sprintf(
                'Un prélèvement de %s F par kilo sur « %s », %s %s F le kilo, ne laisserait rien au planteur. Le taux doit rester inférieur à ce prix.',
                number_format($taux, 0, ',', ' '),
                (string) $produit->getLibelle(),
                /*
                    - On dit D'OÙ vient le prix quand ce n'est pas celui du produit, sans quoi le
                      message contredit la fiche produit que l'opérateur a sous les yeux : « l'hévéa
                      vaut 450 » alors que l'écran affiche 400, et il cherche l'erreur là où elle
                      n'est pas.
                */
                $applicable->origine === OrigineTarif::PRODUIT ? 'qui vaut' : 'payé à ce planteur',
                number_format($prix, 0, ',', ' ')
            );
        }

        return null;
    }
}
