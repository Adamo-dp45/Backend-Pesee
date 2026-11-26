# Ce qui reste à faire

Écrit le 19/11/2026, **mis à jour le 24/11/2026**. Il complète les deux README : eux disent ce que
l'application **fait**, celui-ci ce qu'elle ne fait **pas encore**, et ce qu'il faut avoir tranché
avant d'y toucher.

---

## 1. Où on en est

Sur les neuf fonctionnalités listées en estimation, **quatre sont livrées** :

| # | Fonctionnalité | Livrée |
|---|---|---|
| 1 | Desktop réduit à la pesée — toute la tarification sur le web | oui |
| 2 | Produit et transport payés séparément, chacun en plusieurs fois | oui |
| 6 | Classes de planteurs, prix par classe, prix planteur prioritaire | oui — **liste plate**, pas de hiérarchie |
| 8 | Transport : au kilo, au forfait, ou par tranches de kilomètres | oui — **sans mode `TRANCHES`**, voir plus bas |

Deux écarts avec la liste d'origine — le premier décidé en cours de route, le second imposé par un
défaut trouvé après coup :

- **La #6 n'est pas hiérarchique.** Une catégorie n'a pas de parent : la résolution fait trois sauts
  (tarif du planteur, tarif de sa catégorie, prix du produit) et pas davantage. Un arbre ajouterait
  une récursion dans le chemin chaud de **toute** pesée pour un besoin que personne n'a formulé.
  Si la hiérarchie revient, c'est `TarifApplicable::pour()` qu'il faut rouvrir, et lui seul.
- **La #8 n'a plus de mode « par tranches », et c'est la correction du 24/11.** La distance vivait
  sur la fiche du planteur, en kilomètres — et personne ne la saisissait, puisque le poste crée les
  planteurs avec un libellé et rien d'autre. Le barème ne savait donc pas répondre, le transport
  valait zéro, et ce zéro se figeait puis s'arbitrait. **Une tranche est devenue un barème** : « de
  10 à 20 km : 1 000 F » est un forfait qui porte `kmMin` et `kmMax`, lesquels **ne calculent
  rien** — ils nomment le barème et le classent dans la liste. La migration `Version20261123084942`
  convertit au lieu de supprimer : chaque planteur est repointé sur l'intervalle qui contenait sa
  distance, et aucun montant n'a bougé. `Fournisseur::$distanceKm` n'existe plus.

**La #7 — prix datés et historique consultable — est ABANDONNÉE** (décidé le 19/11/2026). Elle
aurait fait d'un prix un intervalle, et une pesée se serait valorisée au prix de SA date. Ce qu'il
faut en retenir : la revalorisation automatique reste ce qu'elle est, et elle n'a de sens que
parce qu'un prix n'a pas de date. Corriger un prix touche donc TOUTES les pesées non payées et
non arbitrées de ce produit, passées comprises. C'est voulu, pas un manque.

**Tout est commité** dans les deux dépôts, sur `main`, à jour avec `origin` — ce qui n'était pas le
cas quand ces lignes ont été écrites. Les deux dernières entrées sont du 24/11 : `9b931ae` côté
API, `3e202fc` côté web.

### Ce que le 24/11 a livré

La matinée n'a touché aucune des quatre qui restent : elle est partie sur un défaut de la #8.

- **Les tranches sont devenues des barèmes** (voir plus haut), et avec elles sont tombés l'entité
  `Tranche`, sa validation de collection, le refus au rattachement, `motifTransportIncalculable`
  et `motifTransportIndisponible`. Côté web, `modules/collection.js` n'avait plus d'appelant.
- **Le planteur s'identifie par son CODE.** Le libellé seul faisait un planteur de deux homonymes,
  et deux planteurs d'un homme renommé sur le poste. Le libellé ne sert plus que de repli vers une
  fiche d'avant la bascule. Aucune reprise de données : les fiches sans code se marquent au premier
  envoi qui en porte un.
- **Le versement groupé** (`POST /api/paiements/lot`), plusieurs pesées d'un même planteur sous UNE
  transaction. Aucun montant ne se saisit : cocher une pesée veut dire la régler en entier.
- **Le transport s'écrit à la main sur une pesée**, pendant exact de la prime sur l'autre part
  (`PATCH /api/operations/{id}/montant-transport`).

Les deux README portent le détail. Ce qui compte ici : **aucune règle d'argent n'a été rouverte**.

L'après-midi a **enrichi le tableau de bord**, et entame donc la #3 — le détail est sous cette
rubrique plus bas. Monolog y a aussi été posé des deux côtés, l'API n'en ayant jamais eu : sur un
hébergement mutualisé, les journaux partaient dans `php://stderr`, c'est-à-dire dans le journal
d'Apache, inaccessible. Voir le §12 du README de l'API.

---

## 2. Ce qui reste

Les chiffres sont ceux de l'estimation d'origine : jours-homme, pour quelqu'un qui connaît déjà les
deux dépôts, migration, tests et README compris. Hors conception graphique, hors Desktop.

### #3 — Tableau de bord détaillé · **3 à 5 jours** · risque faible

« À qui doit-on, et combien ? » Agrégats SQL et providers de statistiques, cartes et tables React.
Lecture seule, donc aucun invariant d'argent en jeu.

