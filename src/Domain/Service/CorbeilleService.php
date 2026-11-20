<?php

namespace App\Domain\Service;

use App\Domain\Enum\TypeActivite;
use App\Entity\Entreprise;
use App\Entity\BaremeTransport;
use App\Entity\CategorieFournisseur;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Output\Corbeille\CorbeilleItemDto;
use App\Entity\Output\Corbeille\CorbeilleOutput;
use App\Entity\Produit;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/*
    - La corbeille, réservée au super administrateur et MULTI-ENTREPRISES : il voit ce que tous ses
      clients ont supprimé. C'est cohérent avec son métier — il provisionne et dépanne — et c'est ce
      qui rend le rattrapage possible sans donner à un administrateur le pouvoir de faire réapparaître
      un référentiel dans le dos de ses collègues.

    - Les requêtes sont écrites ICI, à la main, et c'est obligatoire : 'PerimetreExtension' n'écarte
      pas seulement les lignes supprimées, elle renvoie '1 = 0' au super administrateur sur tout ce
      qui n'est ni entreprise, ni compte, ni pont bascule. Passer par API Platform ne rendrait donc
      jamais rien. La corbeille est le seul endroit de l'application qui lit délibérément par-dessus
      le cloisonnement, et c'est pour cela qu'elle est fermée à un seul rôle.

    - QUATRE TYPES SEULEMENT, et ce n'est pas une limitation : ce sont les seuls que quoi que ce soit
      mette à la corbeille. Le grand livre, les pesées, les paiements et les journaux portent bien un
      'deletedAt' par héritage, mais rien ne le renseigne jamais — ce sont des historiques, pas des
      brouillons. Les faire apparaître ici laisserait croire qu'on peut les supprimer.
*/
final class CorbeilleService
{
    /** Le type exposé dans les URL est le nom court en minuscules : lisible, et stable dans le temps. */
    public const TYPES = [
        'produit' => Produit::class,
        'fournisseur' => Fournisseur::class,
        'categorie' => CategorieFournisseur::class,
        'bareme' => BaremeTransport::class,
    ];

    private const LIBELLES = [
        'produit' => 'Produit',
        'fournisseur' => 'Planteur',
        'categorie' => 'Catégorie',
        'bareme' => 'Barème de transport',
    ];

    /** Plafond de la LISTE. Les compteurs, eux, restent exacts. */
    private const PLAFOND = 200;

    public function __construct(
        private EntityManagerInterface $em,
        private ActiviteLogger $activites
    )
    {
    }

    /**
     * @param list<string>|null $types      restreint aux types donnés (null ou vide = tous)
     * @param int|null          $entreprise restreint à un client (null = tous)
     */
    public function lister(?array $types, ?int $entreprise): CorbeilleOutput
    {
        $sortie = new CorbeilleOutput();
        $elements = [];

        foreach($this->typesValides($types) as $type) {
            $supprimes = $this->supprimes($type, $entreprise);

            $sortie->totaux[$type] = count($supprimes);
            $sortie->total += count($supprimes);

            foreach($supprimes as $entite) {
                $elements[] = $entite;
            }
        }

        // Les plus récemment supprimés d'abord : c'est le rattrapage d'une erreur qui amène ici
        usort($elements, static fn(Produit|Fournisseur|CategorieFournisseur|BaremeTransport $a, Produit|Fournisseur|CategorieFournisseur|BaremeTransport $b) => ($b->getDeletedAt()?->getTimestamp() ?? 0) <=> ($a->getDeletedAt()?->getTimestamp() ?? 0));

        $sortie->tronquee = count($elements) > self::PLAFOND;
        $elements = array_slice($elements, 0, self::PLAFOND);

        $auteurs = $this->auteurs($elements);

        foreach($elements as $entite) {
            $sortie->elements[] = $this->decrire($entite, $auteurs);
        }

        $sortie->entreprises = $this->entreprisesRepresentees();

        return $sortie;
    }

    /**
     * Sort un élément de la corbeille. Le rendre visible ne suffit pas : il faut qu'il puisse
     * coexister avec ce qui a été créé pendant son absence.
     */
    public function restaurer(string $type, int $id): Produit|Fournisseur|CategorieFournisseur|BaremeTransport
    {
        $entite = $this->resoudre($type, $id);

        if(($motif = $this->motifNonRestaurable($entite)) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }

        $entite->setDeletedAt(null)->setDeletedBy(null);

        $this->activites->log(
            TypeActivite::CORBEILLE_RESTAURATION,
            sprintf('Restauration depuis la corbeille : %s « %s »', self::LIBELLES[$type], (string) $entite),
            $entite
        );

        $this->em->flush();

        return $entite;
    }

