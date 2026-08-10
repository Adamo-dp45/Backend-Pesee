<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Domain\Service\MailerService;
use App\Entity\Input\MotDePasseOublieInput;
use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/*
    - Demande de réinitialisation.
    - Réponse identique que l'adresse existe ou non, et toujours 202 : sinon ce point d'entrée public devient un outil d'énumération des comptes de tous les clients.
    - Les demandes précédentes sont périmées : trois clics ne doivent pas laisser trois liens valables dans une boîte mail.
*/

/**
 * @implements ProcessorInterface<MotDePasseOublieInput, null>
 */
class MotDePasseOublieProcessor implements ProcessorInterface
{
    private const DUREE_VALIDITE = '+1 hour';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private MailerService $mailer,
        private RequestStack $requestStack,
        private RateLimiterFactoryInterface $motDePasseLimiter
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->limiter();

        $utilisateur = $this->userRepository->findOneByEmail((string) $data->email);

        // Un compte machine n'a pas de boîte mail, un compte suspendu n'a pas à revenir par là
        if($utilisateur === null || $utilisateur->estCompteMachine() || !$utilisateur->estActif()) {
            return null;
        }

        $this->tokenRepository->invaliderPrecedents($utilisateur);

        $jeton = (new PasswordResetToken())
            ->setUser($utilisateur)
            ->setToken(bin2hex(random_bytes(32)))
            ->setExpiresAt(new \DateTimeImmutable(self::DUREE_VALIDITE));

        $this->em->persist($jeton);
        $this->em->flush();

        $separateur = str_contains((string) $data->urlReinitialisation, '?') ? '&' : '?';

        $this->mailer->motDePasseOublie(
            (string) $utilisateur->getEmail(),
            $data->urlReinitialisation . $separateur . 'token=' . $jeton->getToken()
        );

        return null;
    }

    /*
        - Point d'entrée public qui ENVOIE UN COURRIEL : sans limite, on inonde la boîte de n'importe qui et on brûle le quota SMTP de l'hébergement.
    */
    private function limiter(): void
    {
        $requete = $this->requestStack->getCurrentRequest();

        if($requete === null) {
            return;
        }

        if(!$this->motDePasseLimiter->create($requete->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop de demandes de réinitialisation. Réessayez plus tard.');
        }
    }
}
