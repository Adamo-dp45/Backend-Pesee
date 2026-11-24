### Brl

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
    > Vu que `make` n'est pas disponible sur `windows` on.. passer par les `scripts` de `composer`
    > !! qu'en `mysql` un index unique contenant une colonne `NULL` n'empêche pas les doublons et que l'auto création par libellé n'aurait rien garanti on a crée une clé normalisée `Cle.php`..
    > Le fuseau horaire en `UTC` vu qu'un hébergeur en `Europe/Paris` décale de 2 heures.. dans les `Kernel.php`, dans les `twig.yaml` pour que son `date_default_timezone_get()` soit à l'abri d'un `php.ini` et dans le `cellules.tsx` on a `timeZone: 'UTC'`
    > Pour éviter de répéter `security: [['bearerAuth' => []]]` sur chaque opération, on.. déclarer globalement dans `OpenApiFactory` avec `security: []` sur les routes publiques
    > On.. `SynchronisationLot` en tant que journal d'un push pour diagnostiquer un pont bascule à distance

- **L..**
    > php bin/console app:super-admin:creer : Pour créer le super administrateur ou en mode interactif `.. --email=root@webpesee.com --nom=admin`
- 

- 
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




root@webpesee.com
bakayoko
adam@gmail.com
Socafpesage10




- Un point que je n’ai pas tranché, dites-moi s’il vous gêne : un changement de taux s’affiche sous le geste « Ouverture d’une mise en compte », faute d’un troisième type dans l’énumération. Le libellé dit exactement ce qui s’est passé, mais la colonne « Geste », elle, annonce une ouverture.

- Ce que ce mode ne fait pas, et qu'il faut écrire noir sur blanc : émettre le chèque solde la pesée immédiatement. L'application ne sait pas s'il a été encaissé. C'est le même compromis que les espèces, à ceci près que le délai est réel — à surveiller si un chèque sans provision devient un cas.


- 3. **Échec mobile money** : un versement remboursé doit annuler sa mise en compte. Le chemin de remboursement existe, il faudra l'étendre.

- Le cas de test qui manquait au plan — « un retrait mobile échoué rend l’argent aux deux livres » — est écrit. Je l’ai éprouvé en désactivant la ligne d’annulation d’épargne : il tombe avec « L’épargne n’a pas été rendue au planteur — 50000 ≠ 200000 », puis repasse une fois la ligne remise. C’est le cas le plus coûteux du lot : les deux livres étant séparés, aucun écran ne signalerait l’écart — le total des caisses retomberait juste, seule la dette envers le planteur serait fausse.

Un risque technique que je préfère nommer tout de suite : cette application a pour règle qu’un POST rend toujours une redirection, et un 422 n’émet pas turbo:load. Une modale qui poste et réaffiche ses erreurs dedans est précisément le motif qui casse. Je la ferais sur un Turbo Frame, pour que l’erreur se rejoue dans le cadre sans emporter la page. C’est faisable, mais c’est le point sur lequel je serai prudent.

modules/surcouche.js vide les portails et le verrou sur turbo:before-cache. Il ne vise pas que ma modale : le sélecteur [data-slot$="-portal"] couvre les tiroirs, les panneaux, les menus déroulants et les bulles. Aucun n’a de raison d’être photographié — ils décrivent un geste en cours, pas la page.

## Les messages de commit

```powershell
git -C Backend-Webpesee-V2 commit -m @'
feat: Tarification à trois étages, transport par tranches, et les totaux des écrans d'argent

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
'@
```

```powershell
git -C Frontend-Webpesee commit -m @'
feat: Les écrans des tarifs, des trois bilans et de la file du guichet

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
'@
```

**Mais je dois te dire que c'est beaucoup pour un seul commit.** L'API a 49 fichiers modifiés et 44 neufs, le web 57 et 29 — et surtout ce n'est pas *une* chose : la tarification, le transport par tranches, le passage du référentiel à l'entreprise, les bilans, l'écran à payer, et un **changement de droits** qui retire à l'opérateur la création de planteurs. Ce dernier mérite d'être visible dans l'historique le jour où quelqu'un demandera « depuis quand on ne peut plus créer un planteur au guichet ? ». Noyé dans un commit de cent fichiers, il ne le sera pas.

Si tu veux le découper, je propose cinq commits par dépôt, dans cet ordre :

