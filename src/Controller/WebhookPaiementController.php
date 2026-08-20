<?php

namespace App\Controller;

use App\Domain\Paiement\PasserellePaiementInterface;
use App\Domain\Service\Caisse\PaiementService;
use App\Repository\PaiementRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/*
    - Notification de l'agrégateur mobile money. Point d'entrée PUBLIC — déclaré dans 'security.yaml' — parce que l'agrégateur n'a pas de compte chez nous : il s'authentifie en signant sa requête, jamais par un jeton.

    - Toujours 200, même sur une notification inconnue ou déjà traitée. Un agrégateur qui reçoit autre chose réessaie, parfois des heures durant : répondre en erreur sur une notification qu'on a délibérément ignorée transforme un non-événement en tempête de requêtes. La seule exception est la signature invalide — une notification non signée n'est pas une notification, et l'émetteur doit le savoir.

    - L'idempotence est portée par 'PaiementService::appliquerResultat()' : un versement déjà dans un état final ne rejoue ni le remboursement ni le crédit. Recevoir deux fois la même notification est donc sans effet.
*/
#[Route('/api/webhooks/paiement', name: 'webhook.paiement.')]
final class WebhookPaiementController extends AbstractController
{
    public function __construct(
        private readonly PasserellePaiementInterface $passerelle,
        private readonly PaiementRepository $paiementRepository,
        private readonly PaiementService $paiements,
        private readonly LoggerInterface $logger
    )
    {
    }

    #[Route('', name: 'notifier', methods: ['POST'])]
    public function notifier(Request $request): JsonResponse
    {
        /*
            - La signature d'abord, sur le corps BRUT et avant tout décodage. Deux raisons :
              · l'empreinte porte sur les octets exacts qui ont été signés ; décoder puis réencoder
                donnerait un autre texte — ordre des clés, espaces, accents échappés — donc une autre
                signature, et le contrôle échouerait sur des notifications légitimes ;
              · tant que l'émetteur n'est pas authentifié, son corps ne mérite aucun traitement.
            - L'en-tête est nommé par la passerelle : chaque agrégateur a le sien, et ce contrôleur ne
              doit pas être à rouvrir le jour où l'on en change.
        */
        $corpsBrut = $request->getContent();

        if(!$this->passerelle->notificationAuthentique($corpsBrut, $request->headers->get($this->passerelle->enteteSignature()))) {
            $this->logger->warning('Signature de notification invalide sur {passerelle}', ['passerelle' => $this->passerelle->nom()]);

            return new JsonResponse(['message' => 'Signature invalide'], Response::HTTP_UNAUTHORIZED);
        }

        $corps = json_decode($corpsBrut, true);

        if(!is_array($corps)) {
            return $this->accuser('Corps illisible');
        }

        $reference = isset($corps['reference']) ? (string) $corps['reference'] : '';
        $paiement = $reference === '' ? null : $this->paiementRepository->findOneByReference($reference);

        if($paiement === null) {
            $this->logger->warning('Notification pour une référence inconnue : {reference}', ['reference' => $reference]);

            return $this->accuser('Référence inconnue');
        }

        /*
            - Le versement doit avoir été traité par la passerelle qui notifie. Sans ce contrôle, changer de passerelle ferait confirmer par la nouvelle des versements encore en vol chez l'ancienne, dont elle ne sait rien.
        */
        if($paiement->getGateway() !== $this->passerelle->nom()) {
            $this->logger->warning('Notification de {actuelle} pour un versement traité par {origine}', [
                'actuelle' => $this->passerelle->nom(),
                'origine' => $paiement->getGateway(),
            ]);

            return $this->accuser('Passerelle inattendue');
        }

        $this->paiements->appliquerResultat($paiement, $this->passerelle->lireNotification($corps));

        return $this->accuser('Notification traitée');
    }

    private function accuser(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], Response::HTTP_OK);
    }
}
