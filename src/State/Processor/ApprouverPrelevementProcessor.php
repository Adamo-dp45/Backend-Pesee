<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use App\Domain\Support\TauxPrelevement;
use App\Entity\DemandePrelevement;
use App\Entity\Prelevement;
use App\Entity\User;
use App\Repository\DemandePrelevementRepository;
use App\Repository\PrelevementRepository;
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

    - Un prélèvement par PRODUIT : la ligne ouverte ici ne touchera que les pesées de ce produit-là.
      Le planteur peut en avoir d'autres, ouvertes à d'autres moments, sur d'autres cultures.

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
        private PrelevementRepository $prelevements,
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
        $produit = $demande->getProduit();

        if($planteur === null || $produit === null) {
            throw new UnprocessableEntityHttpException('Cette demande ne vise pas un planteur et un produit.');
        }

        $taux = $demande->getTauxDemande();

        /*
            - LA RÈGLE EST REVÉRIFIÉE ICI, et ce n'est pas une redondance : la demande a pu être
              déposée quand le cacao valait 100 F et n'être approuvée qu'après une baisse à 40. Le
              prix qui compte est celui du jour où l'on ouvre le robinet, pas celui du jour où on l'a
              demandé.
        */
        if(($motif = TauxPrelevement::motifDuRefus($produit, $taux)) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }

        if($this->prelevements->findActifPour($planteur, $produit) !== null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Un prélèvement est déjà en cours pour ce planteur sur « %s ». Arrêtez-le avant d\'en ouvrir un autre.',
                (string) $produit->getLibelle()
            ));
        }

        $auteur = $this->security->getUser();
        $auteur = $auteur instanceof User ? $auteur : null;

        $planteur->ajouterPrelevement(
            (new Prelevement())
                ->setProduit($produit)
                ->setTaux($taux)
                ->setCreatedBy($auteur?->getId())
        );

        $demande
            ->setStatut(StatutDemande::APPROUVEE)
            ->setTraitePar($auteur)
            ->setTraiteAt(new \DateTimeImmutable())
            ->setUpdatedBy($auteur?->getId());

        // Avant le flush : la trace part dans la même transaction que la décision
        $this->activites->log(
            TypeActivite::FOURNISSEUR_PRELEVEMENT,
            sprintf(
                /* « sur Hévéa » et non « de Hévéa » : la seconde forme demanderait l'élision — « d'hévéa », « de cacao » — et un nom de produit est saisi librement, aucun 'sprintf' ne peut la deviner. Même tournure dans 'BasculerPrelevementProcessor', pour que le journal se lise d'une seule façon. */
                '%s a ouvert une mise en compte de %d F par kilo sur %s pour %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le système',
                $taux,
                (string) $produit->getLibelle(),
                $planteur->getNomComplet()
            ),
            $planteur
        );

        $this->em->flush();

        return $demande;
    }
}