1. `feat: Catégories de planteurs et grille de tarifs à trois étages`
2. `feat: Transport au kilo, au forfait ou par tranches de kilomètres`
3. `feat: Le référentiel appartient à l'entreprise, plus au poste`
4. `feat: Les trois bilans, leurs totaux et leurs exports`
5. `feat: La file du guichet : totaux, filtres, et versement depuis la liste`

Et le changement de droits à part : `refactor: Produit et Fournisseur réservés à l'administrateur`.

Dis-moi si tu veux que je prépare ce découpage — c'est faisable en ajoutant les fichiers par paquets. **Je n'ai rien commité.**






## Pourquoi je garde la grille

Tu as raison sur un point et je l'ai corrigé : il manquait la **vue d'ensemble**. C'était le vrai manque, et c'est ce que l'écran fait maintenant.

Sur la saisie, je tiens la grille, pour quatre raisons.

**1. Ce qu'on vient chercher, c'est le trou — pas la ligne.** Une liste de tarifs ne montre que ce qui existe. « Quels produits ne sont pas tarifés pour cette catégorie ? » y est invisible : une ligne absente ressemble à rien du tout. Or ce sont ces trous qui coûtent — le planteur est payé au prix du produit sans que personne ne l'ait décidé pour lui. La grille affiche une ligne par produit, tarifé ou non, avec le repli écrit dessous.

**2. Une case vide n'est pas une suppression.** Dans un CRUD ligne à ligne, retirer un tarif est un bouton « Supprimer » : il a l'air destructeur et ne dit rien de la conséquence. Dans la grille, tu vides la case et la ligne en dessous te dit ce que le planteur touchera à la place — « Sans tarif ici : 400 F le kilo ». La conséquence est à l'écran au moment du geste.

**3. L'aperçu compte les pesées revalorisées, et il ne peut pas être par ligne.** C'est la raison forte. Enregistrer un tarif revalorise des pesées, et une pesée qui acquiert une valeur est **arbitrée** : sa retenue d'épargne est figée pour toujours, sans chemin de retour. L'aperçu annonce « 1 créé, 0 modifié, 0 retiré, N pesées revalorisées » avant d'écrire. Avec un formulaire par ligne, il faudrait soit refaire cette cérémonie sur chaque création, chaque modification et chaque suppression — donc **N confirmations pour N produits**, plus de clics et non moins — soit la sauter sur un des trois chemins, et c'est ce chemin-là qui figera un jour des milliers de retenues.

**4. Tarifer une catégorie, c'est fixer plusieurs produits ensemble.** La grille est une transaction, un aperçu, une décision. Ligne à ligne, tu peux t'arrêter au milieu — et la moitié manquante paie au prix du produit en silence. C'est exactement la panne qu'on cherche à rendre visible.

J'ajoute que choisir le produit dans une liste déroulante est une version dégradée du même geste : tu choisis à l'aveugle, sans voir lesquels sont déjà tarifés ni à quel prix, et rien n'empêche deux lignes sur le même produit. La grille ne peut pas produire de doublon.

Ce que je te concède : **le deuxième clic**. L'aperçu coûte un aller-retour. C'est le seul que je ne retirerai pas, quelle que soit la forme qu'on donne à l'écran.

Si tu préfères quand même les formulaires, je les fais — mais je garderai l'aperçu sur chaque écriture, donc le deux-temps restera.






## La liste

| # | Fonctionnalité | Ce que ça touche | Jours | Risque |
|---|---|---|---|---|
| 1 | **Desktop réduit à la pesée** — tout le traitement sur le web | Contrat de synchronisation : le poste cesse de faire autorité sur les prix et les montants. `SynchronisationService`, `calculerMontant`, les deux chemins de retarification | **3–5** | moyen — touche les tests canaris de la synchro, et se coordonne avec l'équipe Desktop |
| 2 | **Produit et transport payés séparément ou ensmble**, transport en plusieurs fois |

| 6 | **Classes hiérarchisées de planteurs**, prix par classe, prix planteur prioritaire | La résolution du prix passe de 1 à 3 niveaux, dans le chemin chaud de **toute** pesée — et borne aussi la mise en compte |
-   => un prix par classe **et par produit**, ou un prix par classe tout court ? Et la hiérarchie descend sur combien de niveaux ?
| 8 | **Transport** : forfait ou tranches de kilomètres | Aujourd'hui un seul mode, F/kg. Ajoute un mode + un barème — et **la distance n'existe nulle part** : il faut décider qui la fournit | **4–6** | moyen — bloqué tant que l'origine de la distance n'est pas tranchée |
-   => **#8** — d'où vient la distance ? Du Desktop, de la fiche du planteur, d'une saisie au guichet ?

