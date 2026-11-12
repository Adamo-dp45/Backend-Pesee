<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Le croisement site × produit sur une période, qui alimente la page Bilan et ses exports.
*/
final class ProductionOutput
{
    /** @var array{du: string|null, au: string|null} */
    #[Groups(['read:Statistique'])]
    public array $periode = ['du' => null, 'au' => null];

    /** @var list<array{site: string, produit: string, tonnageNet: int, montantDu: int, montantPaye: int, nbPesees: int}> */
    #[Groups(['read:Statistique'])]
    public array $lignes = [];

    /** @var array{nbPesees: int, tonnageNet: int, montantDu: int, montantPaye: int, montantRestant: int} */
    #[Groups(['read:Statistique'])]
    public array $totaux = [
        'nbPesees' => 0,
        'tonnageNet' => 0,
        'montantDu' => 0,
        'montantTransport' => 0,
        'retenue' => 0,
        'montantPaye' => 0,
        'montantRestant' => 0,
    ];
}
