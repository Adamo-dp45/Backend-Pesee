<?php

namespace App\Tests\Tarification;

use App\Domain\Enum\OrigineTarif;
use App\Domain\Enum\PartPesee;
use App\Domain\Enum\Role;
use App\Domain\Support\TarifApplicable;
use App\Domain\Support\TauxPrelevement;
use App\Entity\CategorieFournisseur;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Repository\OperationRepository;
use App\Tests\InvariantTestCase;

/*
    - CE QUE CES CAS TIENNENT : un prix ne se lit plus sur le produit, il se RÉSOUT en trois sauts —
      le tarif du planteur, celui de sa catégorie, le prix du produit. Chaque saut sortant est une
      somme d'argent qui change de main.

    - Et ce qu'ils tiennent surtout : les deux endroits où la résolution peut mentir sans lever. Le
      taux de mise en compte, qui se bornait sur le prix du produit et laissait passer un taux
      ruineux pour un planteur moins bien payé ; et le garde-fou de la revalorisation, qui regardait
      la part choisie par le TYPE de la cause et serait passé à côté de la récolte.
*/
final class TarifsParCategorieTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $admin;
    private Produit $hevea;
    private Produit $palme;
    private CategorieFournisseur $grosLivreurs;
    private Fournisseur $gros;
    private Fournisseur $ordinaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 50_000_000, 50_000_000);
        $this->site = $this->creerSite($this->entreprise, 'SOF010', 10_000_000, 10_000_000);
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');

        $this->hevea = $this->creerProduit($this->site, 'Hévéa', 400);
        $this->palme = $this->creerProduit($this->site, 'Palme', 145);

        $this->grosLivreurs = $this->creerCategorie($this->entreprise);
        $this->tariferCategorie($this->grosLivreurs, $this->hevea, 450);

        $this->gros = $this->creerPlanteur($this->site, 'Traore', '0700000001');
        $this->gros->setCategorie($this->grosLivreurs);

        $this->ordinaire = $this->creerPlanteur($this->site, 'Bamba', '0700000002');

        $this->em->flush();
    }

    /* ---- La résolution -------------------------------------------------------------------- */

    /*
        - LES TROIS SAUTS, sur une seule assertion chacun. C'est la règle entière : tout le reste de
          cette étape n'est que la conséquence de ces trois lignes.
    */
    public function testLePrixSeResoutEnTroisSauts(): void
    {
        // Sans catégorie : le prix du produit
        $sansRien = TarifApplicable::pour($this->ordinaire, $this->hevea);
        self::assertSame(400, $sansRien->prix);
        self::assertSame(OrigineTarif::PRODUIT, $sansRien->origine);

        // Avec catégorie tarifée : celui de la catégorie
        $parCategorie = TarifApplicable::pour($this->gros, $this->hevea);
        self::assertSame(450, $parCategorie->prix);
        self::assertSame(OrigineTarif::CATEGORIE, $parCategorie->origine);

        // Avec un tarif propre : le sien, qui l'emporte
        $this->tariferPlanteur($this->gros, $this->hevea, 470);
        $this->em->refresh($this->gros);

        $sien = TarifApplicable::pour($this->gros, $this->hevea);
        self::assertSame(470, $sien->prix);
        self::assertSame(OrigineTarif::PLANTEUR, $sien->origine);
    }

    /*
        - UNE CATÉGORIE NE TARIFE PAS TOUT. Son planteur retombe sur le prix du produit pour les
          cultures qu'elle ne nomme pas — c'est le cas qu'une grille pleine cacherait, et celui qui
          surprend au guichet.
    */
    public function testUnProduitQueLaCategorieNeTarifePasRetombeSurLeProduit(): void
    {
        $applicable = TarifApplicable::pour($this->gros, $this->palme);

        self::assertSame(145, $applicable->prix);
        self::assertSame(OrigineTarif::PRODUIT, $applicable->origine);
    }

    /*
        - UN PRODUIT NON TARIFÉ VAUT ZÉRO, PAS 'null'. Le reste de l'application lit « pesée sans
          valeur » sur 'montantdu <= 0' ; traduire ce zéro en 'null' ferait diverger deux lectures
          du même état, et une pesée sans prix deviendrait une pesée sans produit.
    */
    public function testUnProduitSansPrixRendZeroEtNonNull(): void
    {
        $cacao = $this->creerProduit($this->site, 'Cacao', 0);

        self::assertSame(0, TarifApplicable::pour($this->ordinaire, $cacao)->prix);
        self::assertNull(TarifApplicable::pour($this->ordinaire, null)->prix);
    }

    /* ---- Ce que la pesée fige ------------------------------------------------------------- */

    public function testLaPeseeFigeLePrixEtSonOrigine(): void
    {
        $pesee = $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');

        self::assertSame(450, $pesee->getPrixunitaire());
        self::assertSame(OrigineTarif::CATEGORIE, $pesee->getOrigineTarif());
        self::assertSame(450_000, $pesee->getMontantdu());
    }

    /*
        - L'ORIGINE ENTRE DANS LA DÉTECTION DE CHANGEMENT. Un tarif de planteur supprimé au profit
          d'un tarif de catégorie AU MÊME MONTANT ne change aucun chiffre : sans ce cas, la pesée
          garderait une origine qui ment, sur un montant pourtant juste, et plus personne ne saurait
          six mois après d'où venaient ces 450 F.
    */
    public function testUnChangementDOrigineSansChangementDePrixEstBienDetecte(): void
    {
        $this->tariferPlanteur($this->gros, $this->hevea, 450); // Le MÊME montant que sa catégorie
        $this->em->refresh($this->gros);

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');
        self::assertSame(OrigineTarif::PLANTEUR, $pesee->getOrigineTarif());

        foreach($this->gros->getTarifs() as $tarif) {
            $tarif->setDeletedAt(new \DateTimeImmutable());
        }

        self::assertTrue($pesee->calculerMontant(), 'Le changement d\'origine doit compter comme un changement');
        self::assertSame(450, $pesee->getPrixunitaire());
        self::assertSame(OrigineTarif::CATEGORIE, $pesee->getOrigineTarif());
    }

    /* ---- Le taux de mise en compte -------------------------------------------------------- */

    /*
        - LE CAS GRAVE, ET IL ÉTAIT SILENCIEUX. Le taux se bornait sur 'Produit::$prix'. Pour un
          planteur dont la catégorie paie MOINS cher que le produit, un taux compris entre les deux
          était donc accepté — puis la retenue absorbait toute sa récolte. Il repartait du guichet
          avec zéro, sans exception possible : 'figerLaRetenue()' est irréversible.
    */
    public function testLeTauxEstBorneParLePrixDuPlanteurEtNonParCeluiDuProduit(): void
    {
        $maigre = $this->creerCategorie($this->entreprise, 'Petits volumes');
        $this->tariferCategorie($maigre, $this->hevea, 200); // Moitié moins que les 400 du produit

        $petit = $this->creerPlanteur($this->site, 'Kone', '0700000003');
        $petit->setCategorie($maigre);
        $this->em->flush();

        // 300 passe sous le prix du PRODUIT, mais dépasse ce que ce planteur touche
        self::assertNotNull(
            TauxPrelevement::motifDuRefus($petit, $this->hevea, 300),
            'Un taux au-dessus du prix réellement payé doit être refusé'
        );

        // Et il reste acceptable pour qui est payé au prix du produit
        self::assertNull(TauxPrelevement::motifDuRefus($this->ordinaire, $this->hevea, 300));
    }

    /*
        - L'AUTRE MOITIÉ : un planteur mieux payé peut épargner davantage. Refuser 420 F sur un
          hévéa qui lui est payé 450 serait un refus que rien ne justifie, et que l'opérateur ne
          pourrait pas expliquer au planteur en face de lui.
    */
    public function testUnPlanteurMieuxPayePeutEpargnerAuDelaDuPrixDuProduit(): void
    {
        self::assertNull(TauxPrelevement::motifDuRefus($this->gros, $this->hevea, 420));
        self::assertNotNull(TauxPrelevement::motifDuRefus($this->ordinaire, $this->hevea, 420));
    }

    /* ---- La revalorisation ---------------------------------------------------------------- */

    /*
        - LE GARDE-FOU SUIT LA PART, PAS LE TYPE DE LA CAUSE. Une pesée dont le TRANSPORT est déjà
          payé doit voir sa RÉCOLTE revalorisée : ce sont deux parts, et l'une ne gèle pas l'autre.
          Avec l'ancien garde-fou — « un Fournisseur touche le transport » — cette pesée sortait de
          la sélection et sa récolte n'était jamais reprise.
    */
    public function testUnePeseeDontLeTransportEstPayeVoitQuandMemeSaRecolteRevalorisee(): void
    {
        $pesee = $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');
        $pesee->setMontantspayes(0, 5_000); // Le transport est réglé, la récolte non
        $this->em->flush();

        $trouvees = self::getContainer()->get(OperationRepository::class)
            ->findARevaloriser($this->gros, PartPesee::RECOLTE);

        self::assertCount(1, $trouvees, 'Un transport payé ne doit pas geler la récolte');

        // Et l'inverse tient : la part transport, elle, est bien gelée
        self::assertCount(
            0,
            self::getContainer()->get(OperationRepository::class)->findARevaloriser($this->gros, PartPesee::TRANSPORT)
        );
    }

    /*
        - UNE CATÉGORIE SÉLECTIONNE PAR SES PLANTEURS, et seulement par eux. Elle ne touche aucune
          pesée en propre : sans la sous-requête, la sélection serait vide et un tarif de catégorie
          n'aurait jamais revalorisé quoi que ce soit.
    */
    public function testUneCategorieSelectionneLesPeseesDeSesPlanteurs(): void
    {
        $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');
        $this->creerPesee($this->site, $this->hevea, $this->ordinaire, 1_000, '2');

        $trouvees = self::getContainer()->get(OperationRepository::class)
            ->findARevaloriser($this->grosLivreurs, PartPesee::RECOLTE, $this->hevea);

        self::assertCount(1, $trouvees, 'Seul le planteur de la catégorie doit être repris');
        self::assertSame($this->gros->getId(), $trouvees[0]->getFournisseur()?->getId());
    }

    /*
        - LA SÉLECTION SE RESSERRE SUR LE PRODUIT. Un tarif de catégorie ne vise qu'une culture ;
          sans ce resserrement, on revaloriserait toutes les pesées de tous ses planteurs, toutes
          cultures confondues — des milliers de recalculs sans objet, dont chacun peut arbitrer une
          épargne de façon irréversible.
    */
    public function testLaSelectionNePrendPasLesAutresCultures(): void
    {
        $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');
        $this->creerPesee($this->site, $this->palme, $this->gros, 1_000, '2');

        $trouvees = self::getContainer()->get(OperationRepository::class)
            ->findARevaloriser($this->grosLivreurs, PartPesee::RECOLTE, $this->hevea);

        self::assertCount(1, $trouvees);
        self::assertSame($this->hevea->getId(), $trouvees[0]->getProduit()?->getId());
    }

    /*
        - UNE PESÉE ARBITRÉE NE SE REVALORISE PLUS, et ce garde-fou vaut aussi pour les catégories :
          une promesse a été écrite au planteur sur la base de ce montant.
    */
    public function testUnePeseeArbitreeSortDeLaRevalorisation(): void
    {
        $this->creerPeseeArbitree($this->site, $this->hevea, $this->gros, 1_000, '1');

        self::assertCount(
            0,
            self::getContainer()->get(OperationRepository::class)
                ->findARevaloriser($this->grosLivreurs, PartPesee::RECOLTE, $this->hevea)
        );
    }

    /* ---- La saisie groupée ---------------------------------------------------------------- */

    /*
        - L'APERÇU N'ÉCRIT RIEN et annonce le nombre de pesées. C'est le seul garde-fou possible
          devant un arbitrage irréversible : sans lui, l'administrateur découvre après coup qu'il
          vient de figer la retenue de milliers de pesées.
    */
    public function testLApercuAnnonceSansRienEcrire(): void
    {
        $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');

        $this->appeler('POST', '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId() . '/tarifs', $this->admin, [
            'lignes' => [['produit' => '/api/produits/' . $this->hevea->getId(), 'prix' => 500]],
        ]);

        $bilan = $this->reponse();

        self::assertFalse($bilan['applique']);
        self::assertSame(1, $bilan['modifiees']);
        self::assertSame(1, $bilan['peseesConcernees']);

        $this->em->clear();
        self::assertSame(450, $this->em->getRepository(CategorieFournisseur::class)
            ->find($this->grosLivreurs->getId())
            ->tarifPour($this->em->getRepository(Produit::class)->find($this->hevea->getId()))
            ->getPrix());
    }

    public function testLaConfirmationEcritEtRevalorise(): void
    {
        $pesee = $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');

        $this->appeler('POST', '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId() . '/tarifs', $this->admin, [
            'confirmer' => true,
            'lignes' => [['produit' => '/api/produits/' . $this->hevea->getId(), 'prix' => 500]],
        ]);

        self::assertTrue($this->reponse()['applique']);

        $this->em->refresh($pesee);
        self::assertSame(500, $pesee->getPrixunitaire());
        self::assertSame(500_000, $pesee->getMontantdu());
        self::assertSame(OrigineTarif::CATEGORIE, $pesee->getOrigineTarif());
    }

    /*
        - UN RENVOI À L'IDENTIQUE NE DÉCLENCHE RIEN. L'écran renvoie la grille entière, cases
          inchangées comprises ; les compter comme des modifications relancerait la revalorisation
          de toutes les pesées de la catégorie à chaque enregistrement — et chaque passe peut
          arbitrer une épargne.
    */
    public function testUnRenvoiALIdentiqueNeToucheRien(): void
    {
        $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');

        $this->appeler('POST', '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId() . '/tarifs', $this->admin, [
            'confirmer' => true,
            'lignes' => [['produit' => '/api/produits/' . $this->hevea->getId(), 'prix' => 450]],
        ]);

        $bilan = $this->reponse();

        self::assertSame(1, $bilan['inchangees']);
        self::assertSame(0, $bilan['modifiees']);
        self::assertSame(0, $bilan['peseesConcernees'], 'Rien ne change, donc aucune pesée n\'est touchée');
    }

    /*
        - VIDER UNE CASE FAIT RETOMBER SUR LE SAUT SUIVANT, et revalorise. C'est la seule façon de
          retirer un tarif, et elle doit se voir sur les pesées en cours.
    */
    public function testViderUneCaseFaitRetomberSurLeSautSuivant(): void
    {
        $this->tariferPlanteur($this->gros, $this->hevea, 470);
        $this->em->refresh($this->gros);

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->gros, 1_000, '1');
        self::assertSame(470, $pesee->getPrixunitaire());

        $this->appeler('POST', '/api/fournisseurs/' . $this->gros->getId() . '/tarifs', $this->admin, [
            'confirmer' => true,
            'lignes' => [['produit' => '/api/produits/' . $this->hevea->getId(), 'prix' => null]],
        ]);

        self::assertSame(1, $this->reponse()['retirees']);

        $this->em->refresh($pesee);
        self::assertSame(450, $pesee->getPrixunitaire(), 'Il retombe sur le tarif de sa catégorie');
        self::assertSame(OrigineTarif::CATEGORIE, $pesee->getOrigineTarif());
    }

    /*
        - LE MÊME PRODUIT DEUX FOIS dans un lot : la seconde case écraserait la première sans qu'on
          sache laquelle a gagné. Refusé AVANT la moindre écriture.
    */
    public function testUnProduitDeuxFoisDansLeLotEstRefuse(): void
    {
        $iri = '/api/produits/' . $this->hevea->getId();

        $this->appeler('POST', '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId() . '/tarifs', $this->admin, [
            'confirmer' => true,
            'lignes' => [['produit' => $iri, 'prix' => 500], ['produit' => $iri, 'prix' => 600]],
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /* ---- La grille lue depuis un planteur ------------------------------------------------- */

    /*
        - CE QU'IL TOUCHE, PRODUIT PAR PRODUIT. Deux écrans en dépendent : sa grille de tarifs
          propres, et la liste déroulante de mise en compte — qui annonçait le prix du PRODUIT en
          le présentant comme la borne du taux. Depuis les catégories, c'était faux : l'écran
          disait 400, l'API acceptait 449.

        - UNE LIGNE PAR PRODUIT, tarifé ou non. Partir de ses seuls tarifs masquerait les cases
          vides, c'est-à-dire exactement ce qu'on vient regarder.
    */
    public function testLaGrilleLueRendCeQueLePlanteurToucheEtDOuCaVient(): void
    {
        $this->tariferPlanteur($this->gros, $this->palme, 300);

        $this->appeler('GET', '/api/fournisseurs/' . $this->gros->getId() . '/tarifs', $this->admin);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $lignes = [];

        foreach($this->reponse()['lignes'] as $ligne) {
            $lignes[$ligne['libelle']] = $ligne;
        }

        self::assertCount(2, $lignes, 'Une ligne par produit de l\'entreprise, tarifé ou non');

        // Son hévéa vient de sa catégorie
        self::assertSame(450, $lignes['Hévéa']['prix']);
        self::assertSame('CATEGORIE', $lignes['Hévéa']['origine']);
        self::assertFalse($lignes['Hévéa']['sien']);

        // Sa palme vient de son tarif propre
        self::assertSame(300, $lignes['Palme']['prix']);
        self::assertSame('PLANTEUR', $lignes['Palme']['origine']);
        self::assertTrue($lignes['Palme']['sien']);
    }

    /*
        - CE QUE VIDER LA CASE DONNERAIT, et non « le prix du produit ». C'est le chiffre affiché
          sous chaque case : pour un planteur, retirer son tarif propre le laisse au tarif de SA
          catégorie. L'écran disait qu'il le ramènerait à 145 alors qu'il le laissait à 160.
    */
    public function testLeRepliEstLeTarifDeSaCategorieEtNonLePrixDuProduit(): void
    {
        $this->tariferCategorie($this->grosLivreurs, $this->palme, 160);
        $this->tariferPlanteur($this->gros, $this->palme, 300);

        $this->appeler('GET', '/api/fournisseurs/' . $this->gros->getId() . '/tarifs', $this->admin);

        foreach($this->reponse()['lignes'] as $ligne) {
            if($ligne['libelle'] === 'Palme') {
                self::assertSame(145, $ligne['prixProduit']);
                self::assertSame(300, $ligne['prix']);
                self::assertSame(160, $ligne['prixSansTarifPropre'], 'Vider la case le laisse à sa catégorie');

                return;
            }
        }

        self::fail('La palme devrait figurer dans la grille');
    }

    /*
        - LE PLANTEUR D'UN AUTRE CLIENT EST INTROUVABLE, pas « interdit » : dire interdit
          révélerait qu'il existe. Le provider charge par 'find()', donc le cloisonnement de l'API
          ne s'applique pas — il se vérifie à la main, et ce cas le tient.
    */
    public function testLaGrilleDUnPlanteurDUnAutreClientEstIntrouvable(): void
    {
        $rivale = $this->creerEntreprise('RIVALE');
        $siteRival = $this->creerSite($rivale, 'RIV001');
        $planteurRival = $this->creerPlanteur($siteRival, 'Yao', '0700000009');

        $this->appeler('GET', '/api/fournisseurs/' . $planteurRival->getId() . '/tarifs', $this->admin);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Le classement d'un planteur ------------------------------------------------------ */

    /*
        - CLASSER UN PLANTEUR EST UN PRIX. Le mettre en catégorie change ce que vaut sa récolte sur
          toutes ses pesées non payées, exactement comme une tarification — et ça ne se voyait
          nulle part : c'était un champ parmi d'autres sur sa fiche.
    */
    public function testChangerLaCategorieDUnPlanteurRevaloriseSaRecolte(): void
    {
        $pesee = $this->creerPesee($this->site, $this->hevea, $this->ordinaire, 1_000, '1');
        self::assertSame(400, $pesee->getPrixunitaire());

        $this->appeler('PATCH', '/api/fournisseurs/' . $this->ordinaire->getId(), $this->admin, [
            'categorie' => '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId(),
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->refresh($pesee);
        self::assertSame(450, $pesee->getPrixunitaire());
        self::assertSame(OrigineTarif::CATEGORIE, $pesee->getOrigineTarif());
    }

    /* ---- La suppression ------------------------------------------------------------------- */

    /*
        - UNE CATÉGORIE ENCORE PEUPLÉE NE SE SUPPRIME PAS, et le motif NOMME le nombre : à deux
          planteurs on les reclasse, à deux cents on y regarde à deux fois.
    */
    public function testUneCategoriePeupleeNeSeSupprimePas(): void
    {
        $this->appeler('PATCH', '/api/categorie_fournisseurs/' . $this->grosLivreurs->getId() . '/suppression', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 planteur', (string) $this->reponse()['detail']);
    }

    /*
        - UNE CATÉGORIE SUPPRIMÉE DOIT ARRIVER DANS LA CORBEILLE, sans quoi elle disparaît de tous
          les écrans sans aucun moyen de revenir en arrière.

        - CE CAS EST NÉ D'UNE PANNE : le compteur de pesées de la corbeille ne sait compter que les
          produits et les planteurs, et il LEVAIT sur une catégorie. L'erreur n'emportait pas la
          seule ligne fautive mais TOUT l'écran — la corbeille devenait inutilisable pour tous les
          clients à la fois, y compris pour en sortir autre chose.
    */
    public function testUneCategorieSupprimeeApparaitDansLaCorbeille(): void
    {
        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');
        $vide = $this->creerCategorie($this->entreprise, 'Coopératives');

        $this->appeler('PATCH', '/api/categorie_fournisseurs/' . $vide->getId() . '/suppression', $this->admin, []);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->appeler('GET', '/api/corbeille', $patron);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'La corbeille ne doit pas lever sur une catégorie');

        $corbeille = $this->reponse();

        self::assertSame(1, $corbeille['totaux']['categorie']);

        $ligne = $corbeille['elements'][0];

        self::assertSame('categorie', $ligne['type']);
        self::assertSame('Coopératives', $ligne['libelle']);
        self::assertNull($ligne['contexte'], 'Une catégorie n\'a pas de pont bascule à nommer');
        self::assertSame(0, $ligne['peseesCount'], 'Une catégorie n\'a jamais de pesée : zéro, pas une erreur');
        self::assertTrue($ligne['purgeable'], 'Vide, elle se purge sans conséquence');
    }

    /*
        - ELLE SE RESTAURE ET SE PURGE. Les deux répondaient 404 : la liste des types acceptés était
          recopiée en 'instanceof' à côté de celle qui fait foi, et le troisième type y manquait.
          Un refus parfaitement muet — « cet élément n'est pas dans la corbeille » est exactement
          ce qu'on lit quand on s'y prend mal.
    */
    public function testUneCategorieSeRestaureEtSePurge(): void
    {
        $patron = $this->creerUtilisateur(null, Role::SUPER_ADMIN, 'patron@webpesee.test');
        $vide = $this->creerCategorie($this->entreprise, 'Coopératives');
        $id = $vide->getId();

        $this->appeler('PATCH', '/api/categorie_fournisseurs/' . $id . '/suppression', $this->admin, []);
        $this->appeler('PATCH', '/api/corbeille/categorie/' . $id . '/restauration', $patron, []);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(CategorieFournisseur::class)->find($id)?->getDeletedAt(),
            'Elle doit être revenue en service'
        );

        $this->appeler('PATCH', '/api/categorie_fournisseurs/' . $id . '/suppression', $this->admin, []);
        $this->appeler('DELETE', '/api/corbeille/categorie/' . $id, $patron);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->getRepository(CategorieFournisseur::class)->find($id));
    }

    public function testUneCategorieVideSeSupprime(): void
    {
        $vide = $this->creerCategorie($this->entreprise, 'Coopératives');

        $this->appeler('PATCH', '/api/categorie_fournisseurs/' . $vide->getId() . '/suppression', $this->admin, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
