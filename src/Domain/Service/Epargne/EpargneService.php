<?php

namespace App\Domain\Service\Epargne;

use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\TypeMouvementEpargne;
use App\Entity\CompteEpargne;
use App\Entity\Fournisseur;
use App\Entity\MouvementEpargne;
use App\Entity\Operation;
use App\Entity\User;
use App\Repository\CompteEpargneRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Le SEUL endroit qui touche à l'épargne d'un planteur, sur le modèle exact de 'CaisseService' :
      verrou sur la ligne du compte avant de lire son solde, écriture chaînée portant le solde avant
      et après, et aucun autre chemin pour modifier le solde.

    - Il ARBITRE la retenue d'une pesée, et l'arbitrage est définitif. Une pesée arbitrée ne repasse
      jamais ici : c'est ce qui garantit qu'un lot rejoué par le poste — ce qui arrive dès qu'un
      accusé se perd — ne crédite pas deux fois le même planteur.

    - Il arbitre AUSSI les pesées des planteurs qui n'épargnent pas, en y inscrivant une retenue
      nulle. Cela paraît inutile et c'est pourtant ce qui fait tenir la règle « seulement les pesées
      à venir » : une pesée déjà close à zéro ne sera jamais reprise le jour où le planteur ouvrira
      un prélèvement.

    - Aucune caisse n'est touchée. Au moment du prélèvement, le planteur n'est pas encore passé au
      guichet : pas un billet n'a bougé, on ne fait que couper en deux ce que l'entreprise lui doit.
