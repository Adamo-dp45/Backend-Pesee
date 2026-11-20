<?php

namespace App\Domain\Support;

use App\Domain\Enum\OrigineTarif;
use App\Entity\Fournisseur;
use App\Entity\Produit;

/*
    - LA règle du prix, et le seul endroit où elle est écrite. Trois sauts, dans cet ordre :

        le tarif propre au planteur
        ?? le tarif de sa catégorie pour ce produit
        ?? le prix du produit

    - Elle rend DEUX choses, et c'est volontaire : le prix, et d'où il vient. Le second n'est pas du
      confort. Sans lui, deux pesées à 470 F sont indiscernables, et « pourquoi mon voisin touche
      500 » n'a aucune réponse dans les données. L'appelant fige les deux sur la pesée.

    - STATIQUE, ET SANS LA MOINDRE REQUÊTE, sur le modèle de 'TauxPrelevement'. Elle ne lit que le
      graphe déjà chargé — les collections du planteur et de sa catégorie. C'est ce qui permet de
      l'appeler dans la boucle de synchronisation, qui traite des lots de deux cents pesées : un
      service qui interrogerait la base tirerait une requête par pesée, sur le chemin le plus chaud
      de l'application. Le préchargement reste donc la responsabilité de l'appelant, comme il l'est
      déjà pour les prélèvements.

    - PAS DE PRIX NUL INVENTÉ ICI. Un produit non tarifé vaut zéro, pas 'null' : c'est ainsi que le
      reste de l'application lit « pesée sans valeur » ('montantdu <= 0'), et traduire ce zéro en
      'null' ferait diverger deux lectures du même état. 'null' ne sort d'ici que s'il n'y a pas de
      produit du tout.

    - UN TARIF NE VAUT JAMAIS ZÉRO : la contrainte sur 'TarifProduit::$prix' l'interdit. Une ligne à
      zéro ne voudrait pas dire « gratuit » mais « je n'ai pas tranché », et c'est l'ABSENCE de
      ligne qui exprime déjà cela — en faisant retomber sur le saut suivant.
*/
final class TarifApplicable
{
    private function __construct(
        /** Le prix au kilo retenu. 'null' seulement s'il n'y a pas de produit. */
        public readonly ?int $prix,
        public readonly OrigineTarif $origine,
    )
    {
    }

    public static function pour(?Fournisseur $planteur, ?Produit $produit): self
    {
        if($produit === null) {
            return new self(null, OrigineTarif::PRODUIT);
        }

        $sien = $planteur?->tarifPour($produit);

        if($sien !== null) {
            return new self($sien->getPrix(), OrigineTarif::PLANTEUR);
        }

        $deSaCategorie = $planteur?->getCategorie()?->tarifPour($produit);

        if($deSaCategorie !== null) {
            return new self($deSaCategorie->getPrix(), OrigineTarif::CATEGORIE);
        }

        return new self($produit->getPrix(), OrigineTarif::PRODUIT);
    }

    /*
        - Le prix qui BORNE une mise en compte, pour ce planteur sur ce produit. Même résolution, et
          c'est tout l'enjeu : borner sur 'Produit::$prix' quand le planteur a un tarif supérieur
          refuse un taux pourtant légitime, et quand il en a un inférieur ACCEPTE un taux qui
          absorbera toute sa récolte — il repart du guichet avec zéro, sans exception possible,
          l'arbitrage étant irréversible.
    */
    public static function prixPour(?Fournisseur $planteur, ?Produit $produit): ?int
    {
        return self::pour($planteur, $produit)->prix;
    }
}