**Le seul piège connu** : la pagination de l'API plafonne à 100 (`ApiHelper::PAGE_MAX`) et tronque
en silence. Un tableau de bord qui additionne des lignes au lieu de demander des totaux au serveur
affichera des chiffres faux sans la moindre erreur — c'est exactement ce qui est arrivé à l'export
du bilan. Les agrégats se calculent côté API, jamais en recomposant des pages.

**À trancher :** quelle période par défaut, et les montants sont-ils ceux de l'entreprise entière ou
du poste de celui qui regarde ?

**Une part est déjà faite** (session du 19/11/2026) : `/stats/paiements` et `/stats/caisses`
répondent à « combien est sorti, et par où », avec leurs écrans sous `/bilan`. Les deux questions à
trancher ci-dessus ont reçu une réponse de fait — période du mois en cours, et périmètre de celui
qui regarde, comme partout ailleurs.

**L'écran d'accueil a été repris** (24/11/2026), pour rattraper les notions ajoutées depuis qu'il
avait été écrit. Rien n'y a demandé de SQL neuf : tout existait dans l'API et ne remontait pas.

- **Deux rangées de tuiles au lieu d'une.** La période d'abord — tonnage, récolte, **transport**,
  **mise en compte** —, l'argent ensuite. Les mélanger mettait « Montant dû » à côté de « Ma
  caisse », deux grandeurs sans rapport que l'œil additionne.
- **Les deux restes sont nommés à part.** « Reste sur la période » s'arrête au mois affiché ;
  **« Créance totale »** compte toute la file du guichet. Ils portaient le même nom sur deux écrans
  et ne donnaient pas le même nombre. La créance sort de `totauxAPayer()`, la méthode du guichet.
- **« Disponible » remplace le solde brut**, l'immobilisé en moins. Sur le jeu de démonstration, un
  opérateur voyait 4 500 000 et ne pouvait en engager que 1 500 000. L'épargne due est à côté, et
  **ne se retranche pas** : les billets sont dans le tiroir, c'est une dette qui naît à part.
- **Deux bandes** : les pesées dont le produit n'est pas tarifé, et les trois files de demandes en
  attente avec leurs liens.
- `tests/Api/TableauDeBordTest` tient les trois accords qui pouvaient se rompre en silence.

**Ce qui reste de la #3** est la lecture **par planteur** : « à qui doit-on, et combien », avec les
plus grosses créances et le nombre de planteurs concernés. C'est le seul morceau qui demande un
agrégat neuf côté API, et il a été **écarté sciemment** le 24/11 — pas oublié.

### #5 — Recherche et suivi · **entamée** · risque faible

**Ce qui est livré** (session du 19/11/2026) :

- Le bilan des pesées a ses filtres **produit** et **planteur**. Ils étaient déjà relayés à l'API
  par le contrôleur, et aucun écran ne les proposait : le filtre existait et restait inatteignable.
  Les listes passent par `ApiHelper::all()` — au-delà de cent planteurs, une page tronquée en
  aurait fait disparaître la moitié sans un mot.
- **Une colonne par nombre** sur le bilan et sur les dernières pesées d'une fiche planteur, au lieu
  des montants groupés avec leurs écarts en lignes secondaires.
- **Un seul bouton d'impression**, valorisé. Le second, sans montants, ne servait aucun usage que
  l'export Excel ne couvre déjà.
- **Deux bilans d'argent** : `/bilan/versements` et `/bilan/caisses`, période, totaux agrégés par
  l'API, liste filtrable et exports. Ils mordent sur la #3 (voir plus haut).
- Les filtres du bilan ont été **vérifiés mécaniquement** : pour chacun, la somme des totaux
  valeur par valeur retombe exactement sur le total sans filtre.

**Ce qui est livré ensuite** (même journée, après relecture) :

- **Un seul écran de versements.** `/paiements` affichait la même table que `/bilan/versements`
  sans période, sans totaux et sans export : l'adresse est **retirée**, et il ne reste de son
  contrôleur que ce qui n'est pas une liste — engager un versement, et le reçu. Deux écrans sur la
  même ressource finissent toujours par ne plus filtrer pareil.
- **La caisse s'est coupée en deux.** `/caisse` garde les SOLDES et les deux gestes qui les
  déplacent — recharge, dotation des postes — et s'appelle « Trésorerie ». Le grand livre, qui y
  figurait une seconde fois sans période ni totaux, ne vit plus qu'au bilan.
- **Filtres planteur et mode** sur les versements, **filtre de nature** au grand livre — ce
  dernier était relayé à l'API depuis le début sans qu'aucune liste déroulante ne le propose. Ses
  valeurs viennent du bilan lui-même, donc de la base : une nature ajoutée côté serveur se
  proposera toute seule.
- **Le reçu s'ouvre dans un onglet à part.** Pris dans l'onglet courant, le lecteur PDF remplaçait
  la liste, et revenir en arrière la rouvrait sans ses filtres ni sa page.
- **La saisie des tarifs a son écran** (`/tarifs`). Les grilles se saisissaient au bas d'une fiche
  — celle d'une catégorie, celle d'un planteur — et rien dans le menu ne disait qu'elles
  existaient. Les trois étages sont désormais au même endroit : le prix du produit en lecture, la
  grille d'une catégorie, les tarifs propres d'un planteur. Les fiches gardent la LECTURE de leur
  grille et mènent à la saisie. Le chemin d'écriture — aperçu, confirmation, POST — n'a pas bougé :
  une pesée revalorisée fige sa retenue d'épargne pour toujours.
