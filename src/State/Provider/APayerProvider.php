<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation as ApiOperation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Output\APayerOutput;
use App\Repository\OperationRepository;
use App\Security\Perimetre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/*
    - LES TOTAUX DE LA FILE DU GUICHET. L'écran « À payer » liste les pesées terminées et non
      soldées ; celui-ci dit ce qu'elles pèsent, sans que personne n'ait à additionner la page.

    - IL PARLE LA MÊME LANGUE QUE LA COLLECTION, et c'est volontaire : il lit 'fournisseur',
      'produit', 'site', 'numticket' et 'peseeAt2[after]/[before]', exactement les noms des filtres
      de '/api/operations'. Le web envoie donc le MÊME tableau de paramètres aux deux appels, et ne
      peut pas filtrer la liste d'un côté et le total de l'autre. Un total qui décrit une autre
      sélection que la liste ne lève rien : il affiche simplement un chiffre faux.

    - Les deux bornes de l'écran — terminée, pas encore soldée — sont posées par le dépôt et ne se
      décochent pas. Une pesée soldée ou encore sur le pont n'a rien à régler.
*/

/**
 * @implements ProviderInterface<APayerOutput>
 */
final class APayerProvider implements ProviderInterface
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly OperationRepository $operations,
        private readonly RequestStack $requetes
    )
    {
    }

    public function provide(ApiOperation $operation, array $uriVariables = [], array $context = []): APayerOutput
    {
        $sortie = new APayerOutput();
        $requete = $this->requetes->getCurrentRequest();

        $totaux = $this->operations->totauxAPayer($this->perimetre->siteIds(), [
            'fournisseur' => $this->entier($requete, 'fournisseur'),
            'produit' => $this->entier($requete, 'produit'),
            'site' => $this->entier($requete, 'site'),
            'numticket' => $requete?->query->get('numticket'),
            'du' => $this->borne($requete, 'after'),
            'au' => $this->borne($requete, 'before'),
        ]);

        $sortie->totaux = [
            'reste' => $totaux['resteProduit'] + $totaux['resteTransport'],
            'resteProduit' => $totaux['resteProduit'],
            'resteTransport' => $totaux['resteTransport'],
            'dejaVerse' => $totaux['dejaVerse'],
            'enVol' => $totaux['enVol'],
            'nbPesees' => $totaux['nbPesees'],
            'nbSansPrix' => $totaux['nbSansPrix'],
        ];

        return $sortie;
    }

    /* Un identifiant, ou rien. Une valeur illisible ne filtre pas, plutôt que de vider le total en silence. */
    private function entier(?Request $requete, string $cle): ?int
    {
        $valeur = $requete?->query->get($cle);

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    /*
        - Les bornes arrivent sous 'peseeAt2[after]' et 'peseeAt2[before]', les noms du 'DateFilter'
          d'API Platform. Les lire sous un autre nom obligerait le web à traduire une fois pour la
          liste et une fois pour le total — et c'est entre ces deux traductions que l'écart naîtrait.
    */
    private function borne(?Request $requete, string $cote): ?\DateTimeImmutable
    {
        $valeur = $requete?->query->all('peseeAt2')[$cote] ?? null;

        if(!is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($valeur));
        } catch(\Exception) {
            return null; // Une date illisible ne borne rien : mieux vaut un total large qu'un total vide sans explication
        }
    }
}