Un barème par tranches sur un planteur sans distance est refusé à la saisie ; quand c’est sa catégorie qui bascule après coup, la pesée et la fiche le disent au lieu de figer un zéro muet. Et getTransportApplique() répond « qui touche quoi » en suivant les deux sauts — la colonne lisait baremeTransport, donc le sien, et annonçait « Aucun » à des planteurs parfaitement réglés par leur catégorie.

| 7 | **Prix datés** + historique consultable | Le prix cesse d'être une valeur et devient un intervalle. Une pesée se valorise au prix **de sa date**, pas au prix du jour — ce qui change le sens même de la revalorisation automatique | **5–8** | **élevé** — couplé à #6 |
- Prix : programmer les dates d'application des tarifs et conserver un historique consultable des prix effectivement appliqués.
- **#6 et #7 ensemble.** Ils modifient le même geste — « quel prix s'applique à cette pesée ». Faits séparément, le second réécrit le premier.

| 5 | **Recherche et suivi** : tables mieux filtrables, libellés plus précis | Filtres par période partout, colonnes supplémentaires, libellés métier. Le socle est sain — on vient de l'éprouver écran par écran | **3–5** | faible — mais le chiffre dépend du nombre d'écrans retenus |













| 3 | **Tableau de bord détaillé** (à qui doit-on, etc.) | Agrégats SQL + providers de stats, cartes et tables React. Lecture seule | **3–5** | faible |

| 9 | **Relevés imprimables** pour les établissements financiers | L'export existe pour le bilan seul (PDF + Excel). Cinq états de plus, filtrés par période, planteur, site, avec leurs totaux | **4–6** | faible — mais dépend de #2 pour séparer produit et transport |
- Relevés : consulter et imprimer des états détaillés des pesées, paiements de produits, règlements de transport, épargnes et retraits, filtrés par période, fournisseur, site ou autre critère utile aux établissements financiers. 
Plus sur les fournisseurs : permet de faire des documents comme un relevé de qui peut aller en banque 


Priorité : pour définir les propriétés par entreprise du genre une entreprise peut dire moi c'est le prix de la categorie qui est prioritaire par rapport au prix fournisseur etc... :: Gérer du côté super admin




Une chose à savoir : la sélection n'existe que sur le tableau, pas sur les cartes du téléphone, où l'on garde le « Verser » par pesée. C'était déjà le cas avant ces ajustements, et vous ne l'aviez pas demandé — dites-moi si le guichet verse aussi depuis un téléphone.





activer les logs dans l'application vu que l'hébergeur ne montre pas
Monolog pour les logs applicatifs.
Activer les logs dans l'application en cas d'erreur en ligne




- Bilan Log
- Mettre le filtre pont bascule, produit en commentaire pour la partie `À payer`
Je me dis qu'on vas avoir une page pour la modification, aussi on vas mettre un lien dans la partie versement qui y pointe
- Filtre période(à rendre sticky)

La logique concernant la suppression qu'est ce qu'il se passe et est ce que ça pose problème ! vu que moi je pense que s'il a déjà des pesées ne plus le supprimé ! Pas trop sur qu'il explique l'existant et me dise lequel est meilleur entre les 2

- Pour le planteur, (concernant le.. de.. on vas le mettre en place) iTransport a aussi un filtre remote avec autocomplétion distante. Au-delà de quelques centaines de planteurs c’est ce qu’il faudra ; je commencerais par une liste déroulante et je garderais le remote en réserve.
    - filtres par période sur les tableaux de listing de..
    - sur new catégorie lien pour créer barème dans une poppup
    - Lien rapide
- Produit par entreprise, mais on peut ajouter un champ qui permet à un site de faire un surplus sur le prix à l'instant t lors de la pesées
- Lors de la création et modification de l'entreprise, si entreprise à un seule site ou plusieurs -> si un on représente le solde de l'entreprise par celui du site !! Pas trop sure

Les deux fiches gardent la lecture de leur grille et mènent à la saisie (categorie, planteur) ! 


| 4 | **Comptabilité** : références et **pièces jointes** aux reçus et documents | Infrastructure neuve : **aucun mécanisme de fichier n'existe aujourd'hui** dans les deux dépôts. Stockage, dépôt, types et tailles, qui a le droit de lire, rattachement à un paiement / mouvement / demande | **5–8** | moyen — le stockage et les droits de lecture sont à trancher avant de coder |
- Comptabilité : renseigner les références de caisse, de chèque et d'autres justificatifs, avec la possibilité de joindre les pièces aux reçus et documents concernés.
3. **#4** — où vivent les pièces jointes, qui peut les lire, combien de temps les garde-t-on ?


