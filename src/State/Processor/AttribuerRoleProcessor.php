<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Input\AttribuerRoleInput;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserGuard;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
    - Changer le rôle d'un compte, et rien d'autre. Verbe dédié plutôt qu'un champ de la modification : mélangé au reste, corriger son nom suffisait à se promouvoir.
    - Le voter a déjà dit qui peut agir sur qui. Reste ici ce qui dépend du rôle DEMANDÉ, que 'UserGuard' tranche.
*/

/**
 * @implements ProcessorInterface<AttribuerRoleInput, User>
 */
class AttribuerRoleProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private UserGuard $userGuard,
        private UserRepository $userRepository,
        private ActiviteLogger $activites
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        $cible = $this->userRepository->find($uriVariables['id'] ?? 0); /*
            - Et surtout PAS '$context['previous_data']' : API Platform y range un CLONE de l'élément lu, pas l'entité gérée. On le modifiait, le 'flush()' ne voyait rien, et la réponse affichait pourtant le nouveau rôle — l'appelant croyait la promotion faite. Ici Doctrine renvoie l'instance déjà chargée par le provider, sans requête supplémentaire.
        */

        if(!$cible instanceof User) {
            throw new NotFoundHttpException('Compte introuvable.');
        }

        $auteur = $this->security->getUser();
        $role = (string) $data->role;

        if($auteur instanceof User) {
            $this->userGuard->assertPeutAttribuerRole($auteur, $role);
            $cible->setUpdatedBy($auteur->getId());
        }

        // Lu AVANT l'ecrasement : c'est le couple ancien -> nouveau qui fait la valeur de la trace
        $ancien = $cible->getRolePrincipal()?->libelle() ?? 'aucun role';

        $cible->setRoles([$role]);

        $this->activites->log(
            TypeActivite::USER_ROLE,
            sprintf(
                '%s a change le role de %s : %s -> %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                $cible->getNomComplet(),
                $ancien,
                $cible->getRolePrincipal()?->libelle() ?? $role
            ),
            $cible
        );

        $this->em->flush();

        return $cible;
    }
}
