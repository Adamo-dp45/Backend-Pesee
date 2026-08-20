<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\Role;
use App\Entity\Input\AssignerOperateurInput;
use App\Entity\Site;
use App\Entity\User;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Affecte l'opérateur qui tiendra la caisse d'un pont bascule.
    - Les contrôles ne relèvent pas du voter : celui-ci dit qui a le droit d'affecter, pas si la personne affectée est un choix valable. Affecter l'opérateur d'un autre client lui ouvrirait un périmètre de données qui ne le regarde pas.
*/

/**
 * @implements ProcessorInterface<AssignerOperateurInput, Site>
 */
class AssignerOperateurProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private SiteRepository $siteRepository,
        private UserRepository $userRepository,
        private Security $security,
        private ActiviteLogger $activites
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Site
    {
        $site = $this->siteRepository->find($uriVariables['id'] ?? 0);

        if(!$site instanceof Site) {
            throw new NotFoundHttpException('Ce pont bascule est introuvable.');
        }

        $site->setOperateur($data->operateurId === null ? null : $this->operateurValide($data->operateurId, $site)); /*
            - 'null' libère le site : il reste consultable, mais plus personne n'y encaisse
        */

        $auteur = $this->security->getUser();

        if($auteur instanceof User) {
            $site->setUpdatedBy($auteur->getId());
        }

        $this->activites->log(
            TypeActivite::SITE_OPERATEUR,
            sprintf(
                '%s a %s sur le pont bascule %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le système',
                $site->getOperateur() === null
                    ? 'libéré la caisse'
                    : 'confié la caisse à ' . $site->getOperateur()->getNomComplet(),
                (string) $site->getLibellesite()
            ),
            $site
        );

        $this->em->flush();

        return $site;
    }

    private function operateurValide(int $operateurId, Site $site): User
    {
        $operateur = $this->userRepository->find($operateurId);

        if($operateur === null) {
            throw new NotFoundHttpException('Cet utilisateur est introuvable.');
        }

        if(!$operateur->aLeRole(Role::OPERATEUR)) {
            throw new UnprocessableEntityHttpException('Seul un utilisateur ayant le rôle opérateur peut tenir la caisse d\'un pont bascule.');
        }

        if($operateur->getEntreprise()?->getId() !== $site->getEntreprise()?->getId()) {
            throw new UnprocessableEntityHttpException('Cet utilisateur appartient à une autre entreprise.');
        }

        if(!$operateur->estActif()) {
            throw new UnprocessableEntityHttpException('Cet utilisateur est suspendu : il ne peut pas se voir confier une caisse.');
        }

        return $operateur;
    }
}
