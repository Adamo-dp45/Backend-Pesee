<?php

namespace App\Tests\Corbeille;

use App\Domain\Enum\Role;
use App\Domain\Enum\TypeActivite;
use App\Entity\Activite;
use App\Entity\Entreprise;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - L'application met tout à la corbeille et n'en ressortait rien : un produit supprimé par erreur
      demandait un passage en base. C'est ce trou que ces cas ferment.

    - La corbeille est le SEUL endroit qui lit par-dessus le cloisonnement — le super administrateur
      n'a aucun droit sur les produits ni les planteurs — et le seul qui efface réellement une ligne.
      Deux raisons de la border sérieusement.
*/
final class CorbeilleTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $patron;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME');
        $this->site = $this->creerSite($this->entreprise, 'SOF010');
        $this->patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
    }

    public function testUnProduitSupprimeApparaitDansLaCorbeilleAvecSonContexte(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $this->supprimer('produits', $produit->getId());

        $this->appeler('GET', '/api/corbeille', $this->patron);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $corbeille = $this->reponse();

        self::assertSame(1, $corbeille['total']);
        self::assertSame(1, $corbeille['totaux']['produit']);

        $ligne = $corbeille['elements'][0];

        self::assertSame('produit', $ligne['type']);
        self::assertSame('Hévéa', $ligne['libelle']);
        /*
            - PAS DE CONTEXTE, et c'est le point du cas : le contexte nomme le pont bascule d'où
              vient l'élément, ce qui distingue deux « Kouassi » homonymes. Un produit n'a plus de
              poste — c'est son entreprise qui le situe, et elle a sa propre colonne. Y laisser le
              code du poste reviendrait à nommer un rattachement qui n'existe plus.
        */
        self::assertNull($ligne['contexte']);
        self::assertSame('Entreprise ACME', $ligne['entreprise']);
        // 'deletedBy' est un entier illisible : la corbeille doit rendre un nom
        self::assertSame('Admin Test', $ligne['supprimePar']); // 'getNomComplet()' rend prenom puis nom
        self::assertTrue($ligne['purgeable'], 'Un produit sans pesée se purge sans conséquence');
    }

    public function testLaRestaurationLeRemetEnServiceEtLeSortDeLaCorbeille(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $this->supprimer('produits', $produit->getId());

        // Hors des écrans : c'est bien ce que la corbeille vient corriger
        $this->appeler('GET', '/api/produits', $this->admin);
        self::assertCount(0, $this->membres());

        // 204 : l'operation ne rend aucun corps, il n'y a rien a montrer d'un element remis en service
        $this->appeler('PATCH', '/api/corbeille/produit/' . $produit->getId() . '/restauration', $this->patron, []);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->appeler('GET', '/api/produits', $this->admin);
        self::assertSame(['Hévéa'], array_column($this->membres(), 'libelle'));

        $this->appeler('GET', '/api/corbeille', $this->patron);
        self::assertSame(0, $this->reponse()['total']);
    }

    /*
        - Le piège propre à ce modèle : 'UniqueParSite' ne compare qu'aux lignes vivantes, ce qui
          permet de recréer un produit supprimé. Pendant qu'il dort à la corbeille, un homonyme peut
          donc naître — à la main, ou par la synchronisation du poste. Le ressusciter donnerait deux
          référentiels pour la même chose, que plus rien ne distinguerait.
    */
    public function testLaRestaurationEstRefuseeSiUnHomonymeEstNeEntreTemps(): void
    {
        $produit = $this->creerProduit($this->site, 'HEVEA', 400);
        $this->supprimer('produits', $produit->getId());

        // Accents et casse ne comptent pas : c'est la clé normalisée qui rapproche les deux
        $this->creerProduit($this->site, 'Hévéa', 470);

        $this->appeler('GET', '/api/corbeille', $this->patron);
        $ligne = $this->reponse()['elements'][0];

        self::assertFalse($ligne['restaurable'], 'La collision aurait dû être annoncée avant même la tentative');
        self::assertStringContainsString('même nom', (string) $ligne['motifNonRestaurable']);

        $this->appeler('PATCH', '/api/corbeille/produit/' . $produit->getId() . '/restauration', $this->patron, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('même nom', $this->reponse()['detail'] ?? '');
    }

    public function testLaPurgeEfaceReellementLaLigne(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $id = $produit->getId();
        $this->supprimer('produits', $id);

        $this->appeler('DELETE', '/api/corbeille/produit/' . $id, $this->patron);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->find(Produit::class, $id), 'La ligne devait quitter la base');

        $this->appeler('GET', '/api/corbeille', $this->patron);
        self::assertSame(0, $this->reponse()['total']);
    }

    /*
        - Une pesée fige le produit qui l'a valorisée. Purger couperait la ligne de son référentiel :
          le montant resterait, mais plus personne ne saurait de quoi il parle.
    */
    public function testLaPurgeEstRefuseeTantQuUnePeseeReferenceLElement(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($this->site);
        $this->creerPesee($this->site, $produit, $planteur, 500);

        $this->supprimer('produits', $produit->getId());

        $this->appeler('GET', '/api/corbeille', $this->patron);
        $ligne = $this->reponse()['elements'][0];

        self::assertFalse($ligne['purgeable']);
        self::assertSame(1, $ligne['peseesCount']);
        self::assertStringContainsString('1 pesée', (string) $ligne['motifNonPurgeable']);

        $this->appeler('DELETE', '/api/corbeille/produit/' . $produit->getId(), $this->patron);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->em->find(Produit::class, $produit->getId()), 'Le refus devait tout laisser en place');
    }

    /*
        - La corbeille est multi-entreprises : c'est ce qui la rend utile au dépannage, et ce qui
          impose de la réserver. Un administrateur ne doit pas pouvoir y entrer.
    */
    public function testElleEstFermeeATousSaufAuSuperAdministrateur(): void
    {
        $agent = $this->creerUtilisateur($this->entreprise, Role::AGENT, 'agent@acme.test');
        $operateur = $this->creerUtilisateur($this->entreprise, Role::OPERATEUR, 'operateur@acme.test');

        foreach([$this->admin, $agent, $operateur] as $compte) {
            $this->appeler('GET', '/api/corbeille', $compte);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }

        $this->appeler('GET', '/api/corbeille', null);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testElleRassembleLesElementsDeTousLesClients(): void
    {
        $rivale = $this->creerEntreprise('RIVALE');
        $siteRival = $this->creerSite($rivale, 'RIV001');
        $adminRival = $this->creerUtilisateur($rivale, Role::ADMIN, 'admin@rivale.test');

        $mien = $this->creerProduit($this->site, 'Hévéa', 400);
        $sien = $this->creerProduit($siteRival, 'Cacao', 300);

        // Chacun supprime chez lui : l'administrateur d'ACME n'atteint pas le produit de RIVALE
        $this->supprimer('produits', $mien->getId());
        $this->supprimer('produits', $sien->getId(), $adminRival);

        $this->appeler('GET', '/api/corbeille', $this->patron);
        $corbeille = $this->reponse();

        self::assertSame(2, $corbeille['total']);
        self::assertEqualsCanonicalizing(['Entreprise ACME', 'Entreprise RIVALE'], array_column($corbeille['elements'], 'entreprise'));

        // Filtrable par client : c'est ainsi qu'on dépanne un seul d'entre eux
        $this->appeler('GET', '/api/corbeille?entreprise=' . $rivale->getId(), $this->patron);
        self::assertSame(['Cacao'], array_column($this->reponse()['elements'], 'libelle'));

        // Et par type
        $this->appeler('GET', '/api/corbeille?type[]=fournisseur', $this->patron);
        self::assertSame(0, $this->reponse()['total']);
    }

    /*
        - La purge est le seul geste qui fait disparaître une ligne. Le journal doit donc en garder le
          souvenir — et il le peut, parce qu'il ne pointe sa cible que par un type et un identifiant,
          sans clé étrangère qui l'empêcherait de survivre à ce qu'il décrit.
    */
    public function testRestaurationEtPurgeSontJournalisees(): void
    {
        $a = $this->creerProduit($this->site, 'Hévéa', 400);
        $b = $this->creerProduit($this->site, 'Cacao', 300);

        $this->supprimer('produits', $a->getId());
        $this->supprimer('produits', $b->getId());

        $this->appeler('PATCH', '/api/corbeille/produit/' . $a->getId() . '/restauration', $this->patron, []);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->appeler('DELETE', '/api/corbeille/produit/' . $b->getId(), $this->patron);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $types = array_map(
            static fn(Activite $activite) => $activite->getType(),
            $this->em->getRepository(Activite::class)->findBy([], ['id' => 'ASC'])
        );

        self::assertSame([
            TypeActivite::CORBEILLE_SUPPRESSION,
            TypeActivite::CORBEILLE_SUPPRESSION,
            TypeActivite::CORBEILLE_RESTAURATION,
            TypeActivite::CORBEILLE_PURGE,
        ], $types);
    }

    public function testUnElementVivantOuInconnuNeSeTrouvePasDansLaCorbeille(): void
    {
        $produit = $this->creerProduit($this->site, 'Hévéa', 400);

        // Vivant : il n'a rien à faire ici
        $this->appeler('PATCH', '/api/corbeille/produit/' . $produit->getId() . '/restauration', $this->patron, []);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->appeler('PATCH', '/api/corbeille/produit/999999/restauration', $this->patron, []);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /** Passe par l'endpoint réel : c'est lui qui pose 'deletedBy' et journalise la suppression. */
    private function supprimer(string $ressource, ?int $id, ?User $auteur = null): void
    {
        $this->appeler('PATCH', sprintf('/api/%s/%d/suppression', $ressource, (int) $id), $auteur ?? $this->admin, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'La mise à la corbeille a échoué');
    }
}