    /**
     * Suppression définitive. La ligne disparaît de la base : plus de retour possible.
     */
    public function purger(string $type, int $id): void
    {
        $entite = $this->resoudre($type, $id);

        if(($motif = $this->motifNonPurgeable($entite)) !== null) {
            throw new UnprocessableEntityHttpException($motif);
        }

        /*
            - Journalisé AVANT le retrait, tant que l'entité porte encore son libellé et son site.
              C'est la seule trace qui restera : la ligne, elle, ne sera plus là pour raconter.
            - Le journal ne pointe la cible que par son type et son identifiant, sans clé étrangère —
              c'est précisément ce qui lui permet de survivre à la disparition de ce qu'il décrit.
        */
        $this->activites->log(
            TypeActivite::CORBEILLE_PURGE,
            sprintf('Suppression définitive : %s « %s »', self::LIBELLES[$type], (string) $entite),
            $entite
        );

        $this->em->remove($entite);
        $this->em->flush();
    }

    /* ---- Règles ------------------------------------------------------------------------------ */

    /*
        - Le piège de la restauration, et il est propre à ce modèle : 'UniqueParSite' ne compare
          qu'aux lignes VIVANTES — c'est ce qui permet de recréer un produit supprimé. Conséquence :
          pendant qu'un élément dort à la corbeille, un homonyme a pu naître, à la main ou par la
          synchronisation du poste. Le ressusciter donnerait deux référentiels pour la même chose, que
          plus rien ne distinguerait, et les pesées suivantes se répartiraient entre les deux.
    */
    private function motifNonRestaurable(Produit|Fournisseur|CategorieFournisseur|BaremeTransport $entite): ?string
    {
        /*
            - L'homonyme se cherche DANS LE PÉRIMÈTRE DE L'ÉLÉMENT, et les deux ne sont plus le même :
              un planteur est unique par pont bascule, un produit l'est par entreprise. Chercher un
              produit par site ne rendrait rien — il n'en a plus — et la restauration créerait le
              doublon qu'elle existe pour empêcher.
        */
        [$champ, $perimetre] = $entite instanceof Fournisseur
            ? ['site', $entite->getSite()]
            : ['entreprise', $entite->getEntreprise()];

        $vivant = $this->em->getRepository($entite::class)->findOneBy([
            $champ => $perimetre,
            'cle' => $entite->getCle(),
            'deletedAt' => null,
        ]);

        if($vivant === null) {
            return null;
        }

        return sprintf(
            'Un élément portant le même nom existe déjà %s : « %s ». Renommez-le avant de restaurer celui-ci.',
            $entite instanceof Fournisseur ? 'sur ce pont bascule' : 'dans cette entreprise',
            (string) $vivant
        );
    }

    /*
        - Une pesée fige le produit et le planteur qui l'ont valorisée. Purger l'un des deux couperait
          la ligne de son référentiel : le montant resterait, mais plus personne ne saurait de quoi il
          parle. On refuse, en disant combien de pesées sont concernées — c'est le chiffre qui permet
          de décider.
    */
    private function motifNonPurgeable(Produit|Fournisseur|CategorieFournisseur|BaremeTransport $entite): ?string
    {
        /*
            - UNE CATÉGORIE N'A JAMAIS DE PESÉE : le comptage rendrait zéro, elle passerait pour
              purgeable, et la purge détacherait ses planteurs en silence — la clé étrangère est en
              'SET NULL'. Ils retomberaient tous au prix du produit, sans que rien ne le dise.
        */
        if($entite instanceof CategorieFournisseur) {
            $planteurs = $entite->getPlanteursCount();

            if($planteurs === 0) {
                return null;
            }

            return sprintf(
                '%d planteur%s y %s encore rattaché%s : les supprimer définitivement de cette catégorie les ramènerait au prix du produit.',
                $planteurs,
                $planteurs > 1 ? 's' : '',
                $planteurs > 1 ? 'sont' : 'est',
                $planteurs > 1 ? 's' : ''
            );
        }

        /*
            - UN BARÈME N'A PAS DE PESÉE NON PLUS, et sa purge détacherait d'un coup tous ceux qui
              le suivent — planteurs ET catégories, la clé étant en 'SET NULL'. Ils perdraient leur
              transport sans qu'une ligne ne le dise.
        */
        if($entite instanceof BaremeTransport) {
            $suiveurs = $this->compterLesSuiveurs($entite);

            if($suiveurs === 0) {
                return null;
            }

            return sprintf(
                '%d planteur(s) ou catégorie(s) suivent encore ce barème : les supprimer définitivement leur ferait perdre leur transport.',
                $suiveurs
            );
        }

        $pesees = $this->compterLesPesees($entite);

        if($pesees === 0) {
            return null;
        }

        return sprintf(
            '%d pesée%s référence%s encore cet élément : le supprimer définitivement leur ferait perdre leur référentiel.',
            $pesees,
            $pesees > 1 ? 's' : '',
            $pesees > 1 ? 'nt' : ''
        );
    }