- **Un tarif retiré restait affiché.** Trouvé en essayant la grille de bout en bout sur une
  catégorie sans planteur : la suppression logique écrivait bien `deleted_at`, la valorisation
  écartait bien la ligne, mais l'API publiait la collection ENTIÈRE. On retirait un tarif, l'écran
  continuait de le montrer, et les planteurs de la catégorie étaient payés au prix du produit.
  Corrigé côté API : le groupe de lecture est passé sur un getteur qui filtre. `Fournisseur`
  portait le même défaut, jamais vu parce qu'aucun écran ne lisait encore sa collection.
- **L'écran des tarifs montre les tarifs en vigueur** : une colonne par produit pour chaque
  catégorie, une ligne par tarif propre de planteur, et « Changer » qui mène au formulaire du
  produit. Côté API : les catégories publient leur grille sur la liste, et les planteurs tarifés
  à part se lisent sur `/fournisseurs/tarifes?avecTarifPropre=true` — une opération à part, pour
  ne pas faire une requête par ligne sur toutes les listes de planteurs.
- **Le référentiel est passé à l'administrateur.** `Produit` et `Fournisseur` sont en LECTURE pour
  l'opérateur : il ne tarifie plus, ne crée plus de planteur et ne corrige plus de fiche depuis le
  web. Le poste continue de les créer en synchronisant ('ROLE_SITE' n'est pas dans la table des
  droits). **À surveiller** : un planteur qui se présente pour la première fois, et un numéro de
  téléphone faux, demandent maintenant un administrateur.
- **La barre latérale réorganisée** : Exploitation / Argent / Demandes / Référentiels. Les
  demandes ont quitté le bloc de l'argent, où elles faisaient une liste de sept.
- **La marge basse** est désormais posée aussi sur grand écran (`2rem`), pas seulement sous 768 px.
- **Les modèles TypeScript sont à jour** : `Pesee` (15 champs), `Paiement` (3), `MouvementCaisse`
  (4). Le contrôle compare les clés SERVIES par une page aux champs déclarés ; les neuf modèles
  tombent juste. Deux relations sérialisées à vide ont été réparées au passage (`initiePar`,
  `demandeSolde`).
- **Le grand livre lit le libellé de l'API** au lieu de son dictionnaire maison, qui avait déjà
  divergé : « Versement à un planteur » d'un côté, « Paiement d'un planteur » de l'autre.

**L'écran « À payer », repris en entier** (20/11/2026) :

- **Quatre totaux** — reste, déjà versé, en vol, pesées en attente — calculés par la base
  (`/api/stats/a-payer`) et suivant les filtres de la liste. Ils reçoivent EXACTEMENT les
  paramètres de la liste, au même vocabulaire de filtres : c'est la seule façon de garantir
  qu'ils décrivent ce qu'on a sous les yeux.
- **On verse depuis la liste**, dans une modale, avec des raccourcis qui portent leur montant :
  régler tout, la récolte, le transport. Le formulaire complet reste à un clic.
- **Filtres période et planteur**, plus produit. Le type `date_range` existait dans le composant
  de table depuis le début sans qu'aucun écran ne l'ait jamais posé.
- **Une colonne par nombre**, comme au bilan, et la colonne d'action FIGÉE à droite.
- **Les avertissements sont passés sous la ligne**, en bande pleine largeur : logés dans une
  cellule, ils l'élargissaient et décalaient tout le tableau. Sur téléphone, la ligne devient une
  carte.
- **« Barèmes » est devenu « Tarifs transport »** partout où l'utilisateur le lit. Le code garde
  son nom : route, entité et droit voyagent jusqu'à la base et jusqu'au poste.

**Le versement est vérifié de bout en bout** : un règlement de transport de 12 000 F en mobile
money depuis le raccourci de la modale, accepté par l'API, revenu sur la file avec ses filtres. Il
est parti EN VOL et non en versé — c'est juste : le mobile money attend la passerelle, la créance
du planteur ne bouge pas, et c'est la carte « En vol » qui porte les 12 000 F.

**Deux défauts de la modale, corrigés après coup** :

- Le champ d'un montant restait à zéro quand on le vidait, et la frappe suivante donnait
  `01000`. L'état garde désormais la chaîne tapée.
- **Turbo photographiait la modale ouverte.** On suivait « Page complète », on revenait par le
  bouton retour, et la modale était là — sans JavaScript, impossible à fermer, avec le verrou de
  défilement. Elle survivait jusqu'à l'écran de connexion. `modules/surcouche.js` vide tous les
  portails de base-ui avant la photo, donc pour toutes les futures fenêtres aussi.

**La période sur le journal et les synchronisations** (24/11/2026) : les deux écrans où elle
manquait le plus, parce qu'on y cherche toujours un jour précis — « qu'est-ce qui a bougé la
semaine dernière », « qu'est-ce qui s'est passé mardi » — et qu'il fallait dérouler les pages
jusqu'à tomber dessus. L'API n'a rien eu à faire : `Activite` et `SynchronisationLot` déclaraient
déjà un `DateFilter` sur `createdAt`, que rien n'atteignait. Troisième cas de la famille
« relayé mais inatteignable », à ceci près qu'ici même le contrôleur ne le relayait pas.

