### Brl

- 
- **Command**
    > php -S localhost:8000 -t public | symfony serve
    > php bin/console cache:clear
    > php bin/console debug:router
    > php bin/console make:controller
    > php bin/console make:entity
    > php bin/console make:voter
    > php bin/console make:listener
    > php bin/console make:subscriber
    > php bin/console make:fixtures
    > php bin/console doctrine:fixtures:load
    > php bin/console doctrine:database:create
    > php bin/console make:migration
    > php bin/console doctrine:migrations:migrate
    > php bin/console doctrine:schema:update --force : `--env=test` pour les tests
        > php bin/console doctrine:schema:update --dump-sql
    > php bin/console doctrine:fixtures:load : `--env=test` pour les tests
    > php bin/console translation:extract --dump-messages fr
    > php bin/console translation:extract --force fr --format=yaml
    > php bin/console make:test
    > php bin/console make:state-processor
    > php bin/console make:state-provider
- 

- **Important !**
    > Vu que `make` n'est pas disponible sur `windows` on.. ajouter les cibles en `scripts composer`
    > !! qu'en `mysql` un index unique contenant une colonne `NULL` n'empêche pas les doublons et que l'auto création par libellé n'aurait rien garanti on a créer une clé normalisée `Cle.php`..
    > Sur ApiExceptionSubscriber, je ne suis pas d'accord — et je l'ai vérifié plutôt que d'argumenter. Il ne fait pas double emploi avec ApiExceptionHandlerHelper : le helper est appelé dans un catch, là où le contrôleur sait quoi faire de l'erreur ; le subscriber rattrape les appels qui ne sont dans aucun catch. Or il y en a beaucoup — 64 appels API pour 23 catch, et BilanController, PeseeController, TableauDeBordController n'en ont aucun. Je l'ai donc remis, avec un commentaire qui dit précisément en quoi les deux diffèrent — c'est justement la confusion qui t'a fait penser qu'il était devenu inutile. Si tu veux vraiment t'en passer, il faut d'abord entourer chaque lecture d'un try/catch, ce qui ramène le bruit que le subscriber avait supprimé.
    > L'alias @ est déclaré dans tsconfig mais pas dans Webpack — les composants shadcn l'utilisent. webpack.config.js
    > Un défaut sérieux trouvé en testant, qui touchait tous les écrans. En rejetant sans motif, l'API répondait bien 422 — et rien ne s'affichait. Cause : ApiExceptionHandlerHelper traite tout 422 comme une erreur de validation et boucle sur getViolations(). Or un 422 métier (« une demande est déjà en attente », « solde insuffisant », « déjà traitée ») n'a pas de violations par champ, seulement un message. La boucle tournait à vide, la méthode rendait null, le formulaire se réaffichait muet : le geste semblait n'avoir aucun effet. J'ai ajouté le cas manquant — sans violations, le message devient une erreur globale du formulaire. C'est le helper que j'ai porté de Transport, donc le défaut y est probablement aussi.
    > Tu répètes security: [['bearerAuth' => []]] sur chaque opération. Je l'ai déclaré une fois globalement dans OpenApiFactory, avec security: [] sur les routes publiques — vérifié : /api/register et /api/mot-de-passe/oubli sont bien marquées publiques, le reste hérite du bearer. Ça évite de le répéter 35 fois et surtout de l'oublier une fois. Si tu préfères ton écriture explicite par opération, c'est un ajout mécanique, dis-le moi.
    > NotCompromisedPassword a exigé symfony/http-client (installé), et je l'ai réglé en skipOnError — sur une connexion de terrain coupée, la vérification ne doit pas empêcher de créer un compte.
        #[Assert\NotCompromisedPassword(
            message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre',
            skipOnError: true // La vérification interroge un service distant : sur une connexion de terrain coupée, elle ne doit pas empêcher de créer un compte
        )]
    > On.. `SynchronisationLot` en tant que journal d'un push pour diagnostiquer un pont bascule à distance
- 

- 
- Dans le select de voyage de la partie création de tickets les voyages n'affiche pas un voyage quand la date de départ est dépassé ! 
- jspdf, jspdf-autotable, xlsx, xlsx-js-style à reprendre sur github
.. Api payment : Cinetpay, FedaPay, PayDunya, Hub2, Paystack, BUI == Agrégateurs
PaiementPro, Stripe :: Webpesee
Chatgpt : Génération d'images, créatif, tout en un
Claude : Analyse approfondie, développement, rédaction professionnelle

- **Développement**
    > composer install
    > php bin/console lexik:jwt:generate-keypair
    > php bin/console doctrine:migrations:migrate --no-interaction
    > php bin/console doctrine:fixtures:load --no-interaction
    > php -S localhost:8000 -t public

- **Vérification**
    > vendor/bin/phpunit : dont les tests d'intégration sur base réelle
    > vendor/bin/phpstan analyse : niveau 6, lancer `cache:warmup` d'abord si le conteneur manque
    > php bin/console doctrine:schema:validate

- **Git**
    > git push -u origin main : .. `--force`

- **Production**
    > On peut désactiver la doc `ApiPlatform` dans `config/packages/api_platform.yaml`
    > La 1ère
        > git clone .. .
        > Pour le `.env..` on peut `cp .env .env.local` ou `composer dump-env prod` qui génère un fichier `.env.local.php` qui est plus optimisé
        > composer install --no-dev --optimize-autoloader
        > composer require symfony/apache-pack
        > php bin/console lexik:jwt:generate-keypair : Pour générer les clés jwt vu qu'ils ne sont pas versionné
        > `MAILER_DSN` dans `.env.local` : le défaut versionné est `null://null` et n'envoie rien
        > php bin/console doctrine:migrations:migrate --no-interaction
        > php bin/console cache:clear --env=prod
        > php bin/console cache:warmup --env=prod
    > Les prochaines
        > git pull origin main
        > composer install --no-dev --optimize-autoloader
        > php bin/console doctrine:migrations:migrate --no-interaction
        > php bin/console cache:clear --env=prod
        > php bin/console cache:warmup --env=prod

- **Bon à savoir**
    > Après avoir modifié un groupe de sérialisation, `cache:clear` ne suffit pas :
      `php bin/console cache:pool:clear --all`, sinon l'API répond avec l'ancienne forme.
    > Les extensions Doctrine ne cloisonnent pas les requêtes écrites à la main : statistiques,
      bilan et exports passent par `TenantContext`.
    > Le mot de passe d'un compte machine n'est renvoyé qu'à sa création ou à sa régénération,
      et n'est stocké nulle part en clair.


    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd"
        },
        "post-install-cmd": [
            "@auto-scripts"
        ],
        "post-update-cmd": [
            "@auto-scripts"
        ],
        "db-reset": [
            "php bin/console doctrine:database:drop --force --if-exists",
            "php bin/console doctrine:database:create",
            "php bin/console doctrine:migrations:migrate --no-interaction",
            "php bin/console doctrine:schema:validate"
        ],
        "fixtures": "php bin/console doctrine:fixtures:load --no-interaction",
        "test": [
            "php bin/console doctrine:database:drop --force --if-exists --env=test",
            "php bin/console doctrine:database:create --env=test",
            "php bin/console doctrine:migrations:migrate --no-interaction --env=test",
            "vendor/bin/phpunit"
        ],
        "stan": [
            "php bin/console cache:warmup --env=dev",
            "vendor/bin/phpstan analyse --memory-limit=1G"
        ],
        "lint": [
            "php bin/console lint:container",
            "php bin/console lint:yaml config --parse-tags",
            "php bin/console doctrine:schema:validate --skip-sync"
        ]
    },
    "scripts-descriptions": {
        "db-reset": "Recrée la base de développement et rejoue toutes les migrations",
        "fixtures": "Charge le jeu de démonstration",
        "test": "Prépare la base de test et lance la suite PHPUnit",
        "stan": "Analyse statique PHPStan",
        "lint": "Vérifications rapides du conteneur, des YAML et du mapping Doctrine"
    },


