# Webpesee — l'API

> Ce fichier est fait pour qu'on s'y retrouve après six mois d'absence. Il dit **pourquoi** les
> choses sont comme elles sont ; le code dit comment. Quand les deux divergent, c'est le code qui a
> raison et ce fichier qui doit être corrigé.

---

## 1. Ce que fait l'application

Une entreprise de pesage achète des récoltes à des planteurs. Un camion monte sur un **pont
bascule**, on le pèse plein, il se vide, on le repèse : la différence est le **poids net**, et c'est
elle qu'on paie. L'application enregistre ces pesées, tient la caisse de chaque poste, et paie les
planteurs — en espèces ou par mobile money.

Trois acteurs, trois logiciels :

| Qui | Avec quoi | Ce qu'il fait |
|---|---|---|
| Le peseur, sur le pont | Un logiciel tiers, sur le poste | Pèse, imprime un ticket, **envoie** ses pesées à l'API |
| L'opérateur, au guichet | Le web (`Frontend-Webpesee`) | Paie les planteurs, demande du solde |
| L'administrateur | Le web | Tient les caisses, les comptes, les référentiels |

**Le logiciel du poste ne nous appartient pas.** Il pousse ce qu'il veut, quand il veut, et il
rejoue ses lots dès qu'un accusé se perd. Beaucoup de décisions de ce dépôt n'existent que pour
cette raison.

---

## 2. Les deux dépôts

```
Backend-Webpesee-V2   API Platform. TOUTE la règle métier. La base de données.
Frontend-Webpesee     Application Symfony + Twig + React. Ne décide RIEN, affiche.
```

Le web ne possède aucune base. Il s'authentifie à l'API en JWT, garde la charge utile de `/api/me`
en session, et **ne recalcule aucune règle**. Quand un écran doit savoir s'il peut proposer un
bouton, il le demande à l'API — voir §6.

---

## 3. Le vocabulaire

Le patron emploie indifféremment **planteur** et **fournisseur** ; le code garde `Fournisseur`,
parce que c'est le mot du logiciel de pesée et de sa charge utile. De même, une **pesée** est une
`Operation` dans le code — et dans les fichiers où `Operation` désigne déjà celle d'OpenAPI, on
l'importe aliasée : `use App\Entity\Operation as Pesee;`.

La langue du framework est l'anglais, celle du métier le français. On ne francise pas `Repository`,
on ne traduit pas `fournisseur`.

---

## 4. Le chemin d'une pesée

```
poste ──POST /api/synchronisation──▶ SynchronisationService::traiter()
                                      │
                                      ├─ 1. interprétation du lot
                                      ├─ 2. préchargement groupé (produits, planteurs, comptes)
                                      ├─ 3. application ligne à ligne  → calculerMontant(), au prix du web
                                      └─ 4. UNE transaction : flush → mises en compte → flush
```

**Tout ou rien.** Si quoi que ce soit échoue, rien n'est acquitté, le poste renverra, et
l'idempotence absorbe. On préfère un renvoi inutile à une pesée acquittée mais absente de la base.

### Ce que le poste ne décide pas

**Le poste pèse ; il ne tarife pas.** Le prix d'un produit et le transport d'un planteur se
fixent sur le web, qui est aussi l'endroit où l'on paie — une seule source pour le chiffre qui
fonde un versement. Les logiciels de pesée déployés envoient toujours `prix` et `prixtransport`
dans leur charge utile : ces champs sont **lus et jetés**. Les refuser ferait échouer des lots
entiers pour une information dont on n'a plus l'usage.

> Et le référentiel qu'on leur **renvoie** garde `prixtransport`, désormais toujours `null` :
> la colonne n'existe plus sur le planteur, mais une clé qui disparaît d'une charge utile casse
> un logiciel déployé qu'on ne met pas à jour à la demande. Un `null` qu'il ignore ne casse rien.

> Les deux côtés ont longtemps fait autorité, départagés par détection de changement sur un
> `prixPoste` qui mémorisait le dernier envoi. L'arbitrage est parti avec sa raison d'être. Ce
> qu'il coûtait : deux sources pour un chiffre qui sort de l'argent, et une divergence qu'aucun
> écran n'affichait.

**Ce que ça change au quotidien** : un produit créé à la volée par le peseur naît à zéro, et ses
pesées ne valent rien tant que personne ne l'a tarifé au bureau. C'était déjà vrai quand le poste
se taisait ; c'est désormais la règle. La liste des produits sait filtrer **ceux qui attendent
leur prix** (`?prix=0`), et la colonne des prix affiche « Aucun prix » en rouge — sans quoi on les
découvrirait au guichet.

### Ce qui identifie un planteur

Le poste envoie un `codefournisseur` **et** un `libellefournisseur`. C'est le **code** qui
rapproche, et le libellé ne sert plus que de repli — vers une fiche **sans code**, c'est-à-dire une
fiche d'avant la bascule, jamais vers un homonyme qui en porte déjà un. `IndexFournisseurs` tient
cet ordre, et c'est le seul endroit où il est écrit.

Le libellé seul produisait deux défauts qu'aucun écran ne montrait :

- **deux homonymes n'en faisaient qu'un** — « Kouamé » désigne trois planteurs dans un village, et
  leurs pesées, leurs versements et leur épargne atterrissaient sur une seule fiche ;
- **un planteur renommé en devenait deux** — corriger « Traore » en « Traoré » sur le poste créait
  une seconde fiche, la première gardant l'historique.

**Le nom ne se réécrit pas** sur une fiche retrouvée par son code : le poste n'est plus la source du
référentiel, et un nom corrigé au bureau ne doit pas être défait par le prochain envoi — même règle
que pour le numéro de téléphone. Aucune reprise de données n'a été nécessaire : les fiches sans code
se font marquer au premier envoi qui en porte un. `tests/Synchronisation/SynchronisationTest` tient
les trois cas.

### À quel niveau vit chaque référentiel

**Le produit est à l'ENTREPRISE ; le planteur est au POSTE.** Un libellé reçu d'un poste se
rapproche des produits de son entreprise, et un produit inconnu y naît. Les ponts bascules d'un
même client pèsent donc le même cacao au même prix, et chacun reçoit la liste entière — y compris
les produits qu'il ne pèse jamais. Le peseur choisit dans sa liste, il ne la subit pas.

> Ça n'a pas toujours été le cas : chaque poste tenait son propre « Cacao », avec son propre prix.
> C'était tenable tant qu'un prix était un seul nombre. Avec des tarifs portés par l'entreprise, il
> aurait fallu viser « le cacao de Soubré » puis « celui de Gagnoa » et les tenir d'accord à la
> main — la duplication même qu'on cherchait à supprimer.

Le **planteur**, lui, livre à une barrière précise, et deux hommes du même nom à deux postes sont
deux hommes. L'unicité suit le périmètre de chacun : `UniqueParEntreprise` sur le produit,
`UniqueParSite` sur le planteur — deux déclinaisons d'un même `UniqueParPerimetreValidator`, qui
ne compare qu'aux lignes **vivantes** pour qu'une ligne à la corbeille n'interdise pas de recréer.

**Ce que ça change pour un opérateur** : il voit tous les produits de son entreprise, et toujours
ses seuls planteurs. `tests/Securite/CloisonnementTest` tient les deux moitiés de la phrase.

**Et il n'en écrit plus aucun.** L'opérateur tarifait les produits et créait des planteurs, du
temps où le référentiel était à son poste. Depuis qu'il appartient à l'entreprise, un prix saisi
à une barrière vaut à toutes les autres, et une fiche corrigée l'est pour tout le monde :
`Produit` et `Fournisseur` sont passés en LECTURE pour lui. Ce que ça coûte au guichet, et qu'il
faut savoir : un planteur qui se présente pour la première fois ne se crée plus depuis le web par
l'opérateur, et un numéro de téléphone faux ne se corrige plus par lui. **Le poste, lui, continue
de les créer en synchronisant** : il porte `ROLE_SITE`, absent de la table des droits, et ne passe
pas par le voter.

### Ce qui se fige, et pourquoi

Le poste renvoie sa base locale à chaque passage, pas une nouvelle mesure. Trois choses ne
s'écrivent donc **qu'une fois** :

- **le premier passage** (`poids1`, `peseeAt1`) — relevé par la bascule quand le camion monte ; le
  second envoi n'est qu'une recopie ;
- **le second passage** (`poids2`, `poidsbrut`, `poidsnet`, `peseeAt2`) **et le statut avec lui** —
  le poids net est l'assiette de la mise en compte, et un envoi tardif à `poids2 = 0` renverrait en
  « en cours » une pesée déjà créditée, en effaçant son montant ;
- **la retenue** (§7).

La condition porte toujours sur ce qui est **déjà en base**, jamais sur le statut de l'envoi : une
pesée dont le premier envoi n'a rien apporté doit pouvoir se compléter au passage suivant.

---

## 5. L'argent

### Trois caisses, et toutes ne vivent pas au même niveau

| | Caisse **physique** | Caisse **numérique** | Caisse **bancaire** |
|---|---|---|---|
| **Entreprise** | les espèces en coffre | le flotte mobile money | le compte sur lequel les chèques sont tirés |
| **Pont bascule** | le tiroir de l'opérateur | le flotte du poste | — |

