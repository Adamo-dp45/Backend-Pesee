# Ce qui reste à faire

Écrit le 19/11/2026, à la fin de la session qui a livré les barèmes de transport. Il complète les
deux README : eux disent ce que l'application **fait**, celui-ci ce qu'elle ne fait **pas encore**,
et ce qu'il faut avoir tranché avant d'y toucher.

---

## 1. Où on en est

Sur les neuf fonctionnalités listées en estimation, **quatre sont livrées** :

| # | Fonctionnalité | Livrée |
|---|---|---|
| 1 | Desktop réduit à la pesée — toute la tarification sur le web | oui |
| 2 | Produit et transport payés séparément, chacun en plusieurs fois | oui |
| 6 | Classes de planteurs, prix par classe, prix planteur prioritaire | oui — **liste plate**, pas de hiérarchie |
| 8 | Transport : au kilo, au forfait, ou par tranches de kilomètres | oui |

Deux écarts avec la liste d'origine, décidés en cours de route :

- **La #6 n'est pas hiérarchique.** Une catégorie n'a pas de parent : la résolution fait trois sauts
  (tarif du planteur, tarif de sa catégorie, prix du produit) et pas davantage. Un arbre ajouterait
  une récursion dans le chemin chaud de **toute** pesée pour un besoin que personne n'a formulé.
  Si la hiérarchie revient, c'est `TarifApplicable::pour()` qu'il faut rouvrir, et lui seul.
- **La #8 a tranché l'origine de la distance** — elle vit sur la fiche du planteur, en kilomètres.
  C'était la question qui bloquait l'estimation.

**La #7 — prix datés et historique consultable — est ABANDONNÉE** (décidé le 19/11/2026). Elle
aurait fait d'un prix un intervalle, et une pesée se serait valorisée au prix de SA date. Ce qu'il
faut en retenir : la revalorisation automatique reste ce qu'elle est, et elle n'a de sens que
parce qu'un prix n'a pas de date. Corriger un prix touche donc TOUTES les pesées non payées et
non arbitrées de ce produit, passées comprises. C'est voulu, pas un manque.

**Rien n'est commité** dans aucun des deux dépôts à la clôture de cette session.

---

## 2. Les quatre qui restent

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
qui regarde, comme partout ailleurs. Ce qui reste de la #3 est la lecture **par planteur** : « à qui
doit-on, et combien », qui n'a pas encore d'agrégat.

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

**Ce qui reste** : les filtres par période sur les autres écrans de liste (planteurs, produits,
journal, synchronisations), les colonnes et libellés métier à revoir écran par écran.

**Deux règles à ne pas perdre**, apprises en corrigeant des filtres qui ne rendaient rien :
- Un filtre dont la valeur ne correspond à rien côté API **vide la liste sans rien dire**, et une
  liste vide ressemble en tout point à un référentiel vide. Tout filtre neuf se vérifie en comparant
  la somme de ses valeurs au total sans filtre.
- Un tri hors de la liste blanche fait répondre l'API en erreur — ou pire, l'ignore en laissant
  croire au classement demandé.

**À trancher :** la liste des écrans restants. C'est elle qui décide du chiffre, pas la difficulté.

### #4 — Comptabilité : références et pièces jointes · **5 à 8 jours** · risque moyen

**Infrastructure neuve** : aucun mécanisme de fichier n'existe aujourd'hui dans les deux dépôts.
C'est le seul poste de la liste qui n'étend rien.

**À trancher AVANT de coder** — chacun peut déplacer le chiffre de plusieurs jours :
1. Où vivent les fichiers ? Disque du serveur, ou stockage objet ?
2. Qui a le droit de **lire** une pièce jointe ? Le cloisonnement par entreprise ne s'applique pas
   tout seul à un fichier servi par une URL.
3. Quels types, quelle taille, et combien de temps les garde-t-on ?
4. À quoi se rattache une pièce : un paiement, un mouvement de caisse, une demande ?

### #9 — Relevés imprimables pour les banques · **4 à 6 jours** · risque faible

L'export existe pour le bilan seul (PDF et Excel, par `ExportService`). Cinq états de plus, filtrés
par période, planteur et poste, avec leurs totaux.

**Le chemin est ouvert** depuis la session du 19/11/2026 : il y a désormais **trois** états
(pesées, versements, caisses), et ce qu'on exporte vit dans `Domain/Export/Etat` côté web —
colonnes, extraction d'une case, pied du document. Un état de plus est une fabrique statique de
plus : ni boucle de pagination à recopier, ni gabarit à écrire. Ce qui reste à décider n'est donc
plus technique, c'est la forme que les établissements attendent.

**Dépend de la #2**, qui est livrée : un relevé qui distingue les règlements de récolte des
règlements de transport suppose que l'application les distingue déjà. Elle le fait désormais —
`montantproduit` et `montanttransport` sur chaque paiement, avec l'invariant
`montant = montantproduit + montanttransport`.

**À trancher :** quels formats les établissements financiers attendent réellement. Tant que ce n'est
pas répondu, le chiffre est une supposition.

### Ce que ça fait au total

**15 à 24 jours**, soit trois à cinq semaines pour une personne. L'abandon des prix datés a
retiré 5 à 8 jours, et avec eux le seul poste à risque élevé qui restait : les quatre restants
ajoutent des écrans et des états, aucun ne rouvre une règle d'argent.

### L'ordre

Les quatre sont **indépendants** les uns des autres et se prennent dans l'ordre qui arrange. Le
seul enchaînement qui comptait — les prix datés d'abord, parce qu'ils auraient réécrit la
résolution des prix — est tombé avec eux (§1).

---

## 3. Ce qui est connu et pas corrigé

Signalé en son temps, laissé en l'état faute d'être dans le périmètre demandé.

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

## 4. Trois pièges qui ont coûté cher, et qui reviendront

Ils ne sont pas des tâches, mais ils se reproduiront sur n'importe quelle fonctionnalité de la
liste. Les deux premiers sont réparés ; le troisième est une habitude à garder.

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