root@webpesee.com
bakayoko
adam@gmail.com
Socafpesage10

php bin/console app:super-admin:creer
CreerSuperAdminCommand — saisie masquée avec confirmation, ou en non interactif :a
php bin/console app:super-admin:creer --email=root@webpesee.com --nom=Admin --prenom=Super
Elle refuse une adresse invalide, une adresse déjà prise, un mot de passe de moins de 8 caractères. J'avais ajouté une note comptant les super administrateurs existants : le findBy sur une colonne JSON ne matche pas, je l'ai retirée plutôt que de la rafistoler pour un message décoratif.







Le message de correction des dropdownMenu dans les table.tsx

- Met le fuseau horaire de l'application à UTC
- Sache que j'ai supprimé le `Backend-Webpesee/`, j'espère que tu n'en a plus besoin !




# Paiement

**La caisse.** [CaisseService.php](Backend-Webpesee-V2/src/Domain/Service/Caisse/CaisseService.php) est le seul point qui touche un solde : `refresh()` avec `PESSIMISTIC_WRITE`, ordre de verrouillage entreprise→site systématique, et chaque écriture produit sa ligne de grand livre avec `soldeAvant`/`soldeApres`. [PaiementService.php](Backend-Webpesee-V2/src/Domain/Service/Caisse/PaiementService.php) réserve les fonds **avant** l'appel passerelle, dans la même transaction que la création du paiement, borne sur la somme *engagée* (pas confirmée), et `appliquerResultat()` est idempotent pour absorber webhook + réconciliation.

- Lors de la dotation vers un pont bascule, je me dis que normalement l'argent devrait être versé sur le compte de l'opérateur en question, aussi pour le versement planteur :: On peut dire non aussi vu que l'argent peut sortir uniquement du compte de l'admin

- Dans la partie paiement lorsqu'on veut verser le reste à payé d'un planteur on tombe sur "Montant supérieur au reste à payer : 5 320 000 F CFA au maximum." alors la carte et le champ m'affiche la somme de 8320000 F ==> ce fais quand un paiement est cours..


| **5** | Caisse & paiements : `CaisseService`, demandes, passerelle simulée, webhook, réconciliation | Cycle dotation → paiement partiel → soldé |
- La réconciliation des paiements n'est pas planifiée. app:paiements:reconcilier existe mais rien ne la déclenche : un versement dont la notification se perd reste « en vol » indéfiniment, immobilisant l'argent. Il faut un cron, ou le Scheduler de Symfony.
| Paiement | Porte **sur une pesée précise**, **mobile money uniquement**, payable en plusieurs fois |
| Passerelle de paiement | **Abstraction `PaymentGatewayInterface` + driver simulé**, agrégateur réel branché plus tard |
| Recharge solde entreprise | **Saisie admin, immédiate**, historisée |
- **`prixunitaire`**, **`montantDu`**, **`montantPaye`** (défaut 0), **`statutReglement`** — le montant payé est dénormalisé pour éviter un `SUM()` sur chaque ligne de liste. Seule une pesée `TERMINEE` porte une créance et devient payable

### `CaisseService` — le cœur des invariants

Toutes les écritures de solde passent par lui ; aucun processor ne touche `->setSolde()` directement. Chaque opération pose un **verrou pessimiste** (`LockMode::PESSIMISTIC_WRITE`) sur la ligne `entreprise`/`site` et écrit un `MouvementCaisse` avec `soldeAvant`/`soldeApres` :