- Dans le `.sidebar-logo` de `base.html.twig` on vas afficher le nom de l'entreprise et son logo plutôt que `iTransport`
commande utile, bd..


On m'a parler d'un truc pour le faire de manière générique, du genre on a une table Tarif qui gère les différentes types de tarification, qui est différencié par une propriété `type` et qui est prioritaire à la table tranche !

Tarif(categoriefournisseur, fournisseur, produit) -- prioritaire sur la table tranche
    idproduit
    idfournisseur
    idcategoriefournisseur
    prixproduit
    prixtransport
    datedebut
    datefin
    type 

- Syncronisation en API Platform
- On ne reçois que les syncronisations terminé

Dans la partie Frontend.., pour le Dashbord je veux qu'il soit au style réel de Shadcn (→ ui.shadcn.com) en (HTML, Css, etc...), responsive, moderne, etc...
- Tu n'a pas mis de sidebar dans le Dashbord ! Quand je dis que je veux que le tableau de bord soit au style réel de [shadcn/ui](https://ui.shadcn.com) je faisais allusion au [sidebar-07 de shadcn/ui](https://ui.shadcn.com/view/new-york-v4/sidebar-07) !






- Le cron `app:paiements:reconcilier`.. on l'a dans le `Makefile` donc reste à poser sur le serveur

**La sécurité de la synchronisation a été corrigée à la racine.** Dans [SynchronisationService.php](Backend-Webpesee-V2/src/Domain/Service/SynchronisationService.php), le site vient du **compte machine**, plus du champ `code` de la charge utile — en V1, n'importe quel compte authentifié pouvait écrire chez n'importe quel client. S'y ajoutent la clé d'idempotence `codesecret` (index unique), une seule transaction et un seul `flush()` pour tout le lot, et un accusé de réception qui n'acquitte qu'après validation.

**L'unicité** passe par [UniqueParSite](Backend-Webpesee-V2/src/Validator/UniqueParSiteValidator.php), qui ne compare qu'aux lignes vivantes — jamais d'index unique en base, sinon un produit à la corbeille interdirait de le recréer.


- Le principe du second envoi : Un camion passe deux fois sur le pont. À l'entrée, le peseur enregistre le poids 1 et le poste envoie la ligne : la pesée est `EN_COURS`, sans poids net donc sans créance. À la sortie, il enregistre le poids 2 et **renvoie la même ligne**, identifiée par la même clé (`code_id_codepesee`). Le serveur reconnaît cette clé, retrouve la ligne existante et la met à jour au lieu d'en créer une seconde :: **le second envoi porte la ligne entière**


le produit n'existe pas → créé ; il existe → mis à jour
Pour le cas suivant je ne comprend pas : . Le prix multiplie le poids net pour donner ce qui sera versé ; laisser celui qui tient la caisse fixer ce prix reviendrait à lui laisser décider combien il décaisse. En revanche il peut compléter le numéro d'un planteur (attribut dédié MODIFIER_CONTACT) — sans numéro la pesée n'est pas payable, et c'est lui qui a le planteur en face de lui.

Journal de synchronisation (/synchronisations) : reçues / créées / complétées / refusées par poste, avec le motif du refus en clair sur la ligne

Écran « à payer » en tête de la section Caisse pour qui encaisse : la file d'attente du guichet, bornée aux pesées terminées non soldées, bouton Verser en clair sur la ligne. Le motif de non-payabilité du planteur s'y lit avant de cliquer.

Notifications : demande déposée → aux administrateurs, demande traitée → à son auteur, versement échoué → à l'opérateur. Une méthode par événement, pas de notifier() générique. Le point important : aucun envoi ne peut faire échouer le geste métier. Vérifié en pointant le mailer sur un SMTP mort — le journal enregistre Envoi de courriel impossible vers patron@socaf.ci, et le dépôt répond quand même 201.






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