    /* Combien de planteurs et de catégories pointent sur ce barème. En SQL : la liste peut être longue, et on n'en ferait rien d'autre que la compter. */
    private function compterLesSuiveurs(BaremeTransport $bareme): int
    {
        $compter = fn(string $classe) => (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($classe, 'e')
            ->andWhere('e.baremeTransport = :bareme')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('bareme', $bareme)
            ->getQuery()
            ->getSingleScalarResult();

        return $compter(Fournisseur::class) + $compter(CategorieFournisseur::class);
    }

    private function compterLesPesees(Produit|Fournisseur $entite): int
    {
        $champ = $entite instanceof Produit ? 'produit' : 'fournisseur';

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Operation::class, 'o')
            ->andWhere(sprintf('o.%s = :entite', $champ))
            ->setParameter('entite', $entite)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /* ---- Lecture ----------------------------------------------------------------------------- */

    /**
     * @return list<Produit|Fournisseur|CategorieFournisseur|BaremeTransport>
     */
    private function supprimes(string $type, ?int $entreprise): array
    {
        /*
            - L'entreprise s'atteint par deux chemins selon le type : DIRECTEMENT pour un produit et
              pour une catégorie, à travers le pont bascule pour un planteur. La jointure sur
              'e.site' valait pour tout le monde tant que le produit était local ; elle lèverait
              aujourd'hui sur un champ qui n'existe plus.
        */
        $parEntreprise = self::TYPES[$type] !== Fournisseur::class;

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(self::TYPES[$type], 'e')
            ->andWhere('e.deletedAt IS NOT NULL'); // L'inverse exact de ce que fait 'PerimetreExtension'

        if(!$parEntreprise) {
            $qb->join('e.site', 's');
        }

        if($entreprise !== null) {
            $qb
                ->andWhere($parEntreprise ? 'e.entreprise = :entreprise' : 's.entreprise = :entreprise')
                ->setParameter('entreprise', $entreprise);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<Produit|Fournisseur|CategorieFournisseur|BaremeTransport> $elements
     *
     * @return array<int, string> identifiant de compte => nom complet
     */
    private function auteurs(array $elements): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(Produit|Fournisseur|CategorieFournisseur|BaremeTransport $e) => $e->getDeletedBy(), $elements))));

        if($ids === []) {
            return [];
        }

        $noms = [];

        // Une requête pour toute la page : résoudre les noms un par un ferait autant d'allers-retours que de lignes
        foreach($this->em->getRepository(User::class)->findBy(['id' => $ids]) as $compte) {
            $noms[$compte->getId()] = $compte->getNomComplet();
        }

        return $noms;
    }

    /**
     * @param array<int, string> $auteurs
     */
    private function decrire(Produit|Fournisseur|CategorieFournisseur|BaremeTransport $entite, array $auteurs): CorbeilleItemDto
    {
        $type = match(true) {
            $entite instanceof Produit => 'produit',
            $entite instanceof CategorieFournisseur => 'categorie',
            $entite instanceof BaremeTransport => 'bareme',
            default => 'fournisseur',
        };

        /* Seul le planteur pend d'un pont bascule : pour les deux autres la colonne reste vide. */
        $site = $entite instanceof Fournisseur ? $entite->getSite() : null;
        $entreprise = $entite instanceof Fournisseur ? $site?->getEntreprise() : $entite->getEntreprise();

        $dto = new CorbeilleItemDto();
        $dto->type = $type;
        $dto->typeLibelle = self::LIBELLES[$type];
        $dto->id = (int) $entite->getId();
        $dto->libelle = (string) $entite;
        $dto->contexte = $site === null ? null : sprintf('%s (%s)', (string) $site->getLibellesite(), (string) $site->getCode());
        $dto->entreprise = $entreprise?->getNom();
        $dto->entrepriseId = $entreprise?->getId();
        $dto->supprimeLe = $entite->getDeletedAt()?->format(\DateTimeInterface::ATOM);
        $dto->supprimePar = $auteurs[$entite->getDeletedBy()] ?? null;
        /*
            - UNE CATÉGORIE N'A PAS DE PESÉE, et le compteur ne sait compter que les deux autres :
              il LEVAIT sur elle, et l'erreur emportait TOUT l'écran de la corbeille, pas la seule
              ligne fautive. Une catégorie mise à la corbeille rendait donc l'écran inutilisable
              pour tous les clients à la fois, y compris pour en sortir quoi que ce soit.

            - Zéro, et non 'null' : la colonne compte des pesées, et une catégorie n'en a jamais.
              Ce n'est pas une donnée manquante, c'est un comptage à vide.
        */
        /*
            - LA GARDE EST INVERSÉE, ET C'EST VOULU : on compte pour les deux types qui ont des
              pesées, et zéro pour tous les autres. Écrite dans l'autre sens — « zéro si catégorie,
              sinon compter » — elle a laissé passer un quatrième type et levé une erreur de
              typage qui emportait TOUT l'écran de la corbeille, pas la seule ligne fautive. Dans
              ce sens-ci, un type qu'on ajoute demain tombe sur zéro, ce qui est vrai de tout ce
              qui n'est ni produit ni planteur.
        */
        $dto->peseesCount = $entite instanceof Produit || $entite instanceof Fournisseur
            ? $this->compterLesPesees($entite)
            : 0;

        $dto->motifNonPurgeable = $this->motifNonPurgeable($entite);
        $dto->purgeable = $dto->motifNonPurgeable === null;

        $dto->motifNonRestaurable = $this->motifNonRestaurable($entite);
        $dto->restaurable = $dto->motifNonRestaurable === null;

        return $dto;
    }

    /** @return array<int, string> */
    private function entreprisesRepresentees(): array
    {
        $noms = [];

        foreach($this->em->getRepository(Entreprise::class)->findBy(['deletedAt' => null], ['nom' => 'ASC']) as $entreprise) {
            $noms[(int) $entreprise->getId()] = (string) $entreprise->getNom();
        }

        return $noms;
    }

    /* ---- Résolution -------------------------------------------------------------------------- */

    /** Un élément de la corbeille, c'est-à-dire SUPPRIMÉ : un élément vivant n'a rien à faire ici. */
    private function resoudre(string $type, int $id): Produit|Fournisseur|CategorieFournisseur|BaremeTransport
    {
        if(!isset(self::TYPES[$type])) {
            throw new NotFoundHttpException(sprintf('Type inconnu : « %s ».', $type));
        }

        $entite = $this->em->getRepository(self::TYPES[$type])->findOneBy(['id' => $id]);

        /*
            - LA LISTE DES TYPES ACCEPTÉS VIT DANS 'TYPES', et nulle part ailleurs. Elle était
              recopiée ici en 'instanceof', et l'oubli d'un troisième type a fait répondre 404 à
              toute restauration et toute purge de catégorie — un refus muet, puisque « pas dans
              la corbeille » est exactement ce qu'on lit quand on s'y prend mal.

            - On se contente donc de vérifier que l'entité est bien celle que 'TYPES' annonce :
              ajouter un type là-haut suffit désormais, ici il n'y a plus rien à penser.
        */
        $attendu = self::TYPES[$type];

        if(!$entite instanceof $attendu || $entite->getDeletedAt() === null) {
            throw new NotFoundHttpException('Cet élément n\'est pas dans la corbeille.');
        }

        return $entite;
    }

    /**
     * @param list<string>|null $types
     *
     * @return list<string>
     */
    private function typesValides(?array $types): array
    {
        // Un type inconnu est ignoré plutôt que refusé : un filtre obsolète dans un favori ne doit pas casser l'écran
        $retenus = array_values(array_filter($types ?? [], static fn(mixed $t) => is_string($t) && isset(self::TYPES[$t])));

        return $retenus === [] ? array_keys(self::TYPES) : $retenus;
    }
}
