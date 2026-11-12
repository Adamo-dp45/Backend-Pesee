<?php

namespace App\Domain\Service;

use App\Domain\Enum\StatutOperation;
use App\Domain\Support\Cle;
use App\Entity\Fournisseur;
use App\Entity\Operation;
use App\Entity\Produit;
use App\Entity\Site;
use App\Entity\User;
use App\Domain\Service\Epargne\EpargneService;
use App\Repository\FournisseurRepository;
use App\Repository\OperationRepository;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
    - Reçoit un lot de pesées d'un poste et le déverse en base. Tout le flux est ici : interprétation de la charge utile, rapprochement des référentiels, valorisation, écriture, journal, accusé de réception.

    - Le SITE vient du COMPTE MACHINE, jamais du corps de la requête. C'est le correctif de fond par rapport au code d'origine, où le champ 'code' de la charge utile déterminait à lui seul chez quel client les pesées atterrissaient : n'importe quel compte authentifié pouvait écrire dans n'importe quel pont bascule. Le code reçu n'est plus qu'une vérification de cohérence.

    - Une seule transaction pour tout le lot, et un seul 'flush()'. Le code d'origine appelait 'flush()' jusqu'à quatre fois par ligne, soit huit cents allers-retours pour deux cents pesées.

    - En cas d'échec de la transaction, RIEN n'est acquitté. Le poste renverra le lot et l'idempotence sur 'codesecret' fera qu'aucune ligne ne sera dupliquée. C'est le compromis inverse de l'ancien comportement, qui gardait les succès partiels : on préfère un renvoi inutile à une pesée acquittée mais absente de la base.

    - Le mapping des champs n'existe qu'à UN endroit, dans 'appliquer()'. Il n'y a pas d'objet intermédiaire : la charge utile est lue directement sur l'entité, là où le code précédent recopiait tout deux fois — tableau vers DTO, puis DTO vers entité.
