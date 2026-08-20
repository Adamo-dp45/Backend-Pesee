<?php

namespace App\Domain\Paiement;

use App\Entity\Paiement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/*
    - Passerelle de développement. Elle permet de dérouler tout le cycle — réservation des fonds, envoi, notification, remboursement sur échec — sans agrégateur réel ni argent en jeu.

    - Les déclencheurs sont volontairement lisibles pour la démonstration :
      · un numéro se terminant par '00' fait échouer le versement ;
      · un montant se terminant par '99' le fait réussir immédiatement, sans webhook.
      Tout le reste part en 'ENVOYE' et attend la notification, ce qui est le cas nominal.

    - La signature, elle, est bien réelle : même en développement, une notification non signée est refusée. Un contrôle qui ne s'exerce qu'en production est un contrôle qu'on découvre cassé en production.
*/
final class PasserelleSimulee implements PasserellePaiementInterface
{
    public function __construct(
        #[Autowire('%env(PASSERELLE_SECRET)%')]
        private readonly string $secret
    )
    {
    }

    public function nom(): string
    {
        return 'fake';
    }

    public function verser(Paiement $paiement): ResultatVersement
    {
        /*
            - Le déclencheur porte sur la FIN du numéro et non sur son début : depuis que les numéros sont normalisés ils commencent tous par '+225', et un déclencheur sur le préfixe ne se serait plus jamais déclenché — sans que rien ne le signale.
        */
        if(str_ends_with((string) $paiement->getNumeroDestinataire(), '00')) {
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

    public function enteteSignature(): string
    {
        return 'X-Signature';
    }

    /*
        - HMAC-SHA256 du corps brut, comparé avec 'hash_equals()' : une comparaison ordinaire s'arrête au premier caractère différent, et ce temps de réponse se mesure. 'hash_equals()' compare toujours toute la chaîne.
        - Un secret vide REFUSE tout, au lieu de tout accepter. Une variable d'environnement oubliée à la mise en production ouvrirait sinon un point d'entrée public capable de créditer n'importe quelle pesée.
        - Rien ici n'empêche le rejeu d'une notification captée : c'est 'PaiementService::appliquerResultat()' qui s'en charge, en ne traitant qu'une fois un versement déjà final.
    */
    public function notificationAuthentique(string $corpsBrut, ?string $signature): bool
    {
        if($this->secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $corpsBrut, $this->secret), strtolower(trim($signature)));
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
