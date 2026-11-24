<?php

namespace App\Tests\Transport;

use App\Domain\Enum\ModeTransport;
use App\Domain\Enum\OrigineTransport;
use App\Domain\Enum\PartPesee;
use App\Domain\Enum\Role;
use App\Entity\BaremeTransport;
use App\Entity\Entreprise;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Repository\OperationRepository;
use App\Tests\InvariantTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/*
    - DEUX FAÇONS DE PAYER UN TRAJET, là où il n'y en avait qu'une. Ce qui est en jeu : le camion
      d'un planteur qui apporte deux cents kilos a fait la même route que celui qui en apporte dix
      tonnes, et un prix au kilo le payait vingt fois moins.

    - IL Y EN AVAIT TROIS, et la moitié de ce fichier gardait le troisième : un barème « par
      tranches » tenait une suite d'intervalles, dont quatre façons de produire un montant faux
      sans qu'une erreur n'apparaisse — un trou, un chevauchement, une borne ambiguë, un trajet
      au-delà de la dernière tranche. Le mode allait surtout chercher une DISTANCE sur la fiche du
      planteur, que personne ne saisissait : le transport valait alors zéro, se figeait, s'arbitrait.
      Une tranche est maintenant un barème à elle seule, et les quatre façons de se tromper ont
      disparu avec la suite.

    - CE QUI RESTE À GARDER : les deux modes, la résolution en deux sauts, la revalorisation, et les
      BORNES — dont il faut tenir qu'elles ne calculent rien, sans quoi quelqu'un les rebranchera.
*/
final class BaremesTest extends InvariantTestCase
{
    private Entreprise $entreprise;
    private Site $site;
    private User $admin;
    private Produit $hevea;
    private Fournisseur $planteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = $this->creerEntreprise('ACME', 50_000_000, 50_000_000);
        $this->site = $this->creerSite($this->entreprise, 'SOF010', 10_000_000, 10_000_000);
        $this->admin = $this->creerUtilisateur($this->entreprise, Role::ADMIN, 'admin@acme.test');
        $this->hevea = $this->creerProduit($this->site, 'Hévéa', 400);
        $this->planteur = $this->creerPlanteur($this->site, 'Traore', '0700000001');
    }

    /* ---- Les trois modes ------------------------------------------------------------------ */

    public function testLeModeAuKiloSuitLeTonnage(): void
    {
        $this->planteur->setBaremeTransport($this->creerBaremeAuKilo($this->entreprise, 15));
        $this->em->flush();

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');

        self::assertSame(15_000, $pesee->getMontanttransport());
        self::assertSame(15, $pesee->getPrixtransport(), 'Seul ce mode donne un prix au kilo à figer');
        self::assertSame(ModeTransport::AU_KILO, $pesee->getModeTransport());
    }

    /*
        - LE FORFAIT NE DÉPEND PAS DU TONNAGE, et c'est toute la raison d'être de l'étape : deux
          pesées du même planteur, l'une de dix tonnes et l'autre de cent kilos, paient le même
          trajet. Avec un prix au kilo, la seconde touchait cent fois moins pour la même route.
    */
    public function testLeForfaitNeDependPasDuTonnage(): void
    {
        $this->planteur->setBaremeTransport($this->forfait(8_000));
        $this->em->flush();

        $grosse = $this->creerPesee($this->site, $this->hevea, $this->planteur, 10_000, '1');
        $petite = $this->creerPesee($this->site, $this->hevea, $this->planteur, 100, '2');

        self::assertSame(8_000, $grosse->getMontanttransport());
        self::assertSame(8_000, $petite->getMontanttransport());
        self::assertNull($grosse->getPrixtransport(), 'Un forfait n\'a pas de prix au kilo : en inventer un ferait mentir toute multiplication');
        self::assertSame('Forfait, quel que soit le tonnage', $petite->getExplicationTransport());
    }

    /* ---- Les bornes ----------------------------------------------------------------------- */

    /*
        - LA BORNE EXACTE, et c'est le piège le plus silencieux des quatre. Avec deux bornes
          INCLUSES, un planteur à exactement 20 km relève de « 0 à 20 » ET de « 20 à 50 » : deux
          montants pour une distance, départagés par l'ordre de la requête — c'est-à-dire par rien.
          Les deux réponses sont plausibles, donc personne ne s'en aperçoit.

    /*
        - LE MODE DÉCIDE DES CHAMPS, et les autres doivent rester vides. Les laisser coexister
          ouvrirait « lequel gagne ? », question à laquelle aucune réponse n'est bonne.
    */
    public function testUnModeNePorteQueSesPropresChamps(): void
    {
        $auKilo = $this->bareme(ModeTransport::AU_KILO);
        $auKilo->setPrixAuKilo(15)->setMontant(8_000);

        self::assertStringContainsString('ne porte pas ce champ', $this->refus($auKilo));

        $forfait = $this->bareme(ModeTransport::FORFAIT);
        self::assertStringContainsString('demande un montant', $this->refus($forfait));
    }

    /* ---- Les bornes ----------------------------------------------------------------------- */

    /*
        - LES BORNES NOMMENT, ELLES NE CALCULENT PAS, et c'est le seul cas qui l'affirme. Tout ce
          fichier a longtemps tenu l'inverse : la distance du planteur choisissait un intervalle,
          qui choisissait un montant. Si quelqu'un rebranche un jour les bornes sur le calcul, ce
          cas tombe — et c'est exactement ce qu'on veut de lui.
    */
    public function testLesBornesNeChangentAucunMontant(): void
    {
        $borne = $this->forfait(8_000, 'Trajet moyen');
        $borne->setKmMin(20)->setKmMax(50);

        $this->planteur->setBaremeTransport($borne);
        $this->em->flush();

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');

        self::assertSame(8_000, $pesee->getMontanttransport(), 'Le forfait, et rien que lui');
        self::assertStringContainsString('de 20 à 50 km', (string) $pesee->getExplicationTransport());
    }

    /* Un intervalle à l'envers ne désigne aucun planteur, et se lirait pourtant comme une ligne valide dans la liste. */
    public function testUnIntervalleALEnversEstRefuse(): void
    {
        $bareme = $this->forfait(8_000);
        $bareme->setKmMin(50)->setKmMax(10);

        self::assertStringContainsString('doit dépasser celui de départ', $this->refus($bareme));
    }

    /* Aucune borne est un cas ordinaire : la plupart des tarifs ne parlent pas de distance. */
    public function testUnBaremeSansBorneSExpliqueQuandMeme(): void
    {
        $this->planteur->setBaremeTransport($this->forfait(8_000));
        $this->em->flush();

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');

        self::assertSame('Forfait, quel que soit le tonnage', $pesee->getExplicationTransport());
    }

    /* ---- La résolution -------------------------------------------------------------------- */

    /*
        - DEUX SAUTS, et « aucun » n'est pas un échec : c'est le cas de la plupart des planteurs.
    */
    public function testLeBaremeDuPlanteurLEmporteSurCeluiDeSaCategorie(): void
    {
        $categorie = $this->creerCategorie($this->entreprise);
        $categorie->setBaremeTransport($this->forfait(8_000));

        $this->planteur->setCategorie($categorie);
        $this->em->flush();

        // Sans barème propre : celui de sa catégorie
        $parCategorie = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');
        self::assertSame(8_000, $parCategorie->getMontanttransport());
        self::assertSame(OrigineTransport::CATEGORIE, $parCategorie->getOrigineTransport());

        // Avec un barème propre : le sien
        $this->planteur->setBaremeTransport($this->forfait(20_000, 'Son forfait'));
        $this->em->flush();

        self::assertTrue($parCategorie->calculerMontant());
        self::assertSame(20_000, $parCategorie->getMontanttransport());
        self::assertSame(OrigineTransport::PLANTEUR, $parCategorie->getOrigineTransport());
    }

    public function testSansAucunBaremeLeTransportEstNulEtDit(): void
    {
        $pesee = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');

        self::assertSame(0, $pesee->getMontanttransport());
        self::assertSame(OrigineTransport::AUCUN, $pesee->getOrigineTransport());
        self::assertNull($pesee->getExplicationTransport(), 'Ne pas payer de transport est normal : il n y a rien à expliquer');
    }

    /* ---- Les deux silences ---------------------------------------------------------------- */

    /* ---- La revalorisation ---------------------------------------------------------------- */

    public function testChangerUnBaremeRevaloriseLesPeseesQuiLeSuivent(): void
    {
        $bareme = $this->forfait(8_000);
        $this->planteur->setBaremeTransport($bareme);
        $this->em->flush();

        $pesee = $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '1');
        self::assertSame(8_000, $pesee->getMontanttransport());

        $this->appeler('PATCH', '/api/bareme_transports/' . $bareme->getId(), $this->admin, ['montant' => 11_000]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->refresh($pesee);
        self::assertSame(11_000, $pesee->getMontanttransport());
    }

    /*
        - LA SÉLECTION SUIT LA RÈGLE DE RÉSOLUTION, et le 'baremeTransport IS NULL' du second terme
          n'est pas décoratif : sans lui, changer le barème d'une catégorie revaloriserait aussi les
          pesées de ses planteurs qui en ont un à eux, et que ce changement ne concerne pas.
    */
    public function testUnBaremeDeCategorieNeTouchePasCeuxQuiOntLeLeur(): void
    {
        $categorie = $this->creerCategorie($this->entreprise);
        $deLaCategorie = $this->forfait(8_000, 'Celui de la catégorie');
        $categorie->setBaremeTransport($deLaCategorie);

        $suiveur = $this->creerPlanteur($this->site, 'Suiveur', '0700000002');
        $suiveur->setCategorie($categorie);

        $this->planteur->setCategorie($categorie);
        $this->planteur->setBaremeTransport($this->forfait(20_000, 'Le sien'));
        $this->em->flush();

        $this->creerPesee($this->site, $this->hevea, $suiveur, 1_000, '1');
        $this->creerPesee($this->site, $this->hevea, $this->planteur, 1_000, '2');

        $trouvees = self::getContainer()->get(OperationRepository::class)
            ->findARevaloriser($deLaCategorie, PartPesee::TRANSPORT);

        self::assertCount(1, $trouvees, 'Seul celui qui suit vraiment la catégorie');
        self::assertSame($suiveur->getId(), $trouvees[0]->getFournisseur()?->getId());
    }

    /* ---- La suppression ------------------------------------------------------------------- */

    public function testUnBaremeSuiviNeSeSupprimePas(): void
    {
        $bareme = $this->forfait(8_000);
        $this->planteur->setBaremeTransport($bareme);
        $this->em->flush();

        $this->appeler('PATCH', '/api/bareme_transports/' . $bareme->getId() . '/suppression', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 planteur suit encore ce barème', (string) $this->reponse()['detail']);
    }

    /*
        - LE REFUS COMPTE LES DEUX, ET LES NOMME SÉPARÉMENT : ils ne se corrigent pas au même
          endroit. Un total unique — « 3 suiveurs » — laisserait chercher lesquels, et où.
    */
    public function testLeRefusNommeLesPlanteursEtLesCategoriesSeparement(): void
    {
        $bareme = $this->forfait(8_000);

        $this->planteur->setBaremeTransport($bareme);
        $this->creerPlanteur($this->site, 'Second', '0700000002')->setBaremeTransport($bareme);
        $this->creerCategorie($this->entreprise)->setBaremeTransport($bareme);
        $this->em->flush();

        $this->appeler('PATCH', '/api/bareme_transports/' . $bareme->getId() . '/suppression', $this->admin, []);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            '2 planteurs et 1 catégorie suivent encore ce barème',
            (string) $this->reponse()['detail']
        );
    }

    public function testUnBaremeQuePersonneNeSuitSeSupprime(): void
    {
        $bareme = $this->forfait(8_000);

        $this->appeler('PATCH', '/api/bareme_transports/' . $bareme->getId() . '/suppression', $this->admin, []);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /* ---- Ce que la fiche du planteur annonce ------------------------------------------------ */

    /*
        - LA LISTE LISAIT 'baremeTransport', donc LE SIEN, et annonçait « Aucun » à tous ceux que
          leur catégorie règle déjà. Elle criait à l'oubli sur des planteurs en ordre, et c'est
          précisément la colonne qui existe pour signaler les vrais oublis.

        - L'API rend donc la résolution faite, ORIGINE COMPRISE : « par sa catégorie » prévient que
          corriger ce transport-là se fait ailleurs, et touchera tout le groupe.
    */
    public function testLaFicheAnnonceLeBaremeQuiSAppliqueVraiment(): void
    {
        $categorie = $this->creerCategorie($this->entreprise);
        $categorie->setBaremeTransport($this->forfait(8_000, 'Forfait village'));
        $this->planteur->setCategorie($categorie);
        $this->em->flush();

        self::assertSame('Forfait village (par sa catégorie)', $this->planteur->getTransportApplique());

        $this->planteur->setBaremeTransport($this->forfait(20_000, 'Le sien'));

        self::assertSame('Le sien', $this->planteur->getTransportApplique(), 'Le sien l\'emporte, et ne s\'annonce pas comme hérité');

        $this->planteur->setBaremeTransport(null)->setCategorie(null);

        self::assertNull($this->planteur->getTransportApplique(), 'Rien à annoncer n\'est pas une anomalie');
    }

    /* ---- Le filtre « sans transport » ------------------------------------------------------ */

    /*
        - « QUI NE TOUCHE AUCUN TRANSPORT ? » est la seule question qui sépare « on ne lui en paie
          pas », qui est voulu et courant, de « on a oublié », qui coûte de l'argent à quelqu'un.
          Les deux donnent la même ligne en base.

        - Le cas tient le piège du filtre naïf : 'baremeTransport IS NULL' rendrait AUSSI les
          planteurs qu'une catégorie règle déjà. La liste se remplirait de faux oublis, on
          cesserait de la regarder, et les vrais s'y cacheraient.
    */
    public function testLeFiltreSansTransportSuitLesDeuxSauts(): void
    {
        $categorie = $this->creerCategorie($this->entreprise);
        $categorie->setBaremeTransport($this->forfait(8_000));

        $parSaCategorie = $this->creerPlanteur($this->site, 'Par sa categorie', '0700000002');
        $parSaCategorie->setCategorie($categorie);

        $parLuiMeme = $this->creerPlanteur($this->site, 'Par lui meme', '0700000003');
        $parLuiMeme->setBaremeTransport($this->forfait(20_000, 'Le sien'));

        $sansRien = $this->creerPlanteur($this->site, 'Sans rien', '0700000004');

        /* Classé dans une catégorie qui ne porte aucun barème : aussi démuni que s'il n'en avait pas */
        $classeSansBareme = $this->creerPlanteur($this->site, 'Classe sans bareme', '0700000005');
        $classeSansBareme->setCategorie($this->creerCategorie($this->entreprise, 'Sans barème'));

        $this->em->flush();

        self::assertSame(
            ['Classe sans bareme', 'Sans rien', 'Traore'],
            $this->nomsFiltres('sansTransport=1'),
            'Celui que sa catégorie règle ne doit pas passer pour un oubli'
        );

        self::assertSame(
            ['Par lui meme', 'Par sa categorie'],
            $this->nomsFiltres('sansTransport=0'),
            'Les deux sauts comptent : par soi-même comme par sa catégorie'
        );
    }

    /*
        - UNE VALEUR QU'ON NE SAIT PAS LIRE NE FILTRE RIEN, au lieu de vider la liste : un filtre
          qui ne rend aucune ligne ressemble en tout point à un référentiel vide, et c'est ainsi
          qu'on cherche une heure un planteur qui n'a jamais bougé.
    */
    public function testUneValeurIllisibleNeVidePasLaListe(): void
    {
        $this->creerPlanteur($this->site, 'Autre', '0700000002');

        self::assertSame(['Autre', 'Traore'], $this->nomsFiltres('sansTransport=peut-etre'));
        self::assertSame(['Autre', 'Traore'], $this->nomsFiltres(''));
    }

    /** @return list<string> Les noms rendus par la liste, triés pour que le cas ne dépende pas de l'ordre. */
    private function nomsFiltres(string $requete): array
    {
        $this->appeler('GET', '/api/fournisseurs?' . $requete, $this->admin);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $noms = array_column($this->membres(), 'nom');
        sort($noms);

        return $noms;
    }

    /* ---- Le montage ----------------------------------------------------------------------- */

    private function bareme(ModeTransport $mode, string $libelle = 'Barème'): BaremeTransport
    {
        return (new BaremeTransport())
            ->setLibelle($libelle)
            ->setMode($mode)
            ->setEntreprise($this->entreprise);
    }

    private function forfait(int $montant, string $libelle = 'Forfait'): BaremeTransport
    {
        $bareme = $this->bareme(ModeTransport::FORFAIT, $libelle)->setMontant($montant);

        $this->em->persist($bareme);
        $this->em->flush();

        return $bareme;
    }

    /*
        - TOUS les motifs de refus, en toutes lettres : une saisie fautive en déclenche parfois deux,
          et affirmer le premier rendrait le cas dépendant de l'ordre dans lequel la validation les
          trouve.

        - Les cas affirment la PHRASE, pas un code : c'est elle que lit celui qui saisit, et une
          contrainte qui lève sans rien expliquer ne vaut guère mieux qu'un silence.
    */
    private function refus(object $entite): string
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($entite);

        self::assertGreaterThan(0, $violations->count(), 'Cette situation aurait dû être refusée');

        $motifs = [];

        foreach($violations as $violation) {
            $motifs[] = (string) $violation->getMessage();
        }

        return implode(' | ', $motifs);
    }
}
