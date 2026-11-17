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

**Le poste pèse ; il ne tarife pas.** Le prix d'un produit et le prix du transport d'un planteur
se fixent sur le web, qui est aussi l'endroit où l'on paie — une seule source pour le chiffre qui
fonde un versement. Les logiciels de pesée déployés envoient toujours `prix` et `prixtransport`
dans leur charge utile : ces champs sont **lus et jetés**. Les refuser ferait échouer des lots
entiers pour une information dont on n'a plus l'usage.

> Les deux côtés ont longtemps fait autorité, départagés par détection de changement sur un
> `prixPoste` qui mémorisait le dernier envoi. L'arbitrage est parti avec sa raison d'être. Ce
> qu'il coûtait : deux sources pour un chiffre qui sort de l'argent, et une divergence qu'aucun
> écran n'affichait.

**Ce que ça change au quotidien** : un produit créé à la volée par le peseur naît à zéro, et ses
pesées ne valent rien tant que personne ne l'a tarifé au bureau. C'était déjà vrai quand le poste
se taisait ; c'est désormais la règle. La liste des produits sait filtrer **ceux qui attendent
leur prix** (`?prix=0`), et la colonne des prix affiche « Aucun prix » en rouge — sans quoi on les
découvrirait au guichet.

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
| `montanttransport` | le **transport**, `poidsnet x prix du planteur`, payé EN PLUS pour ceux qui livrent eux-mêmes | les écrans d'argent |
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
- Les deux prix sont **figés** sur la pesée (`prixunitaire`, `prixtransport`) à côté des montants :
  un chiffre qu'on ne sait pas réexpliquer est inexplicable devant un planteur qui conteste.
- Une pesée dont le produit n'est pas tarifé (`montantdu <= 0`) **paie quand même son transport**.
  C'était l'inverse tant qu'une pesée se réglait d'un bloc — régler le seul transport aurait laissé
  une pesée à moitié payée que plus personne ne rouvre. Ce qui l'empêche aujourd'hui de disparaître
  de la file du guichet n'est plus ce zéro, mais `StatutReglement`, qui **refuse de la dire soldée**
  tant qu'elle n'est pas tarifée, et le motif de sa part produit, qui nomme ce qu'il faut corriger.

Le **prix du transport** vit sur la fiche du planteur, et se saisit sur le web comme le prix d'un
produit — §4. `null` veut dire « on ne lui paie pas de transport », ce qui est le cas de la
plupart des planteurs ; `0` veut dire « on ne lui en paie plus ». Les deux se distinguent à
l'écran, et il faut qu'ils se distinguent dans le code.

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
`findARevaloriser()`, qui recalcule les pesées de ce produit ou de ce planteur puis ouvre leur
mise en compte dans la foulée. Il a longtemps manqué : l'écran qu'on ouvre précisément pour
tarifer un produit créé à la volée ne faisait que journaliser, et ses pesées restaient à zéro.

> Il y en avait deux. `findRecalculables()` balayait tout un poste quand un prix bougeait côté
> logiciel de pesée ; elle est partie avec l'arbitrage des prix (§4). La portée de celle qui reste
> est étroite exprès : tarifer un produit ne touche que **ses** pesées.

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
7. **Le cloisonnement passe par les interfaces** `EntrepriseOwnedInterface` / `SiteOwnedInterface`,
   jamais par un `where` écrit à la main.
8. **Le frontend ne recopie aucune règle.**

---

## 9. Les pièges qui coûtent une journée