- Concernant le reliquat fournisseur, il est géré à moitié :
    ## Ce qui n'est PAS géré : le compte d'un planteur

    Il n'existe **aucun cumul par planteur**. L'entité `Fournisseur` porte son identité, son contact, son réseau, son prix spécial, son statut — et rien qui ressemble à un solde. Aucun champ en base, aucun endpoint, aucun écran.

    Concrètement, aujourd'hui, pour savoir ce que vous devez encore à Bamba Sekou **en tout** :

    - sa fiche planteur affiche son identité et ses dernières pesées, sans un seul montant ;
    - l'écran « À payer » ne totalise rien, et n'offre même pas de sélecteur de planteur — le filtre existe dans le code mais seulement par URL ;
    - le bilan a bien un « Reste à payer » global, mais il ne se filtre pas par planteur.

    Il faudrait lister ses pesées impayées et **additionner à la main**.

    ## Un détail de conception qui vous intéressera

    Dans [Operation.php:660](Backend-Webpesee-V2/src/Entity/Operation.php:660), le reste refuse d'être négatif, avec cette justification : *« si un tarif a baissé après un versement, le trop-perçu ne doit pas se transformer en dette du planteur »*. Autrement dit, le trop-versé est **oublié** par construction. Une vraie gestion de reliquats devrait trancher là-dessus : le reporter sur la pesée suivante, ou le passer en créance. Aujourd'hui, ni l'un ni l'autre.
- Le reste à payer est géré par pesée — versements multiples, reste à payer, reste à engager, statut partiel — mais il n'existe aucun cumul par planteur : ni champ, ni écran, ni total :
    Le reliquat dont on vous a parlé, c'est lequel des deux ?
        Compte par planteur ou Reste par pesée




Une conséquence demeure : l’entreprise porte une dette qui n’est provisionnée nulle part. Si le planteur vient retirer 10 000 et que le poste n’a que 5 000 en caisse, le retrait échoue faute de fonds — comme un versement. C’est cohérent, mais il faudra que le total des comptes se voie quelque part, sinon l’administrateur ne saura jamais combien il doit.


## Hors de cette version, et une limite à connaître

Le **retrait**, les taux **par produit**, le **plafond**, le rattachement d'un planteur à
plusieurs postes.

**Limite préexistante que la retenue aggrave** : `findRecalculables()` n'a qu'un appelant,
déclenché uniquement par un prix venu du **poste**. Le `Patch` web de tarification ne
retarife rien. Une pesée de produit créé à la volée reste donc à `montantdu = 0` — et
désormais sans retenue — jusqu'au prochain changement de prix côté poste. Avant, personne
n'était lésé ; après, l'épargne du planteur ne démarre jamais sur ces pesées. À traiter dans
la foulée en branchant la même passe sur le `Patch` de `Produit` et de `Fournisseur`, mais
c'est un ajout de périmètre à décider.












- **`PasserelleSimulee` est la passerelle active** — brancher un vrai agrégateur = une ligne dans `services.yaml`, et `PASSERELLE_SECRET` est encore celui de développement




Quand j'effectue un paiement, dans la partie `À payer` le `Reste à payer` ne bouge pas !

## Pourquoi il ne bougeait pas

Le « Reste à payer » de la file se calculait ainsi :

```tsx
Math.max(0, (montantdu ?? 0) - (montantpaye ?? 0))
```

Or `montantpaye` ne compte **que le confirmé** — `recalculerLeReglement()` l'alimente depuis `sommeVersee()`, c'est-à-dire les seuls versements `REUSSI`. Un versement qui vient de partir est en `ENVOYE` : la caisse du poste est débitée, l'API décomptera bien ce montant au prochain versement, mais la ligne affiche exactement le même chiffre qu'avant. Rien ne bouge.

L'ancien commentaire du contrôleur l'assumait d'ailleurs : *« Ceux qui sont en vol n'y figurent pas… C'est voulu »*. Sauf que, vu du guichet, un geste sans effet visible est un geste raté : on recommence, et c'est l'API qui refuse — après le clic, planteur devant soi.

## Deux restes, désormais distincts

- **`resteapayer`** — la créance du planteur : dû moins **confirmé**. Elle ne tombe qu'à la réponse de la passerelle, et c'est juste : tant qu'elle n'a pas répondu, le planteur n'a rien reçu.
- **`resteaengager`** — ce que la caisse peut encore sortir, donc ce que l'API acceptera. C'est exactement le `$reste` que calcule `PaiementService::initier()`.

Côté backend :
- `PaiementRepository::montantsEnVolParPesee()` — somme groupée des versements `EN_ATTENTE`/`ENVOYE` pour toute une page.
- `MontantEnVolProvider` — nouveau provider sur `GetCollection`, calqué sur `CompteurPeseesProvider` : une requête pour la page entière, jamais une par ligne (les `paiements` restent réservés à la fiche).
- `Operation::getMontantenvol()` et `getResteaengager()`, sérialisés dans `read:Operation`.
- `getMotifNonPayable()` gagne le cas « un versement est déjà en cours », **mot pour mot** le refus du service : le bouton se désactive sur la phrase que le serveur renverrait.

