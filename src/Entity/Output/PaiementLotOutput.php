<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - CE QU'UN VERSEMENT GROUPÉ A ÉCRIT. Un objet, et non la liste brute des versements : une
      opération d'API Platform rend UN élément, et une liste n'a pas d'IRI à donner — la première
      tentative a répondu « Unable to generate an IRI », ce qui est la bonne réponse à une mauvaise
      forme.

    - CE QUE L'ÉCRAN EN FAIT : annoncer combien de pesées sont parties et pour quelle somme. Les
      RÉFÉRENCES suivent, parce que c'est ce que le guichet recopie sur un reçu quand un planteur
      conteste — un identifiant interne ne lui servirait de rien.

    - LES DEUX PARTS SONT SÉPARÉES, comme partout ailleurs : « 486 000 F » ne dit pas si le
      carburant a été payé, et c'est précisément la question que pose un planteur revenu le
      lendemain.
*/
final class PaiementLotOutput
{
    /**
     * @param list<string> $references
     */
    public function __construct(
        #[Groups(['read:Paiement'])]
        public int $nombre = 0,

        #[Groups(['read:Paiement'])]
        public int $montant = 0,

        #[Groups(['read:Paiement'])]
        public int $montantProduit = 0,

        #[Groups(['read:Paiement'])]
        public int $montantTransport = 0,

        #[Groups(['read:Paiement'])]
        public array $references = [],
    )
    {
    }
}
