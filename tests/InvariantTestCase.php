<?php

namespace App\Tests;

use App\Domain\Enum\ModeReglement;
use App\Domain\Enum\Role;
use App\Domain\Enum\StatutOperation;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Service\CompteMachineService;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Domain\Enum\ModeTransport;
use App\Entity\BaremeTransport;
use App\Entity\CategorieFournisseur;
use App\Entity\Prelevement;
use App\Entity\TarifCategorie;
use App\Entity\TarifFournisseur;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Le socle des tests d'invariants. Il monte une base vraie — MySQL, la même qu'en production —
      plutôt qu'un double en mémoire : ce qu'on vérifie ici tient au verrouillage, aux transactions
      et aux contraintes d'unicité, c'est-à-dire précisément à ce qu'un faux moteur n'a pas.

    - Le schéma n'est créé qu'une fois par processus ; entre deux cas, on vide les tables. Recréer
      le schéma à chaque test coûterait plus que tout le reste de la suite réunie.

    - Le client HTTP ne redémarre PAS le noyau entre deux requêtes ('disableReboot'). Sans cela,
      l'EntityManager du test et celui de la requête sont deux objets distincts, et les entités
      chargées avant l'appel deviennent des fantômes juste après.

    - PRÉALABLE, une seule fois par poste de travail : la base de test est celle de 'DATABASE_URL'
      suffixée de '_test' (voir 'config/packages/doctrine.yaml'), et elle doit exister.

          php bin/console --env=test doctrine:database:create --if-not-exists

      Le schéma, lui, est monté par la suite elle-même.