La bancaire est à l'**entreprise seule**. Un carnet de chèques ne se dote pas à une barrière de
pesage : il n'y a rien à y remettre, et « doter un poste en banque » ne veut rien dire. D'où deux
accesseurs plutôt qu'un : `TypeCaisse::toutes()` énumère les trois, **`TypeCaisse::celles($compte)`
celles que ce niveau tient réellement**. Toute boucle qui construit des colonnes, un total ou une
liste déroulante passe par la seconde — avec la première, un poste afficherait une caisse bancaire
éternellement à zéro, que personne ne pourrait alimenter, et un opérateur pourrait la réclamer.
`Site::getSoldeDe()` et `DemandeSolde::caissesDUnPoste()` refusent la bancaire en la nommant.

**Rien ne traverse.** Il n'existe aucun verbe pour passer des espèces au flotte, ni du flotte à la
banque : cela se fait à la banque ou chez l'agrégateur, pas ici. Une dotation prend dans la caisse
physique de l'entreprise pour remplir la caisse physique du poste, et pareillement pour le
numérique. La bancaire ne se dote pas : elle se recharge depuis l'écran Caisse.

Le **mode de règlement** d'un paiement décide seul de la caisse qui se vide
(`ModeReglement::caisse()`) : espèces → physique, mobile money → numérique, **chèque → bancaire**.
Un mode ajouté plus tard (virement) se déclare dans cet enum et le reste suit.

### Le chèque, et le seul paiement imputé à l'entreprise

Un chèque ne vide pas le tiroir du poste : il vide le compte en banque de l'**entreprise**. C'est
le premier paiement dont le compte n'est pas celui du site, et `TypeMouvement` tenant à ce que le
type décide seul du compte et du sens, il gagne deux cas plutôt qu'un paramètre :
`PAIEMENT_BANCAIRE` (débit sur l'entreprise) et `REMBOURSEMENT_BANCAIRE` (crédit). `CaisseService`
branche sur `$type->compte()` : verrou et provision sur l'entreprise, ou sur le site comme avant.

`Paiement::numeroCheque` est **obligatoire pour ce mode seulement** : c'est la seule référence qui
permette le rapprochement avec le relevé bancaire, et sans elle un chèque émis est indistinguable
d'un autre.

> **Ce que ce mode ne fait pas** : émettre le chèque solde la pesée immédiatement. L'application ne
> sait pas s'il a été encaissé — même compromis que les espèces, à ceci près que le délai est réel.
> À revoir si le chèque sans provision devient un cas.

### Le grand livre

`CaisseService` est le **seul** endroit qui modifie un solde. Chaque écriture :

1. verrouille la ligne (`SELECT … FOR UPDATE`) avant de lire le solde ;
2. écrit un `MouvementCaisse` portant `soldeAvant` et `soldeApres`.

Pour un même **(compte, caisse)**, le `soldeApres` d'une ligne doit être le `soldeAvant` de la
suivante. Un écart signale immédiatement une écriture passée hors du service. `TypeMouvement`
n'offre volontairement **aucun ajustement libre** : tout mouvement va d'un compte à un autre,
l'argent ne se crée jamais.

### Ce que les bilans agrègent, et où

Quatre ressources de lecture, toutes sous `/stats`, toutes calculées **par la base** :

| | Ce qu'elle rend |
|---|---|
| `/stats/production` | Le croisement site × produit : ce qui a été pesé, ce qu'il vaut, ce qui reste dû |
| `/stats/paiements` | Ce qui est **sorti** vers les planteurs : confirmé, en vol, remboursé, par mode et par poste |
| `/stats/caisses` | Ce qui a **bougé** en caisse : entrées et sorties, par caisse, par cause, par poste |
| `/stats/a-payer` | Ce que la **file du guichet** pèse : reste, déjà versé, en vol, pesées en attente |

Une cinquième, à part, parce qu'elle ne répond pas à une question mais à toutes celles qu'on se
pose en arrivant : `/stats/tableau-de-bord`. **Tout l'écran d'accueil en UNE réponse** — sur une
connexion de terrain, enchaîner six appels le rend inutilisable. Ce qui s'y est ajouté avec les
notions récentes, et les deux pièges que ça a ouverts :

| | Ce que ça rend, et pourquoi |
|---|---|
| `totaux` | La période, décomposée par part : `montantDu`, `montantTransport`, `retenue`. Les trois existaient ; l'écran n'en montrait qu'un. |
| `creance` | **Toute la file du guichet, hors période.** Elle sort de `totauxAPayer($siteIds, [])` — la MÊME méthode que `/stats/a-payer`, appelée sans critères, et non une requête qui lui ressemble. |
| `soldes` | Les **trois chiffres d'un poste** : solde, immobilisé, et `disponible = solde − immobilisé`. L'épargne due est à côté, jamais dedans. |
| `demandes` | Les trois files de décisions — solde, mise en compte, retrait — comptées en `EN_ATTENTE`. |

**`creance` et `totaux.montantRestant` ne sont pas le même nombre**, et c'est le premier piège. Le
second s'arrête à la période affichée ; la première compte toutes les pesées non soldées, quelle
que soit leur date. Ils portaient le même nom — « reste à payer » — sur l'accueil et sur le
guichet, et personne ne pouvait savoir lequel il lisait. Ils sont désormais nommés à part :
« Reste sur la période » et « Créance totale ». C'est la seconde qui dimensionne la trésorerie.

**`disponible` n'est pas le solde, et l'écran annonçait le solde.** L'immobilisé est déjà sorti de
la caisse sans réponse de la passerelle : sur le jeu de démonstration, un opérateur voit 4 500 000
au compteur et ne peut en engager que 1 500 000. Afficher le brut, c'est l'apprendre au guichet,
devant le planteur.

> **L'épargne due ne se retranche de rien**, et c'est le piège symétrique. Au prélèvement, pas un
> billet ne bouge : l'argent reste dans le tiroir et c'est une DETTE qui naît à côté. La soustraire
> du disponible ferait disparaître deux fois des espèces qui sont bien là. Même règle que
> `CaisseOutput`, où elle se lit à côté des soldes et jamais dedans.

> `soldes.entreprise` et `soldes.epargneDue` valent **`null` pour un opérateur**, pas zéro — qui se
> lirait « l'entreprise n'a plus rien ». Et ça se filtre dans le provider, pas dans le gabarit :
> sinon la valeur voyage quand même dans le JSON, où elle se lit en deux clics. Même découpage que
> `CaisseProvider`.

**Un agrégat ne se recompose jamais depuis les pages d'une collection.** L'API plafonne une page à
`pagination_maximum_items_per_page` et tronque en silence : un total additionné côté web serait
faux dès la cent-unième ligne, sans la moindre erreur. C'est exactement ce qui était arrivé à
l'export du bilan.

**`/stats/a-payer` parle la même langue que la collection**, et ce n'est pas un détail de
confort : il lit `fournisseur`, `produit`, `site`, `numticket` et `peseeAt2[after]/[before]` —
exactement les noms des filtres de `/api/operations`. Le web envoie donc le MÊME tableau de
paramètres aux deux appels et ne peut pas filtrer la liste d'un côté et le total de l'autre. Un
total qui décrirait une autre sélection que la liste ne lève rien : il affiche un chiffre faux.

> **La règle du reste y est écrite deux fois**, en SQL et dans `Operation::getResteapayerproduit()`.
> C'est assumé et c'est borné : les `max(0, …)` par part interdisent de sortir le clamp de la
> somme — `SUM(max(0, a - b))` n'est pas `max(0, SUM(a) - SUM(b))`, et un trop-perçu sur une pesée
> épongerait la créance d'une autre. `TotauxAPayerTest` tient les deux versions d'accord sur les
> cas limites : produit sans prix, retenue supérieure au dû, tarif baissé après un versement.
> Même dispositif que `sommerLesVersementsEnVol()` et sa requête groupée.

> **Un piège DQL au passage** : `CASE WHEN … THEN a - b END` ne s'analyse pas — le parseur lit le
> premier terme et bute sur le signe moins. L'arithmétique sort du `CASE`, qui ne rend plus que 0
> ou 1 : `(a - b) * CASE WHEN a - b > 0 THEN 1 ELSE 0 END`.

**Trois familles de versements qui ne s'additionnent pas** : le `REUSSI` a changé de main,
l'`EN_ATTENTE`/`ENVOYE` a quitté la caisse sans réponse de la passerelle et peut encore revenir,
l'`ECHOUE`/`ANNULE` est revenu. Un total unique serait faux dans les deux sens à la fois. Les
retraits d'épargne sont comptés **dans** le versé, pas à côté : ce sont les versements sans pesée.

**Le bilan des caisses donne des FLUX, pas des soldes.** Les soldes du jour sont sur
`/stats/caisse` (au singulier). Un montant de mouvement est toujours positif — c'est `sens` qui
porte la direction — d'où deux colonnes partout, jamais une somme signée. Et une dotation y
apparaît **deux fois**, une par compte : elle sort de l'entreprise et entre dans le poste. Rien ne
traverse sans ces deux écritures.

> Ces providers écrivent leur DQL à la main et ne passent donc **pas** par les extensions de
> cloisonnement. Chacun applique `Perimetre::siteIds()` lui-même, et n'ouvre les écritures du
> compte de l'entreprise qu'à ceux qui ne sont pas opérateurs — même découpage que `/stats/caisse`.

### Les trois chiffres d'un poste

Un opérateur ne peut pas dépenser tout ce que son solde affiche :

- **le solde** — ce que la caisse contient ;
- **l'immobilisé** — ce qui est parti sans réponse de la passerelle (numérique seulement : des
  espèces remises en main propre ne restent jamais en vol) ;
