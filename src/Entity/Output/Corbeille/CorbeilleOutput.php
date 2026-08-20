<?php

namespace App\Entity\Output\Corbeille;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Ce que rend '/api/corbeille'. Les compteurs sont EXACTS et couvrent toute la corbeille ; la
      liste, elle, est plafonnée. Sans cette distinction, un écran affichant « 200 éléments » alors
      qu'il en montre 100 laisserait croire à une perte.
*/
final class CorbeilleOutput
{
    /**
     * Nombre d'éléments par type, sur l'ensemble de la corbeille filtrée.
     *
     * @var array<string, int>
     */
    #[Groups(['read:Corbeille'])]
    public array $totaux = [];

    #[Groups(['read:Corbeille'])]
    public int $total = 0;

    /**
     * @var list<CorbeilleItemDto>
     */
    #[Groups(['read:Corbeille'])]
    public array $elements = [];

    /** Vrai quand la liste a été tronquée : l'écran doit le dire plutôt que de laisser croire au compte. */
    #[Groups(['read:Corbeille'])]
    public bool $tronquee = false;

    /**
     * Les entreprises représentées dans la corbeille, pour alimenter le filtre.
     *
     * @var array<int, string>
     */
    #[Groups(['read:Corbeille'])]
    public array $entreprises = [];
}
