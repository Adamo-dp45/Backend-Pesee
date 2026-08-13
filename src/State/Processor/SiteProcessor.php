<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\CompteMachineService;
use App\Entity\Site;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Déclarer un pont bascule le met en service : la création engendre le compte machine du poste, et la réponse porte ses identifiants — une seule fois.
    - Le mot de passe n'est porté que par l'objet en mémoire, le temps de la réponse. Il n'est stocké nulle part en clair : s'il est perdu, on le régénère.
*/

/**
 * @implements ProcessorInterface<Site, Site>
 */
class SiteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor
     */
    public function __construct(
        private ProcessorInterface $processor,
        private CompteMachineService $compteMachine,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $auteur = $this->security->getUser();

        if(!$operation instanceof Post) {
            if($auteur instanceof User) {
                $data->setUpdatedBy($auteur->getId());
            }

            return $this->processor->process($data, $operation, $uriVariables, $context);
        }

        if($data->getEntreprise() === null) {
            throw new UnprocessableEntityHttpException('Un pont bascule doit être rattaché à une entreprise.');
        }

        if($auteur instanceof User) {
            $data->setCreatedBy($auteur->getId());
        }

        $motDePasse = $this->compteMachine->creer($data);

        // L'identifiant ET le mot de passe : l'un sans l'autre ne permet pas de mettre le poste en service
        $data
            ->setIdentifiantMachine($this->compteMachine->identifiant($data))
            ->setMotDePasseMachine($motDePasse)
            ->setConfiguration($this->compteMachine->configuration($data, $motDePasse));

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