- **l'épargne due** — ce que l'entreprise garde pour les planteurs. Elle n'est dans **aucune**
  caisse : au moment du prélèvement, pas un billet ne bouge (§7).

### Les trois nombres d'une pesée

Une pesée porte **trois** montants, et les confondre est la faute la plus coûteuse du domaine.

| | Ce que c'est | Qui le voit |
|---|---|---|
| `montantdu` | la **récolte seule** : `poidsnet x prix du produit` | le ticket du planteur, la production, le bilan |
| `montanttransport` | le **transport**, ce que son barème donne (§7), payé EN PLUS pour ceux qui livrent eux-mêmes | les écrans d'argent |
| `montantpayable` | `montantdu + montanttransport - retenue` | le guichet, et lui seul |

Et depuis qu'on règle les deux parts séparément, le payable se lit lui-même en **deux** :

```
payableproduit   = max(0, montantdu − retenue)      la récolte, moins ce qu'on lui garde
payabletransport = montanttransport                 le carburant qu'il a avancé
montantpayable   = la somme des deux, et rien d'autre
```

- `montantdu` **ne bouge jamais** : ni le transport ni la mise en compte ne le touchent.
- Le transport ne s'additionne **nulle part ailleurs** que dans le payable. L'ajouter au montant dû
  gonflerait la valeur de la récolte dans la production et le bilan, et surtout **élargirait
  l'assiette de la mise en compte** : on prélèverait l'épargne du planteur sur son propre transport.
- Ce qui a produit chaque montant est **figé** sur la pesée à côté de lui : un chiffre qu'on ne
  sait pas réexpliquer est inexplicable devant un planteur qui conteste. Pour la récolte,
  `prixunitaire` et `origineTarif` ; pour le transport, `modeTransport`, `origineTransport`,
  `trancheTransport` et, au kilo seulement, `prixtransport`. Un montant écrit à la main remet tous
  les autres à `null` et ne garde que son origine : il n'y a plus de barème à citer.
- Une pesée dont le produit n'est pas tarifé (`montantdu <= 0`) **paie quand même son transport**.
  C'était l'inverse tant qu'une pesée se réglait d'un bloc — régler le seul transport aurait laissé
  une pesée à moitié payée que plus personne ne rouvre. Ce qui l'empêche aujourd'hui de disparaître
  de la file du guichet n'est plus ce zéro, mais `StatutReglement`, qui **refuse de la dire soldée**
  tant qu'elle n'est pas tarifée, et le motif de sa part produit, qui nomme ce qu'il faut corriger.

Le **transport** ne se lit plus sur la fiche du planteur : il se résout par un barème, et les
trois façons de le facturer n'ont plus de nombre commun — §7. « Aucun barème » veut toujours dire
« on ne lui paie pas de transport », ce qui reste le cas de la plupart des planteurs.

### Un versement

Un versement porte **deux montants**, un par part : la récolte, le transport, ou les deux. Zéro sur
une part veut dire « on n'y touche pas », et c'est le cas courant. `PaiementService::initier()`
refuse d'abord ce qui ferme les deux parts — planteur suspendu, numéro manquant (mobile seulement),
pesée non terminée, poste bloqué — puis les deux montants à zéro, puis **chaque part contre sa
propre borne**.

> **Deux lignes de toute l'application décident combien d'argent peut sortir**, une par part :
> `max(0, payableproduit − engagé sur la récolte)` et `max(0, payabletransport − engagé sur le
> transport)`. Il n'y en avait qu'une tant qu'une pesée se réglait d'un bloc.
>
> On borne sur ce qui est **engagé**, pas sur ce qui est confirmé — un versement en vol a déjà
> quitté la caisse. Et sur les **payables**, pas sur le montant dû — la part mise en compte ne passe
> pas par le guichet.
>
> **Un refus sur une part fait échouer tout l'appel.** Déverser en silence sur l'autre paierait
> autre chose que ce que l'opérateur a saisi, avec le planteur devant lui.

Un `Paiement` porte donc `montant` — le TOTAL, seul chiffre que voient la caisse, la passerelle et
le grand livre — et sa ventilation en `montantproduit` + `montanttransport`. **L'invariant
`montant = montantproduit + montanttransport` vaut pour toute ligne**, retrait d'épargne compris
(qui porte tout sur la part produit), et une contrainte `CHECK` le fige. Payer les deux parts d'un
geste reste **un seul versement** : une référence, une sortie de caisse, un seul frais mobile.

Les espèces sont confirmées sur-le-champ (`confirmeSansPasserelle()`) : les billets ont changé de
main pendant la saisie, il n'y a personne à interroger. Le mobile money attend la passerelle, et la
réconciliation tranche les versements restés en vol.

---

### Le versement groupé

`POST /api/paiements/lot` règle **plusieurs pesées d'un même planteur** en un geste. Au guichet il
se présente avec trois tickets ; les régler un par un, c'est trois fois l'écran et trois fois le
risque d'en oublier un.

**Aucun montant ne se saisit**, et c'est la décision qui tient tout le reste : cocher une pesée veut
dire la régler EN ENTIER. Il n'y a donc pas de répartition à décider — ni dans l'API, ni à l'écran,
ni dans la tête de l'opérateur — et aucune façon de se tromper d'un franc. Le seul choix est la
**part** : la récolte seule, le transport seul, ou les deux.

**Un versement par pesée**, écrits sous **UNE** transaction. Les deux moitiés comptent :

- un versement pointe sur une pesée, hier comme aujourd'hui, donc tous les écrans existants
  continuent de lire ce qu'ils lisent — reçu, bilan, grand livre, réconciliation ;
- trois appels à `initier()` auraient fait trois transactions, et une caisse à sec sur la
  troisième aurait laissé les deux premières payées : l'opérateur croirait son geste refusé alors
  que l'argent est sorti. D'où `ecrireEtDebiter()`, qui **ne transacte pas**, et `conclure()`, qui
  suit la transaction. Remettre un `wrapInTransaction` dans le premier casse
  `tests/Caisse/VersementGroupeTest`, et c'est sa raison d'être.

**Un seul planteur, une seule caisse**, et le refus tombe avant d'écrire. **Pas de passerelle** non
plus : trois envois mobiles sous une transaction, dont le deuxième échoue, laisseraient un lot à
moitié parti que la réconciliation devrait démêler ligne à ligne.

**La borne est ce qui reste à ENGAGER**, versements en vol compris. Une pesée qui n'a plus rien à
engager est écartée **en silence** plutôt que de faire tomber le lot : elle a pu être réglée à
l'autre guichet pendant que l'opérateur cochait. Le compte rendu (`PaiementLotOutput`) dit donc ce
qui est **vraiment** sorti, et non ce qui avait été demandé.

### Le justificatif d'une ligne

Une ligne d'argent peut porter **une** pièce : le reçu signé du planteur, le bordereau d'une
recharge bancaire, le talon d'un chèque, le document qui motive une demande. Cinq cibles possibles
— versement, mouvement de caisse, et les trois demandes — et c'est la **pièce qui porte la clé**.
Mettre la clé sur les cibles aurait ajouté une colonne à `paiement` et à `mouvement_caisse`, deux
tables d'argent qu'on ne touche pas pour un besoin d'affichage.

**Le fichier n'est pas en base.** Il vit dans `public/documents`, **interdit au serveur web** par
son `.htaccess` — versionné, lui — et sous un nom de 128 bits tirés au sort. Aucune URL n'y mène :
il ne se lit que par `GET /api/piece_jointes/{id}/fichier`. Même dispositif qu'iTransport, et
volontairement : les deux applications se déploient au même endroit, et un développeur qui passe de
l'une à l'autre retrouve le même dossier plutôt que deux conventions à se rappeler.

> ⚠ **Le `.htaccess` ne vaut que sous Apache**, avec `AllowOverride` — ce que `symfony/apache-pack`
> suppose déjà. Le serveur de développement et Nginx l'ignorent. D'où la **seconde barrière**,
> celle qui ne dépend d'aucune configuration : le nom aléatoire, jamais exposé. Sous Nginx,
> ajouter `location ^~ /documents/ { deny all; }`.
>
> **Pour vérifier que ça tient** : ouvrir `/documents/sonde.txt` dans un navigateur. Un 403 est la
> bonne réponse ; si le texte s'affiche, le dossier n'est pas protégé.

**Qui voit la ligne voit sa pièce, et ce n'est pas une formule** : le contrôle de lecture est
littéralement `is_granted('VOIR', $cible)`. Pas de droit sur la pièce, pas de seconde table de
visibilité — celle qui existe suffit, et deux auraient divergé. L'écriture, elle, a son action :
**`JOINDRE`**, parce que voir un versement et pouvoir y coller un document ne sont pas la même
permission. L'agent, qui supervise, lit les justificatifs et n'en pose aucun.

