<?php

namespace App\Validator;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class UniqueParSiteValidator extends ConstraintValidator
{
    public function __construct(
        private EntityManagerInterface $em
    )
    {
    }

    public function validate(mixed $entity, Constraint $constraint): void
    {
        if(!$constraint instanceof UniqueParSite || !is_object($entity) || !method_exists($entity, 'getSite')) {
            return;
        }

        $site = $entity->getSite();

        if($site === null) {
            return; // Le site est obligatoire par ailleurs : ce n'est pas à cette contrainte de le dire
        }

        $criteres = ['site' => $site];

        foreach($constraint->fields as $champ) {
            $getter = 'get' . ucfirst($champ);

            if(!method_exists($entity, $getter)) {
                return;
            }

            $valeur = $entity->$getter();

            if($valeur === null || $valeur === '') {
                return; // Champ vide : rien à comparer
            }

            $criteres[$champ] = $valeur;
        }

        /*
            - Le point de toute la contrainte : on ne compare qu'aux lignes vivantes. Une ligne à la corbeille garde sa valeur mais ne bloque plus rien — supprimer un produit puis le recréer, ou laisser le poste le renvoyer, redevient possible.
        */
        if($this->em->getClassMetadata($entity::class)->hasField('deletedAt')) {
            $criteres['deletedAt'] = null;
        }

        $existant = $this->em->getRepository($entity::class)->findOneBy($criteres);

        if($existant === null) {
            return;
        }

        // Modification : on s'exclut soi-même
        if(method_exists($entity, 'getId') && $entity->getId() !== null && $existant->getId() === $entity->getId()) {
            return;
        }

        $this->context
            ->buildViolation($constraint->message)
            ->atPath($constraint->errorPath ?? $constraint->fields[0]) /*
                - La clé normalisée n'est pas un champ du formulaire : sans 'errorPath', la violation atterrit sur 'cle' et le frontend n'a rien où l'afficher
            */
            ->addViolation();
    }
}
