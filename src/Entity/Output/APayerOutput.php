<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - CE QUE LA FILE DU GUICHET PÈSE. Quatre chiffres, sur exactement les mêmes critères que la
      liste affichée dessous : même période, même planteur, même produit, même poste, même
      recherche. Un total qui ne décrirait pas la liste qu'on a sous les yeux serait pire qu'absent.

    - ILS NE S'ADDITIONNENT PAS ENTRE EUX, et c'est le point à ne pas perdre. Le reste est ce qu'on
      doit encore sortir ; le déjà-versé est sorti et confirmé ; l'en-vol a quitté la caisse SANS
      réponse de la passerelle et peut encore revenir. Les empiler donnerait un nombre qui ne veut
      rien dire.

    - L'EN-VOL EST LE PLUS UTILE DES QUATRE. C'est lui qui explique un tiroir plus léger que la
      liste ne le laisse croire : l'argent est parti, aucune pesée ne le compte comme versé.
*/
final class APayerOutput
{
    /**
     * Ce qu'il reste à verser sur la file, et sa ventilation par part.
     *
     * 'reste' est la somme des deux parts et non leur solde : un trop-perçu sur la récolte n'éponge
     * pas ce qui reste dû sur le transport, ni sur une autre pesée.
     *
     * @var array{reste: int, resteProduit: int, resteTransport: int, dejaVerse: int, enVol: int, nbPesees: int, nbSansPrix: int}
     */
    #[Groups(['read:Statistique'])]
    public array $totaux = [
        'reste' => 0,
        'resteProduit' => 0,
        'resteTransport' => 0,
        'dejaVerse' => 0,
        'enVol' => 0,
        'nbPesees' => 0,
        /*
            - LE SEUL BLOCAGE QU'ON SACHE COMPTER EXACTEMENT. Une pesée dont le produit n'a pas de
              prix ne vaut rien et ne quittera pas cette file tant qu'il n'est pas tarifé. Les
              autres motifs de refus tiennent à une règle à branches ('Operation::motifNonPayable')
              que recopier en SQL ferait diverger — ils se lisent ligne par ligne, pas en total.
        */
        'nbSansPrix' => 0,
    ];
}