| Méthode | Effet |
|---|---|
| `rechargerEntreprise(Entreprise, int, User)` | + solde entreprise (RECHARGE) |
| `doterSite(Site, int, User)` | − entreprise (ATTRIBUTION) et + site (DOTATION), transactionnel |
| `debiterSite(Site, int, Paiement)` | − site (PAIEMENT) |
| `rembourserSite(Site, int, Paiement)` | + site (REMBOURSEMENT), sur échec de payout |
| `ajuster(...)` | ± avec motif obligatoire, réservé à l'**administrateur de l'entreprise** (correction d'erreur de saisie) |

Refus explicite si le solde est insuffisant (`UnprocessableEntityHttpException` avec un message FR).

### Flux de paiement d'un planteur

1. `POST /api/paiements` (`PaiementInput` : `operationId`, `montant`, `numeroDestinataire?`, `reseau?`).
2. `PaiementProcessor` valide : la pesée appartient à un site que l'utilisateur opère · site ACTIF · fournisseur joignable · `0 < montant ≤ operation.montantDu − operation.montantPaye` · `site.solde ≥ montant`.
3. `Paiement` créé en `EN_ATTENTE` + **débit immédiat du site** (réservation des fonds) — la caisse ne peut donc jamais être dépensée deux fois pendant qu'un payout est en vol.
4. `PaymentGatewayInterface::initier(PayoutRequest): PayoutResult` → statut `ENVOYE`, ou `ECHOUE` + remboursement immédiat si l'appel échoue en synchrone.
5. `POST /api/webhooks/paiement/{gateway}` (public, signature vérifiée, idempotent sur `reference`) → `REUSSI` (mise à jour de `montantPaye` / `statutReglement` par `ReglementService`) ou `ECHOUE` (+ ligne de REMBOURSEMENT).
6. Commande `app:paiements:reconcilier` — repasse sur les paiements `ENVOYE` plus vieux que N minutes et interroge la passerelle, filet de sécurité si un webhook se perd.

Le paiement en plusieurs fois tombe naturellement : plusieurs `Paiement` sur la même `Operation`, `statutReglement` passant NON_PAYE → PARTIEL → SOLDE.

### Abstraction passerelle — `src/Domain/Payment/`

`PaymentGatewayInterface` (`initier`, `verifier`, `nom`) · `PayoutRequest` / `PayoutResult` · `WebhookVerifierInterface` · `Gateway/FakeGateway.php` (driver simulé : succès par défaut, échec déclenchable, plus une commande `app:paiements:simuler-webhook` pour dérouler le flux complet en dev) · `PaymentGatewayRegistry` piloté par `APP_PAYMENT_GATEWAY`. Brancher CinetPay ou PayDunya plus tard = une classe, aucune modification du domaine.

### Demandes de solde

`POST /api/demandes-solde` (opérateur, une seule en attente par site) → `PATCH /{id}/approuver` (admin ; passe par `CaisseService::doterSite`, montant accordé éventuellement < montant demandé) ou `PATCH /{id}/rejeter` (motif obligatoire).

- Caisse : recharge 1 000 000 → dotation 400 000 → paiement 150 000 sur une pesée de 300 000 →`statutReglement = PARTIEL`, `site.solde = 250 000`, 4 lignes de `MouvementCaisse` chaînées (`soldeApres[n] === soldeAvant[n+1]`)
- Échec de payout simulé → paiement `ECHOUE` et solde du site restauré à l'unité près

---

## L'argent

Deux niveaux, et l'argent ne se crée jamais en chemin :

```
Recharge  ──►  Solde entreprise  ──►  Caisse d'un pont bascule  ──►  Planteur
 (admin)                          (dotation)                    (versement mobile money)
```

Toute écriture passe par `CaisseService`, qui pose un verrou pessimiste sur la ligne concernée et inscrit un `MouvementCaisse` portant le **solde avant et le solde après**. Une dotation écrit deux lignes : un débit chez l'entreprise, un crédit chez le site. Deux écritures qui se suivent sur un même compte doivent se chaîner — c'est ce qui rend une anomalie visible à l'œil nu.

Un versement sort de la caisse **dès l'envoi**, avant confirmation de la passerelle : c'est ce que montre le « montant immobilisé ». Sans cela, la même somme pourrait être engagée deux fois pendant qu'un paiement est en vol. En cas d'échec, elle revient par une écriture de remboursement.

La passerelle est abstraite (`PasserellePaiementInterface`) ; un pilote simulé sert en développement. Brancher un agrégateur réel = une classe, sans toucher au domaine.

---





## 2. Le cheminement d'un paiement

**Ce qu'il faut comprendre d'abord.** Tu ne déplaces pas l'argent toi-même. Tu le demandes à un **agrégateur mobile money**, qui parle à Orange Money, MTN, Wave. Et il ne répond pas tout de suite : tu envoies un ordre, il te dira plus tard s'il a abouti. Toute la complexité vient de là — **entre la demande et la réponse, il existe un temps où tu ne sais pas.**

### Étape 0 — l'argent doit déjà être en caisse

Rien ne part d'un poste vide. En amont : l'administrateur **recharge** le solde de l'entreprise, puis en **dote** la caisse d'un pont bascule. Deux écritures pour la dotation, parce que l'argent se déplace sans se créer.

### Étape 1 — l'opérateur déclenche

`POST /api/paiements` avec l'identifiant de la pesée et le montant. Le processor vérifie d'abord que **cette pesée est bien dans son périmètre** — sinon 404, pas 403 : on ne révèle pas qu'elle existe ailleurs.

### Étape 2 — les contrôles, avant que rien ne bouge

Pesée terminée, poste non bloqué, planteur joignable, et surtout :

> `reste = montantdu − sommeENGAGÉE`

**Engagée, pas versée.** Un versement encore en vol a déjà quitté la caisse ; ne compter que le confirmé permettrait d'engager deux fois le même reste, et de payer le planteur en double le jour où les deux aboutissent.

### Étape 3 — la réservation des fonds

C'est le moment charnière. Dans **une seule transaction** : on crée le versement en `EN_ATTENTE` **et** on débite la caisse du poste, sous verrou `SELECT … FOR UPDATE`. La ligne de grand livre porte le solde avant et après.

**À partir d'ici, l'argent a quitté la caisse — avant même que la passerelle soit appelée.** C'est délibéré : tant que le versement est en vol, la somme ne peut plus être engagée ailleurs.

### Étape 4 — l'appel à la passerelle

Quatre issues, et la quatrième est la plus subtile :

| Réponse | Ce qu'on fait |
|---|---|
| `REUSSI` | confirmé tout de suite → étape 6 |
| `ENVOYE` | **en vol** : on attend, c'est le cas normal |
| `ECHOUE` | refus → étape 6, remboursement |
| **Injoignable** | on reste en `ENVOYE` et **on ne rembourse pas** |

Ce dernier cas mérite qu'on s'y arrête : la passerelle ne répond pas, donc **on ignore si le versement est parti**. Rembourser immédiatement risquerait de rendre au poste un argent réellement versé au planteur — de l'argent créé à partir de rien. On préfère l'immobiliser et laisser la réconciliation trancher.

### Étape 5 — apprendre le dénouement, par deux chemins

- **Le webhook** — l'agrégateur rappelle `POST /api/webhooks/paiement`. Point d'entrée **public** : il n'a pas de compte chez nous, il s'authentifie en **signant** sa requête. On répond **toujours 200**, même à une référence inconnue : un agrégateur qui reçoit autre chose réessaie, parfois des heures.
- **La réconciliation** — `app:paiements:reconcilier` (en tâche planifiée) réinterroge la passerelle pour tout ce qui traîne depuis plus de 15 minutes. **Sans ce filet, un webhook perdu immobiliserait les fonds indéfiniment.**

Les deux peuvent arriver pour le même versement. D'où l'étape suivante.

### Étape 6 — appliquer le résultat, une seule fois

```php
if ($paiement->estFinal()) {
    return $paiement;   // ← toute l'idempotence tient dans cette ligne
}
```

- **Réussi** → on horodate, puis on recalcule le règlement de la pesée. Le cumul est **recalculé par somme**, jamais incrémenté : une addition se désynchronise au premier rejeu, un recalcul non.
- **Échoué** → on passe une écriture de **REMBOURSEMENT**, contrepartie exacte du débit. Jamais l'effacement de l'écriture initiale : le grand livre ne se réécrit pas, la tentative doit rester lisible. Le courriel part **après**, pour ne pas annoncer un retour d'argent qui n'a pas eu lieu.

### Le vocabulaire à retenir

**Réservation** (débiter avant d'appeler) · **en vol** (parti, pas encore tranché) · **idempotence** (le rejouer ne change rien) · **contrepartie** (annuler = ajouter une écriture inverse) · **réconciliation** (redemander quand on n'a pas eu de réponse).

---

## 3. Où l'argent bouge réellement

Il faut séparer deux choses qu'on appelle toutes les deux « paiement ».

**Les écritures internes — aucun franc ne quitte le système.** Ce sont des lignes dans MySQL, une comptabilité en partie double sur deux niveaux de caisse. Tout passe par `CaisseService`, seul endroit qui modifie un solde (vérifié : aucun autre appelant dans `src/`).

| Écriture | Déclencheur | Effet |
|---|---|---|
| `RECHARGE` | l'administrateur saisit un apport | crédite l'entreprise |
| `ATTRIBUTION` + `DOTATION` | dotation d'un poste, ou approbation d'une demande | débite l'entreprise, crédite le poste |
| `PAIEMENT` | réservation d'un versement | débite le poste |
| `REMBOURSEMENT` | versement échoué | recrédite le poste |

**La seule sortie vers le monde extérieur** est cette ligne :

```
PaiementService.php:111   $resultat = $this->passerelle->verser($paiement);
```

C'est le seul point de tout le code où une somme est demandée à un tiers pour atteindre le téléphone d'un planteur. (`verifier()`, ligne 174, interroge sans déplacer.)

**Et aujourd'hui, aucun argent réel ne bouge.** `services.yaml` branche l'interface sur `PasserelleSimulee` :

```
App\Domain\Paiement\PasserellePaiementInterface: '@App\Domain\Paiement\PasserelleSimulee'
```

Elle déroule tout le cycle sans agrégateur : un numéro commençant par `00` fait échouer, un montant finissant par `99` réussit immédiatement, le reste part en `ENVOYE` et attend le webhook. Passer en production, c'est **écrire une classe** — CinetPay, PayDunya — et **changer cette seule ligne**. Ni la caisse ni les paiements ne bougent.

Deux points à traiter avant ce jour-là : `notificationAuthentique()` renvoie `true` sans rien vérifier dans la version simulée — c'est là que devra vivre le contrôle de signature — et `app:paiements:reconcilier` doit être planifié, sinon le filet n'existe pas.







- - 
| Produit / Fournisseur | Résolution **par libellé dans le périmètre du site, avec auto-création** | Via clé unique..
| Rôle Agent | **Superviseur global en lecture** sur toute l'entreprise + gestion des opérateurs |

- J'ai supprimés : `code`, `libellesite` (dérivables de la FK `site`)
> | `Operation.codesite` | `Operation.refDesktop` | La clé primaire de la pesée dans la base locale du poste, **renvoyée telle quelle dans l'accusé de réception** (`{"operation":[{"id": …}]}`) — c'est ce qui permet au desktop de marquer chaque ligne comme synchronisée, elle ne peut pas disparaître |
> | `Operation.codepesee` | inchangé | Compteur séquentiel de pesée du poste (`000002`) |
> | `Operation.codesecret` | inchangé | Clé d'idempotence `code_refDesktop_codepesee` |

## L'arbitrage des prix

**Le problème.** Deux sources modifient le même chiffre. Le poste envoie son prix à chaque synchronisation, et le web peut le corriger. Sans arbitrage, la tarification faite en ligne est écrasée au premier envoi suivant : la bidirectionnalité ne marche que dans un sens.

**Le principe : un témoin.** `Produit` porte deux colonnes.

| Colonne | Rôle |
|---|---|
| `prix` | Le prix **en vigueur**, celui qui valorise les pesées. Affiché, modifiable des deux côtés. |
| `prixPoste` | La **dernière valeur reçue du poste**. Jamais affichée, jamais modifiée par le web. |

L'arbitrage se fait par **détection de changement**, pas par priorité : on ne réapplique le prix du poste que s'il a bougé *de son côté*.

```php
// Produit::appliquerPrixDuPoste()
if ($prix === $this->prixPoste) return false;  // le poste n'a rien changé → on ignore
$this->prixPoste = $prix;                      // on note ce qu'il dit maintenant
if ($prix === $this->prix)   return false;      // il rejoint la valeur en vigueur → rien à faire
$this->prix = $prix;                            // il a vraiment changé → il l'emporte
```

Le point clé est ailleurs : **`setPrix()`, la tarification web, ne touche pas à `prixPoste`.** C'est cet écart délibéré entre les deux colonnes qui fait qu'on saura ignorer le prochain envoi.

**Chronologie.**

| Événement | `prix` | `prixPoste` | Effet |
|---|---|---|---|
| Le poste envoie 400 | 400 | 400 | appliqué |
| Le web passe à 500 | **500** | 400 | `prixPoste` inchangé — c'est le témoin |
| Le poste renvoie 400 | 500 | 400 | **ignoré**, le web est préservé |
| Le poste passe à 470 | **470** | 470 | appliqué, il a changé de son côté |

Résultat : le dernier qui modifie l'emporte, et rien à changer côté logiciel de pesée.

**Une garde en amont.** `SynchronisationService` teste `array_key_exists('prix', $brute)` avant d'appeler : le poste qui n'envoie pas la clé du tout n'entre jamais dans l'arbitrage. On distingue « absent » de « vide », ce qui n'est possible qu'avec `array_key_exists`, pas avec `??`.

**Le planteur suit la même mécanique**, avec `prixspeciale` / `prixspecialePoste` — mais **une asymétrie assumée** : `Fournisseur::appliquerPrixSpecialDuPoste()` accepte `null` là où `Produit` le refuse. Un poste qui efface un prix négocié efface donc bien le prix négocié, y compris celui saisi en ligne. C'est cohérent avec « le dernier qui modifie l'emporte » — retirer, c'est modifier.

**Comment ce prix atteint une pesée.** `Operation::calculerMontant()` :

1. **Prix spécial du planteur d'abord, prix du produit ensuite** — `$fournisseur?->getPrixspeciale() ?? $produit?->getPrix()`. C'est la règle du patron.
2. Rien tant que la pesée n'est pas terminée : pas de second poids, pas de net, pas de montant.
3. Le prix retenu est **figé sur la pesée** (`prixunitaire`), pour que l'historique reste lisible quand les tarifs bougent.
4. **`montantpaye > 0` ⇒ on ne recalcule plus rien.** De l'argent est parti sur la base de ce montant ; un changement de tarif ne réécrit pas l'histoire.

**La retarification.** Un prix qui bouge ne concerne pas que le lot courant : les pesées déjà reçues et non payées portent l'ancien montant. `SynchronisationService` les recalcule — mais **seulement si un prix a réellement changé**, ce que lui dit le booléen que renvoient les deux `appliquer…()`. Le cas est rare, la requête ne part donc presque jamais.

---

**Un point à surveiller, que tu ne m'as pas demandé de corriger.** La retarification est déclenchée par la **synchronisation** uniquement. Modifier un prix depuis le web passe par `AuditProcessor`, qui ne fait qu'enregistrer l'auteur : **les pesées non payées déjà en base gardent leur ancien montant**. Elles ne seront recalculées qu'au prochain envoi du poste qui change un prix, quel qu'il soit — et à ce moment-là elles reprendront bien le prix web, puisque `findRecalculables` recalcule toutes les pesées non payées du site avec le prix en vigueur. Autrement dit la correction finit par s'appliquer, mais à un moment qu'on ne choisit pas. Si tu veux qu'une tarification en ligne s'applique immédiatement à l'existant, c'est un processor dédié sur le `PATCH` de `Produit` et de `Fournisseur` — dis-moi.

- Pour les prix le dernier qui le modifie l'emporte

Je pense que la notion de bidirectionnalité n'est pas logique dans notre  cas 

Explique moi le principe actuel de la partie arbitrage des prix (prixPoste/prixspecialePoste) et lequel est le plus logique et cohérent
    > Le premier cas : Est ce que :: Ce n'est pas le cas : /api/produit renvoie le prix au poste, donc le poste relit le référentiel du serveur avant de repousser ses pesées :: La boucle est fermée — ce qu'il renvoie est déjà la valeur du web
    > Le deuxième cas : les deux côtés écrivaient en aveugle :: un champ absent n'est pas un champ vide, une charge utile sans prix ne doit pas remettre le tarif à zéro
        - Le seul écart résiduel, écrit en commentaire : un poste qui pèse une journée hors ligne sans relire le référentiel renverra l'ancien prix. Les pesées déjà réglées ne bougent pas (leur montant est figé), les autres sont recalculées. Si un jour ça pose problème, ce sera au poste de relire avant de pousser, pas au serveur d'arbitrer
        - Sur le prix « bidirectionnel » : aujourd'hui le poste renvoie son prix à chaque synchronisation. Si les deux côtés font autorité sans autre règle, une modification faite sur le web est écrasée au prochain envoi — la bidirectionnalité ne marche que dans un sens.
    -- Ne pas oublié le fait qu'il peut avoir des pesées en cours de paiement donc le prix ne doit pas changer pour eux
    -- Je ne veux pas le comportement suivant : Le desktop envoi le prix du produit(`prixModifieSurWeb` (bool), **il fait foi à la création**, puis dès qu'un prix est saisi sur le web (`prixModifieSurWeb = true`) c'est le web qui devient maître et le sync cesse de l'écraser, Sans ce drapeau, chaque synchronisation annulerait le travail de tarification fait en ligne) ?
        - Prix : modifier un prix produit sur le web, resynchroniser → le prix web n'est pas écrasé, et les pesées déjà payées gardent leur `montantDu` d'origine
    -- Sur ta préoccupation initiale, le contrepoids est la traçabilité plutôt que l'interdiction, et elle est déjà en place : `updatedBy` retient qui a modifié un prix, `Operation::prixunitaire` fige le tarif appliqué à chaque pesée, et `Paiement.initiePar` retient qui a déclenché le versement. Un écart se reconstitue donc après coup, ligne par ligne — c'est ce qui permettra plus tard un écran « historique des tarifs » si le besoin apparaît.
    -- **L'arbitrage bidirectionnel des prix** est la trouvaille la mieux documentée : `prixPoste` mémorise la dernière valeur reçue du poste, et on ne réapplique celle-ci que si elle a bougé de son côté ([Produit.php:228](Backend-Webpesee-V2/src/Entity/Produit.php:228)). Le dernier qui modifie l'emporte, sans rien changer au logiciel de pesée.








- - 
## Les messages d'erreur sous les champs

Une seule cause, et elle explique bien plus que les champs :

```css
--destructive: oklch(0.1908 0.0020 106.5859);   /* avant : un quasi-noir */
```

Cette valeur est **la même teinte que `--card-foreground`**, et uniquement dans `:root` — le thème sombre, lui, portait un vrai rouge. En thème clair, tout ce qui s'annonçait en rouge sortait donc en noir, sans que rien ne le signale : les messages d'erreur, la bordure d'un champ invalide, les entrées destructives des menus de ligne, les boutons destructifs, les cartes `border-destructive/40`, et les alertes `destructive`. Corrigé en [app.css:23](Frontend-Webpesee/assets/styles/app.css:23).

## Les alertes

Trois défauts, dont un que tu n'avais pas pu voir :

1. **`variant="warning"` n'existait pas.** La page de connexion le passait au composant, qui ne connaissait que `default` et `destructive` : la variante ne produisait **rien**, et seules les classes ambre recopiées juste à côté donnaient l'effet attendu.
2. **Les teintes étaient recopiées à la main** dans quatre fichiers — la même longue chaîne emerald/amber/sky.
3. **Les alertes informatives restaient neutres** alors que le flash `info` était bleuté : la même notion n'avait pas la même apparence selon l'écran.

[Alert.html.twig](Frontend-Webpesee/templates/components/Alert.html.twig) porte maintenant `success`, `warning` et `info`, et `destructive` reçoit son fond et sa bordure comme les autres au lieu du seul texte coloré. Les chaînes recopiées ont disparu de `flash.html.twig`, `login.html.twig` et `mot_de_passe_oublie.html.twig`. Les **26 alertes** du projet portent chacune sa variante : 14 `destructive`, 7 `info`, 3 `warning`, 2 `success`. `default` reste neutre — une note sans coloration doit rester possible, et une information se demande explicitement.



## Desktop

**S'authentifier d'abord.** Le fichier de configuration déposé à l'installation porte `api.url`, `api.login`, `api.motdepasse`. Le poste fait `POST /api/login_check` (`{username, password}`), garde le `token` et le `refresh_token`, et met `Authorization: Bearer <token>` sur **tous** les appels suivants. Sur 401, `POST /api/token/refresh`. C'est la seule chose qui change par rapport à l'ancienne version.

**Envoyer les pesées** : `POST /api/synchronisation`, corps `{"pesees": [ … ]}`.

| Champ | Obligatoire | Devient |
|---|---|---|
| `id` | **oui** | `refDesktop` — la clé primaire de la pesée dans la base locale du poste |
| `code` | **oui** | vérification de cohérence avec le pont bascule du compte |
| `codepesee` | **oui** | `codepesee` |
| `numticket` | non | émis au second passage |
| `poids1`, `datepesee1`, `temps1` | non | premier passage |
| `poids2`, `poidsbrut`, `poidsnet`, `datepesee2`, `temps2` | non | second passage |
| `libelleproduit`, `codeproduit`, `prix` | non | crée/complète le produit |
| `libellefournisseur`, `codefournisseur`, `contactfournisseur`, `prixspeciale` | non | crée/complète le planteur |
| `libellemouvement`, `libelleclient`, `libelledestination`, `libelleprovenance`, `libelletransporteur`, `chauffeur` | non | texte libre |
| `immatriculation`, `remorque`, `peseur`, `contactpl`, `conteneur`, `plomb` | non | texte libre |

Les six règles qui comptent :

- **La ligne entière à chaque envoi**, pas seulement ce qui a changé — c'est ce que fait déjà le poste, et c'est ce qui autorise le serveur à tout réécrire.
- **La clé d'idempotence est `code_id_codepesee`.** Rejouer un lot ne crée aucun doublon : renvoyer par défaut ce dont on n'est pas sûr est le bon réflexe.
- **Le signal de fin est `poids2 > 0`**, jamais `datepesee2` — le poste la pré-remplit avec la date du premier passage. Tant que `poids2` vaut 0, le serveur ignore poids2/brut/net **et** la date de sortie.
- **`prix` et `prixspeciale` ne sont réappliqués que s'ils ont changé** côté poste depuis le dernier envoi. Les envoyer à chaque fois est sans danger : c'est ce qui préserve une tarification faite en ligne.
- **Dates en `Y-m-d`, heures en `H:i:s`**, séparées. `0000-00-00` vaut « absent ». Les nombres sont acceptés en chaînes.
- **`code` n'est plus la clé de routage** : le pont bascule vient du compte machine. Un code étranger fait refuser la ligne.

**Lire l'accusé de réception** — c'est un contrat, pas une statistique :

```json
{ "code": 1, "fail": 0, "success": 2,
  "operation": [{"id": "101"}, {"id": "102"}],
  "message": "Operation effectuee avec succes",
  "erreurs": [] }
```

Le poste ne doit marquer comme synchronisées **que** les références présentes dans `operation` — y ajouter une ligne non enregistrée effacerait une pesée sur le terrain. `code` : 1 = au moins une enregistrée, 2 = échec, 3 = aucune donnée. Le tableau `erreurs` (`[{id, message}]`) est un ajout : il permet de savoir quoi réessayer, là où l'ancien format n'exposait qu'un message global écrasé par la dernière ligne en échec.

**Les autres routes** consommées par le poste sont inchangées, toutes en POST avec le même Bearer : `/api/mouvement`, `/api/client`, `/api/destination`, `/api/provenance`, `/api/transporteur`, `/api/chauffeur`, `/api/vehicule`, `/api/produit`, `/api/fournisseur`, `/api/site`, `/api/lister`. `/api/produit` renvoie désormais le `prix` — c'est par là que le poste récupère une tarification faite en ligne.

Un détail utile au support : les référentiels se rapprochent sur une clé normalisée — accents retirés, tout ce qui n'est ni lettre ni chiffre supprimé, majuscules. « N'GUESSAN », « N GUESSAN » et « NGUESSAN » sont donc le même planteur, et le poste n'a rien à faire pour ça.

Les trois premiers manquants font rejeter la ligne — pas le lot : les autres passent, et l'id fautif revient dans erreurs[].






## 3. Les tests

**Backend** — PHPUnit installé, `tests/InvariantTestCase.php` monte une vraie base MySQL (le verrouillage et les contraintes d'unicité sont précisément ce qu'un double en mémoire n'a pas), schéma une fois par processus puis troncature entre les cas.

```bash
php bin/console --env=test doctrine:database:create --if-not-exists
```

**35 tests, 216 assertions, tout au vert en 1 min 33.**

- **[GrandLivreTest](Backend-Webpesee-V2/tests/Caisse/GrandLivreTest.php)** (6) — la chaîne `soldeAvant`/`soldeApres` relue compte par compte, la dotation qui déplace sans créer, le refus qui ne laisse aucune écriture orpheline, le journal fermé en écriture par l'API.
- **[PaiementWebhookTest](Backend-Webpesee-V2/tests/Caisse/PaiementWebhookTest.php)** (6) — un échec rejoué trois fois ne rembourse qu'une, une confirmation rejouée ne crédite qu'une, une notification d'une autre passerelle est ignorée, un versement en vol interdit d'engager le même reste à payer.
- **[DemandeSoldeTest](Backend-Webpesee-V2/tests/Caisse/DemandeSoldeTest.php)** (6) — une seule en attente par poste, libérée par l'annulation, le rejet et l'approbation ; une approbation refusée par la caisse ne marque pas la demande.
- **[CloisonnementTest](Backend-Webpesee-V2/tests/Securite/CloisonnementTest.php)** (9) — deux entreprises complètes, l'opérateur borné à ses caisses, la trésorerie globale invisible pour lui, les comptes machine écartés, la corbeille.
- **[SynchronisationTest](Backend-Webpesee-V2/tests/Synchronisation/SynchronisationTest.php)** (8) — rejeu sans doublon, un poste qui ne peut pas écrire chez le voisin, « HEVEA/Hévéa/hevea » = un produit, le camion encore sur le pont qui reste sans montant.

**Frontend** — [AccesVoterTest](Frontend-Webpesee/tests/Security/AccesVoterTest.php), **49 tests**, qui figent la table recopiée ligne par ligne. C'est le filet de la seule vraie faiblesse de l'approche : quand la table de l'API changera, ce fichier tombera en premier.

`npx tsc --noEmit` propre, `lint:twig` propre, assets reconstruits.



## Backend V2 — l'architecture

**Le cloisonnement tient en deux classes.** [Perimetre.php](Backend-Webpesee-V2/src/Security/Perimetre.php) calcule une fois par requête la liste des sites visibles (toute l'entreprise pour admin/agent, ses caisses pour un opérateur, son seul poste pour un compte machine), et [PerimetreExtension.php](Backend-Webpesee-V2/src/Doctrine/PerimetreExtension.php) l'applique à toutes les lectures API Platform — corbeille, puis entreprise, puis sites. Le soft-delete y est traité par simple héritage d'`EntityBase`, pas par une seconde extension comme en V1. [UserExtension.php](Backend-Webpesee-V2/src/Doctrine/UserExtension.php) écarte en plus les comptes machine de l'écran des utilisateurs, en filtrant sur `siteMachine` (relation) et non sur le rôle (colonne JSON).

**Les droits sont une table, pas une hiérarchie.** [AccesVoter.php](Backend-Webpesee-V2/src/Security/Voter/AccesVoter.php) remplace à lui seul les onze voters de V1 : une constante `DROITS[rôle][ressource] = [actions]`, puis la vérification de périmètre. Les règles que la table ne sait pas dire (« on ne suspend pas un fondateur », « un agent ne gère que des opérateurs ») vivent dans [UserGuard.php](Backend-Webpesee-V2/src/Security/UserGuard.php), avec la double surface `peutGerer()` / `assertPeutGerer()`.

**La sécurité de la synchronisation a été corrigée à la racine.** Dans [SynchronisationService.php](Backend-Webpesee-V2/src/Domain/Service/SynchronisationService.php), le site vient du **compte machine**, plus du champ `code` de la charge utile — en V1, n'importe quel compte authentifié pouvait écrire chez n'importe quel client. S'y ajoutent la clé d'idempotence `codesecret` (index unique), une seule transaction et un seul `flush()` pour tout le lot, et un accusé de réception qui n'acquitte qu'après validation.

**L'unicité** passe par [UniqueParSite](Backend-Webpesee-V2/src/Validator/UniqueParSiteValidator.php), qui ne compare qu'aux lignes vivantes — jamais d'index unique en base, sinon un produit à la corbeille interdirait de le recréer.

---

le produit n'existe pas → créé ; il existe → mis à jour
Pour le cas suivant je ne comprend pas : . Le prix multiplie le poids net pour donner ce qui sera versé ; laisser celui qui tient la caisse fixer ce prix reviendrait à lui laisser décider combien il décaisse. En revanche il peut compléter le numéro d'un planteur (attribut dédié MODIFIER_CONTACT) — sans numéro la pesée n'est pas payable, et c'est lui qui a le planteur en face de lui.


4. Aucune trace des actions sensibles. convention.md prévoit un ActiviteLogger ; le backend V2 n'en a pas. On sait qui a modifié une ligne (updatedBy) mais pas ce qui a changé. Pour une suspension de compte, une dotation ou une approbation, c'est ce qui manque le jour d'une contestation.
7. Pas de corbeille. Le soft-delete est partout, mais rien ne permet de voir ni de restaurer un élément supprimé. Un produit supprimé par erreur ne se récupère qu'en base — alors que c'est précisément le scénario qui a produit un des bugs de V1.

Journal de synchronisation (/synchronisations) : reçues / créées / complétées / refusées par poste, avec le motif du refus en clair sur la ligne

Écran « à payer » en tête de la section Caisse pour qui encaisse : la file d'attente du guichet, bornée aux pesées terminées non soldées, bouton Verser en clair sur la ligne. Le motif de non-payabilité du planteur s'y lit avant de cliquer.

Notifications : demande déposée → aux administrateurs, demande traitée → à son auteur, versement échoué → à l'opérateur. Une méthode par événement, pas de notifier() générique. Le point important : aucun envoi ne peut faire échouer le geste métier. Vérifié en pointant le mailer sur un SMTP mort — le journal enregistre Envoi de courriel impossible vers patron@socaf.ci, et le dépôt répond quand même 201.

Un bug attrapé en vérifiant
L'écran « à payer » listait aussi les pesées déjà soldées. J'avais écrit le filtre multi-valeurs 'statutReglement[]' => [...] : le client HTTP échappe les crochets de la clé puis ajoute ses propres indices, l'API ne reconnaît pas le paramètre et l'ignore en silence. La bonne forme est le tableau sous la clé simple. Corrigé, vérifié — 5 lignes puis 4, la soldée exclue. C'est noté en mémoire, le piège se reproduira.

NotCompromisedPassword interroge un service distant — sur une connexion de terrain instable, ça bloquerait toute création de compte. J'ajoute le repli :

Jointure agrégée. CompteurPeseesProvider décore le provider de collection natif (sécurité, périmètre, filtres et pagination inchangés) et pose peseesCount sur toute la page en une requête groupée. Un seul provider pour les trois référentiels — ils posent la même question à la même table. La fiche garde le calcul à la demande : une ligne seule ne justifie pas le détour. Mesuré sur 100 lignes : ~145 ms contre ~190 ms, et l'écart s'élargit avec une base distante où chaque requête coûte un aller-retour.

Plus loin : la double authentification pour les administrateurs.

- Le principe du second envoi : Un camion passe deux fois sur le pont. À l'entrée, le peseur enregistre le poids 1 et le poste envoie la ligne : la pesée est `EN_COURS`, sans poids net donc sans créance. À la sortie, il enregistre le poids 2 et **renvoie la même ligne**, identifiée par la même clé (`code_id_codepesee`). Le serveur reconnaît cette clé, retrouve la ligne existante et la met à jour au lieu d'en créer une seconde :: **le second envoi porte la ligne entière**



## 2. Messenger sur mutualisé

**Oui, ça marche** — le transport Doctrine est fait pour ça. Les messages vont dans une table, un cron consomme :

```bash
* * * * * cd /home/user/webpesee && php bin/console messenger:consume async --time-limit=55 --memory-limit=96M --quiet
```

`--time-limit` sous la minute du cron pour que les processus ne s'empilent pas ; même s'ils se chevauchent, le transport Doctrine verrouille en `SELECT … FOR UPDATE`, donc pas de double envoi. Trois pièges propres au mutualisé : le binaire PHP CLI diffère souvent du PHP web, certains hébergeurs limitent le cron à 5 ou 15 minutes, et il faut un `failure_transport` sinon un mail échoué disparaît sans bruit.

**Mais je vous le déconseille ici.** Les 10 secondes venaient de ma machine, pas de votre code : `MAILER_DSN` pointait sur `localhost:1025` (Mailpit), qui ne tourne pas. Sur votre mutualisé le SMTP est local, un envoi prend quelques dizaines de millisecondes. Messenger ajouterait une table, un cron, un transport d'échec — et surtout un **délai** : si l'hébergeur plafonne le cron à 15 minutes, le lien de réinitialisation arrive un quart d'heure plus tard. Ce serait pire qu'aujourd'hui.

J'ai corrigé la vraie cause : `MAILER_DSN=null://null` par défaut dans `.env`, le contenu des mails reste consultable dans le panneau « Mailer » du profiler, et Mailpit s'active dans `.env.local` pour qui le veut. La production renseigne son SMTP dans `.env.local`, comme `DATABASE_URL`.

À rouvrir le jour où le SMTP est distant et lent, ou quand viendront les notifications de paiement aux planteurs — vous aurez de toute façon déjà un cron pour `app:paiements:reconcilier`, une ligne de plus ne coûtera rien.





- L'opérateur ne voit que sa caisse, celle de ses sites

DATES — le poste envoie la date et l'heure séparément ('datepesee1' + 'temps1'). On les recompose en un seul horodatage : l'écran « BILAN STANDARD » filtre sur une plage date + heure, ce qui serait pénible avec quatre colonnes distinctes
    **`peseeAt1`** (`datetime_immutable`), **`peseeAt2`** (nullable) — recomposés au sync depuis `datepesee1 + temps1` / `datepesee2 + temps2`, ils remplacent `date1/date2/temps1/temps2/datesearch` (5 colonnes → 2, et le filtre date+heure du bilan desktop devient direct)

- Le site sera crée sur le web et l'application génère dans la foulée le compte machine + un fichier de configuration téléchargeable (URL de l'API, code du site, identifiants) consommé à l'installation du logiciel de pesée.

- **`statut`** (`EN_COURS` / `TERMINEE`) — le panneau « PESEE EN ATTENTE » du desktop prouve qu'une pesée peut n'avoir qu'un premier passage. Aujourd'hui `poids2`/`date2` sont NOT NULL : une pesée en cours ne peut tout simplement pas être synchronisée
    - Pesée en cours : pousser une pesée sans `poids2` → enregistrée `EN_COURS`, aucune créance ; la repousser complétée → même ligne, `TERMINEE`, `montantDu` calculé
    !!
    - **`TarificationService::calculer(Operation)`** — `prixunitaire = fournisseur.prixspeciale ?? produit.prix`, `montantDu = poidsnet × prixunitaire`, **uniquement pour les pesées `TERMINEE`**. Recalculé quand un prix produit ou un prix spécial change sur le web, et **jamais sur une pesée déjà payée même partiellement** — sinon un changement de tarif réécrirait l'historique comptable.
    - **Pesées en cours** — une pesée sans second passage est acceptée et stockée en `EN_COURS` ; la synchronisation suivante la complète via le même `codesecret` et la bascule en `TERMINEE`. C'est exactement ce que montre le panneau « PESEE EN ATTENTE ». Les statistiques et les créances ne portent que sur les pesées terminées.
    - **Idempotence** : upsert sur `codesecret`, désormais garanti par l'index unique. Rejouer deux fois le même lot ne crée aucun doublon — c'est le test de non-régression principal.

- La synchronisation **rejette toute pesée dont le `code` ≠ `siteMachine.codesite`** → plus d'injection inter-site (faille actuelle : le tenant vient du payload).
    - Isolation : avec le JWT du site A, pousser une pesée portant le `code` du site B → 403
- Si `site.statut === BLOQUE`, le sync répond 403 → c'est l'implémentation de « bloquer un pont bascule pour qu'il ne reçoive plus de données ».

| **3** | Synchronisation : services, routes legacy, idempotence, pesées en cours, `SynchronisationLot` | Rejouer 2× un lot → 0 doublon |

User `siteMachine` (`OneToOne Site`, nullable, renseigné uniquement pour les comptes `ROLE_SITE`)

- **`SynchronisationService::traiter(User $compte, array $pesees): ResultatSync`** — une seule transaction par lot, `flush()` unique en fin de lot (aujourd'hui : 4 `flush()` par ligne), `SynchronisationLot` écrit à la fin. Erreur par ligne collectée sans interrompre le lot (comportement actuel conservé, c'est le bon). Le site et l'entreprise sont **résolus depuis `$compte`**, jamais depuis le payload : les 6 lignes de `substr($code, 0, 3)` + `findOneByCodePrefix()` disparaissent.


- - 
# Webpesee

Gestion de ponts bascules. Sur chaque site, un logiciel de pesée enregistre les passages de camions et pousse ses pesées vers une API centrale. Depuis le web, une entreprise exploite l'ensemble de son parc — consultation, statistiques, impression — et paie ses planteurs par mobile money.

| | Super administrateur | Administrateur | Agent | Opérateur |
|---|---|---|---|---|
| Périmètre | Les clients | Son entreprise | Son entreprise | Ses ponts bascules |
| Entreprises | créer, modifier, désactiver | modifier la sienne | consulter | — |
| Ponts bascules | **mettre en service**, bloquer | affecter, doter, bloquer | consulter | consulter les siens |
| Utilisateurs | tous | ceux de son entreprise | les opérateurs | — |
| Pesées et statistiques | **aucun accès** | ✓ | ✓ | ses sites |
| Caisse | **aucun accès** | recharger, doter | consulter | sa caisse |
| Payer un planteur | — | ✓ | — | ✓ |
| Tarifer produits et planteurs | — | ✓ | — | ✓ |

Le super administrateur provisionne, il n'exploite pas : ni pesées, ni caisse, ni statistiques. Ce n'est pas son argent.

Un **compte machine** `ROLE_SITE` par pont bascule sert au logiciel de pesée. Il n'a aucun accès web.

- **L'entreprise vient du compte machine**, jamais du payload. Un code mal saisi à l'installation ne peut plus verser les pesées chez le mauvais client.
- **Idempotence garantie par un index unique** sur `operation.codesecret`. Rejouer deux fois le même lot ne crée aucun doublon.
- **Turbo n'affiche pas une réponse 200 à un POST.** Un formulaire doit rediriger après succès, ou renvoyer un statut d'erreur (422) pour être réaffiché. Les deux écrans qui rendent directement leur réponse — mise en service d'un poste et renouvellement de ses identifiants — portent `data-turbo="false"`, parce que le mot de passe n'existe que dans cette réponse.

```bash
cd Backend-Webpesee && vendor/bin/phpunit && vendor/bin/phpstan analyse
cd Frontend-Webpesee && vendor/bin/phpstan analyse && npx tsc --noEmit && php bin/console lint:twig templates
```
- - 

when@test:
    framework:
        validation:
            not_compromised_password: false
validator.yaml

#[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire')]
#[Assert\Length(min: 10, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères')]
#[Assert\NotCompromisedPassword(
    message: 'Ce mot de passe figure dans des fuites de données connues : choisissez-en un autre',
    skipOnError: true // La vérification interroge un service distant : sur une connexion de terrain coupée, elle ne doit pas empêcher de créer un compte
)]
#[Assert\Regex(
    pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
    message: 'Le mot de passe doit contenir au moins une minuscule, une majuscule et un chiffre'
)]






















