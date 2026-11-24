<?php

namespace App\Entity\Input;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\PartPesee;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - PLUSIEURS PESÉES D'UN MÊME PLANTEUR, EN UN GESTE. Au guichet, un planteur se présente avec
      trois tickets : les régler un par un, c'est trois fois l'écran et trois fois le risque d'en
      oublier un.

    - AUCUN MONTANT NE SE SAISIT, et c'est la décision qui tient tout le reste : cocher une pesée,
      c'est la régler EN ENTIER. Il n'y a donc pas de répartition à décider — ni ici, ni à l'écran,
      ni dans la tête de l'opérateur — et aucune façon de se tromper d'un franc.

    - LA PART EST LE SEUL CHOIX : la récolte seule, le transport seul, ou les deux. C'est le besoin
      réel du guichet, où un planteur réclame parfois son carburant le jour même et sa récolte plus
      tard. 'null' veut dire « les deux », et c'est le cas courant.

    - PAS DE NUMÉRO DE DESTINATAIRE : un versement groupé se règle en espèces ou par chèque. Trois
      envois mobiles sous une seule transaction, dont le deuxième échoue, laisseraient un lot à
      moitié parti — le service le refuse, et ce champ absent dit la même chose à la lecture.
*/
final class PaiementLotInput
{
    /**
     * @var list<int>
     */
    #[Assert\NotBlank(message: 'Choisissez au moins une pesée à régler')]
    #[Assert\Count(min: 1, max: 50, maxMessage: 'Pas plus de {{ limit }} pesées en un seul versement : au-delà, la liste ne se vérifie plus à l\'œil.')]
    #[Assert\All([new Assert\Positive(message: 'Identifiant de pesée invalide')])]
    #[Groups(['write:Paiement:lot'])]
    public array $operationIds = [];

    /* Nul pour les deux parts, ce qui est le cas courant. */
    #[Groups(['write:Paiement:lot'])]
    public ?PartPesee $part = null;

    #[Assert\NotNull(message: 'Le mode de règlement est obligatoire')]
    #[Assert\Choice(callback: [ModeReglement::class, 'toutes'], message: 'Ce mode de règlement n\'est pas ouvert.')]
    #[Groups(['write:Paiement:lot'])]
    public ?ModeReglement $modeReglement = null;

    #[Assert\Length(max: 50)]
    #[Groups(['write:Paiement:lot'])]
    public ?string $numeroCheque = null;
}
