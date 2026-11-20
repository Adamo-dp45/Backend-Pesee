<?php

namespace App\DataFixtures;

use App\Domain\Enum\CompteMouvement;
use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\ModeTransport;
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
use App\Entity\BaremeTransport;
use App\Entity\CategorieFournisseur;
use App\Entity\CompteEpargne;
use App\Entity\DemandePrelevement;
use App\Entity\DemandeRetrait;
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
use App\Entity\Tranche;
use App\Entity\TarifCategorie;
use App\Entity\TarifFournisseur;
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
        // La troisième caisse, à l'entreprise SEULE : c'est sur elle que les chèques sont tirés
        $this->ecrire($manager, $socaf, null, TypeCaisse::BANCAIRE, TypeMouvement::RECHARGE, 60_000_000, 'Approvisionnement du compte en banque');

        $this->doter($manager, $socaf, $soubre, TypeCaisse::NUMERIQUE, 15_000_000);
        $this->doter($manager, $socaf, $soubre, TypeCaisse::PHYSIQUE, 2_000_000);
        $this->doter($manager, $socaf, $gagnoa, TypeCaisse::NUMERIQUE, 8_000_000);
        $this->doter($manager, $socaf, $gagnoa, TypeCaisse::PHYSIQUE, 3_000_000);

        /* ---- Référentiels : le produit à l'entreprise, le planteur au pont bascule -------- */

        /*
            - Les quatre produits appartiennent à SOCAF, non à l'un de ses postes : Soubré et Gagnoa
              voient donc le MÊME café au même prix, là où chacun tenait le sien. C'est le jeu de
              données qui rend la chose visible — peser du café à Soubré sans l'y avoir créé.
        */
        $hevea = $this->creerProduit($manager, $socaf, 'Hévéa', 'HEV', 400);
        $palme = $this->creerProduit($manager, $socaf, 'Palme', 'PAL', 145);
        $this->creerProduit($manager, $socaf, 'Cacao', 'CAC', 0); // Sans prix : ses pesées restent non payables
        $cafe = $this->creerProduit($manager, $socaf, 'Café', 'CAF', 950);

        /*
            - DEUX CATÉGORIES, et des tarifs qui ne couvrent pas tout : « Gros livreurs » paie l'hévéa
              et la palme au-dessus du prix du produit, mais rien de particulier sur le café. Un de
              ses planteurs qui livrerait du café retombe donc sur le prix du produit — c'est le cas
              qu'une grille pleine cacherait, et c'est celui qui surprend au guichet.

            - « Coopératives » n'a AUCUN tarif et aucun planteur : une catégorie qu'on vient de créer
              ressemble à ça, et c'est le seul état où elle se supprime sans conséquence.
        */
        $grosLivreurs = $this->creerCategorie(
            $manager,
            $socaf,
            'Gros livreurs',
            'Les planteurs qui livrent plus de dix tonnes par passage.'
        );
        $this->tariferCategorie($manager, $grosLivreurs, $hevea, 450); // 400 pour les autres
        $this->tariferCategorie($manager, $grosLivreurs, $palme, 160); // 145 pour les autres

        $cooperatives = $this->creerCategorie(
            $manager,
            $socaf,
            'Coopératives',
            'Les coopératives sous contrat annuel. Aucun tarif négocié pour l\'instant.'
        );

        /*
            - TROIS BARÈMES, UN PAR MODE, pour que le jeu de démonstration montre les trois façons
              de payer un trajet. Celui au kilo reproduit À L'IDENTIQUE le prix que Yao portait sur
              sa fiche : les scénarios de caisse plus bas versent des montants exacts, qu'un
              transport différent rendrait faux.

            - Les tranches couvrent de zéro à l'infini sans trou : c'est ce que la validation
              exige, et c'est ce qui fait qu'une distance trouve toujours exactement un montant.
        */
        $auKilo = $this->creerBareme($manager, $socaf, 'Transport au kilo', ModeTransport::AU_KILO, prixAuKilo: 15);
        $forfait = $this->creerBareme($manager, $socaf, 'Forfait village', ModeTransport::FORFAIT, montant: 8_000);

        $parDistance = $this->creerBareme($manager, $socaf, 'Par distance', ModeTransport::TRANCHES);
        $this->ajouterTranche($manager, $parDistance, 0, 20, 5_000);
        $this->ajouterTranche($manager, $parDistance, 20, 50, 12_000);
        $this->ajouterTranche($manager, $parDistance, 50, null, 20_000); // La dernière, sans limite

        /* Une catégorie peut porter un barème : ses planteurs sans barème propre le suivent. */
        $cooperatives->setBaremeTransport($forfait);

        $nguessan = $this->creerPlanteur($manager, $soubre, 'N\'Guessan', 'Kouamé', '0701020304', ReseauMobile::ORANGE);
        $yao = $this->creerPlanteur($manager, $soubre, 'Kouame', 'Yao', '0505060708', ReseauMobile::WAVE); // Il livre lui-même
        $yao->setBaremeTransport($auKilo); // 15 F le kilo, exactement ce qu'il portait avant les barèmes
        $sansNumero = $this->creerPlanteur($manager, $soubre, 'Bamba', 'Sekou'); // Non payable : pas de numéro
        $koffi = $this->creerPlanteur($manager, $gagnoa, 'Koffi', 'Jean', '0102030405', ReseauMobile::MTN);
        /*
            - Il n'a AUCUN barème à lui : son transport vient de sa catégorie, au forfait. C'est le
              second saut de la résolution, et le seul endroit du jeu de données où on le voit.
        */
        $koffi->setCategorie($cooperatives);

        /*
            - CELUI PAR QUI LES TROIS SAUTS SE LISENT. Il est en catégorie « Gros livreurs », donc son
              hévéa vaut 450 au lieu de 400 — origine CATEGORIE. Et il a un tarif négocié à lui sur la
              palme, 200 au lieu des 160 de sa catégorie — origine PLANTEUR. Tous les autres restent
              au prix du produit, origine PRODUIT.

            - Il est NEUF exprès, plutôt que de classer un planteur existant : les scénarios de caisse
              plus bas versent des montants exacts, qu'un changement de prix rendrait faux.
        */
        $grossiste = $this->creerPlanteur($manager, $soubre, 'Traoré', 'Salif', '0709101112', ReseauMobile::ORANGE);
        $grossiste->setCategorie($grosLivreurs);
        $grossiste->setBaremeTransport($parDistance)->setDistanceKm(35); // Tranche « de 20 à 50 km » : 12 000 F par pesée
        $this->tariferPlanteur($manager, $grossiste, $palme, 200); // L'emporte sur les 160 de sa catégorie

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

        /* Son hévéa au tarif de sa catégorie, sa palme au sien : deux origines sur la même fiche */
        $this->creerPesee($manager, $soubre, $hevea, $grossiste, '1006', 'T-4506', 9_000, 24_000, '-1 day');
        $this->creerPesee($manager, $soubre, $palme, $grossiste, '1007', 'T-4507', 7_000, 20_000, '-1 day');

        $this->creerPesee($manager, $gagnoa, $cafe, $koffi, '2001', 'T-9001', 7_000, 19_000, '-1 day');

        $this->journaliser($manager, $soubre, 8, 8, 0);
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

        /* ---- Demandes de retrait d'épargne, une par issue --------------------------------- */

        // Accordée et PAS ENCORE SERVIE : c'est la file de travail du guichet, l'état qu'on ne voit qu'ici
        $this->creerDemandeRetrait($manager, $yao, $awa, 400_000, 'Il veut reprendre une part pour la rentrée', StatutDemande::APPROUVEE, $patron, accorde: 300_000);
        $this->creerDemandeRetrait($manager, $nguessan, $awa, 50_000, 'Besoin urgent'); // En attente : elle bloque une nouvelle demande sur ce planteur
        $this->creerDemandeRetrait($manager, $koffi, $ali, 2_000_000, 'Retrait total', StatutDemande::REJETEE, $patron, motifRejet: 'Son épargne ne couvre pas cette somme.');
        $this->creerDemandeRetrait($manager, $sansNumero, $awa, 10_000, 'Erreur de saisie', StatutDemande::ANNULEE);

        /* ---- AgriPese : le voisin, pour éprouver le cloisonnement ------------------------- */

        $agri = $this->creerEntreprise($manager, 'AgriPese', 'AGRI02', '0700000009', 'contact@agri.ci', 'Bouaké');

        $this->creerCompte($manager, $agri, 'boss@agri.ci', 'Diallo', 'Moussa', Role::ADMIN, fondateur: true);
        $bakayoko = $this->creerCompte($manager, $agri, 'op@agri.ci', 'Bakayoko', 'Ibrahim', Role::OPERATEUR);

        $bouake = $this->creerSite($manager, $agri, 'AGR010', 'Pont de Bouaké', 'Bouaké', $bakayoko);

        $this->ecrire($manager, $agri, null, TypeCaisse::NUMERIQUE, TypeMouvement::RECHARGE, 5_000_000, 'Achat de flotte mobile money');
        $this->ecrire($manager, $agri, null, TypeCaisse::PHYSIQUE, TypeMouvement::RECHARGE, 1_000_000, 'Retrait bancaire d\'ouverture');
        $this->ecrire($manager, $agri, null, TypeCaisse::BANCAIRE, TypeMouvement::RECHARGE, 8_000_000, 'Approvisionnement du compte en banque');
        $this->doter($manager, $agri, $bouake, TypeCaisse::NUMERIQUE, 2_000_000);
        $this->doter($manager, $agri, $bouake, TypeCaisse::PHYSIQUE, 500_000);

        $anacarde = $this->creerProduit($manager, $agri, 'Anacarde', 'ANA', 310);
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

    private function creerProduit(ObjectManager $manager, Entreprise $entreprise, string $libelle, string $code, int $prix): Produit
    {
        $produit = (new Produit())
            ->setLibelle($libelle)
            ->setCodeproduit($code)
            ->setPrix($prix)
            ->setEntreprise($entreprise);

        $manager->persist($produit);

        return $produit;
    }

    private function creerCategorie(
        ObjectManager $manager,
        Entreprise $entreprise,
        string $libelle,
        ?string $description = null
    ): CategorieFournisseur {
        $categorie = (new CategorieFournisseur())
            ->setLibelle($libelle)
            ->setDescription($description)
            ->setEntreprise($entreprise);

        $manager->persist($categorie);

        return $categorie;
    }

    private function tariferCategorie(ObjectManager $manager, CategorieFournisseur $categorie, Produit $produit, int $prix): void
    {
        $categorie->addTarif($tarif = (new TarifCategorie())->setProduit($produit)->setPrix($prix));

        $manager->persist($tarif);
    }

    private function tariferPlanteur(ObjectManager $manager, Fournisseur $planteur, Produit $produit, int $prix): void
    {
        $planteur->addTarif($tarif = (new TarifFournisseur())->setProduit($produit)->setPrix($prix));

        $manager->persist($tarif);
    }

    private function creerBareme(
        ObjectManager $manager,
        Entreprise $entreprise,
        string $libelle,
        ModeTransport $mode,
        ?int $prixAuKilo = null,
        ?int $montant = null
    ): BaremeTransport {
        $bareme = (new BaremeTransport())
            ->setLibelle($libelle)
            ->setMode($mode)
            ->setPrixAuKilo($prixAuKilo)
            ->setMontant($montant)
            ->setEntreprise($entreprise);

        $manager->persist($bareme);

        return $bareme;
    }

    private function ajouterTranche(ObjectManager $manager, BaremeTransport $bareme, int $kmMin, ?int $kmMax, int $montant): void
    {
        $bareme->addTranche(
            $tranche = (new Tranche())->setKmMin($kmMin)->setKmMax($kmMax)->setMontant($montant)
        );

        $manager->persist($tranche);
    }

    private function creerPlanteur(
        ObjectManager $manager,
        Site $site,
        string $nom,
        string $prenom,
        ?string $contact = null,
        ?ReseauMobile $reseau = null
    ): Fournisseur {
        $planteur = (new Fournisseur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setContact1($contact)
            ->setReseau($reseau)
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

        /*
            - La même cascade qu'à la reprise des données : la récolte d'abord jusqu'à son payable, le
              surplus au transport. C'est l'ordre dans lequel un humain lirait un versement global,
              et il faut bien en choisir un : le décor doit porter des parts qui font le total.
        */
        $surLaRecolte = min($montant, max(0, $pesee->getPayableproduit() - $pesee->getMontantpayeproduit()));

        $paiement = (new Paiement())
            ->setReference(Paiement::genererReference())
            ->setMontants($surLaRecolte, $montant - $surLaRecolte)
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
            $pesee->setMontantspayes(
                $pesee->getMontantpayeproduit() + $paiement->getMontantproduit(),
                $pesee->getMontantpayetransport() + $paiement->getMontanttransport()
            );

            $pesee->setStatutReglement(StatutReglement::pourLesParts(
                $pesee->getMontantdu(),
                $pesee->getPayableproduit(),
                $pesee->getMontantpayeproduit(),
                $pesee->getPayabletransport(),
                $pesee->getMontantpayetransport(),
            ));
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
    /*
        - Une demande de retrait, dans l'état voulu. 'accorde' ne sert qu'aux demandes approuvées :
          il peut être inférieur au demandé, comme sur une demande de solde, quand la caisse du
          poste ne couvre pas tout.
        - Aucune ne porte de versement : elles sont accordées, pas servies. C'est au guichet que
          l'argent bouge, et les fixtures s'arrêtent avant — sans quoi elles écriraient dans deux
          livres sans passer par les services qui les tiennent.
    */
    private function creerDemandeRetrait(
        ObjectManager $manager,
        Fournisseur $planteur,
        User $demandeur,
        int $montant,
        string $motif,
        StatutDemande $statut = StatutDemande::EN_ATTENTE,
        ?User $traitePar = null,
        ?int $accorde = null,
        ?string $motifRejet = null
    ): void {
        $demande = (new DemandeRetrait())
            ->setFournisseur($planteur)
            ->setSite($planteur->getSite())
            ->setMontantDemande($montant)
            ->setMotif($motif)
            ->setStatut($statut)
            ->setDemandePar($demandeur)
            ->setMotifRejet($motifRejet);

        if($statut === StatutDemande::APPROUVEE) {
            $demande->setMontantAccorde($accorde ?? $montant);
        }

        if($statut->estTraitee()) {
            $demande->setTraitePar($traitePar)->setTraiteAt(new \DateTimeImmutable('-1 day'));
        }

        $manager->persist($demande);
    }

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
        $pesee->setStatutReglement(StatutReglement::pourLesParts(
            $pesee->getMontantdu(),
            $pesee->getPayableproduit(),
            $pesee->getMontantpayeproduit(),
            $pesee->getPayabletransport(),
            $pesee->getMontantpayetransport(),
        ));

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
