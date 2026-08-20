<?php

namespace App\Command;

use App\Domain\Support\NumeroMobile;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/*
    - À passer UNE FOIS, après la mise en service de la normalisation. Les setters ne s'appliquent qu'aux écritures à venir : les planteurs déjà en base gardent la forme dans laquelle ils ont été saisis, et resteraient impayables sans cette reprise.
    - Elle ne réécrit que ce qu'elle sait lire. Un numéro qu'elle ne reconnaît pas est laissé tel quel et signalé : il demande un humain qui connaisse le planteur, pas une règle.
    - Elle passe par 'setContact1()' plutôt que par une requête SQL de masse : la règle de normalisation reste à un seul endroit, et un UPDATE massif se serait périmé au premier changement de format.
*/
#[AsCommand(name: 'app:fournisseurs:normaliser-numeros', description: 'Réécrit les numéros des planteurs au format attendu par les agrégateurs')]
class NormaliserNumerosCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait réécrit sans rien enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        if($simulation) {
            $io->note('Simulation : aucune modification ne sera enregistrée.');
        }

        /*
            - Les supprimés sont inclus : une fiche restaurée depuis la corbeille doit revenir payable, pas revenir à la forme d'avant.
        */
        $planteurs = $this->em->createQuery(
            'SELECT f FROM ' . Fournisseur::class . ' f WHERE f.contact1 IS NOT NULL OR f.contact2 IS NOT NULL'
        )->getResult();

        $reecrits = 0;
        $illisibles = [];
        $lignes = [];
        $vus = 0;

        foreach($planteurs as $planteur) {
            /** @var Fournisseur $planteur */
            ++$vus;
            $avant = [$planteur->getContact1(), $planteur->getContact2()];

            // Les setters portent la règle : les rappeler suffit à remettre la valeur en forme
            $planteur->setContact1($avant[0])->setContact2($avant[1]);

            $apres = [$planteur->getContact1(), $planteur->getContact2()];

            foreach([0, 1] as $i) {
                if($avant[$i] === null || $avant[$i] === $apres[$i]) {
                    continue;
                }

                ++$reecrits;
                $lignes[] = [$planteur->getId(), $planteur->getNomComplet(), 'contact' . ($i + 1), $avant[$i], $apres[$i]];
            }

            foreach([0, 1] as $i) {
                if($apres[$i] !== null && !NumeroMobile::estValide($apres[$i])) {
                    $illisibles[] = sprintf('#%d %s — contact%d : %s', $planteur->getId(), $planteur->getNomComplet(), $i + 1, $apres[$i]);
                }
            }
        }

        // Un seul flush : la reprise porte sur le stock de planteurs d'une entreprise, pas sur un journal
        if(!$simulation) {
            $this->em->flush();
        }

        if($lignes !== []) {
            $io->table(['#', 'Planteur', 'Champ', 'Avant', 'Après'], $lignes);
        }

        $io->success(sprintf('%d fiche(s) examinée(s), %d numéro(s) %s.', $vus, $reecrits, $simulation ? 'à réécrire' : 'réécrit(s)'));

        if($illisibles !== []) {
            // Un avertissement, pas un échec : la commande a fait tout ce qu'une règle peut faire, le reste demande de rappeler le planteur
            $io->warning(sprintf('%d numéro(s) restent illisibles et bloqueront un versement :', count($illisibles)));
            $io->listing($illisibles);
        }

        return Command::SUCCESS;
    }
}