Côté écrans :
- La file « À payer » affiche le reste à engager en gros, avec `X F en cours de confirmation` en dessous quand il y a du vol.
- Le formulaire de versement se pré-remplit avec `resteaengager` au lieu de la créance entière — il proposait jusqu'ici un montant que l'API allait rejeter.

## Deux choses à savoir pour tester

**Videz le cache Symfony du backend**, sinon les nouveaux champs ne sortiront pas du sérialiseur (je m'y suis fait prendre sur l'environnement de test) :

```bash
php bin/console cache:clear
```

**Et sachez que `montantpaye` ne bougera toujours pas en local**, par construction : `PasserelleSimulee` laisse le versement en `ENVOYE` et attend une notification qui n'arrive jamais, puisque ni webhook ni cron ne tournent sur votre poste. Pour confirmer un versement en développement, deux chemins :

```bash
php bin/console app:paiements:reconcilier --delai=1
```

ou bien verser un montant se terminant par `99`, que la passerelle simulée confirme immédiatement.




## Paiement

Feexpay.md : Transfert et collect : agrée par la BECAO
* .. Api payment : Cinetpay, PawaPay, LigdiCach, PaiementPro, PayDunya, FedaPay, Hub2, Paystack, BUI
    > 

- On.. normalisation du numéro de téléphone du planteur pour les agrégateurs via une règle dans `NumeroMobile` qui est tout ce qui entre devient `+225..` et la validation via `NumeroMobileValide`, `NumeroMobileValideValidator`
    > La synchronisation du poste l'accepte vu qu'elle n'a pas de second essai et un refus perdrait la pesée, mais `getMotifNonPayable()` la dénonce
    > Le numéro de circonstance normalisé dans `PaiementService::initier()` avant que les fonds ne soient réservés
    > Si.. des données en base on.. la `NormaliserNumerosCommand` via `app:fournisseurs:normaliser-numero` mais ne corrige pas un numéro incomplet

- !! la réconciliation planifiée on.. `make cron` qui contient la ligne à coller, avec `flock -n` vu que 2 exécutions qui se chevauchent pourraient rembourser deux fois le même versement.. mais un cron s'arrête sans rien dire et personne ne surveille le surveillant donc `/api/stats/caisse` porte `versementsEnVol`, `attenteMinutes`.. et l'écran de caisse lève une alerte au delà de `30min` mais ne désigne pas de coupable.. l'agrégateur peut tarder sans que rien ne soit cassé, il dit quoi regarder 


- **modéliser les frais de transaction** — rien ne les représente aujourd'hui, et ils sortiront bien de quelque part


- Le paiement
    > L..
        > Le paiement porte sur une pesée terminée payable en plusieurs fois
        > La recharge du solde entreprise est saisi par l'administrateur
    > Dans le cas.. le paiement est un `décaissement` l'entreprise envoie vers le mobile money d'un planteur
    > La `CaisseService.php` est le point qui touche un solde, `refresh()` pour l'ordre de verrouillage entreprise→site systématique et chaque écriture produit sa ligne de grand livre avec `soldeAvant` et `soldeApres`
        > La méthode `rechargerEntreprise()` pour recharger le solde de l'entreprise
        >  !! `doterSite()` pour l'attribution de l'entreprise et la dotation du site
        > L'exception `UnprocessableEntityHttpException` si le solde est insuffisant
    > Le `PaiementService.php` réserve les fonds avant l'appel passerelle, dans la même transaction que la création du paiement, borne sur la somme engagée ou pas confirmée et `appliquerResultat()` est idempotent pour absorber webhook + réconciliation
    > La réconciliation des paiements doit être planifiée `app:paiements:reconcilier` sinon un un versement dont la notification se perd reste en vol indéfiniment ce qui immobilise l'argent

### Le point qu'on découvre trop tard : le flottant

La distinction n'est pas académique : les API, les tarifs, les habilitations et surtout **le financement** diffèrent. Beaucoup de prestataires ouvrent la collecte tout de suite et le décaissement seulement après vérification de l'entreprise.

Un décaissement ne va pas « chercher » l'argent au moment du clic. Il puise dans un **compte de flottant** que l'entreprise a **provisionné à l'avance** chez l'agrégateur, par virement bancaire. Flottant vide = tous les versements échouent, quels que soient les soldes affichés dans Webpesee.

