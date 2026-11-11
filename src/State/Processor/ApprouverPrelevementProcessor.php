<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Entity\DemandePrelevement;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Approuver ouvre le robinet : à partir de là, chaque pesée du planteur mettra une part de côté.
      Aucun argent ne bouge ici — c'est la différence avec l'approbation d'une demande de solde, qui
      dote une caisse sur-le-champ.

    - Et c'est précisément pour cela qu'on JOURNALISE. Une dotation laisse deux écritures au grand
      livre, qui racontent tout ; une ouverture de prélèvement n'y laisse rien. Sans la ligne du
      journal, personne ne saurait qui a décidé que ce planteur toucherait moins au guichet.

    - Le taux appliqué est CELUI QUI A ÉTÉ DEMANDÉ. L'opération n'accepte aucun corps : le chiffre
      vient du planteur, par la bouche de l'opérateur, et le retoucher depuis un bureau reviendrait
      à décider de l'épargne de quelqu'un d'autre.
*/

/**
 * @implements ProcessorInterface<mixed, DemandePrelevement>
 */
class ApprouverPrelevementProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DemandePrelevementRepository $demandes,
        private ActiviteLogger $activites,
        private Security $security
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandePrelevement
    {
        $demande = $this->demandes->find($uriVariables['id'] ?? 0);

        if(!$demande instanceof DemandePrelevement) {
            throw new NotFoundHttpException('Cette demande est introuvable.');
        }

        if($demande->estTraitee()) {
            throw new UnprocessableEntityHttpException('Cette demande a déjà été traitée.');
        }

        $planteur = $demande->getFournisseur();

        if($planteur === null) {
            throw new UnprocessableEntityHttpException('Cette demande ne vise aucun planteur.');
        }

        $taux = $demande->getTauxDemande();
        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $planteur->setTauxEpargne($taux);

        $demande
            ->setStatut(StatutDemande::APPROUVEE)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        // Avant le flush : la trace part dans la même transaction que la décision
        $this->activites->log(
            TypeActivite::FOURNISSEUR_PRELEVEMENT,
            sprintf(
                '%s a ouvert une mise en compte de %d F par kilo pour %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le système',
                $taux,
                $planteur->getNomComplet()
            ),
            $planteur
        );

        $this->em->flush();

        return $demande;
    }
}
