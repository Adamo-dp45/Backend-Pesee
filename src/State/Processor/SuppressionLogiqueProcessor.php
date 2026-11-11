<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\EntityBase;
use App\Entity\Fournisseur;
use App\Entity\User;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Suppression logique : on marque, on n'efface pas. Les pesées référencent leurs produits et leurs planteurs, et justifient des paiements — une suppression physique casserait l'historique. 'PerimetreExtension' masque la ligne, la base la garde.
    - Verbe dédié plutôt que DELETE : le poste recréera le référentiel à la synchronisation suivante s'il existe encore de son côté. Ce n'est donc qu'un masquage côté web.
*/

/**
 * @implements ProcessorInterface<EntityBase, EntityBase>
 */
class SuppressionLogiqueProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private ActiviteLogger $activites
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if(!$data instanceof EntityBase) {
            return $data; // 'User' n'en hérite pas : un compte se suspend, il ne se met pas à la corbeille
        }

        /*
            - Un planteur dont l'épargne n'est pas vide ne se met pas à la corbeille : le cloisonnement le ferait disparaître de tous les écrans, et son argent avec lui. Tant qu'il n'existe pas de retrait, il n'y a aucun moyen de le lui rendre après coup.
        */
        if($data instanceof Fournisseur && $data->getSoldeEpargne() > 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Ce planteur a %s F CFA sur son compte d\'épargne : il ne peut pas être mis à la corbeille tant que cette somme ne lui a pas été rendue.',
                number_format($data->getSoldeEpargne(), 0, ',', ' ')
            ));
        }

        $auteur = $this->security->getUser();

        $data->setDeletedAt(new \DateTimeImmutable());

        if($auteur instanceof User) {
            $data->setDeletedBy($auteur->getId());
        }

        /*
            - 'deletedBy' et 'deletedAt' disent qui a supprime — mais la restauration les efface et la
              purge emporte la ligne entiere. Le journal, lui, garde le souvenir du geste.
        */
        $this->activites->log(
            TypeActivite::CORBEILLE_SUPPRESSION,
            sprintf(
                '%s a mis a la corbeille %s %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                strtolower(substr(strrchr($data::class, chr(92)), 1)),
                (string) $data
            ),
            $data
        );

        $this->em->flush();

        return $data;
    }
}