La traduction `periode_debut`/`periode_fin` → `champ[after]`/`champ[before]` vivait en double, sur
la file du guichet et sur le bilan ; elle est passée sur `GestionController::bornesDePeriode()`,
avec le champ en paramètre — une pesée se date par sa sortie du pont, un lot par sa création. Une
troisième copie aurait fini par oublier les `23:59:59` de la borne haute, sans quoi « au 24/11 »
s'arrête à minuit et la liste s'arrête la veille.

**Ce qui reste** : les filtres par période sur les listes de **planteurs et de produits**, et les
colonnes et libellés métier à revoir écran par écran.

**Deux règles à ne pas perdre**, apprises en corrigeant des filtres qui ne rendaient rien :
- Un filtre dont la valeur ne correspond à rien côté API **vide la liste sans rien dire**, et une
  liste vide ressemble en tout point à un référentiel vide. Tout filtre neuf se vérifie en comparant
  la somme de ses valeurs au total sans filtre.
- Un tri hors de la liste blanche fait répondre l'API en erreur — ou pire, l'ignore en laissant
  croire au classement demandé.

**À trancher :** la liste des écrans restants. C'est elle qui décide du chiffre, pas la difficulté.

### #4 — Comptabilité : références et pièces jointes · **l'API est faite**, les écrans restent

Les quatre questions ont été tranchées le 24/11/2026, et la moitié API livrée dans la foulée :

| | Réponse |
|---|---|
| Où vivent les fichiers ? | **Disque du serveur**, `public/documents`, interdit par son `.htaccess` — comme iTransport |
| Qui peut **lire** ? | Qui voit la ligne : `is_granted('VOIR', $cible)`, aucune règle de plus |
| Quels types, quelle taille ? | PDF, JPEG, PNG, WEBP — **5 Mo** |
| À quoi ça se rattache ? | Versement, mouvement de caisse, et les trois demandes. **Pas** une pesée |

**Ce qui est livré** : l'entité `PieceJointe`, le dépôt en multipart
(`POST /api/piece_jointes`), la lecture (`GET /api/piece_jointes/{id}/fichier`), l'action
`JOINDRE` dans la table des droits, la contrainte qui impose une cible et une seule, et
`tests/Api/PieceJointeTest`. Le détail et le pourquoi sont au §5 du README.

**La durée de conservation n'a pas été tranchée**, et rien ne purge aujourd'hui : une pièce vit
tant que sa ligne vit. C'est tenable — 5 Mo par ligne d'argent — mais le quota disque d'un
mutualisé finira par se faire entendre. À reprendre avec la question des sauvegardes.

**Les écrans, premier passage** (25/11/2026) : le champ **justificatif** est posé dans les
formulaires qui CRÉENT la ligne, et non sur un écran d'après — c'est au guichet, le reçu en main,
qu'on numérise. Quatre y sont : le versement (`paiement.new`) et les trois demandes, à la création
**comme à la correction**. La correction est d'ailleurs la seule seconde chance si le dépôt échoue
ou si le papier arrive plus tard ; un versement n'en a pas.

**La recharge et la dotation** ont suivi le 25/11/2026, et c'est l'API qui a dû bouger : leurs
opérations rendaient l'`Entreprise` ou le `Site`, c'est-à-dire un **solde**, qui se relit à tout
moment — jamais l'écriture qui venait de naître, que rien d'autre ne nomme. Elles rendent
désormais un `EcritureOutput` (`id`, `contrepartieId`, `caisse`, `montant`, `soldeApres`) ; aucune
règle d'argent n'a été rouverte, `CaisseService` renvoyait déjà ses mouvements. **Pour la dotation,
le bordereau se pose sur l'écriture d'ENTRÉE**, celle du poste, et la sortie de l'entreprise est la
contrepartie : l'opérateur lit les écritures de sa caisse et jamais celles de l'entreprise, donc
c'est la seule des deux que celui qui a signé la décharge puisse relire. L'administrateur voit les
deux de toute façon — c'est le choix le plus large sans ouvrir quoi que ce soit à qui n'y a pas droit.

**Ce qui reste des écrans :**

**Relire une pièce** a suivi le 25/11/2026, **sur les versements seulement**. Le point qui comptait
n'était pas l'affichage : **un lien ne porte pas de jeton**. L'API sert la pièce derrière un
`Bearer`, donc un `<a href>` pointant droit sur elle arrive les mains vides. Le web a maintenant
`/justificatifs/{id}` (`JustificatifController`), qui va la chercher avec son jeton et la relaie —
**un seul chemin pour les cinq cibles**, puisque c'est la ligne qui décide de qui la voit, et aucun
contrôle de périmètre recopié côté web.

Deux écrans s'en servent : la **colonne Justificatif** de la liste des versements, en lecture seule,
et la nouvelle **fiche d'un versement** (`/paiements/{id}`). Lecture seule, et c'est une décision :
une pièce de versement se joint au guichet, le reçu en main, jamais après coup — rouvrir le dépôt
sur une liste rendrait le document d'origine indiscernable de celui qu'on aurait trouvé ensuite.

**Les deux écrans du guichet** ont suivi le même jour. Ils sont rendus par React et postent un
formulaire HTML nu : pas de `FormInterface`, donc rien qui valide — d'où `PieceJointeService::deposer()`,
qui prend l'`UploadedFile` directement et contrôle taille et format sur les constantes du champ
Symfony, une seule borne pour les deux chemins. Ils n'ont qu'**un** champ : au guichet, debout avec
le planteur en face, le numéro de pièce comptable n'existe pas.

