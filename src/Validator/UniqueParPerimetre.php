<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/*
    - Unicité bornée à un PÉRIMÈTRE, et seulement parmi les lignes VIVANTES. C'est ce qui distingue
      ces contraintes du '#[UniqueEntity]' de Symfony, qui ne sait pas ignorer la corbeille : avec
      lui, un produit supprimé bloquerait à jamais la recréation du même libellé.

    - Volontairement PAS d'index unique en base à côté : une ligne à la corbeille garde sa valeur,
      l'index la ferait entrer en collision avec la nouvelle.

    - DEUX PÉRIMÈTRES, UN SEUL CODE. Le planteur est unique par pont bascule, le produit par
      entreprise, et c'est la seule chose qui les sépare. Un second validateur recopié aurait
      dupliqué la ligne qui ignore la corbeille et celle qui déplace la violation vers un champ
      visible — exactement les deux qu'on ne voudrait pas voir diverger.
*/
abstract class UniqueParPerimetre extends Constraint
{
    public string $message = 'Cette valeur existe déjà';

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

    /** La propriété qui borne la comparaison : 'site' ou 'entreprise'. */
    abstract public function champDuPerimetre(): string;

    public function validatedBy(): string
    {
        return UniqueParPerimetreValidator::class;
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
