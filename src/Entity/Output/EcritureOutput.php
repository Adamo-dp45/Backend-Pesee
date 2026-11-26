<?php

namespace App\Entity\Output;

use App\Entity\MouvementCaisse;
use Symfony\Component\Serializer\Attribute\Groups;

/*
    - CE QUE LE GESTE A ÉCRIT AU GRAND LIVRE. Recharger et doter rendaient l'entreprise ou le site,
      c'est-à-dire un SOLDE — ce qui se lit très bien mais ne dit pas quelle ligne vient de naître.
      Le web n'avait donc aucune prise pour y rattacher un bordereau : il connaissait le résultat
      sans connaître l'écriture.

    - UNE DOTATION EN ÉCRIT DEUX, et c'est le propre du geste : l'argent sort de l'entreprise et
      entre dans le poste. 'id' porte CELLE QUI REÇOIT, 'contrepartieId' celle qui donne.
      Pourquoi ce sens-là : le justificatif suit l'argent jusqu'à celui qui le prend en charge, et
      l'opérateur voit les écritures de son poste quand la caisse de l'entreprise lui est fermée.
      L'administrateur, lui, voit les deux de toute façon — c'est donc le choix le plus large sans
      ouvrir quoi que ce soit à qui n'y a pas droit.

    - Une recharge n'a pas de contrepartie : l'argent vient de l'extérieur, 'contrepartieId' est nul.
*/
final class EcritureOutput
{
    public function __construct(
        #[Groups(['read:Ecriture'])]
        public int $id = 0,

        #[Groups(['read:Ecriture'])]
        public ?int $contrepartieId = null,

        #[Groups(['read:Ecriture'])]
        public string $caisse = '',

        #[Groups(['read:Ecriture'])]
        public int $montant = 0,

        /* Le solde du compte DE CETTE écriture après coup : c'est ce que l'écran annonce sans avoir à relire la caisse */
        #[Groups(['read:Ecriture'])]
        public int $soldeApres = 0,
    )
    {
    }

    public static function depuis(MouvementCaisse $ecriture, ?MouvementCaisse $contrepartie = null): self
    {
        return new self(
            id: (int) $ecriture->getId(),
            contrepartieId: $contrepartie?->getId(),
            caisse: $ecriture->getCaisse()->value,
            montant: $ecriture->getMontant(),
            soldeApres: $ecriture->getSoldeApres(),
        );
    }
}
