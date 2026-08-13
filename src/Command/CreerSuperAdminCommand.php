<?php

namespace App\Command;

use App\Domain\Enum\Role;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Le seul moyen d'obtenir un super administrateur. Le rôle est volontairement absent de 'Role::attribuables()' et de la table de 'AccesVoter' : personne ne peut se le donner par l'API, il faut un accès au serveur.
    - À lancer une fois à l'installation. Il n'appartient à aucune entreprise : il provisionne les clients, il n'exploite rien.
*/
#[AsCommand(name: 'app:super-admin:creer', description: 'Crée le compte super administrateur de la plateforme')]
class CreerSuperAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $hasher
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du compte')
            ->addOption('nom', null, InputOption::VALUE_REQUIRED, 'Nom')
            ->addOption('prenom', null, InputOption::VALUE_REQUIRED, 'Prénom')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED, 'Mot de passe (à éviter : il reste dans l\'historique du shell)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getOption('email') ?? $io->ask('Adresse e-mail');
        $nom = $input->getOption('nom') ?? $io->ask('Nom', 'Administrateur');
        $prenom = $input->getOption('prenom') ?? $io->ask('Prénom', 'Super');

        if(!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('Cette adresse e-mail n\'est pas valide.');

            return Command::INVALID;
        }

        if($this->userRepository->findOneByEmail($email) !== null) {
            $io->error(sprintf('Un compte existe déjà avec l\'adresse "%s".', $email));

            return Command::FAILURE;
        }

        $motDePasse = $input->getOption('mot-de-passe') ?? $this->demanderMotDePasse($io);

        if(!is_string($motDePasse) || strlen($motDePasse) < 6) {
            $io->error('Le mot de passe doit faire au moins 6 caractères.');

            return Command::INVALID;
        }

        $compte = (new User())
            ->setEmail($email)
            ->setNom((string) $nom)
            ->setPrenom((string) $prenom)
            ->setRole(Role::SUPER_ADMIN); // Sans entreprise : il les gère toutes, il n'appartient à aucune

        $compte->setPassword($this->hasher->hashPassword($compte, $motDePasse));

        $this->em->persist($compte);
        $this->em->flush();

        $io->success(sprintf('Super administrateur créé : %s', $compte->getEmail()));

        /*
            - Relancer la commande crée un second compte, et c'est voulu : 'UserGuard' interdit à quiconque de gérer un super administrateur depuis l'interface, même à un pair. Perdre l'accès à l'unique compte se règle donc en repassant par ici, pas en base.
        */
        $io->note('Ce compte ne se gère pas depuis l\'interface web. Relancez cette commande pour en créer un autre.');

        return Command::SUCCESS;
    }

    // Saisie masquée, et confirmation : un mot de passe mal tapé ici ne se corrige que par une seconde exécution
    private function demanderMotDePasse(SymfonyStyle $io): ?string
    {
        $question = (new Question('Mot de passe'))->setHidden(true)->setHiddenFallback(false);
        $motDePasse = $io->askQuestion($question);

        $confirmation = (new Question('Confirmation'))->setHidden(true)->setHiddenFallback(false);

        if($motDePasse !== $io->askQuestion($confirmation)) {
            $io->error('Les deux saisies ne correspondent pas.');

            return null;
        }

        return $motDePasse;
    }
}
