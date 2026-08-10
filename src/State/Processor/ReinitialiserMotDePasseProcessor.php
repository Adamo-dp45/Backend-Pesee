<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Input\ReinitialiserMotDePasseInput;
use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/*
    - Consommation du jeton de réinitialisation.
    - Un seul message pour les trois cas — jeton inconnu, expiré, déjà utilisé — pour ne pas indiquer à un tiers qu'un jeton a existé.
*/

/**
 * @implements ProcessorInterface<ReinitialiserMotDePasseInput, null>
 */
class ReinitialiserMotDePasseProcessor implements ProcessorInterface
{
    private const LIEN_INVALIDE = 'Ce lien de réinitialisation n\'est plus valable. Demandez-en un nouveau.';

    public function __construct(
        private EntityManagerInterface $em,
        private PasswordResetTokenRepository $tokenRepository,
        private UserPasswordHasherInterface $hasher
    )
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $jeton = $this->tokenRepository->findOneUtilisable((string) $data->token);
        $utilisateur = $jeton?->getUser();

        if($utilisateur === null) {
            throw new UnprocessableEntityHttpException(self::LIEN_INVALIDE);
        }

        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, (string) $data->motDePasse));

        $jeton->setUsedAt(new \DateTimeImmutable()); // Brûlé : un lien ne sert qu'une fois

        $this->em->flush();

        return null;
    }
}