> Le **versement groupé** a demandé une dernière chose à l'API : `PaiementLotOutput` rend désormais
> les `ids` des versements écrits, et pas seulement leurs références. Le planteur signe UNE décharge
> pour ses N pesées, et elle se pose sur **chacune** — le modèle tient une pièce par ligne d'argent
> sans exception, et l'accrocher à la première seulement ferait paraître les autres injustifiées.
> Cela fait N copies du même fichier : à reprendre avec la question du quota disque.

**La lecture est complète depuis le 25/11/2026 au soir.** Les cinq listes portent leur colonne
`Justificatif`, les trois écrans de décision montrent le papier entre les chiffres et le formulaire —
c'est là qu'il sert le plus, puisque c'est le seul endroit où quelqu'un d'autre que le demandeur lit
la demande — et les trois écrans de correction affichent la pièce **en place, au-dessus du champ qui
la remplacerait** : un nouveau dépôt écrase le précédent sans rien demander.

> Toute la règle tient en trois endroits : `CelluleJustificatif` pour les colonnes,
> `partials/_justificatif.html.twig` pour les cartes, `GestionController::urlJustificatif()` pour
> l'adresse. Le cloisonnement a été vérifié de bout en bout : un opérateur ouvre la pièce de sa
> demande et reçoit 403 sur le bordereau d'une recharge de l'entreprise.

**Les exports portent la colonne** depuis le 25/11/2026 — versements et caisses, en dernière
position. Elle ne sert pas à lire le relevé, elle sert à préparer un contrôle : on filtre sur
« non vide » et on sait quelles lignes ont un papier à sortir du classeur. Son contenu est le
**numéro de pièce comptable**, le nom du fichier à défaut, « Oui » en dernier recours. Pas de lien :
un classeur part chez un tiers, à qui une adresse interne ne servirait de rien.

**Le #4 est clos.**

**« Références »** a été lu comme le numéro de pièce comptable, porté par la pièce elle-même, à ne
pas confondre avec `Paiement::reference` — la référence marchand de la passerelle, qui sert de clé
d'idempotence au webhook. Une ligne qui voudrait une référence **sans** document n'est pas couverte.

### #9 — Relevés imprimables pour les banques · **les deux relevés sont livrés**

Livré le 25/11/2026. L'énoncé demandait « des états détaillés des pesées, paiements de produits,
règlements de transport, épargnes et retraits, filtrés par période, fournisseur, site ». Les trois
premiers existaient déjà en PDF et en Excel, avec leurs filtres ; ce qui manquait était l'ÉPARGNE —
aucun export ne la portait — et deux documents : celui qui met la position entière d'un planteur
sur une page, et celui qui donne la vue d'ensemble de l'entreprise.

**Un écran à part, `/releves`.** Un bilan répond à « montre-moi les lignes » ; un relevé à
« donne-moi un document à remettre ». Les mettre en troisième bouton sur les bilans les aurait
confondus avec les deux exports qui y sont déjà. L'écran ne montre aucun chiffre : il ne porte que
les paramètres, et un aperçu serait un quatrième endroit où lire les mêmes montants.

| Relevé | Ce qu'il croise |
|---|---|
| **Compte d'un planteur** | Livraisons, règlements, épargne (optionnelle), position de clôture |
| **Activité de l'entreprise** | Achats par poste et produit, caisse par nature, épargne, trésorerie |

**Ce n'est pas un `Etat`.** Un état décrit une ressource et se rend en une table ; un relevé est
composite. D'où `Domain/Releve` côté web : `Releve` (le document), `Bloc` (chiffres ou tableau), et
un assembleur par relevé. Un troisième relevé est un assembleur de plus — ni gabarit, ni écriture
de classeur à reprendre.

**Trois décisions qui se lisent dans les documents.** L'épargne du planteur est optionnelle, et la
case ajoute aussi la colonne des retenues — une mise en compte est de l'argent qu'on ne verse pas.
Un retrait d'épargne apparaît DEUX fois, en Règlements et en Épargne, parce que les deux sont vrais.
Et les soldes de caisse sont ceux **du jour**, jamais d'une fin de période : `/stats/caisse` ne sait
pas remonter le temps, et le relevé le dit plutôt que de laisser croire à une clôture.

> **Un piège de cette livraison, et il ne lève pas** : les soldes de l'entreprise s'appellent
> `soldeEntreprisePhysique`, pas `soldePhysique` — les deux niveaux cohabitent dans la même charge
> utile. Les avoir devinés sortait **trois zéros** sur un document qui annonce une trésorerie.
> Ils sont de plus **nuls pour un opérateur**, à qui la trésorerie globale ne regarde pas : le
> relevé affiche un tiret, zéro se lirait « l'entreprise n'a plus rien ».

**Ce qui reste :** la ventilation par mode de règlement n'y est pas — elle vit au bilan des
versements, avec ses filtres, et la recopier en ferait un second endroit où lire le même chiffre.
Les trois journaux détaillés restent les annexes qu'on joint quand l'établissement veut la ligne à
ligne.

### Ce que ça fait au total

L'estimation d'origine disait **15 à 24 jours** pour les quatre. Au 24/11, il en reste des
morceaux : la lecture par planteur de la #3, deux listes à filtrer pour la #5, **les écrans** de la
#4 dont l'API est faite, et la #9 entière — qui attend moins du code que la réponse des banques.

