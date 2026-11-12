<?php

namespace App\Domain\Support;

use App\Entity\Produit;

/*
    - LA règle du taux de mise en compte, et le seul endroit où elle est écrite : un taux doit être
      strictement INFÉRIEUR au prix du kilo du produit. Égal, le planteur repartirait de chaque
      livraison avec exactement zéro ; au-dessus, il la laisserait entièrement sur son épargne.

    - Elle vit ici et non dans une contrainte de validation parce qu'elle se vérifie à QUATRE
      moments et que le message doit nommer le produit et son prix à chaque fois : au dépôt de la
      demande, à sa correction, à son approbation — le prix a pu bouger entre-temps — et à
      l'ouverture directe par l'administrateur.

    - Un produit non tarifé ('prix' nul ou zéro) n'accepte AUCUN taux. C'est voulu : ses pesées ne
      valent rien, un prélèvement dessus ne mettrait jamais un franc de côté, et le message renvoie
      l'opérateur là où il y a quelque chose à corriger.
*/
final class TauxPrelevement
{
    /** @return string|null le motif du refus, ou null si le taux est acceptable */
    public static function motifDuRefus(?Produit $produit, int $taux): ?string
    {
        if($produit === null) {
            return 'Un prélèvement vise toujours un produit : c\'est son prix au kilo qui borne le taux.';
        }

        if($taux <= 0) {
            return 'Le taux de prélèvement doit être supérieur à zéro.';
        }

        $prix = (int) $produit->getPrix();

        if($prix <= 0) {
            return sprintf(
                'Le produit « %s » n\'a pas de prix : tarifez-le avant d\'ouvrir un prélèvement dessus, sinon rien ne sera jamais mis de côté.',
                (string) $produit->getLibelle()
            );
        }

        if($taux >= $prix) {
            return sprintf(
                'Un prélèvement de %s F par kilo sur « %s », qui vaut %s F le kilo, ne laisserait rien au planteur. Le taux doit rester inférieur au prix du produit.',
                number_format($taux, 0, ',', ' '),
                (string) $produit->getLibelle(),
                number_format($prix, 0, ',', ' ')
            );
        }

        return null;
    }
}
