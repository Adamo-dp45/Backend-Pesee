<?php

namespace App\Controller;

use App\Domain\Service\SynchronisationService;
use App\Entity\Operation;
use App\Entity\User;
use App\Repository\FournisseurRepository;
use App\Repository\OperationRepository;
use App\Repository\ProduitRepository;
use App\Repository\SiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/*
    - Les routes consommées par le logiciel de pesée. Leur forme est conservée à l'identique : mêmes chemins, mêmes noms de champs en entrée comme en sortie. Seul l'en-tête 'Authorization' s'ajoute, ce qui limite la reprise côté poste à la lecture d'un fichier de configuration.

    - 'ROLE_SITE' est le compte machine d'un pont bascule, et le site en découle. Les référentiels sont eux aussi bornés à ce site : auparavant ils prenaient un code en paramètre sans vérifier qu'il appartenait à l'appelant.
*/
#[Route('/api', name: 'synchronisation.')]
#[IsGranted('ROLE_SITE')]
final class SynchronisationController extends AbstractController
{
    public function __construct(
        private readonly SynchronisationService $synchronisation,
        private readonly OperationRepository $operationRepository
    )
    {
    }

    #[Route('/synchronisation', name: 'index', methods: ['POST'])]
    public function synchroniser(Request $request): JsonResponse
    {
        $pesees = $this->corps($request)['pesees'] ?? null;

        if(!is_array($pesees) || $pesees === []) {
            return $this->json([
                'code' => 3,
                'fail' => 0,
                'success' => 0,
                'operation' => [],
                'message' => 'Pas de donnees',
                'erreurs' => [],
            ]);
        }

        return $this->json($this->synchronisation->traiter($this->compteMachine(), array_values($pesees), $request->getClientIp()));
    }

    /* ---- Référentiels des listes déroulantes de l'écran de pesée -------------------------- */

    #[Route('/mouvement', name: 'mouvement', methods: ['POST'])]
    public function mouvement(): JsonResponse
    {
        return $this->json($this->referentiel('mouvement'));
    }

    #[Route('/client', name: 'client', methods: ['POST'])]
    public function client(): JsonResponse
    {
        return $this->json($this->referentiel('client'));
    }

    #[Route('/destination', name: 'destination', methods: ['POST'])]
    public function destination(): JsonResponse
    {
        return $this->json($this->referentiel('destination'));
    }

    #[Route('/provenance', name: 'provenance', methods: ['POST'])]
    public function provenance(): JsonResponse
    {
        return $this->json($this->referentiel('provenance'));
    }

    #[Route('/transporteur', name: 'transporteur', methods: ['POST'])]
    public function transporteur(): JsonResponse
    {
        return $this->json($this->referentiel('transporteur'));
    }

    #[Route('/chauffeur', name: 'chauffeur', methods: ['POST'])]
    public function chauffeur(): JsonResponse
    {
        return $this->json($this->referentiel('chauffeur'));
    }

    #[Route('/vehicule', name: 'vehicule', methods: ['POST'])]
    public function vehicule(): JsonResponse
    {
        return $this->json($this->referentiel('vehicule'));
    }

    /*
        - Produits et planteurs ne sont plus des valeurs libres lues dans les pesées : ce sont des entités depuis leur extraction. La forme de la réponse ne change pas pour autant, et le prix est désormais transmis — c'est ce qui permet au poste de récupérer une tarification faite en ligne.
    */
    #[Route('/produit', name: 'produit', methods: ['POST'])]
    public function produit(ProduitRepository $produits): JsonResponse
    {
        $site = $this->synchronisation->resoudreSite($this->compteMachine());

        return $this->json(array_map(static fn($produit) => [
            'produit' => $produit->getLibelle(),
            'codeproduit' => $produit->getCodeproduit(),
            'prix' => $produit->getPrix(),
        ], $produits->findPourLePoste($site)));
    }

    #[Route('/fournisseur', name: 'fournisseur', methods: ['POST'])]
    public function fournisseur(FournisseurRepository $fournisseurs): JsonResponse
    {
        $site = $this->synchronisation->resoudreSite($this->compteMachine());

        return $this->json(array_map(static fn($fournisseur) => [
            'fournisseur' => $fournisseur->getNom(),
            'codefournisseur' => $fournisseur->getCodefournisseur(),
            'prixspeciale' => $fournisseur->getPrixspeciale(),
            'contact' => $fournisseur->getContact1(),
        ], $fournisseurs->findPourLePoste($site)));
    }

    /*
        - Les ponts bascules de l'entreprise du poste. La clé 'codesite' est conservée telle quelle bien que la colonne s'appelle désormais 'code' : c'est ce que le logiciel de pesée attend.
    */
    #[Route('/site', name: 'site', methods: ['POST'])]
    public function site(SiteRepository $sites): JsonResponse
    {
        $entreprise = $this->compteMachine()->getEntreprise();

        if($entreprise === null) {
            return $this->json([]);
        }

        return $this->json(array_map(static fn($site) => [
            'id' => $site->getId(),
            'codesite' => $site->getCode(),
            'libellesite' => $site->getLibellesite(),
        ], $sites->findBy(['entreprise' => $entreprise, 'deletedAt' => null], ['libellesite' => 'ASC'])));
    }

