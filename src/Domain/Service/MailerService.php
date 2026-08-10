<?php

namespace App\Domain\Service;

use App\Domain\Enum\Role;
use App\Domain\Enum\StatutDemande;
use App\Entity\DemandeSolde;
use App\Entity\Paiement;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/*
    - Les courriels de l'application. Une méthode par événement métier plutôt qu'un 'notifier()'
      générique : le sujet et le corps font partie de l'événement, et un service qui prend un titre
      et un texte déplace simplement la rédaction chez l'appelant.

    - AUCUN envoi ne doit faire échouer le geste qui l'a déclenché. Un serveur SMTP injoignable ne
      peut pas annuler un versement déjà parti ni une demande déjà approuvée : les erreurs sont
      journalisées et avalées, c'est le sens de 'envoyer()'.

    - Le défaut versionné de 'MAILER_DSN' est 'null://null' : rien ne part, mais le message reste
      consultable dans le panneau « Mailer » du profiler. Le vrai SMTP se renseigne dans '.env.local'.
*/
class MailerService
{
    public function __construct(
        private MailerInterface $mailer,
        private ParameterBagInterface $params,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    )
    {
    }

    public function motDePasseOublie(string $to, string $lien): void
    {
        $this->envoyer($to, 'Réinitialisation de votre mot de passe', "
            <h2>Réinitialisation du mot de passe</h2>
            <p>Cliquez sur le lien ci-dessous :</p>
            <a href='$lien'>Réinitialiser mon mot de passe</a>
            <p>Ce lien expire dans une heure et ne sert qu'une fois.</p>
        ");
    }

    /*
        - Une demande déposée part vers les administrateurs de l'entreprise : sans cela elle n'est vue
          qu'en ouvrant l'écran, et un opérateur à court d'argent attend sans savoir si quelqu'un a lu.
    */
    public function demandeDeposee(DemandeSolde $demande): void
    {
        $site = $demande->getSite();
        $entreprise = $site?->getEntreprise();

        if($entreprise === null) {
            return;
        }

        $sujet = sprintf('Demande de solde — %s', $site?->getLibellesite() ?? 'pont bascule');
        $corps = sprintf("
            <h2>Nouvelle demande de réapprovisionnement</h2>
            <p><strong>%s</strong> demande <strong>%s F CFA</strong> pour %s.</p>
            <p>Motif : %s</p>
        ",
            $this->echapper($demande->getDemandePar()?->getNomComplet() ?? 'Un opérateur'),
            number_format($demande->getMontantDemande(), 0, ',', ' '),
            $this->echapper((string) $site?->getLibellesite()),
            $this->echapper($demande->getMotif() ?? 'non précisé')
        );

        foreach($this->administrateurs($entreprise->getId()) as $administrateur) {
            $this->envoyer((string) $administrateur->getEmail(), $sujet, $corps);
        }
    }

    /*
        - La réponse part vers l'AUTEUR de la demande, et porte le montant accordé ou le motif du
          refus : c'est exactement ce qu'il lui faut pour agir, sans rouvrir l'application.
    */
    public function demandeTraitee(DemandeSolde $demande): void
    {
        $auteur = $demande->getDemandePar();

        if($auteur === null) {
            return;
        }

        $approuvee = $demande->getStatut() === StatutDemande::APPROUVEE;

        $corps = $approuvee
            ? sprintf('<h2>Demande approuvée</h2><p>La caisse de %s a été dotée de <strong>%s F CFA</strong>.</p>',
                $this->echapper((string) $demande->getSite()?->getLibellesite()),
                number_format((int) $demande->getMontantAccorde(), 0, ',', ' '))
            : sprintf('<h2>Demande rejetée</h2><p>Motif : %s</p>', $this->echapper((string) $demande->getMotifRejet()));

        $this->envoyer((string) $auteur->getEmail(), $approuvee ? 'Votre demande de solde a été approuvée' : 'Votre demande de solde a été rejetée', $corps);
    }

    /*
        - Un versement échoué est remboursé automatiquement, mais personne ne le sait : le planteur
          est reparti, l'opérateur croit l'avoir payé. C'est la notification la plus utile des trois.
    */
    public function paiementEchoue(Paiement $paiement): void
    {
        $destinataire = $paiement->getInitiePar();

        if($destinataire === null) {
            return;
        }

        $this->envoyer(
            (string) $destinataire->getEmail(),
            'Versement échoué — ' . $paiement->getReference(),
            sprintf("
                <h2>Le versement n'est pas passé</h2>
                <p>Référence <strong>%s</strong> · <strong>%s F CFA</strong> vers %s.</p>
                <p>Motif : %s</p>
                <p>La somme a été recréditée sur la caisse du poste. Le planteur n'a rien reçu.</p>
            ",
                $this->echapper((string) $paiement->getReference()),
                number_format($paiement->getMontant(), 0, ',', ' '),
                $this->echapper((string) $paiement->getNumeroDestinataire()),
                $this->echapper($paiement->getMessageErreur() ?? 'non précisé')
            )
        );
    }

    /** @return list<User> Les administrateurs actifs de l'entreprise, comptes machine exclus */
    private function administrateurs(?int $entrepriseId): array
    {
        if($entrepriseId === null) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.entreprise = :entreprise')
            ->andWhere('u.siteMachine IS NULL')
            ->andWhere('u.statut = :actif')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('entreprise', $entrepriseId)
            ->setParameter('actif', \App\Domain\Enum\StatutActivation::ACTIF)
            ->setParameter('role', '%' . Role::ADMIN->value . '%')
            ->getQuery()
            ->getResult();
    }

    private function envoyer(string $to, string $sujet, string $corps): void
    {
        if(trim($to) === '') {
            return;
        }

        try {
            $this->mailer->send(
                (new Email())
                    ->from("{$this->params->get('app.mailer.name')} <{$this->params->get('app.mailer.email')}>")
                    ->to($to)
                    ->subject($sujet)
                    ->html($corps)
            );
        } catch(\Throwable $e) {
            // Journalisé, jamais propagé : un SMTP injoignable ne doit pas annuler le geste métier
            $this->logger->error('Envoi de courriel impossible vers {destinataire} : {erreur}', [
                'destinataire' => $to,
                'erreur' => $e->getMessage(),
            ]);
        }
    }

    // Les corps sont du HTML composé ici : un nom de planteur avec une esperluette ne doit pas casser le message
    private function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
