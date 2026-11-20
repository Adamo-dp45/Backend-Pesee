<?php

namespace App\Entity\Input;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\ReseauMobile;
use App\Validator\NumeroMobileValide;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - Servir au guichet un retrait déjà accordé. Le MONTANT N'Y FIGURE PAS, et c'est voulu : il a
      été décidé par l'administrateur, et le laisser saisir ici rendrait l'accord décoratif.

    - Le reste est exactement ce que demande un versement sur pesée — le mode décide de la caisse,
      le numéro ne vaut que pour le mobile, le numéro de chèque ne vaut que pour le chèque — parce
      que c'est le même circuit qui règle les deux.
*/
final class VersementRetraitInput
{
    #[Assert\NotNull(message: 'Le mode de règlement est obligatoire')]
    #[Groups(['write:Retrait:versement'])]
    /* Même garde que sur un versement de pesée : un mode fermé ne s'ouvre pas par la requête. */
    #[Assert\Choice(callback: [ModeReglement::class, 'toutes'], message: 'Ce mode de règlement n\'est pas ouvert.')]
    public ?ModeReglement $modeReglement = null;

    #[Assert\Length(max: 30)]
    #[NumeroMobileValide]
    #[Groups(['write:Retrait:versement'])]
    public ?string $numeroDestinataire = null;

    #[Groups(['write:Retrait:versement'])]
    public ?ReseauMobile $reseau = null;

    #[Assert\Length(max: 50)]
    #[Groups(['write:Retrait:versement'])]
    public ?string $numeroCheque = null;
}
