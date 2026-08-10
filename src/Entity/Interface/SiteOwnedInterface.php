<?php

namespace App\Entity\Interface;

use App\Entity\Site;

/*
    - Marque une entité rattachée à un pont bascule. C'est ce marqueur qui permet de borner un
      opérateur aux seules caisses qu'il tient : le cloisonnement par entreprise ne suffirait pas,
      il verrait alors les pesées et les paiements de ses collègues.
*/
interface SiteOwnedInterface
{
    public function getSite(): ?Site;

    public function setSite(?Site $site): static;
}
