<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/*
    - Unicité bornée au pont bascule, et seulement parmi les lignes VIVANTES. C'est ce qui distingue cette contrainte du '#[UniqueEntity]' de Symfony, qui ne sait pas ignorer la corbeille : avec lui, un produit supprimé bloquerait à jamais la recréation du même libellé.
    - Volontairement PAS d'index unique en base à côté : une ligne à la corbeille garde sa valeur, l'index la ferait entrer en collision avec la nouvelle.
*/
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueParSite extends Constraint
{
    public string $message = 'Cette valeur existe déjà sur ce pont bascule';

    public array $fields = [];

    public function __construct(
        array $fields = [],
        ?string $message = null,
        public ?string $errorPath = null, // Le champ que l'utilisateur voit ; à défaut le premier de 'fields'
        ?array $groups = null,
        mixed $payload = null,
    )
    {
        $this->fields = $fields;

        if($message) {
            $this->message = $message;
        }

        parent::__construct([], $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }

    /** @return string[] */
    public function getRequiredOptions(): array
    {
        return ['fields'];
    }
}
