<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\CompteMachineService;
use App\Entity\Site;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use Doctrine\ORM\EntityManagerInterface;

/*
    - Renouvelle les identifiants du poste : remplacement de matériel, fichier de configuration égaré, ou simplement mot de passe perdu — il n'est affiché qu'une fois.
    - Attention à ce que cela ne fait PAS : les jetons déjà émis restent valables jusqu'à leur expiration. Pour couper immédiatement un poste suspect, c'est le blocage du site qu'il faut utiliser, puisqu'il est vérifié à chaque envoi.
*/

/**
 * @implements ProcessorInterface<Site, Site>
 */
class RegenererIdentifiantsProcessor implements ProcessorInterface
{
    public function __construct(
        private CompteMachineService $compteMachine,
        private EntityManagerInterface $em,
        private ActiviteLogger $activites,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Site
    {
        $motDePasse = $this->compteMachine->regenerer($data);

        /*
            - Le renouvellement ne réécrit QUE le mot de passe : l'adresse du compte ne bouge pas,
              sans quoi un poste renommé perdrait son login au premier renouvellement. L'écran
              remet donc les deux, mais l'identifiant est celui que le compte porte déjà.
        */
        $data
            ->setIdentifiantMachine($this->compteMachine->identifiantDe($data))
            ->setMotDePasseMachine($motDePasse)
            ->setConfiguration($this->compteMachine->configuration($data, $motDePasse));

        /*
            - Le seul geste de l'application qui ne laissait rien du tout : pas meme un 'updatedBy'.
              Un poste qui cesse de synchroniser apres un renouvellement devenait alors une enigme.
        */
        $auteur = $this->security->getUser();

        $this->activites->log(
            TypeActivite::SITE_IDENTIFIANTS,
            sprintf(
                '%s a renouvele les identifiants du poste %s (%s)',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                (string) $data->getLibellesite(),
                (string) $data->getCode()
            ),
            $data
        );

        $this->em->flush();

        return $data;
    }
}