    /* ---- Écran « Bilan standard » du poste ------------------------------------------------ */

    #[Route('/lister', name: 'lister', methods: ['POST'])]
    public function lister(Request $request): JsonResponse
    {
        $site = $this->synchronisation->resoudreSite($this->compteMachine());
        $donnees = $this->corps($request);

        // La limite de 500 lignes en dur a laissé place à une pagination. Les paramètres sont facultatifs : sans eux le poste reçoit la première page, comme auparavant
        $page = max(1, (int) ($donnees['page'] ?? 1));
        $limit = min(1000, max(1, (int) ($donnees['limit'] ?? 500)));

        $resultat = $this->operationRepository->lister(
            $site,
            [
                'mouvement' => $donnees['mouvement'] ?? null,
                'client' => $donnees['client'] ?? null,
                'destination' => $donnees['destination'] ?? null,
                'provenance' => $donnees['provenance'] ?? null,
                'transporteur' => $donnees['transporteur'] ?? null,
                'chauffeur' => $donnees['chauffeur'] ?? null,
                'vehicule' => $donnees['immatriculation'] ?? null,
                'produit' => $donnees['produit'] ?? null,
                'fournisseur' => $donnees['fournisseur'] ?? null,
            ],
            $this->horodatage($donnees['datepesee1'] ?? null, '00:00:00'),
            $this->horodatage($donnees['datepesee2'] ?? null, '23:59:59'),
            $page,
            $limit
        );

        return $this->json([
            'msg' => 'OK',
            'total' => $resultat['poidsnet'],
            'rows' => array_map($this->versLigneBilan(...), $resultat['rows']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'nombre' => $resultat['total'],
                'pages' => (int) ceil($resultat['total'] / $limit),
            ],
        ]);
    }

    /*
        - Réponse explicite plutôt que sérialisation automatique de l'entité. L'ancien endpoint renvoyait l'objet tel quel, ce qui liait le poste à la structure interne de la table : renommer une colonne cassait l'écran de bilan sans prévenir.
        - Les noms de champs sont ceux du format d'origine, y compris pour les colonnes qui ont changé de nom en base.

        @return array<string, mixed>
     */
    private function versLigneBilan(Operation $operation): array
    {
        return [
            'id' => $operation->getRefDesktop(),
            'code' => $operation->getSite()?->getCode(),
            'libellesite' => $operation->getSite()?->getLibellesite(),
            'codepesee' => $operation->getCodepesee(),
            'numticket' => $operation->getNumticket(),
            'mouvement' => $operation->getMouvement(),
            'client' => $operation->getClient(),
            'destination' => $operation->getDestination(),
            'provenance' => $operation->getProvenance(),
            'transporteur' => $operation->getTransporteur(),
            'chauffeur' => $operation->getChauffeur(),
            'produit' => $operation->getProduit()?->getLibelle(),
            'fournisseur' => $operation->getFournisseur()?->getNomComplet(),
            'immatriculation' => $operation->getImmatriculation(),
            'remorque' => $operation->getRemorque(),
            'conteneur' => $operation->getConteneur(),
            'plomb' => $operation->getPlomb(),
            'peseur' => $operation->getPeseur(),
            'poids1' => $operation->getPoids1(),
            'poids2' => $operation->getPoids2(),
            'poidsbrut' => $operation->getPoidsbrut(),
            'poidsnet' => $operation->getPoidsnet(),
            'datepesee1' => $operation->getPeseeAt1()?->format('Y-m-d'),
            'temps1' => $operation->getPeseeAt1()?->format('H:i:s'),
            'datepesee2' => $operation->getPeseeAt2()?->format('Y-m-d'),
            'temps2' => $operation->getPeseeAt2()?->format('H:i:s'),
            // Alimente l'état « Imprimer avec gain » du poste
            'prixunitaire' => $operation->getPrixunitaire(),
            'montant' => $operation->getMontantdu(),
        ];
    }

    /** @return list<array<string, string>> */
    private function referentiel(string $nom): array
    {
        $site = $this->synchronisation->resoudreSite($this->compteMachine());

        return $this->operationRepository->getReferentiel($site, $nom);
    }

    private function compteMachine(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array<string, mixed> */
    private function corps(Request $request): array
    {
        try {
            return $request->toArray();
        } catch(\Throwable) {
            return [];
        }
    }

    /*
        - L'écran « Bilan standard » filtre sur une plage de dates. On borne la journée entière pour que le filtre reste inclusif, comme le fait le poste avec ses heures 00:00:01 et 23:59:59.
    */
    private function horodatage(mixed $date, string $heure): ?\DateTimeImmutable
    {
        if(!is_string($date) || trim($date) === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        $horodatage = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($date) . ' ' . $heure);

        return $horodatage === false ? null : $horodatage;
    }
}
