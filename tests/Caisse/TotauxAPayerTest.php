<?php

namespace App\Tests\Caisse;

use App\Entity\Operation;
use App\Repository\OperationRepository;
use App\Tests\InvariantTestCase;

/*
    - LA RÈGLE DU RESTE EST ÉCRITE DEUX FOIS, et ce cas existe pour qu'elles ne divergent jamais.

      En PHP, 'Operation::getResteapayerproduit()' enchaîne deux clamps : le payable d'abord
      — « le dû moins ce qui part à l'épargne, jamais négatif » — puis le reste.

      En SQL, 'OperationRepository::totauxAPayer()' doit rendre LA MÊME CHOSE sur toute la file, en
      une requête. Additionner les lignes servies n'est pas une option : une page d'API est
      plafonnée à cent lignes et tronquée en silence, donc un total calculé côté web serait faux
      dès la cent-unième pesée, sans une seule erreur.

    - CE QU'UNE DIVERGENCE DONNERAIT : un chiffre plausible en tête de l'écran « À payer », qui ne
      décrit pas la liste en dessous. Personne ne le verrait — c'est pour ça qu'on le teste plutôt
      que de l'espérer.

    - LES CAS LIMITES SONT LE SUJET. 'SUM(max(0, a - b))' n'est pas 'max(0, SUM(a) - SUM(b))' : il
      suffit d'une pesée en trop-perçu pour que la seconde forme éponge la créance d'une autre.
*/
final class TotauxAPayerTest extends InvariantTestCase
{
    private OperationRepository $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operations = $this->em->getRepository(Operation::class);
    }

    /*
        - LE CAS ORDINAIRE, et le socle de tous les autres : trois pesées sans rien de particulier.
    */
    public function testLeTotalEgaleLaSommeDesLignes(): void
    {
        $site = $this->creerSite($this->creerEntreprise('ACME'));
        $produit = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        foreach (['1', '2', '3'] as $index) {
            $this->creerPesee($site, $produit, $planteur, 1_000, $index);
        }

        $this->em->flush();

        $this->comparer($site->getId(), 'trois pesées ordinaires');
    }

    /*
        - UN PRODUIT SANS PRIX ne vaut rien, et sa pesée reste dans la file tant qu'il n'est pas
          tarifé. C'est le seul blocage que le total sache compter, et il doit le compter juste.
    */
    public function testUneRecolteSansPrixNePeseRien(): void
    {
        $site = $this->creerSite($this->creerEntreprise('ACME'));
        $tarife = $this->creerProduit($site, 'Hévéa', 400);
        $sansPrix = $this->creerProduit($site, 'Cacao', 0);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $this->creerPesee($site, $tarife, $planteur, 1_000, '1');
        $this->creerPesee($site, $sansPrix, $planteur, 2_000, '2');

        $this->em->flush();

        $totaux = $this->comparer($site->getId(), 'une pesée sans prix');

        self::assertSame(1, $totaux['nbSansPrix'], 'La pesée non tarifée doit être signalée');
        self::assertSame(2, $totaux['nbPesees'], 'Elle reste dans la file : elle attend un prix, pas un versement');
    }

    /*
        - UNE RETENUE QUI DÉPASSE LE DÛ. Le payable tombe à zéro, et le reste avec : la forme à plat
          de la requête doit retomber sur le même zéro que les deux clamps de l'entité.
        - Sans ce cas, une soustraction négative passerait dans la somme et RETRANCHERAIT la créance
          des autres pesées. Le total afficherait moins que ce qu'on doit, et on cesserait de payer.
    */
    public function testUneRetenueSuperieureAuDuNeCreusePasLeTotal(): void
    {
        $site = $this->creerSite($this->creerEntreprise('ACME'));
        $produit = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $normale = $this->creerPesee($site, $produit, $planteur, 1_000, '1');
        $absorbee = $this->creerPesee($site, $produit, $planteur, 1_000, '2');

        /*
            - Toute la récolte part à l'épargne, et un peu plus : le guichet n'a rien à remettre.
            - Par 'figerLaRetenue()' et non par un setteur : c'est le seul chemin, et il est
              irréversible — l'arbitrage ne se rejoue pas.
        */
        $absorbee->figerLaRetenue(null, ($absorbee->getMontantdu() ?? 0) + 50_000);

        $this->em->flush();

        $totaux = $this->comparer($site->getId(), 'une retenue qui absorbe tout');

        self::assertSame(
            $normale->getResteapayer(),
            $totaux['resteProduit'] + $totaux['resteTransport'],
            'La pesée absorbée ne doit ni ajouter ni retrancher'
        );
    }

    /*
        - UN TROP-PERÇU. Si un tarif baisse après un versement, le payé dépasse le payable. La
          créance de CETTE pesée est nulle — elle ne devient pas une dette du planteur — et surtout
          elle ne doit pas éponger ce qui reste dû sur les autres.
    */
    public function testUnTropPercuNEpongePasLaCreanceDesAutres(): void
    {
        $site = $this->creerSite($this->creerEntreprise('ACME'));
        $produit = $this->creerProduit($site, 'Hévéa', 400);
        $planteur = $this->creerPlanteur($site, 'Kouame');

        $due = $this->creerPesee($site, $produit, $planteur, 1_000, '1');
        $tropPayee = $this->creerPesee($site, $produit, $planteur, 1_000, '2');

        /*
            - Versé au-delà du payable : le tarif a baissé entre le versement et aujourd'hui.
            - 'setMontantspayes()' pose les deux parts d'un geste : il n'existe pas de setteur par
              part, pour qu'un total ne puisse jamais cesser d'être la somme de ses parts.
        */
        $tropPayee->setMontantspayes(($tropPayee->getMontantdu() ?? 0) + 100_000, 0);

        $this->em->flush();

        $totaux = $this->comparer($site->getId(), 'un trop-perçu');

        self::assertSame(
            $due->getResteapayer(),
            $totaux['resteProduit'] + $totaux['resteTransport'],
            'Le trop-perçu d\'une pesée ne doit pas effacer la créance d\'une autre'
        );
    }

    /* ---- Le montage ----------------------------------------------------------------------- */

    /**
     * Les deux écritures de la règle, côte à côte, sur la même file.
     *
     * @return array<string, int>
     */
    private function comparer(?int $siteId, string $cas): array
    {
        self::assertNotNull($siteId);

        $totaux = $this->operations->totauxAPayer([$siteId], []);

        $attendu = ['nbPesees' => 0, 'resteProduit' => 0, 'resteTransport' => 0, 'dejaVerse' => 0];

        foreach ($this->fileDuGuichet($siteId) as $pesee) {
            $attendu['nbPesees']++;
            $attendu['resteProduit'] += $pesee->getResteapayerproduit();
            $attendu['resteTransport'] += $pesee->getResteapayertransport();
            $attendu['dejaVerse'] += $pesee->getMontantpaye();
        }

        foreach ($attendu as $cle => $valeur) {
            self::assertSame($valeur, $totaux[$cle], sprintf('%s : « %s » doit dire la même chose en SQL et en PHP', $cas, $cle));
        }

        return $totaux;
    }

    /**
     * La file telle que l'écran la borne, relue depuis les ENTITÉS.
     *
     * @return list<Operation>
     */
    private function fileDuGuichet(int $siteId): array
    {
        return $this->em->createQuery(
            'SELECT o FROM App\Entity\Operation o'
            . ' WHERE o.site = :site AND o.deletedAt IS NULL'
            . ' AND o.statut = :terminee AND o.statutReglement IN (:aRegler)'
        )
            ->setParameter('site', $siteId)
            ->setParameter('terminee', \App\Domain\Enum\StatutOperation::TERMINEE)
            ->setParameter('aRegler', [\App\Domain\Enum\StatutReglement::NON_PAYE, \App\Domain\Enum\StatutReglement::PARTIEL])
            ->getResult();
    }
}
