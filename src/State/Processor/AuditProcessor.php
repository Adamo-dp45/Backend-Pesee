<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\EntityBase;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Retient l'auteur d'une création ou d'une modification, puis délègue à la persistance native. C'est le contrepoids des permissions larges : on n'empêche pas un opérateur de tarifer, on retient qui a changé quoi.
    - Le processor par défaut de toute ressource qui n'a pas de métier propre.
*/

/**
 * @implements ProcessorInterface<mixed, mixed>
 */
class AuditProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor Persistance d'API Platform, injectée dans 'services.yaml'
     */
    public function __construct(
        private ProcessorInterface $processor,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $auteur = $this->security->getUser();

        if($data instanceof EntityBase && $auteur instanceof User) {
            $operation instanceof Post
                ? $data->setCreatedBy($auteur->getId())
                : $data->setUpdatedBy($auteur->getId());
        }

        return $this->processor->process($data, $operation, $uriVariables, $context);
    }
}
