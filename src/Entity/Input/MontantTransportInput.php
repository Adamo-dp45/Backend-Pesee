<?php

namespace App\Entity\Input;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/*
    - LE MONTANT DU TRANSPORT ÉCRIT À LA MAIN SUR UNE PESÉE : un détour imprévu, une route coupée,
      un second voyage. Rien de tout cela ne se range dans un barème sans être donné à tous ceux
      qui le suivent.

    - UN MONTANT, ET NON UN PRIX AU KILO, parce que c'est le seul chiffre qui ait un sens dans les
      deux modes de facturation : un forfait n'a pas de prix unitaire.

    - ZÉRO EST ACCEPTÉ, contrairement au prix de la récolte, et la différence est réelle :
      « finalement on ne lui paie pas ce trajet » est une décision courante, alors qu'une récolte à
      zéro franc n'en est pas une.
*/
final class MontantTransportInput
{
    #[Assert\NotNull(message: 'Le montant du transport est obligatoire')]
    #[Assert\PositiveOrZero(message: 'Le montant du transport ne peut pas être négatif')]
    #[Groups(['write:Operation:transport'])]
    public ?int $montant = null;

    #[Assert\Length(max: 500)]
    #[Groups(['write:Operation:transport'])]
    public ?string $motif = null;
}