> **Charger la cible par son identifiant contourne le cloisonnement**, et c'est le piège de ce
> mécanisme : les extensions Doctrine ne s'appliquent qu'aux requêtes d'API Platform. Sans le
> `is_granted` du processor, changer un chiffre dans le formulaire attachait un document au
> versement d'une autre entreprise.

**Exactement une cible par pièce**, et c'est la base qui le tient — une contrainte `CHECK` sur la
somme des cinq colonnes. Zéro cible ferait une pièce orpheline que le contrôle d'accès ne saurait
pas juger, faute de ligne à interroger ; deux en feraient une pièce que deux lignes revendiquent,
où la plus permissive déciderait. Les deux arrivent par un simple oubli de code, et aucun ne lève.

> Cette contrainte vit dans la **migration**, pas dans le mapping. Le schéma de test étant
> construit depuis le mapping, elle n'est pas exercée par la suite : elle a été vérifiée à la main
> sur la base de développement.

**Un second dépôt remplace le premier**, dans la même ligne : on se trompe de scan, on recommence,
et Vich efface le fichier précédent. Créer une seconde pièce après avoir effacé l'ancienne aurait
laissé le fichier orphelin sur le disque — et les index uniques l'auraient refusée de toute façon.

PDF, JPEG, PNG et WEBP, **5 Mo au plus**. Le PDF d'abord parce qu'un bordereau arrive numérisé, les
images parce qu'au guichet on photographie un reçu avec le téléphone qu'on a.

**Recharger et doter rendent l'écriture, pas le solde.** C'est la seule chose que les pièces
jointes ont fait bouger dans une opération existante. Les deux gestes répondaient par l'`Entreprise`
ou le `Site` — un solde, qui se relit à tout moment — et rien ne nommait la ligne de grand livre
qui venait de naître : le web n'avait donc aucune cible pour son bordereau. Elles rendent désormais
un `EcritureOutput` (`id`, `contrepartieId`, `caisse`, `montant`, `soldeApres`). Aucune règle
d'argent n'a été rouverte : `CaisseService` renvoyait déjà ses mouvements, personne ne les lisait.

**Un versement groupé rend aussi ses `ids`.** `PaiementLotOutput` ne portait que les `references`,
qui se recopient sur un reçu quand un planteur conteste — jamais de quoi désigner les lignes dans
l'API. Le planteur signe pourtant UNE décharge pour ses N pesées, et le web doit la poser sur
chacune : le modèle tient une pièce par ligne d'argent sans exception. Ce sont les identifiants des
versements **réellement écrits**, pas de ceux qui avaient été demandés — une pesée réglée à l'autre
guichet pendant qu'on cochait est écartée en silence par le service.

> **Une dotation en écrit deux, et le bordereau va sur celle du POSTE.** `id` porte l'entrée,
> `contrepartieId` la sortie de l'entreprise. L'opérateur lit les écritures de sa caisse et jamais
> celles de l'entreprise : accrocher la décharge à la sortie l'aurait rendue invisible à celui-là
> même qui l'a signée. L'administrateur voit les deux de toute façon — c'est donc le sens le plus
> large sans rien ouvrir à qui n'y a pas droit.

---

## 6. Les droits

Une table, un voter, **zéro recopie**.

```
src/Security/Droits.php   rôle × ressource × actions   ← LA source de vérité
src/Security/Voter/AccesVoter.php   tranche chaque appel
src/Security/Perimetre.php          quel client, quels postes
src/Security/UserGuard.php          qui peut agir sur QUEL compte
```

- `is_granted('VOIR', 'Site')` → collection ou création, le sujet est le nom court.
- `is_granted('VOIR', $site)` → élément, le sujet est l'entité.

**Le frontend ne décide rien.** `/api/me` renvoie les droits **à plat** (`SITE_VOIR`,
`MOUVEMENTCAISSE_VOIR`…) et son voter d'affichage se contente d'un `in_array`. De même,
`/api/users` porte sur chaque ligne `droitsSurCeCompte` et `motifNonGerable` : la table et la
hiérarchie déjà croisées. Avant, le web tenait trois copies de ces règles — elles avaient commencé
à diverger.

Ajouter une ressource : une ligne dans `Droits::TABLE`, rien d'autre. Le voter est générique et
`/me` publiera les nouvelles chaînes tout seul.

---

## 7. La mise en compte (l'épargne)

Un planteur demande qu'on lui retienne **tant par kilo** sur chaque pesée. La part retenue va sur un
compte à son nom — une dette de l'entreprise envers lui.

```
Pesée de 500 kg à 200 F/kg = 100 000 F, taux 20 F/kg

à la pesée    │ compte : 0 → 10 000      │ caisse : NE BOUGE PAS
au guichet    │ compte : 10 000          │ caisse : − 90 000
au retrait    │ compte : 10 000 → 0      │ caisse : − 10 000
```

- `montantdu` **ne bouge jamais** : c'est ce que vaut la récolte, et c'est ce qu'imprime le ticket.
  Ce qui baisse est `montantpayable = montantdu + montanttransport − retenue`.
- La retenue est **plafonnée au montant dû**, donc à la **récolte seule** : un prélèvement ne crée
  jamais de dette, et il ne mord pas sur le transport — le planteur a avancé le carburant.

### Le prix se RÉSOUT, il ne se lit pas

Un prix n'est plus un nombre posé sur le produit. Il se résout en **trois sauts**, du plus précis au
plus général, et le premier qui répond l'emporte :

```
TarifFournisseur   le tarif négocié avec CE planteur
  ?? TarifCategorie   le tarif de sa catégorie pour ce produit
     ?? Produit::$prix   le prix du produit, celui de tout le monde
```

`TarifApplicable::pour()` porte la règle, et c'est le **seul** endroit où elle est écrite. Elle est
statique et **ne requête rien** : elle lit le graphe déjà chargé, ce qui permet de l'appeler dans la
boucle de synchronisation, qui traite des lots de deux cents pesées.

**Chaque saut est un PRIX COMPLET, jamais un écart.** « Le cacao vaut 500 F pour cette catégorie »,
et non « +30 F » : un écart oblige à relire le prix de base pour savoir ce qu'on paie, et il se
périme en silence le jour où ce prix bouge.

**Une catégorie ne tarife pas tout, et c'est voulu.** Les cultures qu'elle laisse vides retombent sur
le prix du produit. L'absence de ligne est la seule façon d'exprimer « rien de particulier » — d'où
l'interdiction d'un tarif à zéro, qui serait lu comme un prix et mettrait ces pesées à néant.

La pesée fige `origineTarif` à côté de `prixunitaire`. Sans elle, deux pesées à 470 F sont
indiscernables et « pourquoi mon voisin touche 500 » n'a **aucune réponse dans les données**. Elle
entre aussi dans la détection de changement de `calculerMontant()` : un tarif de planteur supprimé au
profit d'un tarif de catégorie identique ne change aucun chiffre, mais change le pourquoi.

### La prime : un quatrième chemin, qui n'est pas un saut

Un prix au kilo peut être écrit à la main sur **une** pesée : `PATCH /api/operations/{id}/prix-unitaire`.
C'est la prime — une récolte particulièrement propre, un planteur qu'on veut retenir. Elle ne se range
dans aucun référentiel par nature : sur le produit elle irait à tout le monde, sur le planteur à toutes
ses livraisons à venir.

`origineTarif` passe à `MANUEL`, et ce n'est pas décoratif : c'est lui qui **ferme la part récolte** dans
`calculerMontant()`, au même titre qu'un versement. Sans ce gel, la prochaine tarification du produit
balaierait la prime en silence et le planteur toucherait autre chose que ce qu'on lui a annoncé.

**La fermeture est au PREMIER VERSEMENT sur la récolte**, pas à l'arbitrage. `Operation::isPrixModifiable()`
porte la règle et sort dans la charge utile : l'écran la lit pour afficher le formulaire, le processor la
revérifie. Les versements **en vol** comptent comme versés. Le transport déjà payé, lui, ne ferme rien —
c'est le cas même qu'on veut pouvoir tarifer ensuite : un planteur payé de son trajet sur une pesée dont
le produit n'était pas tarifé.

**L'arbitrage de la mise en compte reste définitif, et la hausse va au guichet.** Une pesée porte
**une** ligne d'épargne — `mouvement_epargne.operation_id` est `UNIQUE`, première des quatre barrières
contre le double crédit. La prime ne rouvre donc pas la part mise de côté : elle grossit le
**payable**, et le planteur reçoit la différence en main propre. Le seul refus est le cas inverse —
un prix qui mettrait la récolte **sous** ce qui est déjà mis en compte : reprendre la différence
serait un retrait, qui passe par la demande du planteur et l'accord d'un administrateur. Une pesée
jamais arbitrée (produit non tarifé) est, elle, arbitrée normalement une fois le prix écrit.

À l'administrateur seul (`Operation => MODIFIER`). L'opérateur a le planteur devant lui quand celui-ci
réclame sa prime : lui ouvrir ce champ ferait négocier le prix à la barrière. `PrixDeLaPeseeTest` tient
les dix cas.

### Le transport se résout aussi, en DEUX sauts

```
Fournisseur::$baremeTransport        le barème négocié avec CE planteur
  ?? CategorieFournisseur::$baremeTransport    celui de sa catégorie
     ?? aucun                              on ne lui paie pas de transport
```

