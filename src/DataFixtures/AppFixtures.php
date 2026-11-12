<?php

namespace App\DataFixtures;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\ReseauMobile;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutActivation;
use App\Domain\Enum\StatutEntreprise;
use App\Domain\Enum\StatutOperation;
use App\Domain\Enum\StatutDemande;
use App\Domain\Enum\StatutPaiement;
use App\Domain\Enum\StatutReglement;
use App\Domain\Enum\StatutSite;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Enum\TypeMouvement;
use App\Domain\Enum\TypeMouvementEpargne;
use App\Domain\Service\CompteMachineService;
use App\Entity\CompteEpargne;
use App\Entity\DemandePrelevement;
use App\Entity\DemandeSolde;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\MouvementCaisse;
use App\Entity\MouvementEpargne;
use App\Entity\Operation;
use App\Entity\Paiement;
use App\Entity\Prelevement;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\SynchronisationLot;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Un jeu de données cohérent pour travailler sur les écrans, et pour retrouver un état connu quand les essais l'ont dispersé. Tous les comptes partagent le même mot de passe : 'motdepasse123'.

    - Deux entreprises, et c'est délibéré : le cloisonnement ne se vérifie pas avec une seule. « AgriPese » sert de voisin dont on ne doit jamais rien voir.

    - Les soldes ne sont PAS posés à la main : chaque écriture passe par 'ecrire()', qui tient la chaîne 'soldeAvant'/'soldeApres' comme le fait 'CaisseService'. Un jeu de données dont les soldes ne correspondraient pas au grand livre rendrait tout écran de caisse suspect.

    - On n'appelle pas 'CaisseService' lui-même : il lit l'utilisateur connecté par 'Security', et il n'y en a aucun en console.
*/
class AppFixtures extends Fixture
{
    private const MOT_DE_PASSE = 'motdepasse123';

