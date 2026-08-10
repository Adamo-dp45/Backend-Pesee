<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\CompteMachineService;
use App\Entity\Site;
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
        private EntityManagerInterface $em
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Site
    {
        $motDePasse = $this->compteMachine->regenerer($data);

        $data
            ->setMotDePasseMachine($motDePasse)
            ->setConfiguration($this->compteMachine->configuration($data, $motDePasse));

        $this->em->flush();

        return $data;
    }
}
