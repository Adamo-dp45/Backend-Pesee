<?php

namespace App\Tests\Securite;

use App\Entity\Input\ChangerMotDePasseInput;
use App\Entity\Input\RegisterInput;
use App\Entity\Input\ReinitialiserMotDePasseInput;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/*
    - La politique de mot de passe est une DÉCISION, pas un défaut de framework : six caractères, une
      minuscule, une majuscule, un chiffre. Rien d'autre.

    - Elle est répétée sur quatre entrées — création d'un compte, inscription, changement,
      réinitialisation — et les faire diverger ne casse rien de visible : la saisie passe d'un côté et
      se fait refuser de l'autre, avec un message qui ne dit pas d'où vient le refus. C'était le cas
      avant : l'API exigeait dix caractères pendant que les écrans en annonçaient huit.

    - Le contrôle contre les fuites de mots de passe connues a été retiré : à six caractères il
      refusait presque tout, en parlant de fuites de données là où la vraie cause était la longueur.
      Un cas ci-dessous le vérifie, parce qu'un 'NotCompromisedPassword' remis « pour la sécurité »
      rendrait la politique inapplicable sans que personne ne comprenne pourquoi.
*/
final class MotDePasseTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function motsDePasse(): iterable
    {
        yield 'six caractères bien composés' => ['Abc123', true];
        yield 'cinq caractères' => ['Abc12', false];
        yield 'sans majuscule' => ['abc123', false];
        yield 'sans minuscule' => ['ABC123', false];
        yield 'sans chiffre' => ['Abcdef', false];
        yield 'long et bien composé' => ['MotDePasse1234', true];
    }

    /*
        - Les quatre entrées sont vérifiées ensemble, sur les mêmes valeurs : c'est la seule façon de
          voir tout de suite qu'elles ont cessé de dire la même chose.
    */
    #[DataProvider('motsDePasse')]
    public function testLesQuatreEntreesAppliquentLaMemePolitique(string $motDePasse, bool $accepte): void
    {
        foreach ($this->entrees($motDePasse) as $quoi => $objet) {
            self::assertSame(
                $accepte,
                $this->violationsSurLeMotDePasse($objet) === [],
                sprintf(
                    '%s : « %s » devrait être %s — %s',
                    $quoi,
                    $motDePasse,
                    $accepte ? 'accepté' : 'refusé',
                    implode(' / ', $this->violationsSurLeMotDePasse($objet)) ?: 'aucune violation'
                )
            );
        }
    }

    /*
        - 'Abc123' figure dans toutes les fuites de mots de passe connues. Qu'il passe est donc la
          preuve qu'aucun contrôle distant ne s'est réinvité dans la politique.
    */
    public function testAucunControleDistantNeSInterposePlus(): void
    {
        foreach ($this->entrees('Abc123') as $quoi => $objet) {
            self::assertSame([], $this->violationsSurLeMotDePasse($objet), $quoi);
        }
    }

    /** Un compte se corrige sans retaper son mot de passe : le champ vide ne déclenche rien. */
    public function testUnMotDePasseAbsentNEstPasUneErreurSurUnCompteExistant(): void
    {
        self::assertSame([], $this->violationsSurLeMotDePasse((new User())->setPlainPassword(null)));
    }

    /* ---- Outils ------------------------------------------------------------------------------ */

    /** @return array<string, object> */
    private function entrees(string $motDePasse): array
    {
        $inscription = new RegisterInput();
        $inscription->motDePasse = $motDePasse;

        $changement = new ChangerMotDePasseInput();
        // Le mot de passe ACTUEL est exigé et n'a rien à voir avec la politique : on le renseigne pour que sa propre violation ne vienne pas se mêler à la mesure
        $changement->motDePasseActuel = 'PeuImporte1';
        $changement->nouveauMotDePasse = $motDePasse;

        $reinitialisation = new ReinitialiserMotDePasseInput();
        $reinitialisation->motDePasse = $motDePasse;

        return [
            'création d\'un compte' => (new User())->setPlainPassword($motDePasse),
            'inscription' => $inscription,
            'changement' => $changement,
            'réinitialisation' => $reinitialisation,
        ];
    }

    /**
     * Les seules violations portant sur le mot de passe : les autres champs de ces objets sont vides
     * et se plaindraient, ce qui n'a rien à voir avec ce qu'on mesure ici.
     *
     * @return list<string>
     */
    private function violationsSurLeMotDePasse(object $objet): array
    {
        $messages = [];

        foreach ($this->validator->validate($objet) as $violation) {
            if (str_contains(strtolower($violation->getPropertyPath()), 'motdepasse')
                || $violation->getPropertyPath() === 'plainPassword') {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }
}
