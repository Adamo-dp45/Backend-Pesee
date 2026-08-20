<?php

namespace App\Validator;

use App\Domain\Support\NumeroMobile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class NumeroMobileValideValidator extends ConstraintValidator
{
    public function validate(mixed $valeur, Constraint $constraint): void
    {
        if(!$constraint instanceof NumeroMobileValide) {
            return;
        }

        /*
            - La règle de forme n'est PAS ici : elle vit dans 'NumeroMobile', d'où la normalisation la
              tire aussi. Les deux ne peuvent donc pas diverger — un numéro accepté par la validation
              est un numéro que la normalisation sait écrire.
        */
        if(NumeroMobile::estValide(is_string($valeur) ? $valeur : null)) {
            return;
        }

        $this->context->buildViolation($constraint->message)->addViolation();
    }
}
