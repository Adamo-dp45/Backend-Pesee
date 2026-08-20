<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/*
    - Refuse un numéro que l'agrégateur mobile money ne saurait pas joindre. Elle s'applique aux TROIS
      champs qui finissent par lui être transmis : les deux contacts d'un planteur et le numéro de
      circonstance d'un versement.

    - Une contrainte plutôt qu'un '#[Assert\Regex]' recopié trois fois : la règle de forme vit dans
      'NumeroMobile', et une seule chose doit changer le jour où le produit se déploie hors de Côte
      d'Ivoire. Trois expressions régulières identiques, c'est trois occasions d'en oublier une.

    - Un champ VIDE passe : l'absence de numéro n'est pas une faute de saisie. C'est un autre
      problème, dit à sa place par 'Fournisseur::getMotifNonPayable()'.
*/
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NumeroMobileValide extends Constraint
{
    /*
        - Le message montre un EXEMPLE. « Numéro invalide » n'apprend rien à quelqu'un qui vient de
          taper son numéro exactement comme il le lit sur son téléphone.
    */
    public string $message = 'Numéro mobile non reconnu. Attendu : dix chiffres, avec ou sans indicatif — par exemple 07 01 02 03 04 ou +225 0701020304';

    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    )
    {
        if($message) {
            $this->message = $message;
        }

        parent::__construct([], $groups, $payload);
    }
}
