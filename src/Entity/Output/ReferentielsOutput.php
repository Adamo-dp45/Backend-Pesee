<?php

namespace App\Entity\Output;

use Symfony\Component\Serializer\Attribute\Groups;

/*
    - Les valeurs distinctes qui alimentent les listes déroulantes de la page Bilan, bornées au
      périmètre de l'utilisateur.

    - Remplace le proxy du frontend, qui interrogeait les routes du logiciel de pesée réservées
      aux comptes machine : il recevait donc 403 et renvoyait silencieusement une liste vide,
      laissant tous les filtres désespérément vides à l'écran.
*/
final class ReferentielsOutput
{
    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $mouvement = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $client = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $destination = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $provenance = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $transporteur = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $chauffeur = [];

    /** @var list<string> */
    #[Groups(['read:Statistique'])]
    public array $vehicule = [];
}