    /** @var array<int, CompteEpargne> Les comptes d'épargne du jeu, indexés par planteur : rien n'est encore en base pendant la construction */
    private array $comptesEpargne = [];

    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private CompteMachineService $compteMachine
    )
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->creerSuperAdmin($manager);

        /* ---- SOCAF Pesage : le client sur lequel on travaille ------------------------------ */

        $socaf = $this->creerEntreprise($manager, 'SOCAF Pesage', 'SOCAF01', '0700000000', 'contact@socaf.ci', 'Abidjan, Zone 4');

        $patron = $this->creerCompte($manager, $socaf, 'patron@socaf.ci', 'Kouassi', 'Adama', Role::ADMIN, fondateur: true);
        $this->creerCompte($manager, $socaf, 'agent@socaf.ci', 'Bamba', 'Salif', Role::AGENT);
        $awa = $this->creerCompte($manager, $socaf, 'awa@socaf.ci', 'Kone', 'Awa', Role::OPERATEUR);
        $ali = $this->creerCompte($manager, $socaf, 'ali@socaf.ci', 'Traore', 'Ali', Role::OPERATEUR);
        $this->creerCompte($manager, $socaf, 'suspendu@socaf.ci', 'Diarra', 'Moussa', Role::OPERATEUR, statut: StatutActivation::SUSPENDU);

        $soubre = $this->creerSite($manager, $socaf, 'SOF010', 'Pont de Soubré', 'Soubré', $awa);
        $gagnoa = $this->creerSite($manager, $socaf, 'SOF020', 'Pont de Gagnoa', 'Gagnoa', $ali);
        $bloque = $this->creerSite($manager, $socaf, 'SOF030', 'Pont de Daloa', 'Daloa', null, StatutSite::BLOQUE);

        /*
            - Les caisses : l'entreprise recharge CHACUNE des deux, puis dote ses ponts bascules. Les
              deux natures d'argent ne se croisent jamais — on retrouve donc deux chaînes de grand
              livre distinctes sur chaque compte.
            - Soubré est volontairement mieux doté en numérique qu'en physique : c'est ce déséquilibre
              qui rend l'écran de trésorerie utile, et qui justifie sa demande d'espèces plus bas.
        */
        $this->ecrire($manager, $socaf, null, TypeCaisse::NUMERIQUE, TypeMouvement::RECHARGE, 40_000_000, 'Achat de flotte mobile money');
        $this->ecrire($manager, $socaf, null, TypeCaisse::PHYSIQUE, TypeMouvement::RECHARGE, 12_000_000, 'Retrait bancaire d\'ouverture');

        $this->doter($manager, $socaf, $soubre, TypeCaisse::NUMERIQUE, 15_000_000);
        $this->doter($manager, $socaf, $soubre, TypeCaisse::PHYSIQUE, 2_000_000);
        $this->doter($manager, $socaf, $gagnoa, TypeCaisse::NUMERIQUE, 8_000_000);
        $this->doter($manager, $socaf, $gagnoa, TypeCaisse::PHYSIQUE, 3_000_000);

        /* ---- Référentiels, tels que le poste les créerait -------------------------------- */

        $hevea = $this->creerProduit($manager, $soubre, 'Hévéa', 'HEV', 400);
        $palme = $this->creerProduit($manager, $soubre, 'Palme', 'PAL', 145);
        $this->creerProduit($manager, $soubre, 'Cacao', 'CAC', 0); // Sans prix : ses pesées restent non payables
        $cafe = $this->creerProduit($manager, $gagnoa, 'Café', 'CAF', 950);

        $nguessan = $this->creerPlanteur($manager, $soubre, 'N\'Guessan', 'Kouamé', '0701020304', ReseauMobile::ORANGE);
        $yao = $this->creerPlanteur($manager, $soubre, 'Kouame', 'Yao', '0505060708', ReseauMobile::WAVE, prixtransport: 15); // Il livre lui-même
        $sansNumero = $this->creerPlanteur($manager, $soubre, 'Bamba', 'Sekou'); // Non payable : pas de numéro
        $koffi = $this->creerPlanteur($manager, $gagnoa, 'Koffi', 'Jean', '0102030405', ReseauMobile::MTN);

        /*
            - Yao met de côté sur DEUX cultures, à deux taux différents : c'est tout l'intérêt du
              prélèvement par produit. Chacun reste sous le prix de son produit — 50 < 400 pour
              l'hévéa, 20 < 145 pour la palme — sans quoi il repartirait les mains vides.
        */
        $this->ouvrirPrelevement($manager, $yao, $hevea, 50);
        $this->ouvrirPrelevement($manager, $yao, $palme, 20);

        /* ---- Pesées ---------------------------------------------------------------------- */

        // Terminée et soldée : deux versements confirmés couvrent le montant dû
        $soldee = $this->creerPesee($manager, $soubre, $hevea, $nguessan, '1001', 'T-4501', 12_000, 32_000, '-3 days');
        $this->verser($manager, $soldee, 4_000_000, StatutPaiement::REUSSI);
        $this->verser($manager, $soldee, 4_000_000, StatutPaiement::REUSSI);

        // Terminée, un versement en vol : c'est la pesée qui exerce la réconciliation
        $enVol = $this->creerPesee($manager, $soubre, $palme, $yao, '1002', 'T-4502', 9_000, 25_000, '-2 days');
        $this->mettreEnCompte($manager, $enVol, $yao); // Il épargne : une part part sur son compte avant tout versement
        $this->verser($manager, $enVol, 3_000_000, StatutPaiement::ENVOYE);

        /*
            - Le planteur n'a pas de numéro : impayable par mobile money, mais réglable en ESPÈCES. C'est
              le cas qui justifie à lui seul les deux caisses — avant, cette pesée attendait qu'on trouve
              un téléphone à quelqu'un qui n'en a pas.
        */
        $enEspeces = $this->creerPesee($manager, $soubre, $hevea, $sansNumero, '1003', 'T-4503', 10_000, 28_000, '-1 day');
        $this->verser($manager, $enEspeces, 1_500_000, StatutPaiement::REUSSI, ModeReglement::ESPECES);

        // En cours : le camion est entré, pas encore ressorti
        $this->creerPesee($manager, $soubre, $palme, $nguessan, '1004', null, 11_000, null, '-2 hours');

        // Un versement échoué, remboursé : la caisse doit être revenue à son niveau
        $echouee = $this->creerPesee($manager, $soubre, $hevea, $yao, '1005', 'T-4505', 8_000, 22_000, '-1 day');
        $this->mettreEnCompte($manager, $echouee, $yao);
        $this->verser($manager, $echouee, 1_000_000, StatutPaiement::ECHOUE);

        $this->creerPesee($manager, $gagnoa, $cafe, $koffi, '2001', 'T-9001', 7_000, 19_000, '-1 day');

        $this->journaliser($manager, $soubre, 6, 6, 0);
        $this->journaliser($manager, $gagnoa, 1, 1, 0);
        $this->journaliser($manager, $bloque, 2, 0, 2); // Poste bloqué : ses envois sont refusés

        /* ---- Demandes de réapprovisionnement, une par issue ------------------------------- */

        $this->creerDemande($manager, $soubre, $awa, 500_000, 'Plus de billets, et trois planteurs sans téléphone', TypeCaisse::PHYSIQUE); // En attente : c'est elle qui bloque une nouvelle demande sur Soubré
        $this->creerDemande($manager, $gagnoa, $ali, 300_000, 'Fin de semaine chargée', TypeCaisse::NUMERIQUE, StatutDemande::APPROUVEE, $patron, 250_000);
        $this->creerDemande($manager, $gagnoa, $ali, 900_000, 'Prévision de récolte', TypeCaisse::NUMERIQUE, StatutDemande::REJETEE, $patron, motifRejet: 'Montant hors de proportion avec les pesées du mois.');
        $this->creerDemande($manager, $gagnoa, $ali, 100_000, 'Erreur de poste', TypeCaisse::PHYSIQUE, StatutDemande::ANNULEE);            // Retirée par son auteur

        /* ---- Demandes de mise en compte, une par issue ------------------------------------ */

        // Approuvée : c'est elle qui explique les 50 F/kg que Yao met de côté sur l'hévéa
        $this->creerDemandePrelevement($manager, $yao, $hevea, $awa, 50, 'Il veut mettre de côté pour la rentrée', StatutDemande::APPROUVEE, $patron);
        $this->creerDemandePrelevement($manager, $sansNumero, $hevea, $awa, 15, 'Demande faite au guichet'); // En attente : elle bloque une nouvelle demande sur CE couple planteur/produit
        $this->creerDemandePrelevement($manager, $koffi, $cafe, $ali, 400, 'Épargne forcée', StatutDemande::REJETEE, $patron, 'Taux hors de proportion : il ne lui resterait presque rien au guichet.');
        $this->creerDemandePrelevement($manager, $nguessan, $hevea, $awa, 30, 'Erreur de planteur', StatutDemande::ANNULEE);

        /* ---- AgriPese : le voisin, pour éprouver le cloisonnement ------------------------- */

        $agri = $this->creerEntreprise($manager, 'AgriPese', 'AGRI02', '0700000009', 'contact@agri.ci', 'Bouaké');

        $this->creerCompte($manager, $agri, 'boss@agri.ci', 'Diallo', 'Moussa', Role::ADMIN, fondateur: true);
        $bakayoko = $this->creerCompte($manager, $agri, 'op@agri.ci', 'Bakayoko', 'Ibrahim', Role::OPERATEUR);

        $bouake = $this->creerSite($manager, $agri, 'AGR010', 'Pont de Bouaké', 'Bouaké', $bakayoko);

        $this->ecrire($manager, $agri, null, TypeCaisse::NUMERIQUE, TypeMouvement::RECHARGE, 5_000_000, 'Achat de flotte mobile money');
        $this->ecrire($manager, $agri, null, TypeCaisse::PHYSIQUE, TypeMouvement::RECHARGE, 1_000_000, 'Retrait bancaire d\'ouverture');
        $this->doter($manager, $agri, $bouake, TypeCaisse::NUMERIQUE, 2_000_000);
        $this->doter($manager, $agri, $bouake, TypeCaisse::PHYSIQUE, 500_000);

        $anacarde = $this->creerProduit($manager, $bouake, 'Anacarde', 'ANA', 310);
        $sanogo = $this->creerPlanteur($manager, $bouake, 'Sanogo', 'Fanta', '0708091011', ReseauMobile::MOOV);
        $this->creerPesee($manager, $bouake, $anacarde, $sanogo, '5001', 'T-1201', 6_000, 21_000, '-1 day');

        $manager->flush();
    }

    /* ---- Fabriques ---------------------------------------------------------------------- */

    private function creerSuperAdmin(ObjectManager $manager): void
    {
        $compte = (new User())
            ->setEmail('root@webpesee.com')
            ->setNom('Administrateur')
            ->setPrenom('Super')
            ->setRole(Role::SUPER_ADMIN); // Sans entreprise : il les gère toutes

        $manager->persist($this->avecMotDePasse($compte));
    }

    private function creerEntreprise(ObjectManager $manager, string $nom, string $code, string $contact, string $email, string $adresse): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom($nom)
            ->setCodeentreprise($code)
            ->setContact1($contact)
            ->setEmail($email)
            ->setAdresse($adresse)
            ->setStatut(StatutEntreprise::ACTIVE);

        $manager->persist($entreprise);

        return $entreprise;
    }

    private function creerCompte(
        ObjectManager $manager,
        Entreprise $entreprise,
        string $email,
        string $nom,
        string $prenom,
        Role $role,
        bool $fondateur = false,
        StatutActivation $statut = StatutActivation::ACTIF
    ): User {
        $compte = (new User())
            ->setEmail($email)
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setTelephone('0700000000')
            ->setEntreprise($entreprise)
            ->setRole($role)
            ->setFondateur($fondateur)
            ->setStatut($statut);

        $manager->persist($this->avecMotDePasse($compte));

        return $compte;
    }

    private function creerSite(
        ObjectManager $manager,
        Entreprise $entreprise,
        string $code,
        string $libelle,
        string $localite,
        ?User $operateur,
        StatutSite $statut = StatutSite::ACTIF
    ): Site {
        $site = (new Site())
            ->setCode($code)
            ->setLibellesite($libelle)
            ->setLocalite($localite)
            ->setEntreprise($entreprise)
            ->setOperateur($operateur)
            ->setStatut($statut);

        $manager->persist($site);

        // Le compte machine du poste, comme le ferait la mise en service. Son mot de passe est perdu : on le régénère depuis l'écran du site
        $this->compteMachine->creer($site);

        return $site;
    }

    private function creerProduit(ObjectManager $manager, Site $site, string $libelle, string $code, int $prix): Produit
    {
        $produit = (new Produit())
            ->setLibelle($libelle)
            ->setCodeproduit($code)
            ->setPrix($prix)
            ->setSite($site);

        $manager->persist($produit);

        return $produit;
    }

    private function creerPlanteur(
        ObjectManager $manager,
        Site $site,
        string $nom,
        string $prenom,
        ?string $contact = null,
        ?ReseauMobile $reseau = null,
        ?int $prixtransport = null
    ): Fournisseur {
        $planteur = (new Fournisseur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setContact1($contact)
            ->setReseau($reseau)
            ->setPrixtransport($prixtransport)
            ->setSite($site);

        $manager->persist($planteur);

        return $planteur;
    }

    /*
        - 'poids2' à null = pesée en cours : ni poids net, ni montant, ni créance. C'est le panneau « PESEE EN ATTENTE » du logiciel de pesée.
    */
    private function creerPesee(
        ObjectManager $manager,
        Site $site,
        Produit $produit,
        Fournisseur $planteur,
        string $refDesktop,
        ?string $numticket,
        int $poids1,
        ?int $poids2,
        string $quand
    ): Operation {
        $entree = new \DateTimeImmutable($quand);
        $terminee = $poids2 !== null;

        $pesee = (new Operation())
            ->setCodesecret($site->getCode() . '_' . $refDesktop . '_P1')
            ->setRefDesktop($refDesktop)
            ->setCodepesee('P1')
            ->setNumticket($numticket)
            ->setSite($site)
            ->setProduit($produit)
            ->setFournisseur($planteur)
            ->setMouvement('RECEPTION')
            ->setClient($site->getEntreprise()?->getNom())
            ->setProvenance('Plantation ' . $site->getLocalite())
            ->setDestination('Usine Abidjan')
            ->setTransporteur('Trans Ivoire')
            ->setChauffeur('Koffi Yao')
            ->setImmatriculation('1234 AB 01')
            ->setPeseur('Yao')
            ->setPoids1($poids1)
            ->setPoids2($poids2)
            ->setPoidsbrut($poids2)
            ->setPoidsnet($terminee ? $poids2 - $poids1 : null)
            ->setPeseeAt1($entree)
            ->setPeseeAt2($terminee ? $entree->modify('+3 hours') : null)
            ->setStatut(StatutOperation::depuis($poids2))
            ->setSyncAt(new \DateTimeImmutable());

        $pesee->calculerMontant(); // La même méthode que la synchronisation : les montants ne sont jamais posés à la main

        $manager->persist($pesee);

        return $pesee;
    }

    /*
        - Un versement, avec les écritures de caisse qu'il entraîne : le débit à la réservation, et le remboursement si la passerelle a refusé.
    */
    private function verser(ObjectManager $manager, Operation $pesee, int $montant, StatutPaiement $statut, ModeReglement $mode = ModeReglement::MOBILE_MONEY): void
    {
        $site = $pesee->getSite();
        $planteur = $pesee->getFournisseur();
        $mobile = $mode->passeParLaPasserelle();

        $paiement = (new Paiement())
            ->setReference(Paiement::genererReference())
            ->setMontant($montant)
            ->setModeReglement($mode)
            ->setOperation($pesee)
            ->setFournisseur($planteur)
            ->setSite($site)
            ->setNumeroDestinataire($mobile ? $planteur?->getContact1() : null)
            ->setReseau($mobile ? $planteur?->getReseau() : null)
            ->setGateway($mobile ? 'fake' : 'caisse')
            ->setStatut($statut);

        if($statut === StatutPaiement::REUSSI) {
            $paiement->setGatewayTransactionId('SIM-' . $paiement->getReference())->setConfirmeAt(new \DateTimeImmutable());
        }

        if($statut === StatutPaiement::ENVOYE) {
            $paiement->setGatewayTransactionId('SIM-' . $paiement->getReference());
        }

        if($statut === StatutPaiement::ECHOUE) {
            $paiement->setMessageErreur('Numéro destinataire refusé par l\'opérateur.');
        }

        $manager->persist($paiement);

        // Les fonds quittent la caisse du MODE dès la création, quel que soit le sort du versement
        $caisse = $mode->caisse();

        $this->ecrire($manager, $site->getEntreprise(), $site, $caisse, TypeMouvement::PAIEMENT, $montant, 'Paiement ' . $paiement->getReference(), $paiement);

        if($statut === StatutPaiement::ECHOUE) {
            $this->ecrire($manager, $site->getEntreprise(), $site, $caisse, TypeMouvement::REMBOURSEMENT, $montant, 'Remboursement du paiement ' . $paiement->getReference(), $paiement);
        }

        // Seul le confirmé compte dans ce qui a été versé
        if($statut === StatutPaiement::REUSSI) {
            $verse = $pesee->getMontantpaye() + $montant;

            $pesee->setMontantpaye($verse)->setStatutReglement(StatutReglement::pour($pesee->getMontantdu(), $pesee->getMontantpayable(), $verse));
        }
    }

    /** Doter un site produit DEUX écritures : l'argent sort de l'entreprise et entre dans le site, dans la MÊME caisse. */
    private function doter(ObjectManager $manager, Entreprise $entreprise, Site $site, TypeCaisse $caisse, int $montant): void
    {
        $this->ecrire($manager, $entreprise, null, $caisse, TypeMouvement::ATTRIBUTION, $montant, 'Attribution au pont bascule ' . $site->getCode(), null);
        $this->ecrire($manager, $entreprise, $site, $caisse, TypeMouvement::DOTATION, $montant, 'Dotation reçue de l\'entreprise');
    }

    /*
        - Une écriture de grand livre, et la mise à jour du solde qu'elle entraîne. Le sens vient du TYPE, jamais de l'appelant : c'est la même garantie que dans 'CaisseService'.
    */
    /*
        - La mise en compte d'une pesée, écrite à la main comme les mouvements de caisse plus haut :
          le service d'épargne verrouille et transacte, ce qui n'a pas de sens dans un jeu de
          démonstration qui construit tout puis écrit d'un bloc.
        - Le plafond est le même qu'en production : jamais plus que ce que vaut la pesée.
    */
    private function creerDemandePrelevement(
        ObjectManager $manager,
        Fournisseur $planteur,
        Produit $produit,
        User $demandeur,
        int $taux,
        string $motif,
        StatutDemande $statut = StatutDemande::EN_ATTENTE,
        ?User $traitePar = null,
        ?string $motifRejet = null
    ): void {
        $demande = (new DemandePrelevement())
            ->setFournisseur($planteur)
            ->setProduit($produit)
            ->setSite($planteur->getSite())
            ->setTauxDemande($taux)
            ->setMotif($motif)
            ->setStatut($statut)
            ->setDemandePar($demandeur)
            ->setMotifRejet($motifRejet);

        if($statut->estTraitee()) {
            $demande->setTraitePar($traitePar)->setTraiteAt(new \DateTimeImmutable('-1 day'));
        }

        $manager->persist($demande);
    }

    private function ouvrirPrelevement(ObjectManager $manager, Fournisseur $planteur, Produit $produit, int $taux): void
    {
        $planteur->ajouterPrelevement(
            $prelevement = (new Prelevement())->setProduit($produit)->setTaux($taux)
        );

        $manager->persist($prelevement);
    }

    /* Reproduit à la main ce que fait 'EpargneService' : le taux vient du prélèvement ouvert SUR LE PRODUIT PESÉ, et non du planteur. */
    private function mettreEnCompte(ObjectManager $manager, Operation $pesee, Fournisseur $planteur): void
    {
        $taux = $planteur->prelevementPour($pesee->getProduit())?->getTaux() ?? 0;
        $retenue = min($taux * (int) $pesee->getPoidsnet(), (int) $pesee->getMontantdu());

        if($retenue <= 0) {
            return;
        }

        $compte = $this->comptesEpargne[spl_object_id($planteur)] ?? null;

        if($compte === null) {
            $compte = (new CompteEpargne())
                ->setFournisseur($planteur)
                ->setSite($planteur->getSite())
                ->setEntreprise($planteur->getSite()?->getEntreprise());

            $manager->persist($compte);
            $this->comptesEpargne[spl_object_id($planteur)] = $compte;
        }

        $avant = $compte->getSolde();
        $apres = $avant + $retenue;

        $compte->setSolde($apres);
        $pesee->figerLaRetenue($taux, $retenue);
        $pesee->setStatutReglement(StatutReglement::pour($pesee->getMontantdu(), $pesee->getMontantpayable(), $pesee->getMontantpaye()));

        $manager->persist(
            (new MouvementEpargne())
                ->setCompte($compte)
                ->setOperation($pesee)
                ->setType(TypeMouvementEpargne::PRELEVEMENT)
                ->setSens(TypeMouvementEpargne::PRELEVEMENT->sens())
                ->setMontant($retenue)
                ->setSoldeAvant($avant)
                ->setSoldeApres($apres)
                ->setMotif(sprintf('Mise en compte sur la pesée %s (%s)', (string) $pesee->getNumticket(), (string) $pesee->getProduit()?->getLibelle()))
                ->setEntreprise($compte->getEntreprise())
                ->setSite($compte->getSite())
        );
    }

    private function ecrire(ObjectManager $manager, Entreprise $entreprise, ?Site $site, TypeCaisse $caisse, TypeMouvement $type, int $montant, string $motif, ?Paiement $paiement = null): void
    {
        $surLeSite = $type->compte() === CompteMouvement::SITE;
        $compte = $surLeSite ? $site : $entreprise;
        $avant = $compte->getSoldeDe($caisse);
        $apres = $avant + ($type->sens()->signe() * $montant);

        $compte->setSoldeDe($caisse, $apres);

        $manager->persist(
            (new MouvementCaisse())
                ->setCompte($type->compte())
                ->setCaisse($caisse)
                ->setEntreprise($entreprise)
                ->setSite($surLeSite ? $site : null)
                ->setSens($type->sens())
                ->setType($type)
                ->setMontant($montant)
                ->setSoldeAvant($avant)
                ->setSoldeApres($apres)
                ->setMotif($motif)
                ->setPaiement($paiement)
        );
    }

    /*
        - Une demande de réapprovisionnement dans l'état voulu. L'approbation N'ÉCRIT PAS de mouvement de caisse ici : les soldes des fixtures sont posés par 'doter()', et doubler l'écriture casserait le chaînage 'soldeAvant'/'soldeApres'. Ce qu'on veut éprouver à l'écran, c'est l'affichage des quatre issues.
    */
    private function creerDemande(
        ObjectManager $manager,
        Site $site,
        User $demandeur,
        int $montant,
        string $motif,
        TypeCaisse $caisse = TypeCaisse::NUMERIQUE,
        StatutDemande $statut = StatutDemande::EN_ATTENTE,
        ?User $traitePar = null,
        ?int $montantAccorde = null,
        ?string $motifRejet = null
    ): DemandeSolde
    {
        $demande = (new DemandeSolde())
            ->setSite($site)
            ->setCaisse($caisse)
            ->setDemandePar($demandeur)
            ->setMontantDemande($montant)
            ->setMotif($motif)
            ->setStatut($statut)
            ->setMontantAccorde($statut === StatutDemande::APPROUVEE ? ($montantAccorde ?? $montant) : null)
            ->setMotifRejet($motifRejet)
            ->setTraitePar($statut === StatutDemande::ANNULEE ? null : $traitePar) // Une annulation n'a pas d'arbitre : l'auteur s'est ravisé
            ->setTraiteAt($statut->estTraitee() ? new \DateTimeImmutable('-1 day') : null);

        $manager->persist($demande);

        return $demande;
    }

    private function journaliser(ObjectManager $manager, Site $site, int $recus, int $crees, int $erreurs): void
    {
        $manager->persist(
            (new SynchronisationLot())
                ->setSite($site)
                ->setNbRecus($recus)
                ->setNbCrees($crees)
                ->setNbModifiees($recus - $crees - $erreurs)
                ->setNbErreurs($erreurs)
                ->setErreurs($erreurs === 0 ? null : [['id' => '9001', 'message' => 'Ce pont bascule est bloqué : ses pesées ne sont plus acceptées.']])
                ->setIp('41.203.12.44')
                ->setDureeMs(random_int(18, 120))
        );
    }

    private function avecMotDePasse(User $compte): User
    {
        return $compte->setPassword($this->hasher->hashPassword($compte, self::MOT_DE_PASSE));
    }
}