*/
class SynchronisationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private OperationRepository $operationRepository,
        private ProduitRepository $produitRepository,
        private FournisseurRepository $fournisseurRepository,
        private EpargneService $epargne,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * @param list<mixed> $pesees Les lignes arrivent d'une charge utile JSON : leur forme n'est pas garantie, d'où le 'mixed'. Annoncer un tableau de tableaux promettrait au reste du code une structure que le poste ne s'est jamais engagé à respecter.
     *
     * @return array<string, mixed> La réponse, au format attendu par les postes déjà déployés
     */
    public function traiter(User $compte, array $pesees, ?string $ip = null): array
    {
        $site = $this->resoudreSite($compte);
        $debut = microtime(true);

        $recus = 0;
        $erreurs = [];
        $lignes = [];

        /* 1. Interprétation minimale : on ne retient que la clé d'idempotence, sans toucher à la base. */
        foreach($pesees as $brute) {
            ++$recus;

            if(!is_array($brute)) {
                $erreurs[] = ['id' => null, 'message' => 'Ligne illisible : un objet était attendu.'];

                continue;
            }

            $refDesktop = $this->texte($brute, 'id');
            $codesite = $this->texte($brute, 'code');
            $codepesee = $this->texte($brute, 'codepesee');

            // Ces trois valeurs composent la clé d'idempotence : sans elles, impossible de savoir si la pesée existe déjà. On refuse la ligne plutôt que de créer un doublon
            if($refDesktop === null || $codesite === null || $codepesee === null) {
                $erreurs[] = ['id' => $refDesktop, 'message' => 'Ligne incomplète : les champs "id", "code" et "codepesee" sont obligatoires.'];

                continue;
            }

            // Une divergence de code signale un fichier de configuration déposé sur le mauvais poste, ou une tentative d'écriture chez un voisin : dans les deux cas on refuse
            if($codesite !== $site->getCode()) {
                $erreurs[] = ['id' => $refDesktop, 'message' => sprintf('Cette pesée porte le code "%s" alors que ce poste est enregistré pour "%s".', $codesite, (string) $site->getCode())];

                continue;
            }

            $lignes[] = [
                'brute' => $brute,
                'refDesktop' => $refDesktop,
                'codesecret' => $codesite . '_' . $refDesktop . '_' . $codepesee,
            ];
        }

        if($lignes === []) {
            $this->journaliser($site, $recus, 0, 0, $erreurs, $ip, $debut);

            return $this->reponse($recus, [], $erreurs);
        }

        /* 2. Préchargement par lot : un envoi de deux cents pesées ne doit pas produire quatre cents lectures. */
        $produits = $this->produitRepository->parCle($site);
        $fournisseurs = $this->fournisseurRepository->parCles($site, $this->clesFournisseurs($lignes));
        $existantes = $this->operationRepository->parCodesecret(array_column($lignes, 'codesecret'));

        /* 3. Application. */
        $acquittees = [];
        $retarife = false;

        /*
            - Les pesées qui attendent qu'on tranche leur mise en compte. On les met de côté au lieu
              d'écrire tout de suite : une ligne du livre d'épargne pointe sur une pesée, et une
              pesée neuve n'a pas encore d'identifiant avant le premier 'flush()'.
        */
        $aArbitrer = [];

        foreach($lignes as $ligne) {
            try {
                $existante = $existantes[$ligne['codesecret']] ?? null;
                $operation = $existante ?? new Operation();

                $retarife = $this->appliquer($operation, $ligne, $site, $produits, $fournisseurs) || $retarife;

                if($existante === null) {
                    $this->em->persist($operation);
                    $existantes[$ligne['codesecret']] = $operation;
                }

                $acquittees[] = [$ligne['refDesktop'], $existante === null];

                if($operation->attendUnArbitrage()) {
                    $aArbitrer[] = $operation;
                }
            } catch(\Throwable $e) {
                $erreurs[] = ['id' => $ligne['refDesktop'], 'message' => $e->getMessage()];
            }
        }

        /*
            - Un prix qui a bougé côté poste ne concerne pas que le lot courant : les pesées déjà reçues et pas encore payées portent l'ancien montant. On les recalcule, mais seulement quand un prix a réellement changé — le cas est rare, la requête ne part donc presque jamais.
        */
        if($retarife) {
            foreach($this->operationRepository->findRecalculables($site) as $ancienne) {
                $ancienne->calculerMontant();

                // Une pesée qui vient d'être valorisée devient arbitrable dans le même lot : son épargne ne doit pas attendre le prochain envoi
                if($ancienne->attendUnArbitrage()) {
                    $aArbitrer[] = $ancienne;
                }
            }
        }

        /*
            4. Écriture, tout ou rien.

            - Le premier 'flush()' n'est pas cosmétique : il donne leur identifiant aux pesées
              neuves, et le livre d'épargne pointe sur elles.
            - Les mises en compte sont dans LA MÊME transaction que le lot, et c'est délibéré. Si
              l'une échoue, rien n'est acquitté et le poste renverra — l'idempotence absorbe. Créditer
              en différé ouvrirait une fenêtre pendant laquelle la pesée est payable en totalité : le
              guichet verserait tout, la retenue arriverait après, et l'entreprise paierait deux fois.
        */
        try {
            $this->em->wrapInTransaction(function() use ($aArbitrer): void {
                $this->em->flush();

                $this->epargne->preleverSurLot($aArbitrer);

                $this->em->flush();
            });
        } catch(\Throwable $e) {
            $this->logger->error('Échec de synchronisation du pont bascule {site} : {erreur}', [
                'site' => $site->getCode(),
                'erreur' => $e->getMessage(),
                'exception' => $e,
            ]);

            // Aucune ligne n'a été acquittée : on signale l'échec pour chaque référence, afin que le poste sache exactement quoi renvoyer
            foreach($acquittees as [$refDesktop]) {
                $erreurs[] = ['id' => $refDesktop, 'message' => 'Lot non enregistré : ' . $e->getMessage()];
            }

            $acquittees = [];
        }

        $crees = count(array_filter($acquittees, static fn(array $a) => $a[1]));

        $this->journaliser($site, $recus, $crees, count($acquittees) - $crees, $erreurs, $ip, $debut);

        return $this->reponse($recus, array_column($acquittees, 0), $erreurs);
    }

    /*
        - Le pont bascule dont ce compte machine pousse les pesées. Sans lui le compte est dans un état incohérent : un poste ne peut être mis en service qu'après la déclaration de son site, puisque c'est cette déclaration qui produit son fichier de configuration.
    */
    public function resoudreSite(User $compte): Site
    {
        $site = $compte->getSiteMachine();

        if($site === null) {
            throw new AccessDeniedHttpException('Ce compte n\'est rattaché à aucun pont bascule. Contactez votre administrateur.');
        }

        if(!$site->accepteLaSynchronisation()) {
            throw new AccessDeniedHttpException('Ce pont bascule est bloqué : ses pesées ne sont plus acceptées.');
        }

        return $site;
    }

    /*
        - Chaque envoi porte la LIGNE ENTIÈRE, pas seulement ce qui a changé : le poste affiche et transmet un enregistrement qui s'enrichit au second passage, il ne suit pas les champs modifiés. Confirmé par l'éditeur du logiciel de pesée. C'est ce qui autorise à tout réécrire ici.
        - Le seul champ traité différemment est le prix : 'array_key_exists' distingue « absent » de « vide », ce qu'exige l'arbitrage bidirectionnel.

        @param array{brute: array<string, mixed>, refDesktop: string, codesecret: string} $ligne
        @param array<string, Produit>     $produits
        @param array<string, Fournisseur> $fournisseurs
        @return bool vrai si un prix de référentiel a changé, donc si les anciennes pesées doivent être recalculées
     */
    private function appliquer(Operation $operation, array $ligne, Site $site, array &$produits, array &$fournisseurs): bool
    {
        $brute = $ligne['brute'];

        $poids2 = $this->entier($brute, 'poids2');
        $statut = StatutOperation::depuis($poids2);
        $terminee = $statut->estTerminee();

        $operation
            ->setCodesecret($ligne['codesecret'])
            ->setRefDesktop($ligne['refDesktop'])
            ->setCodepesee((string) $this->texte($brute, 'codepesee'))
            ->setNumticket($this->texte($brute, 'numticket'))
            ->setSite($site)
            ->setMouvement($this->texte($brute, 'libellemouvement'))
            ->setClient($this->texte($brute, 'libelleclient'))
            ->setDestination($this->texte($brute, 'libelledestination'))
            ->setProvenance($this->texte($brute, 'libelleprovenance'))
            ->setTransporteur($this->texte($brute, 'libelletransporteur'))
            ->setChauffeur($this->texte($brute, 'chauffeur'))
            ->setImmatriculation($this->texte($brute, 'immatriculation'))
            ->setRemorque($this->texte($brute, 'remorque'))
            ->setPeseur($this->texte($brute, 'peseur'))
            ->setContactPl($this->texte($brute, 'contactpl') ?? $this->texte($brute, 'contactPl'))
            ->setConteneur($this->texte($brute, 'conteneur'))
            ->setPlomb($this->texte($brute, 'plomb'))
            ->setSyncAt(new \DateTimeImmutable());

        /*
            - LE PREMIER PASSAGE NE S'ÉCRIT QU'UNE FOIS. Le poids d'entrée est relevé par la bascule quand le camion monte dessus ; le second envoi le rapporte encore, mais ce n'est plus qu'une recopie de sa base locale — rien ne l'a repesé. Le laisser écraser la valeur acquise, c'est accepter qu'une saisie corrigée sur le poste, ou un champ revenu vide, réécrive après coup un fait de bascule.

            - L'heure d'entrée suit le même sort, et pour la même raison : un poids relevé au premier passage avec une heure venue du second ne décrit aucun moment réel.

            - La condition porte sur ce qui est DÉJÀ EN BASE, pas sur le statut : une pesée dont le premier envoi n'aurait rien apporté — champ absent, poste mal configuré — peut encore se compléter au passage suivant. On fige ce qu'on sait, on ne refuse pas ce qu'on ignore.
        */
        if($operation->getPoids1() === null) {
            $operation->setPoids1($this->entier($brute, 'poids1'));
        }

        if($operation->getPeseeAt1() === null) {
            $operation->setPeseeAt1($this->horodatage($brute, 'datepesee1', 'temps1'));
        }

        /*
            - Tant que la pesée n'est pas terminée on ignore le second poids, le brut, le net ET la date de second passage : le poste pré-remplit cette date avec celle du premier passage, la stocker donnerait une heure de sortie inventée.

            - LE SECOND PASSAGE NE S'ÉCRIT QU'UNE FOIS, lui aussi, et le statut avec lui. Même raison que pour le premier, plus une qui lui est propre : le poids net est l'assiette de la mise en compte. Une pesée déjà créditée sur l'épargne d'un planteur, dont un renvoi ultérieur réécrirait le net, verrait sa retenue reposer sur un poids qui n'est plus celui qui l'a produite.

            - Le statut est dans le même bloc à dessein. L'en sortir laisserait un envoi tardif à poids2 nul renvoyer en « en cours » une pesée déjà créditée, et effacer son montant dû : il resterait une ligne d'épargne en face d'une pesée officiellement jamais terminée.
        */
        if(!$operation->estTerminee()) {
            $operation
                ->setStatut($statut)
                ->setPoids2($terminee ? $poids2 : null)
                ->setPoidsbrut($terminee ? $this->entier($brute, 'poidsbrut') : null)
                ->setPoidsnet($terminee ? $this->entier($brute, 'poidsnet') : null)
                ->setPeseeAt2($terminee ? $this->horodatage($brute, 'datepesee2', 'temps2') : null);
        }

        $retarife = $this->resoudreProduit($brute, $site, $produits, $produit);
        $retarife = $this->resoudreFournisseur($brute, $site, $fournisseurs, $fournisseur) || $retarife;

        $operation->setProduit($produit)->setFournisseur($fournisseur);
        $operation->calculerMontant();

        return $retarife;
    }

    /*
        - Produits et planteurs sont créés à la volée : sur l'écran de pesée, chaque champ de référentiel a son bouton « Créer », et le peseur s'en sert. Le serveur ne peut qu'enregistrer ce qui arrive ; le web complète ensuite le prix, le contact, le réseau mobile.
        - Le rapprochement se fait sur la clé normalisée et non sur le libellé brut, sinon « HEVEA », « Hevea » et « Hévéa » créeraient trois produits. Voir 'Cle'.

        @param array<string, mixed>   $brute
        @param array<string, Produit> $produits
     */
    private function resoudreProduit(array $brute, Site $site, array &$produits, ?Produit &$produit): bool
    {
        $produit = null;
        $libelle = $this->texte($brute, 'libelleproduit');

        if($libelle === null) {
            return false;
        }

        $cle = Cle::depuis($libelle);
        $produit = $produits[$cle] ?? null;

        if($produit === null) {
            $produit = (new Produit())->setLibelle($libelle)->setSite($site);

            $produits[$cle] = $produit;
            $this->em->persist($produit);
        }

        if(($codeproduit = $this->texte($brute, 'codeproduit')) !== null) {
            $produit->setCodeproduit($codeproduit);
        }

        // Le prix n'est réappliqué que si le poste l'a réellement changé, sinon chaque envoi écraserait la tarification faite en ligne avec une valeur pourtant identique de son côté
        return array_key_exists('prix', $brute) && $produit->appliquerPrixDuPoste($this->entier($brute, 'prix'));
    }

    /**
     * @param array<string, mixed>        $brute
     * @param array<string, Fournisseur>  $fournisseurs
     */
    private function resoudreFournisseur(array $brute, Site $site, array &$fournisseurs, ?Fournisseur &$fournisseur): bool
    {
        $fournisseur = null;
        $libelle = $this->texte($brute, 'libellefournisseur');

        if($libelle === null) {
            return false;
        }

        $cle = Cle::depuis($libelle);
        $fournisseur = $fournisseurs[$cle] ?? null;

        if($fournisseur === null) {
            // Le poste n'envoie qu'un libellé : on le prend comme nom, sans prénom. La fiche se complète ensuite depuis le web
            $fournisseur = (new Fournisseur())->setNom($libelle)->setSite($site);

            $fournisseurs[$cle] = $fournisseur;
            $this->em->persist($fournisseur);
        }

        if(($codefournisseur = $this->texte($brute, 'codefournisseur')) !== null) {
            $fournisseur->setCodefournisseur($codefournisseur);
        }

        // Un numéro reçu du poste ne remplace jamais celui saisi en ligne : c'est le web qui rend un planteur payable
        if($fournisseur->getContact1() === null) {
            $fournisseur->setContact1($this->texte($brute, 'contactfournisseur') ?? $this->texte($brute, 'contactFournisseur'));
        }

        // Pas de 'array_key_exists' ici, contrairement au produit : le prix de transport absent de la charge utile et le prix de transport à null sont la même chose, et la méthode refuse déjà les deux
        return $fournisseur->appliquerPrixTransportDuPoste($this->entier($brute, 'prixtransport'));
    }

    /* ---- Lecture de la charge utile -------------------------------------------------------- */

    /** @param array<string, mixed> $ligne */
    private function texte(array $ligne, string $cle): ?string
    {
        $valeur = $ligne[$cle] ?? null;

        if($valeur === null || is_array($valeur)) {
            return null;
        }

        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    /** @param array<string, mixed> $ligne */
    private function entier(array $ligne, string $cle): ?int
    {
        $valeur = $ligne[$cle] ?? null;

        // Les nombres arrivent parfois en chaînes, selon la sérialisation du poste
        return ($valeur === null || $valeur === '' || !is_numeric($valeur)) ? null : (int) $valeur;
    }

    /*
        - Recompose un horodatage à partir de la date et de l'heure envoyées séparément. Les dates nulles de MySQL ('0000-00-00') et les valeurs vides sont traitées comme absentes plutôt que comme le 30 novembre -0001.

        @param array<string, mixed> $ligne
     */
    private function horodatage(array $ligne, string $cleDate, string $cleHeure): ?\DateTimeImmutable
    {
        $date = $this->texte($ligne, $cleDate);

        if($date === null || str_starts_with($date, '0000-00-00')) {
            return null;
        }

        // La date porte parfois déjà l'heure : on ne la duplique pas
        $valeur = str_contains($date, ':') ? $date : $date . ' ' . ($this->texte($ligne, $cleHeure) ?? '00:00:00');
        $horodatage = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $valeur);

        return $horodatage === false ? null : $horodatage;
    }

    /* ---- Sortie ---------------------------------------------------------------------------- */

    /*
        - L'accusé de réception est un contrat de fiabilité, pas une statistique : le champ 'id' renvoyé dans 'operation' est la clé primaire de la pesée dans la base LOCALE du poste, et c'est ce qui lui permet de marquer ses lignes comme synchronisées. Y faire figurer une ligne non enregistrée revient à effacer une pesée sur le terrain, sans aucun message d'erreur. Une référence n'y entre donc qu'après validation de la transaction.
        - Codes d'origine conservés : 1 = au moins une pesée enregistrée, 2 = échec, 3 = aucune donnée.
        - Le tableau 'erreurs' est un ajout : le format d'origine n'exposait qu'un message global, écrasé par la dernière ligne en échec. Le poste peut désormais savoir quoi réessayer.

        @param list<string>                                  $acquittees
        @param list<array{id: string|null, message: string}> $erreurs
        @return array<string, mixed>
     */
    private function reponse(int $recus, array $acquittees, array $erreurs): array
    {
        $succes = count($acquittees);

        return [
            'code' => $succes > 0 ? 1 : ($recus === 0 ? 3 : 2),
            'fail' => count($erreurs),
            'success' => $succes,
            'operation' => array_map(static fn(string $id) => ['id' => $id], $acquittees),
            'message' => $succes > 0 ? 'Operation effectuee avec succes' : ($recus === 0 ? 'Pas de donnees' : 'Echec operation'),
            'erreurs' => $erreurs,
        ];
    }

    /*
        - Écrit le journal en SQL direct, et non via l'ORM. Ce n'est pas un raccourci : quand un 'flush()' échoue, Doctrine FERME l'EntityManager et tout 'persist()' ultérieur lève une exception. Passer par l'ORM ferait donc disparaître la trace au moment précis où elle est la plus utile — un poste qui échoue en silence depuis trois semaines. La connexion DBAL, elle, reste utilisable après un retour arrière.

        @param list<array{id: string|null, message: string}> $erreurs
     */
    private function journaliser(Site $site, int $recus, int $crees, int $modifiees, array $erreurs, ?string $ip, float $debut): void
    {
        try {
            $maintenant = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->em->getConnection()->insert('synchronisation_lot', [
                'site_id' => $site->getId(),
                'nb_recus' => $recus,
                'nb_crees' => $crees,
                'nb_modifiees' => $modifiees,
                'nb_erreurs' => count($erreurs),
                'erreurs' => $erreurs === [] ? null : json_encode($erreurs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip' => $ip,
                'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        } catch(\Throwable $e) {
            // Le diagnostic ne doit jamais faire échouer un envoi de pesées qui a réussi
            $this->logger->error('Journal de synchronisation non écrit : {erreur}', ['erreur' => $e->getMessage()]);
        }
    }

    /**
     * @param list<array{brute: array<string, mixed>, refDesktop: string, codesecret: string}> $lignes
     *
     * @return list<string>
     */
    private function clesFournisseurs(array $lignes): array
    {
        $cles = [];

        foreach($lignes as $ligne) {
            if(($libelle = $this->texte($ligne['brute'], 'libellefournisseur')) !== null) {
                $cles[] = Cle::depuis($libelle);
            }
        }

        return array_values(array_unique($cles));
    }
}
