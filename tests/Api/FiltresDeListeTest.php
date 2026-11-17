<?php

namespace App\Tests\Api;

use App\Domain\Enum\Role;
use App\Entity\Entreprise;
use App\Entity\Site;
use App\Entity\User;
use App\Tests\InvariantTestCase;

/*
    - UN FILTRE QUI NE FILTRE PAS NE LÈVE RIEN. Il rend une liste — vide, ou entière — et l'écran
      l'affiche sans broncher. L'utilisateur en conclut qu'il n'y a rien à voir, et c'est la seule
      chose qu'on lui dise.

    - Les deux cas réellement rencontrés sont figés ici :

      1. 'actif' sur les produits était déclaré dans un 'SearchFilter', qui compare des CHAÎNES. La
         colonne étant un booléen, 'actif=true' et 'actif=false' — les deux valeurs que l'écran
         envoie — ne correspondaient à rien et la liste se vidait sur les deux choix.

      2. Le champ de recherche des produits, des planteurs et des ponts bascules annonce « ou
         code », mais n'interrogeait qu'une colonne : taper un code ne trouvait jamais rien.

    - Ces cas valent pour toutes les listes : ils n'éprouvent pas une fonctionnalité, ils éprouvent
      qu'une liste filtrée dit la vérité.
*/
final class FiltresDeListeTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME');
        $this->site = $this->creerSite($this->entreprise, 'ACM001');
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
    }

    /** @return list<string> Les libellés rendus par la collection, dans l'ordre */
    private function libelles(string $uri, string $champ = 'libelle'): array
    {
        $this->appeler('GET', $uri, $this->admin);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $reponse = $this->reponse();
        $lignes = $reponse['member'] ?? $reponse['hydra:member'] ?? [];

        return array_values(array_map(static fn(array $l): string => (string) ($l[$champ] ?? ''), $lignes));
    }

    /*
        - LE cas signalé : l'écran propose « Actif » et « Désactivé », et les deux vidaient la liste.
          Un produit désactivé devient alors introuvable — y compris pour celui qui vient le
          réactiver.
    */
    public function testLeFiltreActifSepareLesProduitsAuLieuDeToutVider(): void
    {
        $this->creerProduit($this->site, 'Hévéa', 200);
        $retire = $this->creerProduit($this->site, 'Palme', 150);

        $retire->setActif(false);
        $this->em->flush();

        self::assertSame(['Hévéa'], $this->libelles('/api/produits?actif=true'));
        self::assertSame(['Palme'], $this->libelles('/api/produits?actif=false'));

        // Et sans filtre, les deux : le filtre retire des lignes, il n'en invente pas
        self::assertCount(2, $this->libelles('/api/produits'));
    }

    /* '1' et '0' doivent répondre comme 'true' et 'false' : c'est ce que la barre d'adresse porte quand on partage une liste filtrée. */
    public function testLeFiltreActifAccepteAussiUnEtZero(): void
    {
        $this->creerProduit($this->site, 'Hévéa', 200);
        $this->creerProduit($this->site, 'Palme', 150)->setActif(false);
        $this->em->flush();

        self::assertSame(['Hévéa'], $this->libelles('/api/produits?actif=1'));
        self::assertSame(['Palme'], $this->libelles('/api/produits?actif=0'));
    }

    /* ---- La recherche libre : un champ, plusieurs colonnes ------------------------------------ */

    /*
        - Le code est ce que l'opérateur a sous les yeux, sur le ticket ou sur la borne. Le champ
          annonce « ou code » depuis toujours ; il ne cherchait que dans le libellé.
    */
    public function testLaRechercheDUnProduitTrouveParLeCodeCommeParLeLibelle(): void
    {
        $this->creerProduit($this->site, 'Hévéa', 200)->setCodeproduit('HEV-01');
        $this->creerProduit($this->site, 'Palme', 150)->setCodeproduit('PAL-07');
        $this->em->flush();

        self::assertSame(['Palme'], $this->libelles('/api/produits?recherche=PAL-07'), 'Le code ne trouve rien');
        self::assertSame(['Hévéa'], $this->libelles('/api/produits?recherche=Hév'), 'Le libellé ne trouve plus rien');
        self::assertCount(0, $this->libelles('/api/produits?recherche=introuvable'));
    }

    public function testLaRechercheDUnPontBasculeTrouveParLeCode(): void
    {
        $this->creerSite($this->entreprise, 'ACM002');

        self::assertSame(['Pont bascule ACM002'], $this->libelles('/api/sites?recherche=ACM002', 'libellesite'));
        self::assertCount(2, $this->libelles('/api/sites?recherche=Pont', 'libellesite'));
    }

    /*
        - Le planteur se retrouve par son nom, son prénom ou son code. Le prénom compte : la fiche
          affiche « Kouame Yao », et chercher « Yao » ne doit pas dépendre de la colonne où il est.
    */
    public function testLaRechercheDUnPlanteurCouvreNomPrenomEtCode(): void
    {
        $this->creerPlanteur($this->site, 'Kouame')->setPrenom('Yao')->setCodefournisseur('PL-042');
        $this->creerPlanteur($this->site, 'Bamba')->setPrenom('Sekou');
        $this->em->flush();

        self::assertSame(['Kouame'], $this->libelles('/api/fournisseurs?recherche=Yao', 'nom'));
        self::assertSame(['Kouame'], $this->libelles('/api/fournisseurs?recherche=PL-042', 'nom'));
        self::assertSame(['Bamba'], $this->libelles('/api/fournisseurs?recherche=Bamb', 'nom'));
    }
}