`TransportApplicable::pour()` porte la règle, comme `TarifApplicable` porte celle du prix :
statique, sans requête, appelable dans la boucle de synchronisation.

Un barème facture de **deux façons** :

| `ModeTransport` | Ce qu'il donne | Ce qu'il exige |
|---|---|---|
| `AU_KILO` | `prixAuKilo x poidsnet` | un prix au kilo |
| `FORFAIT` | `montant`, quel que soit le tonnage | un montant |

> Le prix au kilo était la seule façon de payer, et il payait mal : le camion d'un planteur qui
> apporte deux cents kilos a fait la même route que celui qui en apporte dix tonnes, et touchait
> cinquante fois moins. Les anciennes valeurs sont devenues des barèmes `AU_KILO`, un par prix
> distinct et par entreprise : aucun montant n'a bougé à la reprise.

**Il y avait un troisième mode, `TRANCHES`, et son retrait est la correction la plus lourde de
cette étape.** Il tenait une suite d'intervalles dans une entité `Tranche`, et allait chercher
`Fournisseur::$distanceKm` pour choisir le bon. Personne ne saisissait cette distance — le poste
crée les planteurs avec un libellé et rien d'autre — donc le barème ne savait pas répondre, le
transport valait zéro, et ce zéro se figeait puis s'arbitrait. Tout un appareil s'était construit
autour de ce silence : une validation de collection contre quatre façons de produire un montant
faux (trou, chevauchement, aucune tranche ouverte, départ ailleurs qu'à zéro), un refus au
rattachement, un `motifTransportIncalculable` sur la pesée, un `motifTransportIndisponible` sur le
planteur. **Rien de tout cela n'existe plus.**

**Une tranche est désormais un barème.** « De 10 à 20 km : 1 000 F » est un `FORFAIT` de 1 000 F
qui porte `kmMin` et `kmMax` ; on le rattache au planteur comme n'importe quel autre tarif, et il
n'y a plus rien à aller chercher ailleurs. **Les bornes ne calculent rien** : elles nomment le
barème et le classent dans la liste — sans elles, trois forfaits se ressemblent. Les deux sont
nullables, et les quatre combinaisons ont un sens (aucune, les deux, le départ seul « au-delà de
50 km », la fin seule « moins de 10 km »). `tests/Transport/BaremesTest` tient qu'elles ne
changent aucun montant : si quelqu'un les rebranche un jour sur le calcul, ce cas tombe.

La migration `Version20261123084942` fait la conversion plutôt que la suppression : **chaque
intervalle devient un barème**, et **chaque planteur est repointé sur celui qui contenait sa
distance** — ce qu'il touchait hier, il le touche encore. Une catégorie, qui n'a pas de distance,
suit le premier intervalle, et c'est le seul endroit où un montant peut avoir bougé. Les pesées
déjà valorisées ont leur `mode_transport` converti en `FORFAIT` : un mode resté en base que
l'énumération ne connaît plus ne dort pas, il lève à la première lecture et emporte tout l'écran.

Côté planteur, `getTransportApplique()` et le filtre `sansTransport` répondent à « qui ne touche
rien ? » en suivant les deux sauts : `baremeTransport IS NULL` seul aurait rendu tous les planteurs
qu'une catégorie règle déjà.

La pesée fige **le libellé** des bornes retenues, pas une clé étrangère : rééditer le barème ne
doit pas rendre un intervalle qui n'a jamais servi à ce montant-là.

### Le transport écrit à la main

Le montant du transport d'**une** pesée s'écrit aussi : `PATCH /api/operations/{id}/montant-transport`.
Un détour imprévu, une route coupée, un second voyage — des choses qu'aucun barème ne saurait
porter sans les donner à tous ceux qui le suivent. C'est le pendant exact de la prime sur l'autre
part, et ce qui les sépare mérite d'être lu avant d'y toucher.

**Un MONTANT, et non un prix au kilo.** C'est le seul chiffre qui ait un sens dans les deux modes :
un forfait n'a pas de prix unitaire. `prixtransport`, `modeTransport` et `trancheTransport`
repassent donc à `null` — les laisser ferait mentir toute multiplication, et nommerait un barème
qui n'a pas décidé ce montant. `getExplicationTransport()` répond alors « Montant saisi sur cette
pesée », parce qu'il n'y a plus de barème à citer.

**Zéro est une valeur, pas une absence.** « Finalement on ne lui paie pas ce trajet » est une
décision, et `OrigineTransport::MANUEL` la distingue d'un planteur sans barème — c'est la même
raison qui fait exister `AUCUN` à côté d'un forfait à zéro. Comme `OrigineTarif::MANUEL`, elle
**ferme la part transport** dans `calculerMontant()` : sans ce gel, la prochaine retouche du barème
effacerait le montant écrit à la main sans que personne ne le voie partir.

**Deux routes et non un appel à deux champs**, parce que les deux parts se ferment
indépendamment : un seul appel refuserait le transport d'une pesée dont la récolte est déjà réglée,
qui est le cas courant depuis qu'on paie les deux séparément. `isTransportModifiable()` porte la
règle — jusqu'au premier versement sur le TRANSPORT, en vol compris, et la récolte n'y entre pas.

Et il est **plus simple que la prime d'une chose** : la mise en compte ne mord jamais sur le
transport, que le planteur a avancé de sa poche. Il n'y a donc pas d'épargne à consulter, aucun
arbitrage à rouvrir, et aucun refus possible de ce côté-là. À l'administrateur seul
(`Operation => MODIFIER`), avec un motif facultatif qui part au journal : il se relit le jour où un
autre planteur demandera pourquoi son voisin a touché plus.

### Où se LISENT les grilles

| | Ce que ça rend |
|---|---|
| `GET /categorie_fournisseurs` | Chaque catégorie **avec sa grille** : elles se comptent sur les doigts d'une main |
| `GET /fournisseurs/tarifes?avecTarifPropre=true` | Les planteurs tarifés à part, avec leurs lignes |
| `GET /fournisseurs/{id}/tarifs` | La grille RÉSOLUE d'un planteur : le prix, son origine, et le repli |

**Pourquoi une opération à part pour les planteurs** et non un champ de plus sur `/fournisseurs` :
la grille se lit à travers une relation, donc la publier sur la collection ordinaire ferait une
requête par ligne sur **toutes** les listes de planteurs — listes déroulantes de filtres comprises,
qui les chargent tous. Les catégories n'ont pas ce problème : il y en a cinq.

**`avecTarifPropre` et non `exists[tarifs]`** : le filtre natif ne regarde que l'existence d'une
ligne, pas son état. Un planteur dont le seul tarif a été RETIRÉ y répondrait encore oui, la liste
se remplirait de planteurs sans aucun tarif en vigueur, et on cesserait de la croire.
`App\Filter\AvecTarifPropreFilter` fait un `EXISTS` sur les lignes vivantes — un `EXISTS` et non
une jointure, qui rendrait le planteur autant de fois qu'il a de tarifs.

### Ce qu'un changement de tarif déclenche, et pourquoi c'est sans retour

Tarifer revalorise les pesées **non payées** et **non arbitrées**. Une pesée qui acquiert ainsi une
valeur est aussitôt arbitrée : sa retenue d'épargne est figée, et `figerLaRetenue()` lève au second
appel. **Il n'existe aucun chemin d'annulation.**

D'où la saisie **groupée** de la grille, et son **aperçu** : `POST …/tarifs` sans `confirmer`
recalcule tout et rend le bilan — créés, modifiés, inchangés, retirés — **plus le nombre de pesées
qui seraient revalorisées**, sans écrire une ligne. L'écran l'annonce, et ne rappelle qu'après un
oui.

Trois règles de cette saisie méritent d'être lues avant d'y toucher :

| Règle | Ce qu'elle évite |
|---|---|
| Un renvoi **à l'identique** compte comme « inchangé » et ne déclenche **rien** | L'écran renvoie la grille entière ; compter les cases intactes relancerait la revalorisation de toute la catégorie à chaque enregistrement, et chaque passe peut arbitrer. |
| Le même produit **deux fois** dans un lot est refusé **avant** la première écriture | La seconde case écraserait la première sans qu'on sache laquelle a gagné. |
| La sélection se resserre **sur le produit touché** | Sans cela, un tarif sur le cacao revaloriserait aussi l'hévéa et la palme de tous les planteurs de la catégorie. |

**Le garde-fou de la revalorisation suit la PART, pas le type de la cause.** Il se choisissait sur
`$cause instanceof Produit` : un `Fournisseur` gardait le transport. C'était vrai tant qu'un planteur
ne portait qu'un prix de transport ; il touche aujourd'hui la **récolte** par son tarif propre et par
sa catégorie. L'appelant passe donc un `PartPesee` — il est le seul à savoir ce qu'il change.

**`TauxPrelevement` se borne sur le prix APPLICABLE AU PLANTEUR**, pas sur celui du produit. Avec le
prix du produit : un planteur mieux payé se voyait refuser un taux légitime, et un planteur moins
bien payé se voyait **accepter** un taux qui absorbait toute sa récolte. Le second est le cas grave,
et il était silencieux.

### Un prélèvement PAR PRODUIT

Un planteur n'a pas un taux, il a **une ligne `Prelevement` par culture** : 20 F par kilo de cacao,
50 F par kilo d'hévéa. Chacune s'ouvre, se change et s'arrête de son côté, et une pesée n'est
prélevée que par celle de **son** produit (`Fournisseur::prelevementPour()`).

```
Prelevement : fournisseur, produit, taux, arreteAt, arretePar
```

- **Le taux est strictement INFÉRIEUR au prix du kilo du produit.** Égal, le planteur repartirait de
  chaque livraison avec exactement zéro. La règle vit dans `TauxPrelevement::motifDuRefus()` et
  n'est écrite nulle part ailleurs ; elle est vérifiée **quatre fois** — dépôt, correction,
  **approbation** et ouverture directe. L'approbation n'est pas une redondance : la demande a pu
  être déposée quand le cacao valait 100 F et n'être tranchée qu'après une baisse à 40.
- Un produit **non tarifé** n'accepte aucun prélèvement : ses pesées ne valent rien, le robinet ne
  mettrait jamais un franc de côté.
- **Un seul compte d'épargne par planteur**, pas un par culture : c'est de l'argent qu'on lui doit,
  et le découper par produit n'aurait aucun sens au moment de le lui rendre. Seul le taux est par
  produit.
- **Arrêter ne supprime pas la ligne, ça la date.** Changer un taux arrête l'ancien et en ouvre un
  nouveau : sans cela la fiche prétendrait qu'il a toujours épargné à 60 F alors que les pesées de
  mars portent 20 F figés, et plus rien n'expliquerait l'écart devant un planteur qui compte.
- Pas d'index UNIQUE sur `(fournisseur, produit)` : la table garde les arrêtés. L'unicité porte sur
  les seuls **actifs**, ce qu'un index ne sait pas dire — les processors la tiennent, comme un
  validateur tient l'unicité des libellés en ignorant la corbeille.
- `prelevementPour()` compare les produits **par identité d'objet d'abord**, par identifiant
  ensuite. Deux `getId()` valant `null` avant le `flush()` se ressemblent, et le premier prélèvement
  de la liste répondrait alors pour toutes les cultures — les fixtures l'ont montré, une pesée de
  palme repartait avec le taux de l'hévéa.
- Le livre d'épargne (`MouvementEpargne`) est **séparé** du grand livre des caisses, parce qu'un
  prélèvement ne déplace aucun argent. L'y mêler romprait le chaînage des soldes de caisse.

### L'arbitrage : une fois, et toujours

Quand une pesée devient arbitrable — terminée, valorisée, avec un planteur — `EpargneService` écrit
**toujours** une valeur : le prélèvement s'il y en a un, **zéro sinon**. Une pesée arbitrée à zéro
ne sera jamais reprise.

C'est ce qui implémente « seulement les pesées à venir » **sans une seule comparaison de dates**,
et c'est pourquoi la migration pose `retenue = 0` sur tout l'existant. `retenue` est *nullable* à
dessein : `null` = « jamais arbitrée », `0` = « arbitrée à taux nul ».

### Quatre barrières contre le double crédit

1. **`UNIQUE` sur `mouvement_epargne.operation_id`** — la seule qui survive à deux requêtes
   simultanées, et le poste rejoue ses lots.
2. Le verrou `PESSIMISTIC_WRITE` sur le compte.
3. `Operation::figerLaRetenue()` qui **lève** au second appel.
4. `attendUnArbitrage()` qui filtre en amont.

`findRecalculables()` et `findARevaloriser()` excluent les pesées arbitrées : une baisse de prix ne
doit pas mettre le payable à zéro sur une pesée dont le planteur a déjà la promesse — et il n'existe
aucun retrait pour défaire.

**Un seul chemin de retarification**, celui du web : `TarificationProcessor` appelle
`findARevaloriser()`, qui recalcule les pesées de la cause donnée — un produit, un planteur, une
catégorie ou un barème de transport — puis ouvre leur mise en compte dans la foulée. Il a longtemps manqué : l'écran qu'on ouvre précisément pour
tarifer un produit créé à la volée ne faisait que journaliser, et ses pesées restaient à zéro.

> Il y en avait deux. `findRecalculables()` balayait tout un poste quand un prix bougeait côté
> logiciel de pesée ; elle est partie avec l'arbitrage des prix (§4). La portée de celle qui reste
> est étroite exprès : tarifer un produit ne touche que **ses** pesées.

La branche du barème redit la règle de résolution en DQL, et son `baremeTransport IS NULL` n'est
pas décoratif : sans lui, changer le barème d'une catégorie revaloriserait aussi les pesées de ses
planteurs qui en ont un à eux, et que ce changement ne concerne pas.

### Qui ouvre

L'**administrateur** ouvre et arrête (`PATCH /api/fournisseurs/{id}/epargne`, droit `PRELEVER`),
en précisant **le produit** dans les deux sens — « arrêter la mise en compte de Kouassi » ne veut
rien dire sans dire laquelle.

L'**opérateur** dépose une demande (`DemandePrelevement`), qui vise elle aussi un produit, approuvée
**au taux demandé** : le chiffre vient du planteur, le retoucher depuis un bureau reviendrait à
décider de l'épargne d'autrui. Une seule demande en attente **par planteur et par produit** : le
cacao et l'hévéa ne se gênent pas.

Une ouverture n'écrit rien au grand livre — d'où la ligne au **journal des activités**, sans quoi
décider qu'un homme touchera moins au guichet ne laisserait aucune trace.

### Le retrait : un versement sans pesée

Rendre son épargne à un planteur passe par le **versement existant**, `Paiement.operation` étant
devenu nullable. Le retrait hérite ainsi sans une ligne de plus : la référence, les trois modes de
règlement, la passerelle mobile, l'état « en vol », le remboursement automatique sur échec, la
réconciliation et l'écran de guichet. `PaiementService::initier()` et `initierRetrait()` ne
diffèrent que par **la borne**, puis partagent `executer()` :

```
initier(pesée, …)        borne = montantpayable − déjà engagé sur la pesée   ┐
                                                                            ├→ executer(…)
initierRetrait(…)        borne = le solde d'épargne                        ┘
```

**Les deux livres marchent au pas cadencé**, et c'est ce qui rend le double retrait impossible :

| | caisse | épargne |
|---|---|---|
| à l'initiation | débit (réservation) | **débit** (`RETRAIT`) |
| sur échec | `REMBOURSEMENT` | **`ANNULATION_RETRAIT`** |
| sur succès | rien de plus | rien de plus |

Les deux écritures tiennent dans **la même transaction** : l'épargne est débitée juste avant la
caisse, sous le même `wrapInTransaction`. Débiter l'épargne seulement à la confirmation ouvrirait
une fenêtre pendant laquelle le solde est encore entier alors que l'argent est parti : deux
retraits du même solde partiraient en vol ensemble. C'est aussi pourquoi la borne n'a **rien à
soustraire** — un retrait en vol a déjà quitté le solde, il n'y a pas d'équivalent de
`sommeEngagee()` à écrire.

### Qui décide d'un retrait

L'opérateur **demande** (`DemandeRetrait`), l'administrateur **accorde**, l'opérateur **verse**.
Trois temps et non deux : contrairement à la demande de solde, qui dote la caisse sur-le-champ,
l'administrateur n'a ni le planteur devant lui, ni son numéro, ni le choix du mode. Il autorise un
montant — qui peut être **inférieur** au demandé, jamais supérieur — et **l'accord n'écrit aucun
mouvement**. L'argent ne bouge qu'au guichet, et les deux soldes y baissent ensemble.

Une seule demande en attente par planteur, et aucune nouvelle tant qu'un accord n'a pas été servi :
sans cette seconde règle, deux accords dormants videraient le compte deux fois.

---

## 8. Les invariants

Si l'un d'eux saute, c'est de l'argent qui se perd ou qui se crée.

1. **Un solde ne se modifie que par son service** — `CaisseService` pour les caisses,
   `EpargneService` pour l'épargne. Jamais un `setSolde…()` dans un processor.
2. **Toute écriture chaîne** : `soldeApres` d'une ligne = `soldeAvant` de la suivante, par compte
   **et par caisse**.
3. **Aucun ajustement libre** : tout mouvement de caisse va d'un compte à un autre.
4. **Une pesée s'arbitre une fois**, et l'index unique le garantit.
5. **Un versement est borné par les payables**, jamais par le montant dû — et par part : celui de
   la récolte, celui du transport, chacun contre ce qui est déjà engagé sur lui.
6. **Un trop-perçu ne devient pas une dette du planteur** : `max(0, …)` partout.
7. **Tout classement se départage par un identifiant.** Deux lignes ex æquo sortent sinon dans un
   ordre que le plan d'exécution décide, et il change d'un appel à l'autre : sur une liste coupée
   par `setMaxResults`, l'une entre et l'autre disparaît, sans que rien ne l'explique.
8. **Le cloisonnement passe par les interfaces** `EntrepriseOwnedInterface` / `SiteOwnedInterface`,
   jamais par un `where` écrit à la main. `Produit` et `CategorieFournisseur` portent la première,
   `Fournisseur` la seconde — c'est là, et nulle part ailleurs, que se lit le niveau d'un
   référentiel.
9. **Le frontend ne recopie aucune règle.**

---

## 9. Les pièges qui coûtent une journée

| Piège | Ce qui se passe |
|---|---|
| **`SearchFilter` sur un booléen** | Il compare des **chaînes** à ce que porte la colonne. Sur un `bool`, seul `=1` répond ; `=true` et `=false` — ce que les écrans envoient — ne correspondent à rien, et la liste se **vide sur les deux choix** sans la moindre erreur. `BooleanFilter` est fait pour ça. Le cas vécu : le filtre « Actif / Désactivé » des produits. |
| **Un champ de recherche, plusieurs colonnes** | Un `SearchFilter` par colonne donne un paramètre par colonne, jamais un OU. Les écrans qui annoncent « Nom **ou code** » n'en interrogeaient qu'une, et le code ne trouvait jamais rien. Le paramètre `recherche` (`QueryParameter` + `FreeTextQueryFilter` + `OrFilter` + `PartialSearchFilter`) les met en OU : voir `Produit`, `Fournisseur`, `Site`. |
| **Un filtre qui ne filtre pas ne lève rien** | Il rend une liste vide, ou entière, et l'écran l'affiche. Le contrôle qui les débusque tous tient en une comparaison : total filtré contre total nu, valeur par valeur — toujours zéro, ou toujours le total, et c'est cassé. `tests/Api/FiltresDeListeTest` fige les deux cas rencontrés. |
| **Une collection sérialisée porte les lignes RETIRÉES** | Une suppression logique laisse la ligne en base — c'est voulu, savoir qui a retiré le tarif hévéa d'une catégorie et quand est la question qu'on posera le jour où un planteur verra sa paie baisser. Mais la VALORISATION l'écarte (`tarifPour()` teste `deletedAt`), alors que `#[Groups]` posé sur la collection la publiait entière : on retirait un tarif de catégorie, l'écran de saisie continuait de l'afficher, et les planteurs étaient payés au prix du produit. Deux lectures du même état qui se contredisent, sans une erreur. Le groupe va sur un getteur qui FILTRE, avec `#[SerializedName]` pour garder le nom de la clé — `getTarifsVivants()` sur `CategorieFournisseur` ET sur `Fournisseur`, qui portait le même défaut en silence. À vérifier sur toute collection `OneToMany` d'entités à suppression logique. |
| **Cache du sérialiseur** | Un getter `#[Groups]` tout neuf sort **absent du JSON**, sans la moindre erreur. `cache:clear` ne suffit pas toujours : supprimer `var/cache/dev` à la main. Pareil pour `--env=test` avant PHPUnit. |
| **Relation sérialisée vide** | Une relation ne s'embarque que si une propriété de la cible porte le groupe de lecture du **parent** (`read:DemandePrelevement` sur `Fournisseur::getNomComplet()`). Sinon on n'obtient que l'IRI, l'écran affiche un tiret, et rien ne lève. **À faire pour CHAQUE champ qu'un écran lit à travers la relation**, pas seulement le nom : `DemandeRetrait` a montré les deux moitiés du piège — la colonne Planteur vide, et, plus discret, le formulaire de versement qui se pré-remplissait à blanc faute de `contact1` et `reseau`. Les formes partagées côté web (`SiteBref`, `UserBref`) fixent le minimum : `id`, `code`, `libellesite`, `nomComplet`. Deux autres dormaient sur `MouvementCaisse` et `Paiement` : `initiePar` ne rendait que son IRI et un `createdAt` égaré, `demandeSolde` pas même un identifiant. Le contrôle est mécanique et côté web : comparer les clés SERVIES par une page aux champs déclarés dans `assets/react/models/`. |
| **Un `#[Groups]` sans préfixe `get`** | Les groupes posés sur une méthode qui n'est ni `get…`, ni `is…`, ni `has…` sont **ignorés en silence**. `estActif()` serait absent du JSON, et l'écran afficherait tout comme arrêté : d'où `Prelevement::getActif()` à côté de lui. |
| **`Operation` ambigu** | `ApiPlatform\OpenApi\Model\Operation` masque l'entité. Aliaser : `use App\Entity\Operation as Pesee;`. |
| **`??` ne protège pas un appel** | `$a[$k]?->m() ?? 0` lève quand même « Undefined array key » : `??` n'étend ses semblants d'`isset` qu'aux chaînes de propriétés. Lire la case à part. |
| **`$processor` est réservé** | `services.yaml` lie ce **nom d'argument** à la persistance native. Un processor qui décore autre chose doit nommer son argument autrement (`$audit`). |
| **`deletedAt` dans un processor** | Les extensions Doctrine ne s'appliquent qu'aux requêtes d'API Platform. Dans un repository appelé par un processor, filtrer `deletedAt IS NULL` à la main. |
| **Une garde de purge qui ne compte qu'une table** | La suppression définitive d'un planteur ne regardait que ses PESÉES. Sept autres tables le référencent en `NO ACTION` — tarif propre, mise en compte, demandes, versements, compte d'épargne — et un planteur sans pesée mais avec l'une d'elles passait la garde : c'est la CLÉ ÉTRANGÈRE qui refusait, en 500 brut, sans un mot sur ce qu'il fallait faire. Même défaut sur un produit (ses tarifs, ses prélèvements) et sur une catégorie (sa grille). `motifDesReferences()` les énumère toutes et nomme la première trouvée. Le contrôle est mécanique : `information_schema.KEY_COLUMN_USAGE` donne la liste exacte de ce qui retient une ligne. |
| **Un cas d'énumération retiré ne dort pas en base** | Retirer `TRANCHES` de `ModeTransport` et convertir les barèmes ne suffit pas : les PESÉES avaient figé ce mode à côté de leur montant. À la première lecture, Doctrine lève « is not a valid backing value » et c'est TOUTE la file du guichet qui tombe en 502 — pas seulement les lignes concernées. Toute migration qui retire un cas doit balayer **chaque colonne** qui porte cette énumération, y compris les copies figées sur les pesées. |
| **Un journal qui part dans `php://stderr`** | C'est ce que pose la recette de Monolog, et sur un hébergement mutualisé cela veut dire le journal d'erreurs d'Apache : la trace est produite, et personne ne peut l'ouvrir. L'API a tenu plus longtemps encore **sans Monolog du tout**, alors que dix appels au logger étaient déjà écrits dans le code — passerelle injoignable, réconciliation impossible, échec de synchronisation d'un poste — et tombaient dans le même trou par le logger de repli de Symfony. Ces pannes-là ne lèvent rien côté écran : le journal est le seul endroit où elles existent. Voir §12. |
| **Fins de ligne** | Le dépôt est en **LF**. Un éditeur qui réécrit en CRLF produit un diff de fichier entier. |
| **L'adresse du compte machine ne suit pas le code** | Elle est dérivée du code **à la création** puis figée : un poste renommé garde la sienne, et son ancien code reste occupé. Recomposer l'adresse depuis le code — à l'affichage ou au renouvellement — rendait un identifiant faux, ou tuait la requête sur l'unicité de `user.email` (500 à la création d'un site **et** au renouvellement). Toujours lire `identifiantDe()`, jamais `identifiant()`. |

---

## 10. Où se trouve quoi

```
src/
  Controller/            Seulement ce qu'API Platform ne couvre pas (synchronisation, sécurité)
  DataFixtures/          Le jeu de démonstration : un client complet + un voisin, pour le cloisonnement
  Doctrine/              PerimetreExtension (cloisonnement), UserExtension (comptes machine)
  Domain/
    Enum/                Les vocabulaires fermés. Chaque enum porte SA règle (TypeMouvement::sens()…)
    Support/             Les règles de RÉSOLUTION, statiques et sans requête : TarifApplicable
                         (le prix), TransportApplicable (le transport), TauxPrelevement
    Service/
      Caisse/            CaisseService, PaiementService, réconciliation
      Epargne/           EpargneService
      SynchronisationService.php
  Entity/                Les entités. Les règles qui ne dépendent que de l'objet vivent ICI
    Input/ Output/       Les DTO des opérations personnalisées
  Filter/                Les filtres d'API qu'aucun filtre livré n'exprime (SansTransportFilter)
  Repository/            Les requêtes. Les requêtes GROUPÉES y vivent aussi (éviter les N+1)
  Security/              Droits, Perimetre, UserGuard, AccesVoter
  Upload/                NomAleatoireNamer : le nom sur disque d'une pièce jointe, 128 bits au sort
  Validator/             UniqueParPerimetre et ses trois déclinaisons (site, entreprise, périmètre)
  State/
    Processor/           Ce qui écrit : validation métier + effet
    Provider/            Ce qui lit : enrichissement groupé d'une page
```

**La règle de placement :** ce qui ne dépend que d'une entité vit sur l'entité
(`getMotifNonPayable()`) ; ce qui a besoin d'un verrou, d'une transaction ou de plusieurs entités
vit dans un service ; ce qui n'est qu'une question de droit vit dans la table.

---

## 11. Les tests, et ce qu'ils gardent

```
tests/Caisse/GrandLivreTest        le chaînage des soldes, caisse par caisse
tests/Caisse/DeuxCaissesTest       aucune caisse n'en renfloue une autre
tests/Caisse/PaiementWebhookTest   un webhook rejoué ne crédite qu'une fois
tests/Caisse/ReconciliationTest    les versements restés en vol
tests/Caisse/ResteAEngagerTest     un versement en vol baisse le reste à engager, pas le payé
tests/Caisse/PeseeNonPayableTest   un montant nul N'EST PAS une pesée soldée   ← le canari
tests/Caisse/PrixTransportTest     le transport s'ajoute au payable, jamais au montant dû
tests/Transport/BaremesTest        les deux modes, les bornes qui ne calculent rien, les deux sauts
tests/Caisse/DeuxPartsTest         récolte et transport se règlent séparément, chacun avec sa borne
tests/Caisse/VersementGroupeTest   plusieurs pesées d'un planteur sous UNE transaction, et ce qui tombe avec elle
tests/Tarification/PrixDeLaPeseeTest  la prime et le transport écrits à la main : ce qu'ils figent, ce qui les ferme
tests/Caisse/DemandeSoldeTest      une seule demande en attente par poste
tests/Caisse/ChequeEtRetraitTest   le chèque vide la banque et non le poste, le retrait les deux livres
tests/Api/FiltresDeListeTest       un filtre qui ne filtre pas ne lève rien : actif, et la recherche par code
tests/Api/TableauDeBordTest        l'accueil dit la même chose que le guichet, et pas à qui il ne faut pas
tests/Api/PieceJointeTest          un justificatif revient entier, jamais à l'entreprise d'à côté, et la décharge d'une dotation suit l'opérateur
tests/Api/ChampsNulsTest           un champ nul sort à null, même là où une opération pose son propre contexte
tests/Epargne/MiseEnCompteTest     le prélèvement ne touche aucune caisse, ne crédite qu'une fois
tests/Epargne/DemandePrelevementTest  l'ouverture, le rejet, l'arrêt, le taux sous le prix
tests/Site/IdentifiantsPosteTest   le poste reçoit un identifiant qui ouvre vraiment, et qui ne bouge pas
tests/Securite/CloisonnementTest   deux clients dans la même base, aucun ne voit l'autre
tests/Securite/DroitsTest          ce que /me annonce est ce que l'API accorde
tests/Synchronisation/…            le gel des passages, l'idempotence d'un lot, le prix du poste ignoré
```

`tests/InvariantTestCase.php` porte les fabriques. `creerPesee()` rend une pesée **jamais
arbitrée** ; `creerPeseeArbitree()` en rend une passée par l'épargne — deux helpers distincts à
dessein, pour que les cas anciens ne changent pas de sens d'un coup. `ouvrirPrelevement()` ouvre une
mise en compte sur un produit **sans rien vérifier** : ce sont les processors qui tiennent la règle
du taux, et un cas qui veut éprouver le plafond doit pouvoir poser un taux délirant.

---

## 12. Commandes

- **Git**
    > git push -u origin main : .. `--force`

- **Développement**
    > composer install
    > php bin/console lexik:jwt:generate-keypair
    > php bin/console doctrine:migrations:migrate --no-interaction
    > php bin/console doctrine:fixtures:load --no-interaction
    > php -S localhost:8000 -t public

  `make db-reset` fait le tour complet : drop, create, migrate, validate. `make fixtures` recharge
  le jeu de démonstration.

  ⚠ **Ne pas passer `public/index.php` en script routeur à `php -S`** : les fichiers statiques
  sortent alors avec une erreur PHP collée à la suite, et plus rien ne fonctionne côté web.

- **Test**
    > php vendor/bin/phpunit
    > php bin/console doctrine:schema:validate
    > php bin/console lint:container

  Deux exécutions simultanées de PHPUnit partagent la même base de test et se marchent dessus :
  des 401 et des 404 apparaissent sans raison. Une à la fois.

- **Production**
    > On peut désactiver la doc `ApiPlatform` dans `config/packages/api_platform.yaml`
    > La 1ère
        > git clone .. .
        > Pour le `.env..` on peut `cp .env .env.local` ou `composer dump-env prod` qui génère un fichier `.env.local.php` qui est plus optimisé
        > composer install --no-dev --optimize-autoloader
        > composer require symfony/apache-pack
        > php bin/console lexik:jwt:generate-keypair : Pour générer les clés jwt vu qu'ils ne sont pas versionné
        > php bin/console doctrine:migrations:migrate --no-interaction
        > php bin/console cache:clear --env=prod
        > php bin/console cache:warmup --env=prod
    > Les prochaines
        > git pull origin main
        > composer install --no-dev --optimize-autoloader
        > php bin/console doctrine:migrations:migrate --no-interaction
        > php bin/console cache:clear --env=prod
        > php bin/console cache:warmup --env=prod

- **Les pièces jointes**
    > public/documents/   les justificatifs, sous un nom aléatoire — dossier INTERDIT par son .htaccess

  ⚠ **Ce dossier doit être SAUVEGARDÉ.** Son contenu est hors de git — seuls le `.htaccess` et la
  sonde suivent le dépôt — et il survit aux déploiements, qui n'y touchent pas. Mais aucune
  migration ne rendra une pièce comptable perdue, et la base n'en garde que le nom. À mettre dans
  la sauvegarde au même titre que la base elle-même.

  **Après le premier déploiement**, ouvrir `/documents/sonde.txt` : un 403 est la bonne réponse. Si
  le texte s'affiche, le dossier est servi en clair et il faut le régler avant d'y déposer quoi que
  ce soit.

  Il compte aussi sur le **quota disque** du mutualisé, qui est la vraie limite : 5 Mo par pièce,
  une pièce par ligne d'argent.

- **Les journaux**
    > var/log/prod-AAAA-MM-JJ.log          un fichier par jour, quinze jours gardés
    > var/log/deprecation-AAAA-MM-JJ.log   les avertissements de mise à jour, trois jours

  Ils se lisent **par FTP ou par le gestionnaire de fichiers de l'hébergeur**, et c'est ce qui a
  décidé de leur forme : du texte en lignes, pas du JSON. La recette de Symfony les envoyait dans
  `php://stderr`, c'est-à-dire dans le journal d'erreurs d'Apache — produits, et illisibles.

  **Rien ne s'écrit tant que tout va bien** : `fingers_crossed` garde les messages de la requête
  en mémoire et ne les déverse qu'à la première erreur, de sorte qu'on lit le chemin qui y menait
  et pas seulement la dernière ligne. Un journal vide veut donc dire « aucune erreur », pas « rien
  n'est branché ».

  ⚠ **Un 4xx compte comme une erreur** pour Symfony, qui journalise toute exception HTTP de statut
  inférieur à 500 en `error` et non en `warning`. Sans précaution, un jeton périmé ou un versement
  refusé en 422 — les refus ordinaires de l'API, que l'opérateur lit à l'écran — déverseraient
  cinquante lignes chacun et noieraient les vraies pannes. D'où `excluded_http_codes`, dont la
  liste est explicite ici et ne se limite pas aux deux codes de la recette. Y toucher est le seul
  réglage qui décide de ce qu'on verra.

  ⚠ **Ce que Monolog ne verra jamais** : une erreur fatale qui tue PHP avant que le noyau ne
  démarre — un `composer install` à moitié fait, une mémoire épuisée, une erreur de syntaxe. Il
  n'y a alors ni requête, ni conteneur, donc personne pour écrire, et c'est exactement le cas où
  l'on cherche un journal. Pour celles-là, c'est PHP lui-même qu'on branche, par un `.user.ini` à
  la racine du site (hébergement en CGI/FPM, le cas courant) :

    > log_errors = On
    > error_log = /chemin/absolu/vers/var/log/php.log

  En mod_php, les deux mêmes lignes vont dans `.htaccess`, en `php_value`.

  **Pour être prévenu par courriel**, le bloc est écrit et commenté en bas de
  `config/packages/monolog.yaml` : deux handlers à décommenter, et une adresse à poser dans
  `.env.local`.

---

## 13. Ce qui n'existe pas encore

> Les fonctionnalités prévues, ce qu'il faut avoir tranché avant chacune et les écarts connus
> vivent dans [A-FAIRE.md](A-FAIRE.md). Cette section-ci ne garde que ce qui manque à
> l'application telle qu'elle est.

- **L'encaissement d'un chèque.** L'émission solde la pesée ; rien ne dit si le chèque a été
  présenté, ni s'il est revenu impayé. Le numéro est là pour le rapprochement à la main.
- **Le prix négocié par planteur** (`prixspeciale`), qui remplaçait le prix du produit pour TOUTES
  ses cultures. Il est revenu, mais **par produit** : `TarifFournisseur`, qui vise une culture et
  laisse les autres au prix commun. C'est ce que l'ancien ne savait pas dire.
- **Un planteur sur plusieurs postes.** Une même personne livrant à deux ponts bascules existe comme
  **deux** planteurs, sans lien. Avec de l'épargne, cela se voit.

**Une limite connue de la mise en compte** : le taux est tenu sous le prix du produit à l'ouverture,
mais rien ne le resserre si le prix **baisse** ensuite. Un prélèvement ouvert à 50 F sur un produit
tombé à 40 prend alors toute la récolte — le plafond `min(taux × poidsnet, montantdu)` empêche la
dette, pas le guichet vide. Le refus `RETENU` le dit à l'opérateur, et il reste à l'administrateur
à baisser le taux.