*/
abstract class InvariantTestCase extends WebTestCase
{
    private static bool $schemaCree = false;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->preparerLaBase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->em->close();
    }

    private function preparerLaBase(): void
    {
        $metadonnees = $this->em->getMetadataFactory()->getAllMetadata();
        $outil = new SchemaTool($this->em);

        if(!self::$schemaCree) {
            $outil->dropSchema($metadonnees);
            $outil->createSchema($metadonnees);

            self::$schemaCree = true;

            return; // Un schéma neuf est déjà vide
        }

        $connexion = $this->em->getConnection();
        $plateforme = $connexion->getDatabasePlatform();

        /*
            - Les contraintes sont levées le temps du vidage : les tables se référencent en cycle
              ('site' ↔ 'user'), et aucun ordre de troncature ne les satisfait toutes.
        */
        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach($metadonnees as $metadonnee) {
            /*
                - 'EntityBase' est une superclasse mappée : elle porte les colonnes communes mais
                  n'a pas de table à elle. La vider reviendrait à en chercher une qui n'existe pas.
            */
            if($metadonnee->isMappedSuperclass || $metadonnee->isEmbeddedClass) {
                continue;
            }

            $connexion->executeStatement($plateforme->getTruncateTableSQL($metadonnee->getTableName(), true));
        }

        $connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em->clear();
    }

    /*
        - CE CAS N'A DE SENS QUE SI LE MOBILE MONEY EST OUVERT À LA SAISIE. Le mode est fermé tant
          qu'un vrai agrégateur n'est pas en place, et l'API refuse de l'écrire — or c'est le SEUL
          chemin qui produise un versement en vol, les espèces étant confirmées sur-le-champ.

        - ON SAUTE PLUTÔT QUE DE SUPPRIMER, et le garde-fou interroge 'ModeReglement::toutes()'
          plutôt qu'un drapeau à lui : la passerelle, le webhook et la réconciliation sont toujours
          là, et ces cas sont ce qui les tient. Le jour où la ligne commentée de l'énumération
          rouvrira, ils reviendront d'eux-mêmes — sans que personne n'ait à se souvenir d'eux.
    */
    protected function exigeLeMobileMoney(): void
    {
        if(!in_array(ModeReglement::MOBILE_MONEY, ModeReglement::toutes(), true)) {
            self::markTestSkipped('Le mobile money est ferme a la saisie : ce cas rouvrira avec lui.');
        }
    }

    /* ---- Montage du décor ------------------------------------------------------------------- */

    /*
        - Le solde posé sans argument de caisse va dans la NUMÉRIQUE, parce que c'est elle que la
          plupart des cas éprouvent : le mobile money est le chemin qui a une passerelle, un webhook
          et une réconciliation. Un cas qui veut des espèces le dit, et renseigne l'autre.
    */
    protected function creerEntreprise(string $code = 'ACME', int $solde = 0, int $soldePhysique = 0): Entreprise
    {
        $entreprise = (new Entreprise())
            ->setNom('Entreprise ' . $code)
            ->setCodeentreprise($code)
            ->setContact1('0700000000')
            ->setSoldeDe(TypeCaisse::NUMERIQUE, $solde)
            ->setSoldeDe(TypeCaisse::PHYSIQUE, $soldePhysique);

        $this->em->persist($entreprise);
        $this->em->flush();

        return $entreprise;
    }

    protected function creerSite(Entreprise $entreprise, string $code = 'SOF010', int $solde = 0, int $soldePhysique = 0): Site
    {
        $site = (new Site())
            ->setCode($code)
            ->setLibellesite('Pont bascule ' . $code)
            ->setEntreprise($entreprise)
            ->setSoldeDe(TypeCaisse::NUMERIQUE, $solde)
            ->setSoldeDe(TypeCaisse::PHYSIQUE, $soldePhysique);

        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }

    protected function creerUtilisateur(?Entreprise $entreprise, Role $role, string $email): User
    {
        $utilisateur = (new User())
            ->setEmail($email)
            ->setNom('Test')
            ->setPrenom(ucfirst(strtolower($role->name)))
            ->setEntreprise($entreprise)
            ->setRole($role);

        $utilisateur->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, 'MotDePasse1234')
        );

        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }

    /*
        - Désigne qui tient la caisse du poste. Ce n'est pas un détail de décor : le périmètre d'un
          opérateur, donc tout ce qu'il voit et tout ce qu'il peut payer, se déduit de cette relation.
    */
    protected function affecterOperateur(Site $site, User $operateur): void
    {
        $site->setOperateur($operateur);
        $this->em->flush();
    }

    /** Le compte machine du poste, celui qui pousse les pesées. */
    protected function creerCompteMachine(Site $site): User
    {
        self::getContainer()->get(CompteMachineService::class)->creer($site);
        $this->em->flush();

        $compte = $site->getCompteMachine();
        self::assertNotNull($compte, 'Le compte machine devrait être rattaché au site');

        return $compte;
    }

    /*
        - L'ARGUMENT RESTE LE POSTE alors que le produit naît sur l'entreprise : c'est ce qu'un cas
          de test a sous la main, et ce qu'il veut dire est « un produit pesable ici ». Le rendre
          explicite partout n'apprendrait rien de plus et toucherait une vingtaine d'appels.

        - Conséquence à garder en tête en écrivant un cas : deux appels sur deux postes d'une MÊME
          entreprise créent deux fois le même produit. Rien ne lève ici — la validation ne tourne
          pas sur un 'persist' direct — mais l'API, elle, refuserait le second.
    */
    protected function creerProduit(Site $site, string $libelle = 'Hévéa', int $prix = 200): Produit
    {
        $entreprise = $site->getEntreprise();
        self::assertNotNull($entreprise, 'Un poste de test devrait porter son entreprise');

        $produit = (new Produit())->setLibelle($libelle)->setPrix($prix)->setEntreprise($entreprise);

        $this->em->persist($produit);
        $this->em->flush();

        return $produit;
    }

    protected function creerPlanteur(Site $site, string $nom = 'Kouassi', ?string $contact = '0700000001'): Fournisseur
    {
        $planteur = (new Fournisseur())->setNom($nom)->setSite($site)->setContact1($contact);

        $this->em->persist($planteur);
        $this->em->flush();

        return $planteur;
    }

    /*
        - Une pesée TERMINÉE et valorisée, dans l'état où la synchronisation l'aurait laissée. Le
          montant n'est pas posé à la main : 'calculerMontant()' applique la même règle qu'en
          production — le prix du produit pour la récolte, et le transport du planteur par-dessus.
    */
    protected function creerPesee(Site $site, Produit $produit, Fournisseur $planteur, int $poidsnet = 500, string $ref = '1'): Operation
    {
        $pesee = (new Operation())
            ->setCodesecret($site->getCode() . '_' . $ref . '_P' . $ref)
            ->setRefDesktop($ref)
            ->setCodepesee('P' . $ref)
            ->setNumticket('T' . $ref)
            ->setSite($site)
            ->setProduit($produit)
            ->setFournisseur($planteur)
            ->setPoids1(1000)
            ->setPoids2(1000 + $poidsnet)
            ->setPoidsbrut(1000 + $poidsnet)
            ->setPoidsnet($poidsnet)
            ->setStatut(StatutOperation::TERMINEE)
            ->setPeseeAt1(new \DateTimeImmutable('-2 hours'))
            ->setPeseeAt2(new \DateTimeImmutable('-1 hour'));

        $pesee->calculerMontant();

        $this->em->persist($pesee);
        $this->em->flush();

        return $pesee;
    }

    /*
        - Ouvre une mise en compte sur UN PRODUIT, comme le ferait l'approbation d'une demande. Le
          taux doit rester sous le prix du produit, sauf à vouloir éprouver justement ce plafond —
          le helper ne vérifie rien, ce sont les processors qui tiennent la règle.
    */
    /*
        - UN BARÈME AU KILO, qui reproduit exactement ce qu'un 'prixtransport' faisait : prix ×
          poids net. Les cas qui l'utilisent n'ont donc rien changé à leurs attentes.
    */
    protected function creerBaremeAuKilo(Entreprise $entreprise, int $prixAuKilo, string $libelle = 'Au kilo'): BaremeTransport
    {
        $bareme = (new BaremeTransport())
            ->setLibelle($libelle)
            ->setMode(ModeTransport::AU_KILO)
            ->setPrixAuKilo($prixAuKilo)
            ->setEntreprise($entreprise);

        $this->em->persist($bareme);
        $this->em->flush();

        return $bareme;
    }

    protected function creerCategorie(Entreprise $entreprise, string $libelle = 'Gros livreurs'): CategorieFournisseur
    {
        $categorie = (new CategorieFournisseur())->setLibelle($libelle)->setEntreprise($entreprise);

        $this->em->persist($categorie);
        $this->em->flush();

        return $categorie;
    }

    protected function tariferCategorie(CategorieFournisseur $categorie, Produit $produit, int $prix): TarifCategorie
    {
        $categorie->addTarif($tarif = (new TarifCategorie())->setProduit($produit)->setPrix($prix));

        $this->em->persist($tarif);
        $this->em->flush();

        return $tarif;
    }

    protected function tariferPlanteur(Fournisseur $planteur, Produit $produit, int $prix): TarifFournisseur
    {
        $planteur->addTarif($tarif = (new TarifFournisseur())->setProduit($produit)->setPrix($prix));

        $this->em->persist($tarif);
        $this->em->flush();

        return $tarif;
    }

    protected function ouvrirPrelevement(Fournisseur $planteur, Produit $produit, int $taux): Prelevement
    {
        $planteur->ajouterPrelevement($prelevement = (new Prelevement())->setProduit($produit)->setTaux($taux));

        $this->em->persist($prelevement);
        $this->em->flush();

        return $prelevement;
    }

    /*
        - Une pesée passée par l'arbitrage de la mise en compte, telle que la synchronisation
          l'aurait laissée. Volontairement DISTINCTE de 'creerPesee()' plutôt qu'un paramètre de
          plus : les cas existants doivent continuer de produire des pesées jamais arbitrées, sinon
          ils changeraient tous de sens d'un coup.
    */
    protected function creerPeseeArbitree(Site $site, Produit $produit, Fournisseur $planteur, int $poidsnet = 500, string $ref = '1'): Operation
    {
        $pesee = $this->creerPesee($site, $produit, $planteur, $poidsnet, $ref);

        self::getContainer()->get(EpargneService::class)->preleverSur($pesee);

        $this->em->flush();

        return $pesee;
    }

    /* ---- Appels HTTP ------------------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $corps
     */
    protected function appeler(string $methode, string $uri, ?User $utilisateur = null, ?array $corps = null): void
    {
        $entetes = ['HTTP_ACCEPT' => 'application/ld+json'];

        if($utilisateur !== null) {
            $entetes['HTTP_AUTHORIZATION'] = 'Bearer ' . self::getContainer()
                ->get(JWTTokenManagerInterface::class)
                ->create($utilisateur);
        }

        if($corps !== null) {
            // 'merge-patch+json' pour un PATCH : c'est le seul format que déclare API Platform
            $entetes['CONTENT_TYPE'] = $methode === 'PATCH' ? 'application/merge-patch+json' : 'application/json';
        }

        $this->client->request($methode, $uri, [], [], $entetes, $corps === null ? null : json_encode($corps, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function reponse(): array
    {
        $contenu = $this->client->getResponse()->getContent();

        self::assertIsString($contenu);

        return json_decode($contenu, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    /**
     * Les éléments d'une collection API Platform.
     *
     * @return list<array<string, mixed>>
     */
    protected function membres(): array
    {
        return $this->reponse()['member'] ?? [];
    }
}
