<?php

namespace App\Validator;

/* Unique au sein d'une entreprise : c'est le cas du PRODUIT, pour qu'un cacao soit un cacao sur tous ses ponts bascules. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueParEntreprise extends UniqueParPerimetre
{
    public string $message = 'Cette valeur existe déjà dans cette entreprise';

    public function champDuPerimetre(): string
    {
        return 'entreprise';
    }
}
