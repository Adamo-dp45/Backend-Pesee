<?php

namespace App\Domain\Service;

use App\Domain\Enum\TypeActivite;
use App\Entity\Activite;
use App\Entity\Entreprise;
use App\Entity\Interface\EntrepriseOwnedInterface;
use App\Entity\Interface\SiteOwnedInterface;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/*
    - Écrit une ligne au journal des décisions. Un seul appelant possible : les processors, au moment du geste, parce qu'eux seuls connaissent l'ancienne valeur et la nouvelle.

    - 'persist()' sans 'flush()' : c'est le processor appelant qui décide quand écrire, et il doit appeler 'log()' AVANT son flush final. La trace part ainsi dans la même transaction que le geste — si le geste échoue, le journal ne raconte pas quelque chose qui n'a pas eu lieu.

    - L'entreprise vient de la CIBLE, pas de l'acteur. C'est l'écart avec Transport, et il est imposé par le super administrateur : il n'appartient à aucune entreprise, mais il renouvelle des identifiants de postes et désactive des clients. Prendre son entreprise ne donnerait rien, et le journal du client perdrait précisément les gestes qui viennent de l'extérieur.

    - Une trace qui échoue ne doit JAMAIS faire échouer le geste : on journalise l'incident et on laisse passer. Un blocage de poste refusé parce que l'audit a eu un hoquet serait pire que l'absence de trace.
*/
class ActiviteLogger
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * @param object|null $cible l'entité visée, dont on tire le nom court, l'identifiant et l'entreprise
     */
    public function log(TypeActivite $type, string $libelle, ?object $cible = null): void
    {
        try {
            $entreprise = $this->entreprise($cible);

            if($entreprise === null) {
                // Sans entreprise, la ligne ne serait rattachée à aucun périmètre : personne ne la lirait
                $this->logger->warning('Activité non journalisée, entreprise introuvable : {type} — {libelle}', [
                    'type' => $type->value,
                    'libelle' => $libelle,
                ]);

                return;
            }

            $auteur = $this->security->getUser();

            $activite = (new Activite())
                ->setType($type)
                ->setLibelle(mb_substr($libelle, 0, 500))
                ->setCibleType($cible === null ? null : $this->nomCourt($cible))
                ->setCibleId($cible !== null && method_exists($cible, 'getId') ? $cible->getId() : null)
                ->setAuteur($auteur instanceof User ? $auteur : null)
                ->setEntreprise($entreprise);

            if($auteur instanceof User) {
                $activite->setCreatedBy($auteur->getId());
            }

            $this->em->persist($activite);
        } catch(\Throwable $e) {
            $this->logger->error('Journal d\'activité non écrit : {erreur}', ['erreur' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /*
        - L'entreprise de la cible, quel que soit son rattachement : elle est une entreprise, elle en porte une, ou elle appartient à un pont bascule qui en porte une. Le compte de l'acteur ne sert que de dernier recours — pour un geste qui ne vise rien de rattaché.
    */
    private function entreprise(?object $cible): ?Entreprise
    {
        $depuisLaCible = match(true) {
            $cible instanceof Entreprise => $cible,
            $cible instanceof EntrepriseOwnedInterface => $cible->getEntreprise(),
            $cible instanceof Site => $cible->getEntreprise(),
            $cible instanceof SiteOwnedInterface => $cible->getSite()?->getEntreprise(),
            $cible instanceof User => $cible->getEntreprise(),
            default => null,
        };

        $acteur = $this->security->getUser();

        return $depuisLaCible ?? ($acteur instanceof User ? $acteur->getEntreprise() : null);
    }

    /** 'Proxies\__CG__\App\Entity\Produit' → 'Produit' : le nom court survit au proxy Doctrine. */
    private function nomCourt(object $cible): string
    {
        return substr(strrchr($cible::class, '\\'), 1);
    }
}