git push -u origin main



On vas développer l'application en architecture séparé 
    - Backend : Symfony, API..
    - Frontend : Angular

- Ignore le fichier brl.md du projet, c'est juste un brouillon



- - 
Salut Claude Code, mon patron m'a demandé de développé une application web qui a pour objectif de générer un certifcat de vérification qui contient 2 pages mais il ne m'a pas donnée assez d'informations. Voici quelques notions que j'ai compris :

- En réalité :
    1. **Le technicien** se déplace chez un client pour intervenir sur un équipement de pesage (pont bascule, ou éventuellement un autre type d'appareil)
    2. Il remplit une **fiche d'intervention** contenant les informations de l'intervention
    3. Puis arrivée au bureau il donne la fiche d'intervention à mon patron qui rédige le certificat, la page 2 du certificat contient les résultats de 3 contrôles :
        - **Contrôle de fidélité**
        - **Contrôle de justesse**
        - **Contrôle d'excentration**

Mon patron m'a aussi dit que pour le contrôle d'excentration le pont bascule peut avoir entre 4,6,8 ou 12 capteurs ce qui fait l'objet des colonnes du tableau de contrôle d'excentration

- Maintenant, mon patron voudrait d'une application qui lui permettra de dédié ça tâche à la sécrétaire :: d'abord question est ce que le technicien saisi dans l'appli



J'envoi l'image de la fiche d'intervention du technicien et les images du certificat de vérification que l'application doit générer. ce que j'ai mis en bic sont de nouvelles variables qu'on vas rajouter




- Le patron veut dédier cette tâche à la sécrétaire
- Le technicien ne saisi pas dans l'application il rend juste ça fiche d'intervention à la sécrétaire


- Y'a t'il un **calcul automatique** qui est fait dans les parties :
    | Élément | Valeur observée | Origine probable |
    |---|---|---|
    | Limite contrôle fidélité | ± 30 000 kg | = Portée max ÷ 2 (60 000 ÷ 2) |

        Si la valeur nominale saisi par l'utilisateur est supérieur à la moitié (ici le max /2) de la porté max alors Non

    | Limite contrôle excentration | 20 000 kg | = Portée max ÷ 3 (60 000 ÷ 3) |

        !! idème mais à demander de diminué à /2

    | Tolérance EMT (±20 kg) | identique sur les 3 tableaux | = Échelon de vérification (20 kg) |
    | Écart relevé | 0 sur toutes les lignes | = Valeur nominale − Indication lue |

        Oui, pour contrôle de fidélité

    | État final "CONFORME" | texte en vert | = calculé selon les 3 réponses Oui/Non |

        Oui, caluclé selon les 3

Si ces règles sont confirmées, l'appli peut **calculer automatiquement** les limites, les écarts et le verdict final — le technicien n'aurait qu'à saisir les valeurs lues, pas les tolérances.

## Le tableau d'excentration — la partie dynamique

C'est le point que tu as souligné : le tableau a des colonnes = nombre de capteurs (4, 6, 8 ou 12), avec pour chaque position (capteur) :
- N° de position
- Valeur Nominale VN (kg)
- Indication lue I (kg)
- Indication + surcharge de sensibilité (kg)
- Écart relevé (kg)
- + une colonne EMT fixe à droite

Sur l'exemple fourni, le pont bascule a 4 capteurs → seules 4 colonnes sont remplies (le gabarit papier semble prévoir 6 colonnes fixes, dont 2 vides ici). Pour l'appli, il faudra générer dynamiquement N colonnes selon le nombre de capteurs choisi. ?? Ou se limiter à 6 ? :: Oui le pont bascule peut avoir plusieurs capteurs(dynamique)


3. Le **N° de certificat** (CVSOC2002050625) — c'est quel format de génération ? Auto-incrémenté par année/mois ?
4. Pour le **contrôle de justesse**, le nombre de "points d'essai" (0 à 4 ici) est-il toujours fixe à 5, ou variable aussi ?
5. Le **verdict "CONFORME"** — calculé automatiquement à partir des 3 cases Oui/Non, ou saisi manuellement par la secrétaire ?
7. Faut-il stocker un **historique** des certificats (recherche par client/date/n° certificat) ?

4. **"Pont bascule ou autres appareils"** : les autres types d'équipements suivent-ils le même certificat en 2 pages, ou seulement le pont bascule a ce format avec les 3 contrôles ? Tous


- La mise en page exacte à reproduire (pour que je sache si on vise un PDF fidèle au template ou une appli qui génère juste les données)
- La structure exacte du tableau d'excentration

1. Je suis d'accord avec le fait qu'il paege. 
2. Les formules ne dépendent pas de la classe de précision de la norme FD X 07‑016
3. Le N° du certificat (CVSOC2) ne change pas, 02 incrémenté auto en fonction du nombre de CV éffectué dans le mois, 050625 .. MJA en 2 caractère
4. 
5. 
6. Oui la sortie attendue est PDF téléchargeable/imprimable
7. Oui


- Les autres types d'équipements .. Oui




Contrôle de fidélité :
                                        |   valeur nominale - indication lue = écrat relevé, écart maximal relevé = max(écart relevé)
On peut avoir plusieurs points d'éssai  |   La valeur nominale est saisi par l'utilisateur
                                        |

Contrôle de justesse :
                                        |   valeur nominale saisi par l'utilisateur(et ne doit pas dépasser la moitié de la portée max) et les points d'éssai généres doivent être multiple de l'EMT
On peut avoir plusieurs points d'éssai  |  valeur lue en montée, valeur lue en descente, indication + surchage de sensibilité (l'utilisateur peut entrer une valeur ex:8kg mais doit être en dessous de l'EMT sur les valeurs nominale, donc ex: s'il rente 8 la valeur nominale si 10 000 devient 10 008 )
générer de façon automatique            | l'écart revlévé ici = indication surha.. - valeur nominale