L'abandon des prix datés avait retiré le seul poste à risque élevé. Rien de ce qui reste ne rouvre
une règle d'argent : ce sont des écrans et des états.

### L'ordre

Ils sont **indépendants** les uns des autres et se prennent dans l'ordre qui arrange. Le seul
enchaînement qui comptait — les prix datés d'abord, parce qu'ils auraient réécrit la résolution
des prix — est tombé avec eux (§1).

---

## 3. Ce qui est connu et pas corrigé

Signalé en son temps, laissé en l'état faute d'être dans le périmètre demandé.

### Les trois états imprimés, refaits le 26/11/2026

Ils sortaient le même tableau que le classeur, et une feuille n'est pas une grille : le bilan des
pesées alignait **vingt colonnes en paysage** et chaque pesée tenait sur **trois lignes physiques**
— « T- » puis « 4503 », « Pont de » puis « Soubré ». `Domain/Export/Impression` sépare désormais
les deux : le PDF n'imprime que les colonnes qu'on lit, met le reste en ligne de détail, et se coupe
en sections ; le classeur garde tout.

- **Pesées** : sept colonnes, l'acheminement en détail sous chaque ligne. Sept pesées tiennent
  maintenant sur une page là où il en fallait trois.
- **Versements** : une section par mode de règlement avec son sous-total — c'est la question qu'on
  pose à ce document, et les chèques sont les seuls à se rapprocher d'un relevé bancaire.
- **Grand livre** : une section par compte et par caisse, report d'ouverture, entrée et sortie en
  deux colonnes.

> **Le grand livre promettait une propriété qu'il ne tenait pas**, et c'est la trouvaille de cette
> reprise. Son commentaire dit depuis toujours qu'« un relevé imprimé est l'endroit où un écart de
> chaînage se voit » — il sortait en `createdAt DESC`, toutes caisses mélangées, donc deux lignes
> qui se suivent n'étaient presque jamais du même compte. La propriété était invérifiable. D'où
> `Etat::$ordre`, que le document impose.

> **Deux défauts silencieux corrigés au passage.** `fromArray` comparait sans type et **sautait les
> zéros** : « transport 0 » sortait en case vide dans tous les classeurs, ce qui se lit « sans
> objet ». Et le libellé d'un total occupait ses colonnes par soustraction, ce qui lui faisait
> manger une colonne du milieu : sur les versements, onze millions disparaissaient du sous-total.

### Les trois classeurs, refaits le 26/11/2026

Le document imprimé avait été repris, pas le classeur : il sortait toujours en **vidage plat**,
c'est-à-dire avec tout ce qui fait d'une grille autre chose qu'une capture d'écran en moins. Un
classeur ne se lit pas du haut vers le bas, il se trie, se filtre et se recoupe — il doit donc
répondre aux questions qu'on n'a pas prévues.

- **Les dates étaient du TEXTE.** « 03/12/2026 » se triait donc dans l'ordre alphabétique, avant
  « 25/11/2026 », et aucun filtre par mois ne les voyait. Elles sortent maintenant en vraies dates
  (`Etat::$dates`), le format de colonne les réaffichant en `dd/mm/yyyy hh:mm`.
- **Aucun total.** On exporte un bilan d'argent, et la première chose qu'on y fait est de
  sélectionner une colonne pour en lire la somme — une somme qui se perd à la fermeture. La ligne
  de totaux utilise `SUBTOTAL(9, …)` et non `SUM` : elle ne compte que les lignes que le filtre
  laisse voir. Avec `SUM`, elle serait restée celle du fichier entier, donc fausse pour tout le
  monde dès le premier filtre posé, sans jamais se signaler.
- **Aucun filtre, et la première colonne non figée.** Les deux sont posés d'avance ; sur vingt
  colonnes, arrivé au montant payé on ne savait plus de quelle pesée il s'agissait.
- **Les largeurs étaient automatiques**, donc dictées par la case la plus longue : une provenance
  ou un nom de fichier poussait les colonnes d'argent hors de l'écran. Elles sont bornées, et
  mesurées sur le texte *affiché* — « 7 200 000 F » est deux fois plus large que le 7200000 que la
  case contient.
- **Rien n'était prévu pour l'impression** d'un classeur qui finit imprimé : paysage, ajusté à une
  largeur de page, ligne d'en-tête répétée, période et pagination en en-tête et pied.

> **On ne totalise pas tout ce qui est un nombre**, d'où `Etat::$cumulables`. La somme des prix au
> kilo, celle des poids de camion vide et celle des soldes successifs d'un grand livre sont des
> chiffres que rien ne décrit — et un total faux posé en bas d'un classeur ne se signale jamais.

> **L'en-tête n'a plus de fond bleu**, ni ici ni sur le document imprimé. Un bandeau plein sur toute
> la largeur est un décor d'écran posé sur une grille : il vide une cartouche à l'impression, il
> pèse plus lourd que les chiffres qu'il annonce, et il résiste au thème sombre comme au mode
> contraste. Du gras et un filet suffisent.

### Le balayage de cohérence du 26/11/2026