**Cela dit quelque chose d'important sur votre modèle** : le `solde` d'un pont bascule est une **allocation de gestion**, pas un coffre. Il n'existe qu'une seule poche d'argent réelle — le flottant. Les caisses par poste disent « ce poste a le droit de dépenser tant » ; c'est un budget. Quelqu'un finira par se demander pourquoi la somme des caisses ne correspond à aucun relevé bancaire : mieux vaut l'écrire quelque part dès maintenant.

### Qui appelle-t-on

- **Un agrégateur** — un contrat, une API, les quatre réseaux. En Côte d'Ivoire : CinetPay, PayDunya, Hub2, InTouch. C'est le choix par défaut, et c'est exactement ce que `PasserellePaiementInterface` suppose.
- **Les opérateurs en direct** (Orange Money, MTN MoMo, Wave) — meilleurs tarifs à gros volume, mais quatre contrats, quatre intégrations, **quatre flottants à approvisionner** et quatre réconciliations. À envisager seulement après.

### La forme de l'échange

Elle est la même partout, et c'est pourquoi votre interface n'a rien à changer :

1. **S'authentifier** — clé + secret, souvent contre un jeton à durée de vie courte.
2. **Décaisser** — montant, devise `XOF`, **numéro au format international**, opérateur, et **votre propre référence** qui sert de clé d'idempotence. → réponse : accepté + identifiant de transaction.
3. **Attendre** — webhook signé, plus un endpoint de consultation pour ceux qui n'arrivent jamais.

Vos quatre méthodes couvrent exactement ça : `verser()`, `verifier()`, `notificationAuthentique()`, `lireNotification()`. **Le domaine ne bougera pas.** Les noms de champs exacts viendront de la doc du prestataire retenu.




### Ce qu'il reste à faire, concrètement

| | Quoi | Pourquoi c'est bloquant |
|---|---|---|
| 1 | Une classe `PasserelleXxx` + changer l'alias dans `services.yaml` | c'est la seule ligne à toucher |
| 2 | **Normaliser le numéro** | `contact1` est du texte libre saisi au poste ; les agrégateurs exigent un format strict. Sinon les échecs tombent un par un, après coup |
| 3 | **Vérifier la signature du webhook** | `notificationAuthentique()` renvoie `true` sans rien contrôler. C'est une porte publique : n'importe qui pourrait confirmer un versement |
| 4 | **Planifier `app:paiements:reconcilier`** | la commande existe, rien ne l'exécute. Sans elle, un webhook perdu immobilise les fonds pour toujours |
| 5 | Décider du **réseau** | beaucoup d'agrégateurs le déduisent du préfixe ; d'autres l'exigent. `ReseauMobile` est saisi à la main aujourd'hui |
| 6 | Les secrets dans `.env.local` + le bac à sable du prestataire | pour la recette |

**Et une question que rien ne modélise encore** : les **frais**. Chaque décaissement en a. Le grand livre débite aujourd'hui exactement le montant versé — si les frais sont à votre charge, il faudra soit les provisionner, soit leur donner leur propre écriture.

Dis-moi par quoi on commence sur les paiements — le choix de l'agrégateur conditionne les points 1 et 5, mais **2, 3 et 4 sont indépendants** et peuvent être faits dès maintenant.


les points 2, 3 et 4 de ma liste (format du numéro, signature du webhook, réconciliation planifiée) sont indépendants du choix de l'agrégateur




