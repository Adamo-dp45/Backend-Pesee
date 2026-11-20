<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\EntityBase;
use App\Entity\BaremeTransport;
use App\Entity\CategorieFournisseur;
use App\Entity\Fournisseur;
use App\Entity\User;
use App\Domain\Enum\TypeActivite;
use App\Domain\Service\ActiviteLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - Suppression logique : on marque, on n'efface pas. Les pesées référencent leurs produits et leurs planteurs, et justifient des paiements — une suppression physique casserait l'historique. 'PerimetreExtension' masque la ligne, la base la garde.
    - Verbe dédié plutôt que DELETE : le poste recréera le référentiel à la synchronisation suivante s'il existe encore de son côté. Ce n'est donc qu'un masquage côté web.
*/

/**
 * @implements ProcessorInterface<EntityBase, EntityBase>
 */
class SuppressionLogiqueProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private ActiviteLogger $activites
    )
    {
    }

    /* Combien de lignes vivantes d'une classe pointent sur cette entite par ce champ. En SQL : on ne veut que le nombre. */
    private function compter(string $classe, string $champ, object $cible): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($classe, 'e')
            ->andWhere(sprintf('e.%s = :cible', $champ))
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('cible', $cible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if(!$data instanceof EntityBase) {
            return $data; // 'User' n'en hérite pas : un compte se suspend, il ne se met pas à la corbeille
        }

        /*
            - Un planteur dont l'épargne n'est pas vide ne se met pas à la corbeille : le cloisonnement le ferait disparaître de tous les écrans, et son argent avec lui. Tant qu'il n'existe pas de retrait, il n'y a aucun moyen de le lui rendre après coup.
        */
        /*
            - UNE CATÉGORIE ENCORE PEUPLÉE NE SE SUPPRIME PAS. Le cloisonnement la ferait disparaître
              de tous les écrans, mais ses planteurs y resteraient rattachés : ils continueraient
              d'être payés au tarif d'une catégorie que plus personne ne peut ni lire ni corriger.
              Et la purge, elle, détacherait tout le monde d'un coup — la clé est en 'SET NULL' —
              ramenant chacun au prix du produit sans qu'une ligne ne le dise.

            - Le motif NOMME LE NOMBRE, parce que c'est ce qui permet de décider : à deux planteurs
              on les reclasse, à deux cents on y regarde à deux fois.
        */
        /*
            - UN BARÈME QUE QUELQU'UN SUIT NE SE SUPPRIME PAS. Le cloisonnement le ferait
              disparaître des écrans, mais les planteurs et les catégories continueraient de
              pointer dessus : leur transport se calculerait encore sur un barème que plus
              personne ne peut ni lire ni corriger. Et la purge les détacherait tous d'un coup.
        */
        if($data instanceof BaremeTransport) {
            $planteurs = $this->compter(Fournisseur::class, 'baremeTransport', $data);
            $categories = $this->compter(CategorieFournisseur::class, 'baremeTransport', $data);

            if($planteurs + $categories > 0) {
                /*
                    - LES DEUX COMPTES SÉPARÉMENT, parce qu'ils ne se corrigent pas au même endroit :
                      un planteur se rouvre fiche par fiche, une catégorie emmène tous les siens d'un
                      geste. « 3 suiveurs » laisserait chercher lesquels, et où.
                */
                $qui = array_filter([
                    $planteurs > 0 ? sprintf('%d planteur%s', $planteurs, $planteurs > 1 ? 's' : '') : null,
                    $categories > 0 ? sprintf('%d catégorie%s', $categories, $categories > 1 ? 's' : '') : null,
                ]);

                throw new UnprocessableEntityHttpException(sprintf(
                    '%s %s encore ce barème : rattachez-%s ailleurs avant de le mettre à la corbeille.',
                    implode(' et ', $qui),
                    $planteurs + $categories > 1 ? 'suivent' : 'suit',
                    $planteurs + $categories > 1 ? 'les' : 'le'
                ));
            }
        }

        if($data instanceof CategorieFournisseur) {
            $planteurs = $this->compter(Fournisseur::class, 'categorie', $data);

            if($planteurs > 0) {
                throw new UnprocessableEntityHttpException(sprintf(
                    '%d planteur%s %s encore classé%s dans cette catégorie : reclassez-les avant de la mettre à la corbeille.',
                    $planteurs,
                    $planteurs > 1 ? 's' : '',
                    $planteurs > 1 ? 'sont' : 'est',
                    $planteurs > 1 ? 's' : ''
                ));
            }
        }

        if($data instanceof Fournisseur && $data->getSoldeEpargne() > 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Ce planteur a %s F CFA sur son compte d\'épargne : il ne peut pas être mis à la corbeille tant que cette somme ne lui a pas été rendue.',
                number_format($data->getSoldeEpargne(), 0, ',', ' ')
            ));
        }

        $auteur = $this->security->getUser();

        $data->setDeletedAt(new \DateTimeImmutable());

        if($auteur instanceof User) {
            $data->setDeletedBy($auteur->getId());
        }

        /*
            - 'deletedBy' et 'deletedAt' disent qui a supprime — mais la restauration les efface et la
              purge emporte la ligne entiere. Le journal, lui, garde le souvenir du geste.
        */
        $this->activites->log(
            TypeActivite::CORBEILLE_SUPPRESSION,
            sprintf(
                '%s a mis a la corbeille %s %s',
                $auteur instanceof User ? $auteur->getNomComplet() : 'Le systeme',
                strtolower(substr(strrchr($data::class, chr(92)), 1)),
                (string) $data
            ),
            $data
        );

        $this->em->flush();

        return $data;
    }
}