*/
class EpargneService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompteEpargneRepository $comptes,
        private Security $security
    )
    {
    }

    /**
     * Arbitre une pesée isolée, dans sa propre transaction.
     */
    public function preleverSur(Operation $pesee): ?MouvementEpargne
    {
        return $this->em->wrapInTransaction(fn(): ?MouvementEpargne => $this->arbitrer($pesee));
    }

    /*
        - Arbitre tout un lot. Suppose la transaction DÉJÀ OUVERTE, celle de la synchronisation :
          imbriquer deux 'wrapInTransaction' est à proscrire, DBAL ne pose pas de point de reprise
          par défaut et l'échec intérieur rend le diagnostic de l'extérieur illisible.

        - Les pesées sont triées par planteur avant d'écrire, pour que deux lots concurrents
          prennent toujours leurs verrous dans le même ordre. Sans cela, deux lots qui se recoupent
          s'attendent mutuellement. C'est la même discipline que « toujours l'entreprise PUIS le
          site » du service de caisse.
    */
    /**
     * @param list<Operation> $pesees
     *
     * @return int le nombre d'écritures passées
     */
    public function preleverSurLot(array $pesees): int
    {
        usort(
            $pesees,
            static fn(Operation $a, Operation $b): int => ($a->getFournisseur()?->getId() ?? 0) <=> ($b->getFournisseur()?->getId() ?? 0)
        );

        $ecrites = 0;

        foreach($pesees as $pesee) {
            if($this->arbitrer($pesee) instanceof MouvementEpargne) {
                $ecrites++;
            }
        }

        return $ecrites;
    }

    /*
        - Tranche le sort d'une pesée, une fois pour toutes. Renvoie l'écriture passée, ou null quand
          il n'y avait rien à mettre de côté — ce qui reste un arbitrage : la pesée en ressort close.
    */
    private function arbitrer(Operation $pesee): ?MouvementEpargne
    {
        if(!$pesee->attendUnArbitrage()) {
            return null;
        }

        $planteur = $pesee->getFournisseur();

        if($planteur === null) {
            return null; // Déjà exclu par le prédicat, gardé pour l'analyseur statique
        }

        /*
            - Le taux vient du prélèvement ouvert SUR LE PRODUIT PESÉ, et d'aucun autre. Un planteur
              qui épargne sur son cacao ne doit rien voir retenu sur une livraison d'hévéa : ce sont
              deux décisions distinctes, et il n'en a pris qu'une.

            - Pas de prélèvement sur ce produit, ou aucun du tout : on ferme quand même la pesée à
              zéro. C'est ce qui implémente « seulement les pesées à venir » sans comparaison de
              dates, et ouvrir un prélèvement ensuite ne reprendra pas celle-ci.
        */
        $prelevement = $planteur->prelevementPour($pesee->getProduit());
        $taux = $prelevement?->getTaux() ?? 0;

        if($taux <= 0) {
            $pesee->figerLaRetenue(null, 0);
            $this->rafraichirLeReglement($pesee);

            return null;
        }

        /*
            - Plafonnée à ce que vaut la RÉCOLTE : un prélèvement ne doit JAMAIS créer de dette du
              planteur envers l'entreprise, et il ne mord pas sur le transport, que le planteur a
              avancé de sa poche.

            - Le taux est pourtant déjà tenu sous le prix du produit à l'ouverture ('TauxPrelevement'),
              donc ce plafond ne devrait jamais mordre. Il reste parce que le prix du produit peut
              BAISSER après coup : un prélèvement ouvert à 50 F sur un produit tombé à 40 prendrait
              alors tout. Le planteur repart sans liquide — mais sans rien devoir.
        */
        $retenue = min($taux * (int) $pesee->getPoidsnet(), (int) $pesee->getMontantdu());

        if($retenue <= 0) {
            $pesee->figerLaRetenue($taux, 0);
            $this->rafraichirLeReglement($pesee);

            return null;
        }

        $compte = $this->compteDe($planteur);
        $this->verrouiller($compte);

        $avant = $compte->getSolde();
        $apres = $avant + $retenue;

        $compte->setSolde($apres);

        $pesee->figerLaRetenue($taux, $retenue);
        $this->rafraichirLeReglement($pesee);

        return $this->tracer(
            (new MouvementEpargne())
                ->setCompte($compte)
                ->setOperation($pesee)
                ->setType(TypeMouvementEpargne::PRELEVEMENT)
                ->setSens(TypeMouvementEpargne::PRELEVEMENT->sens())
                ->setMontant($retenue)
                ->setSoldeAvant($avant)
                ->setSoldeApres($apres)
                // Le produit est dans le motif : le livre d'épargne mélange les cultures, et c'est par elles que le planteur raisonne quand il conteste une ligne
                ->setMotif(sprintf(
                    'Mise en compte sur la pesée %s (%s)',
                    (string) $pesee->getNumticket(),
                    (string) $pesee->getProduit()?->getLibelle()
                ))
                ->setEntreprise($compte->getEntreprise())
                ->setSite($compte->getSite())
        );
    }

    /*
        - Le montant payable vient de changer, donc l'état du règlement aussi : une pesée dont
          l'épargne a tout absorbé n'a plus rien à verser au guichet et doit le dire. 'calculerMontant()'
          ne le ferait pas — il revient sans rien faire tant que le montant dû n'a pas bougé.
    */
    private function rafraichirLeReglement(Operation $pesee): void
    {
        $pesee->setStatutReglement(
            StatutReglement::pour($pesee->getMontantdu(), $pesee->getMontantpayable(), $pesee->getMontantpaye())
        );
    }

    /*
        - Le compte du planteur, créé au premier prélèvement et pas avant. Il est écrit en base
          immédiatement : le verrou qui suit porte sur une ligne, et une ligne qui n'existe pas ne se
          verrouille pas.
    */
    private function compteDe(Fournisseur $planteur): CompteEpargne
    {
        if(($compte = $this->comptes->pourFournisseur($planteur)) !== null) {
            return $compte;
        }

        $site = $planteur->getSite();

        $compte = (new CompteEpargne())
            ->setFournisseur($planteur)
            ->setSite($site)
            ->setEntreprise($site?->getEntreprise());

        $this->em->persist($compte);
        $this->em->flush();

        return $compte;
    }

    /*
        - 'SELECT … FOR UPDATE' sur la ligne du compte. Sans lui, deux lots simultanés lisent le même
          solde et le second écrase le premier : le planteur perd une mise en compte, et la chaîne
          des soldes le montre sans qu'on sache laquelle.
    */
    private function verrouiller(CompteEpargne $compte): void
    {
        $this->em->refresh($compte, LockMode::PESSIMISTIC_WRITE);
    }

    /*
        - L'auteur est rattaché par RÉFÉRENCE et non par l'objet du jeton de sécurité. Celui-ci peut
          très bien être détaché de l'EntityManager — il suffit qu'un 'clear()' soit passé entre
          l'authentification et l'écriture — et Doctrine le prendrait alors pour un compte neuf
          qu'on tente de créer en même temps que l'écriture. Une référence est toujours gérée, ne
          coûte aucune requête, et l'auteur n'est jamais perdu.
    */
    private function tracer(MouvementEpargne $ecriture): MouvementEpargne
    {
        $auteur = $this->security->getUser();

        if($auteur instanceof User && $auteur->getId() !== null) {
            $ecriture
                ->setEffectuePar($this->em->getReference(User::class, $auteur->getId()))
                ->setCreatedBy($auteur->getId());
        }

        $this->em->persist($ecriture);

        return $ecriture;
    }
}