| `debiterSite(Site, int, Paiement)` | − site (PAIEMENT) |
| `rembourserSite(Site, int, Paiement)` | + site (REMBOURSEMENT), sur échec de payout |
| `ajuster(...)` | ± avec motif obligatoire, réservé à l'**administrateur de l'entreprise** (correction d'erreur de saisie) |




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
- 






- 
| Produit / Fournisseur | Résolution **par libellé dans le périmètre du site, avec auto-création** | Via clé unique..
| Rôle Agent | **Superviseur global en lecture** sur toute l'entreprise + gestion des opérateurs |

- J'ai supprimés : `code`, `libellesite` (dérivables de la FK `site`)
> | `Operation.codesite` | `Operation.refDesktop` | La clé primaire de la pesée dans la base locale du poste, **renvoyée telle quelle dans l'accusé de réception** (`{"operation":[{"id": …}]}`) — c'est ce qui permet au desktop de marquer chaque ligne comme synchronisée, elle ne peut pas disparaître |
> | `Operation.codepesee` | inchangé | Compteur séquentiel de pesée du poste (`000002`) |
> | `Operation.codesecret` | inchangé | Clé d'idempotence `code_refDesktop_codepesee` |

- **L'arbitrage des prix** : Le problème, 2 sources modifient le même chiffre. Le poste envoie son prix à chaque synchronisation, et le web peut le corriger. Sans arbitrage, la tarification faite en ligne est écrasée au premier envoi suivant : la bidirectionnalité ne marche que dans un sens
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

- **Comment ce prix atteint une pesée.** `Operation::calculerMontant()` :
    1. **Prix spécial du planteur d'abord, prix du produit ensuite** — `$fournisseur?->getPrixspeciale() ?? $produit?->getPrix()`. C'est la règle du patron.
    2. Rien tant que la pesée n'est pas terminée : pas de second poids, pas de net, pas de montant.
    3. Le prix retenu est **figé sur la pesée** (`prixunitaire`), pour que l'historique reste lisible quand les tarifs bougent.
    4. **`montantpaye > 0` ⇒ on ne recalcule plus rien.** De l'argent est parti sur la base de ce montant ; un changement de tarif ne réécrit pas l'histoire.

    **La retarification.** Un prix qui bouge ne concerne pas que le lot courant : les pesées déjà reçues et non payées portent l'ancien montant. `SynchronisationService` les recalcule — mais **seulement si un prix a réellement changé**, ce que lui dit le booléen que renvoient les deux `appliquer…()`. Le cas est rare, la requête ne part donc presque jamais.

**Un point à surveiller, que tu ne m'as pas demandé de corriger.** La retarification est déclenchée par la **synchronisation** uniquement. Modifier un prix depuis le web passe par `AuditProcessor`, qui ne fait qu'enregistrer l'auteur : **les pesées non payées déjà en base gardent leur ancien montant**. Elles ne seront recalculées qu'au prochain envoi du poste qui change un prix, quel qu'il soit — et à ce moment-là elles reprendront bien le prix web, puisque `findRecalculables` recalcule toutes les pesées non payées du site avec le prix en vigueur. Autrement dit la correction finit par s'appliquer, mais à un moment qu'on ne choisit pas. Si tu veux qu'une tarification en ligne s'applique immédiatement à l'existant, c'est un processor dédié sur le `PATCH` de `Produit` et de `Fournisseur` — dis-moi.

!! Je ne veux pas le comportement suivant : Le desktop envoi le prix du produit(`prixModifieSurWeb` (bool), **il fait foi à la création**, puis dès qu'un prix est saisi sur le web (`prixModifieSurWeb = true`) c'est le web qui devient maître et le sync cesse de l'écraser, Sans ce drapeau, chaque synchronisation annulerait le travail de tarification fait en ligne) ?






Les six règles qui comptent :

- **La ligne entière à chaque envoi**, pas seulement ce qui a changé — c'est ce que fait déjà le poste, et c'est ce qui autorise le serveur à tout réécrire. sauf poids1 et temps1 à la deuxième
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





**l'architecture**

- **Le cloisonnement tient en deux classes.** [Perimetre.php](Backend-Webpesee-V2/src/Security/Perimetre.php) calcule une fois par requête la liste des sites visibles (toute l'entreprise pour admin/agent, ses caisses pour un opérateur, son seul poste pour un compte machine), et [PerimetreExtension.php](Backend-Webpesee-V2/src/Doctrine/PerimetreExtension.php) l'applique à toutes les lectures API Platform — corbeille, puis entreprise, puis sites. Le soft-delete y est traité par simple héritage d'`EntityBase`, pas par une seconde extension comme en V1. [UserExtension.php](Backend-Webpesee-V2/src/Doctrine/UserExtension.php) écarte en plus les comptes machine de l'écran des utilisateurs, en filtrant sur `siteMachine` (relation) et non sur le rôle (colonne JSON).
- **Les droits sont une table, pas une hiérarchie.** [AccesVoter.php](Backend-Webpesee-V2/src/Security/Voter/AccesVoter.php) remplace à lui seul les onze voters de V1 : une constante `DROITS[rôle][ressource] = [actions]`, puis la vérification de périmètre. Les règles que la table ne sait pas dire (« on ne suspend pas un fondateur », « un agent ne gère que des opérateurs ») vivent dans [UserGuard.php](Backend-Webpesee-V2/src/Security/UserGuard.php), avec la double surface `peutGerer()` / `assertPeutGerer()`.