Repassé après la refonte des exports, en douze contrôles mécaniques. **253 tests, 1 531
assertions, 8 ignorés, OK.** Le schéma Doctrine est en phase avec les entités, les conteneurs des
deux applications se lintent, les 180 gabarits Twig sont valides et aucun gabarit cité n'est
absent. Les 114 routes déclarées couvrent les 93 citées — **aucune référence morte** — et les 53
attributs de droit interrogés par le web existent tous dans la table (les 7 jamais interrogés sont
ceux déjà consignés plus haut).

**Le document et le classeur disent la même chose, et ce n'était pas acquis.** Ils partagent la
requête, donc les lignes ; pas les totaux. Le document les compose avec `Impression::$total`, qui
lit les champs BRUTS de l'API ; le classeur additionne les cases RENDUES par `Etat::$valeur`. Deux
chemins pour un même chiffre, et personne ne met jamais les deux fichiers côte à côte. Les onze
colonnes totalisées des trois états tombent au franc près, et les pieds de page se recoupent :
43 870 000 + 482 000 − 1 020 000 − 9 500 276 = 33 831 724, exactement le total « Reste à payer ».

**Le relevé d'entreprise se recoupe avec les trois bilans**, jusqu'à l'invariant qui compte : la
somme des cinq soldes de caisse au jour du tirage (102 003 224 F) est exactement la variation du
grand livre, entrées moins sorties. Les achats par poste retombent sur le total des pesées, et
« Acheté » vaut bien le dû plus le transport.

**Le cloisonnement tient sur le chemin des pièces.** Vérifié sur trois rôles et trois pièces :
l'administrateur lit tout ce qui est à son entreprise, l'opérateur est refusé (403) sur le
bordereau d'une recharge d'entreprise mais lit les pièces des versements, et un compte d'une AUTRE
entreprise est refusé sur les trois. Le nom de fichier stocké ne sort d'aucune charge.

**Les douze invariants SQL tiennent — un échouait, et la faute était dans les fixtures.** Le
versement de 3 000 000 F du décor (la pesée T-4502 « en vol ») dépassait de 760 000 F ce que la
pesée accepte. Son aide `verser()` versait la récolte jusqu'à son payable puis jetait TOUT le reste
sur le transport, **sans regarder ce que le transport devait** : 1 000 000 F de part transport sur
une pesée qui en doit 240 000. L'application, elle, refuserait ce versement —
`PaiementService::exigerUnMontantTenable()` plafonne chaque part. Le décor naissait donc dans un
état que le code interdit, ce qui faisait échouer tout contrôle passé sur la base de développement.

**Corrigé le 26/11/2026, en trois morceaux.** `verser()` plafonne désormais CHAQUE part et
**refuse** — `LogicException` — un montant qui dépasse ce qui reste payable, au lieu de l'écrêter
en silence : rogner aurait caché la faute de celui qui écrit le décor, exactement comme la cascade
le faisait. Ce qui est déjà engagé est suivi dans `$engage`, indexé par pesée, comme les comptes
d'épargne : on ne peut pas le relire sur l'entité, puisque `montantpaye` ne compte que le confirmé
et que la collection de versements reste vide avant l'écriture. Et l'appel demande maintenant
`$enVol->getMontantpayable()` plutôt qu'un nombre rond écrit à la main, qui se périmait au premier
changement de poids ou de prix. Le garde-fou a été éprouvé en remettant 3 000 000 F : le
chargement s'arrête sur « Décor intenable : 3000000 F versés sur la pesée T-4502, qui n'en accepte
plus que 2240000 (récolte 2000000, transport 240000) ».

> **La base de développement garde l'ancienne ligne** : le correctif est dans le code, pas dans les
> données déjà écrites. Elle se résorbe à un `doctrine:fixtures:load`, qui efface aussi les essais
> en cours.

> **`php -S` ne lit pas les `.htaccess`**, donc `/documents/sonde.txt` répond 200 en développement
> là où Apache rendrait 403 — ce que le fichier dit lui-même. La seconde barrière tient : pas de
> listage du dossier (le contrôleur frontal rend 404) et le nom stocké, aléatoire sur 128 bits, ne
> figure dans aucune charge d'API. En production sous Apache ou avec la règle Nginx, le dossier est
> fermé.

**Trois champs servis et absents des modèles TypeScript** : `droitsSurCeCompte` et
`motifNonGerable` sur un compte, `libelleComplet` sur un barème. Les deux premiers sont lus côté
PHP par `UtilisateurController`, donc rien n'est cassé ; ils restent inatteignables depuis React le
jour où une table en aura besoin. Un commentaire de `gestion.model.ts` affirmait par ailleurs que
la grille de tarifs n'arrive jamais sur la liste des catégories, alors que l'API l'y sert
délibérément et que `templates/tarif/index.html.twig` la lit : corrigé.

### Le balayage de cohérence du 25/11/2026

Cinq contrôles mécaniques passés sur l'application entière, après la livraison des relevés. Ce qui
est propre : **aucune référence de route morte** (93 routes citées, 114 déclarées), **aucune chaîne
de droit inconnue de la table** (53 attributs interrogés par le web, tous valides), **aucun filtre
relayé mais inatteignable** — le bilan des pesées propose bien ses douze filtres, les helpers
`entite()` et `libre()` les construisent — et **les cinq invariants SQL tiennent** sur la base de
développement : `montant = produit + transport`, les deux livres chaînés (caisse et épargne),
montants strictement positifs, une cible et une seule par pièce jointe.

