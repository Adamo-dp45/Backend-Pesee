<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\Role;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Création et modification d'un compte humain.

    - Deux règles que ni le voter ni la garde ne portent, parce qu'elles dépendent du CORPS de la requête et non du compte visé :
        · le rôle demandé doit faire partie des rôles attribuables. Sans ce contrôle, un administrateur se fabriquerait un super administrateur, ou pire un compte 'ROLE_SITE' — c'est-à-dire un poste de pesée capable de déposer des pesées ;
        · l'entreprise du nouveau compte est celle de son créateur, jamais celle transmise dans la requête. Seul le super administrateur choisit.
*/

/**
 * @implements ProcessorInterface<User, User>
 */
class UserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor
     */
    public function __construct(
        private ProcessorInterface $processor,
        private UserPasswordHasherInterface $hasher,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $auteur = $this->security->getUser();
        $creation = $operation instanceof Post;

        if($creation) {
            $this->verifierLeRole($data); /*
                - Uniquement à la création : c'est le seul moment où l'appelant choisit un rôle. Le contrôler sur toute modification faisait échouer l'enregistrement de son propre profil pour un super administrateur, dont le rôle — qu'il n'avait pas touché — n'est pas dans la liste des attribuables
            */
        }

        if($auteur instanceof User) {
            if($creation) {
                $data->setCreatedBy($auteur->getId());

                if(!$auteur->aLeRole(Role::SUPER_ADMIN)) {
                    $data->setEntreprise($auteur->getEntreprise()); // Jamais celle transmise : c'est ce qui empêche de créer un compte chez un autre client
                }
            } else {
                $data->setUpdatedBy($auteur->getId());
            }
        }

        $enClair = $data->getPlainPassword();

        if($enClair !== null && $enClair !== '') {
            $data->setPassword($this->hasher->hashPassword($data, $enClair));
            $data->setPlainPassword(null);
        }

        if($creation && ($data->getPassword() === null || $data->getPassword() === '')) {
            throw new UnprocessableEntityHttpException('Un mot de passe est obligatoire à la création.');
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }

    /*
        - Liste blanche : administrateur, agent, opérateur. Un rôle est obligatoire — sans lui on créerait un compte incapable de se connecter, et l'erreur n'apparaîtrait qu'au premier essai de l'intéressé, sans rapport visible avec sa création.
    */
    private function verifierLeRole(User $data): void
    {
        $metier = array_diff($data->getRoles(), ['ROLE_USER']);

        if($metier === []) {
            throw new UnprocessableEntityHttpException('Un rôle est obligatoire : administrateur, agent ou opérateur.');
        }

        foreach($metier as $role) {
            if(!in_array($role, Role::valeursAttribuables(), true)) {
                throw new UnprocessableEntityHttpException(sprintf('Le rôle "%s" ne peut pas être attribué depuis cette interface.', $role));
            }
        }
    }
}
