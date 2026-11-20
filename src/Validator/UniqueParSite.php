<?php

namespace App\Validator;

/* Unique au sein d'un pont bascule : c'est le cas du PLANTEUR, qui livre à une barrière précise. Deux hommes du même nom à deux postes sont deux hommes. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueParSite extends UniqueParPerimetre
{
    public string $message = 'Cette valeur existe déjà sur ce pont bascule';

    public function champDuPerimetre(): string
    {
        return 'site';
    }
}
