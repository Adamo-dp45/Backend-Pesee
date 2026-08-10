<?php

namespace App\Command;

use App\Domain\Service\Caisse\PaiementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
    - Filet de sécurité des versements, à planifier toutes les quelques minutes.
    - Sans elle, un webhook perdu — coupure réseau, redéploiement au mauvais moment, panne de l'agrégateur — laisserait un versement en attente pour toujours. Or les fonds sont réservés dès la création : la caisse du site resterait amputée d'une somme que personne ne réclame, et l'opérateur ne pourrait plus payer alors que son solde affiché paraît suffisant.
*/
#[AsCommand(name: 'app:paiements:reconcilier', description: 'Réinterroge la passerelle pour les versements restés en attente')]
class ReconcilierPaiementsCommand extends Command
{
    public function __construct(
        private PaiementService $paiements
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('delai', null, InputOption::VALUE_REQUIRED, 'Ancienneté minimale d\'un versement, en minutes, avant de le réinterroger', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $traites = $this->paiements->reconcilier(max(1, (int) $input->getOption('delai')));

        $io->success($traites === 0
            ? 'Aucun versement en attente à régulariser.'
            : sprintf('%d versement(s) régularisé(s).', $traites));

        return Command::SUCCESS;
    }
}