- **Contrôle de fidélité** (répétabilité des mesures)
- **Contrôle de justesse** (écart entre valeur mesurée et valeur de référence)
- **Contrôle d'excentration** (test de charge décentrée sur les capteurs — avec un tableau dont le nombre de colonnes varie selon le nombre de capteurs du pont bascule : 4, 6, 8 ou 12)


## Certificat

**Fiche d'intervention** (terrain, remplie à la main par le technicien)
Client, localisation, interlocuteur, contact, type d'équipement (Pont Bascule), marque, modèle, portée maxi/mini, échelon, date/heure, demande de travaux, détails travaux, matériel utilisé, visa technicien + visa client. → C'est le document source de terrain, **pas encore le certificat**.

**Certificat page 1** (« CERTIFICAT DE VERIFICATION »)
En-tête fixe (SOCAF, accréditation COFRAC N°2‑1376), N° de certificat, client destinataire, puis les caractéristiques officielles de l'instrument vérifié (désignation, marque, modèle, portée max/mini, classe de précision, échelon de vérification, conditions environnementales), dates de vérification/émission, mention "2 pages", signature du service métrologie.

**Certificat page 2** (« RESULTAT D'ETALONNAGE »)
Les **3 contrôles** que ton patron a mentionnés, chacun avec un tableau de mesures + une case Oui/Non de conformité, puis un verdict global "CONFORME" et la référence à la norme **FD X 07‑016**.