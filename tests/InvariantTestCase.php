<?php

namespace App\Tests;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutOperation;
use App\Domain\Enum\TypeCaisse;
use App\Domain\Service\CompteMachineService;
use App\Domain\Service\Epargne\EpargneService;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Prelevement;
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

    protected function creerProduit(Site $site, string $libelle = 'Hévéa', int $prix = 200): Produit
    {
        $produit = (new Produit())->setLibelle($libelle)->setPrix($prix)->setSite($site);

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
