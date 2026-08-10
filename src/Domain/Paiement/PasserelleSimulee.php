<?php

namespace App\Domain\Paiement;

use App\Entity\Paiement;

/*
    - Passerelle de développement. Elle permet de dérouler tout le cycle — réservation des fonds, envoi, notification, remboursement sur échec — sans agrégateur réel ni argent en jeu.

    - Les déclencheurs sont volontairement lisibles pour la démonstration :
      · un numéro commençant par '00' fait échouer le versement ;
      · un montant se terminant par '99' le fait réussir immédiatement, sans webhook.
      Tout le reste part en 'ENVOYE' et attend la notification, ce qui est le cas nominal.

    - Elle accepte toute notification : il n'y a pas de secret partagé à vérifier. La vraie implémentation devra contrôler la signature, c'est ce que documente l'interface.
*/
final class PasserelleSimulee implements PasserellePaiementInterface
{
    public function nom(): string
    {
        return 'fake';
    }

    public function verser(Paiement $paiement): ResultatVersement
    {
        if(str_starts_with((string) $paiement->getNumeroDestinataire(), '00')) {
            return ResultatVersement::echoue('Numéro destinataire refusé par l\'opérateur.');
        }

        $transaction = 'SIM-' . $paiement->getReference();

        if(str_ends_with((string) $paiement->getMontant(), '99')) {
            return ResultatVersement::reussi($transaction, ['simule' => true, 'immediat' => true]);
        }

        return ResultatVersement::envoye($transaction, ['simule' => true]);
    }

    /*
        - En développement, réinterroger un versement le confirme. C'est ce qui permet à la réconciliation de débloquer les paiements laissés en attente.
    */
    public function verifier(string $reference): ResultatVersement
    {
        return ResultatVersement::reussi('SIM-' . $reference, ['simule' => true, 'reconcilie' => true]);
    }

    public function notificationAuthentique(array $corps, ?string $signature): bool
    {
        return true;
    }

    public function lireNotification(array $corps): ResultatVersement
    {
        $transaction = isset($corps['transaction']) ? (string) $corps['transaction'] : null;

        if(strtoupper((string) ($corps['statut'] ?? 'REUSSI')) === 'ECHOUE') {
            return ResultatVersement::echoue((string) ($corps['message'] ?? 'Versement refusé par l\'opérateur.'), $transaction, $corps);
        }

        return ResultatVersement::reussi($transaction ?? 'SIM', $corps);
    }
}