| Piège | Ce qui se passe |
|---|---|
| **`SearchFilter` sur un booléen** | Il compare des **chaînes** à ce que porte la colonne. Sur un `bool`, seul `=1` répond ; `=true` et `=false` — ce que les écrans envoient — ne correspondent à rien, et la liste se **vide sur les deux choix** sans la moindre erreur. `BooleanFilter` est fait pour ça. Le cas vécu : le filtre « Actif / Désactivé » des produits. |
| **Un champ de recherche, plusieurs colonnes** | Un `SearchFilter` par colonne donne un paramètre par colonne, jamais un OU. Les écrans qui annoncent « Nom **ou code** » n'en interrogeaient qu'une, et le code ne trouvait jamais rien. Le paramètre `recherche` (`QueryParameter` + `FreeTextQueryFilter` + `OrFilter` + `PartialSearchFilter`) les met en OU : voir `Produit`, `Fournisseur`, `Site`. |
| **Un filtre qui ne filtre pas ne lève rien** | Il rend une liste vide, ou entière, et l'écran l'affiche. Le contrôle qui les débusque tous tient en une comparaison : total filtré contre total nu, valeur par valeur — toujours zéro, ou toujours le total, et c'est cassé. `tests/Api/FiltresDeListeTest` fige les deux cas rencontrés. |
| **Cache du sérialiseur** | Un getter `#[Groups]` tout neuf sort **absent du JSON**, sans la moindre erreur. `cache:clear` ne suffit pas toujours : supprimer `var/cache/dev` à la main. Pareil pour `--env=test` avant PHPUnit. |
| **Relation sérialisée vide** | Une relation ne s'embarque que si une propriété de la cible porte le groupe de lecture du **parent** (`read:DemandePrelevement` sur `Fournisseur::getNomComplet()`). Sinon on n'obtient que l'IRI, l'écran affiche un tiret, et rien ne lève. **À faire pour CHAQUE champ qu'un écran lit à travers la relation**, pas seulement le nom : `DemandeRetrait` a montré les deux moitiés du piège — la colonne Planteur vide, et, plus discret, le formulaire de versement qui se pré-remplissait à blanc faute de `contact1` et `reseau`. Les formes partagées côté web (`SiteBref`, `UserBref`) fixent le minimum : `id`, `code`, `libellesite`, `nomComplet`. |
| **Un `#[Groups]` sans préfixe `get`** | Les groupes posés sur une méthode qui n'est ni `get…`, ni `is…`, ni `has…` sont **ignorés en silence**. `estActif()` serait absent du JSON, et l'écran afficherait tout comme arrêté : d'où `Prelevement::getActif()` à côté de lui. |
| **`Operation` ambigu** | `ApiPlatform\OpenApi\Model\Operation` masque l'entité. Aliaser : `use App\Entity\Operation as Pesee;`. |
| **`??` ne protège pas un appel** | `$a[$k]?->m() ?? 0` lève quand même « Undefined array key » : `??` n'étend ses semblants d'`isset` qu'aux chaînes de propriétés. Lire la case à part. |
| **`$processor` est réservé** | `services.yaml` lie ce **nom d'argument** à la persistance native. Un processor qui décore autre chose doit nommer son argument autrement (`$audit`). |
| **`deletedAt` dans un processor** | Les extensions Doctrine ne s'appliquent qu'aux requêtes d'API Platform. Dans un repository appelé par un processor, filtrer `deletedAt IS NULL` à la main. |
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
    Service/
      Caisse/            CaisseService, PaiementService, réconciliation
      Epargne/           EpargneService
      SynchronisationService.php
  Entity/                Les entités. Les règles qui ne dépendent que de l'objet vivent ICI
    Input/ Output/       Les DTO des opérations personnalisées
  Repository/            Les requêtes. Les requêtes GROUPÉES y vivent aussi (éviter les N+1)
  Security/              Droits, Perimetre, UserGuard, AccesVoter
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
tests/Caisse/DeuxPartsTest         récolte et transport se règlent séparément, chacun avec sa borne
tests/Caisse/DemandeSoldeTest      une seule demande en attente par poste
tests/Caisse/ChequeEtRetraitTest   le chèque vide la banque et non le poste, le retrait les deux livres
tests/Api/FiltresDeListeTest       un filtre qui ne filtre pas ne lève rien : actif, et la recherche par code
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

---

## 13. Ce qui n'existe pas encore

- **L'encaissement d'un chèque.** L'émission solde la pesée ; rien ne dit si le chèque a été
  présenté, ni s'il est revenu impayé. Le numéro est là pour le rapprochement à la main.
- **Le prix négocié par planteur** (`prixspeciale`), qui remplaçait le prix du produit. Retiré avec
  le prix du transport, le temps d'en revoir le mécanisme. La migration **supprime les valeurs** :
  les exporter avant de migrer une base de production.
- **Un planteur sur plusieurs postes.** Une même personne livrant à deux ponts bascules existe comme
  **deux** planteurs, sans lien. Avec de l'épargne, cela se voit.

**Une limite connue de la mise en compte** : le taux est tenu sous le prix du produit à l'ouverture,
mais rien ne le resserre si le prix **baisse** ensuite. Un prélèvement ouvert à 50 F sur un produit
tombé à 40 prend alors toute la récolte — le plafond `min(taux × poidsnet, montantdu)` empêche la
dette, pas le guichet vide. Le refus `RETENU` le dit à l'opérateur, et il reste à l'administrateur
à baisser le taux.