**Trois champs servis par l'API et absents des modèles TypeScript**, donc inatteignables côté
React : `prixModifiable` et `transportModifiable` sur une pesée, `nomAvecCode` sur un planteur. Ils
ont été déclarés. C'est la quatrième fois que ce contrôle trouve quelque chose — il mérite d'être
refait à chaque fois qu'un groupe `read:` bouge.

**Sept droits que le web n'interroge jamais**, et c'est à savoir sans être à corriger :

- les cinq `*_JOINDRE`. Le champ justificatif est affiché sans vérifier le droit de déposer — ça
  tient parce que tous les rôles qui atteignent ces formulaires l'ont, et **par coïncidence de la
  table**, pas par construction. Un rôle qui gagnerait `PAIEMENT_PAYER` sans `PAIEMENT_JOINDRE`
  verrait un champ qu'il ne peut pas remplir ; le message d'échec le dirait, mais après coup.
- `MOUVEMENTEPARGNE_VOIR` : aucun écran ne le teste. Le relevé d'un planteur lit pourtant le livre
  d'épargne — même coïncidence, et si elle cesse, le document échoue **bruyamment**, ce qui est le
  bon comportement.
- `USER_SUPPRIMER` : aucun écran n'offre de supprimer un utilisateur. Droit sans geste.

### Un poste bloqué refuse de payer, mais accepte d'être doté

Le blocage d'un poste **est** tenu par l'API, et il l'est bien : `PaiementService` le vérifie à ses
trois entrées — versement simple, versement groupé, retrait d'épargne — et `SynchronisationService`
à la sienne. Rien ne sort donc de la caisse d'un poste bloqué, et plus aucune pesée n'y remonte.

> La garde ne se cherche pas sur `StatutSite::BLOQUE`, qui n'apparaît qu'aux fixtures, mais sur
> **`Site::accepteLaSynchronisation()`**. Un `grep` sur le cas d'énumération conclut à tort qu'elle
> n'existe pas — c'est la méthode qui porte la règle, et c'est elle qu'il faut chercher.

Ce qui manque est l'autre sens : **`CaisseService::doterSite()` ne consulte pas le statut.** On peut
donc verser de l'argent dans une caisse gelée, où il restera immobilisé sans pouvoir en sortir. Le
seul garde-fou était le bouton « Doter » désactivé sur la fiche d'un poste — c'est-à-dire du décor :
un POST forgé passait.

**Rien n'a été changé**, parce que refuser une dotation est une règle d'argent et que cela se
décide : l'immobilisation est-elle un problème, ou dote-t-on justement un poste qu'on s'apprête à
débloquer ? En attendant, le nouvel écran de dotation **nomme** les postes bloqués dans sa liste
(« — bloqué ») plutôt que de les cacher ou de faire semblant de les interdire.

### Quatre notions d'iTransport repérées, non reprises
Relevées en analysant l'autre dépôt, hors périmètre des étapes livrées :

- **Tests dans une transaction annulée.** iTransport ouvre une transaction par cas et la rejette à
  la fin, au lieu de recharger le schéma. Ici la suite complète prend ~16 minutes ; c'est le levier
  le plus net sur ce chiffre.
- **Autocomplétion distante** pour les listes de planteurs. Le socle existe
  (`data-remote-select`, tom-select, déjà géré par le thème de formulaire), mais aucun écran ne s'en
  sert. Au-delà de quelques centaines de planteurs, une liste déroulante entière devient pénible.
- **Commande de sauvegarde** de la base, lancée depuis l'application.
- **Un risque de transaction dans `CaisseService`.** Repéré par comparaison, jamais reproduit :
  à vérifier avant d'y toucher plutôt qu'à corriger de confiance.

---

## 4. Quatre pièges qui ont coûté cher, et qui reviendront

Ils ne sont pas des tâches, mais ils se reproduiront sur n'importe quelle fonctionnalité de la
liste. Les deux premiers sont réparés ; les deux autres sont des habitudes à garder.

1. **Un formulaire refusé en 422 n'émet pas `turbo:load`.** Turbo remplace le corps de la page et
   n'émet que `turbo:render`. Tout module câblé sur le seul `turbo:load` mourait donc dès la
   première erreur de saisie — sur la page même où l'on vient de se tromper. Voir `assets/app.js` :
   les deux événements sont écoutés, avec un garde-fou pour ne pas câbler deux fois.
2. **L'option « Aucun » d'une liste déroulante était toujours `disabled`.** Le navigateur sautait
   alors sur la première option valide : un champ facultatif qu'on n'avait pas touché repartait avec
   une valeur que personne n'avait choisie. Voir `templates/form/form_theme.html.twig`.
3. **Le cache du sérialiseur ne se vide pas tout seul.** Ajouter un groupe à un getter sans
   `rm -rf var/cache/dev` (ou `test`) laisse le champ absent du JSON, sans aucune erreur. Perdu deux
   fois dans la même session.
4. **Un cas d'énumération retiré ne dort pas en base** (24/11). Retirer `TRANCHES` de
   `ModeTransport` et convertir les barèmes ne suffisait pas : les PESÉES avaient figé ce mode à
   côté de leur montant. À la première lecture, Doctrine lève « is not a valid backing value » et
   c'est TOUTE la file du guichet qui tombe en 502 — pas seulement les lignes concernées. Toute
   migration qui retire un cas doit balayer **chaque colonne** qui porte cette énumération, les
   copies figées sur les pesées comprises.
