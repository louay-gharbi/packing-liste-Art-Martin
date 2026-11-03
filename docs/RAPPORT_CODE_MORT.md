# Rapport — Code mort dans PackingList

> Analyse du code source au 27/10/2026, par **lecture uniquement** : aucun fichier n'a été modifié.
> Chaque élément a été vérifié en recoupant les routes (`public/index.php`), les vues réellement rendues par les contrôleurs, les formulaires et le JavaScript des écrans.

## 1. Résumé

| # | Code mort | Fichier | Taille | Risque si on le garde |
|---|---|---|---|---|
| 1 | Message « palette plus ouverte » jamais affiché | `ColisController.php` | 4 lignes | L'opérateur n'est pas prévenu |
| 2 | `showMoveForm()` | `ColisController.php` | ≈ 50 lignes | Pas de route, vue absente, pas de contrôle de site |
| 3 | `processColisScan()` (scan classique) | `ColisController.php` | ≈ 175 lignes | **Les tests vérifient ce chemin mort au lieu du chemin réel** |
| 4 | `HomeController` | `HomeController.php` | 6 méthodes | Pages de test restées en production |
| 5 | `ColisSearchService` + son test | `Services/`, `tests/` | ≈ 40 lignes | Test qui ne peut pas passer (TypeError) |
| 6 | Vues `colis/search.php`, `colis/search_v2.php` | `Views/colis/` | 2 fichiers | Confusion avec la vraie recherche |
| 7 | Fichiers vides | divers | 4 fichiers | Confusion |
| 8 | `generate_qr.php`, `Example_generate_qr.txt` | racine | 2 fichiers | Accessibles depuis le web |
| 9 | Bloc de synthèse dupliqué dans l'export Excel | `PackingListController.php` | ≈ 20 lignes | Calcul inutile, origine probable du bug de l'export PDF |

**Gain estimé du nettoyage :** environ 400 lignes et 10 fichiers en moins, avec des tests qui couvrent enfin le chemin de scan réellement utilisé.

## 2. Point corrigé depuis l'ancien audit

L'ancien audit ([rapport_audit_packinglist.md](../rapport_audit_packinglist.md), §3.3 et recommandation 10) signalait une branche inatteignable dans `processScanAjax()` : un `if ($activeParentId)` placé à l'intérieur d'un `if (!$activeParentId)`.

**Ce n'est plus le cas.** Le code actuel est correct ([ColisController.php:589-598](../src/Controllers/ColisController.php#L589-L598)) :

```php
$currentParentNumber = null;
if ($activeParentId) {                       // atteignable : groupage en cours
    $currentParent = Colis::findById((int)$activeParentId);
    $currentParentNumber = $currentParent ? $currentParent->package_number : null;
}
if ($packageNumber !== $currentParentNumber && Colis::packageNumberExists($packageNumber)) { ... }
```

Le parsing des QR a lui aussi été centralisé dans `Colis::parseQrPayload()` (recommandation 10, première partie). Cette recommandation peut être marquée comme **réalisée**.

## 3. Détail

### 3.1 Message d'avertissement jamais affiché

**Où :** [ColisController.php:62-69](../src/Controllers/ColisController.php#L62-L69) (`scanInterface`)

```php
if (!$activePalette || $activePalette->status !== 'open') {
    unset($_SESSION['active_scan_palette_id']);
    $activePalette = null;                                     // ← mis à null…
    if ($activePalette && $activePalette->status !== 'open') { // ← …donc toujours faux
        $this->flash(FLASH_WARNING, "La palette précédemment active n'est plus ouverte pour le scan.");
    }
}
```

**Pourquoi c'est mort :** la condition teste `$activePalette` juste après l'avoir mis à `null`.

**Effet :** quand la palette active a été fermée par quelqu'un d'autre, l'opérateur ne reçoit **aucun avertissement** : la palette disparaît simplement de l'écran.

**Action :** déplacer le test **avant** la mise à `null` pour que le message s'affiche.

### 3.2 `showMoveForm()` : sans route et sans vue

**Où :** [ColisController.php:412](../src/Controllers/ColisController.php#L412)

**Pourquoi c'est mort :**
- aucune route de [index.php](../public/index.php) n'appelle cette méthode. Le déplacement passe par une fenêtre modale et la route `POST /colis/move/{id}` vers `processMove()` ;
- elle rend la vue `colis/move_form.php`, qui **n'existe pas** (le dossier `src/Views/colis/` ne contient que `scan.php`, `search.php` et `search_v2.php`).

**Risque :** si une route était ajoutée par erreur, la page planterait. De plus, la méthode ne vérifie pas le site de l'utilisateur.

**Action :** supprimer la méthode.

### 3.3 Scan classique `processColisScan()` : routé mais inatteignable

**Où :** [ColisController.php:153](../src/Controllers/ColisController.php#L153), route `POST /colis/process-scan` ([index.php:130](../public/index.php#L130))

**Pourquoi c'est mort :**
- le formulaire de scan ([scan.php:318](../src/Views/colis/scan.php#L318)) n'a **pas d'attribut `action`** ;
- le JavaScript intercepte la soumission et appelle `/colis/process-scan-ajax` ([scan.php:547](../src/Views/colis/scan.php#L547)), traité par `processScanAjax()`.

`processColisScan()` (≈ 175 lignes) n'est donc jamais appelé par l'application. Il duplique la logique AJAX avec ses propres différences, notamment le traitement de la quantité (voir M7 dans [RAPPORT_PROBLEMES.md](RAPPORT_PROBLEMES.md)).

**⚠️ Piège important :** le test [tests/ColisGroupageTest.php:33-39](../tests/ColisGroupageTest.php#L33-L39) appelle `processColisScan()`. **Les tests du groupage vérifient le chemin mort, pas celui utilisé par les opérateurs.** Un bug dans `processScanAjax()` passerait inaperçu.

**Action :**
1. adapter `ColisGroupageTest` pour qu'il appelle `processScanAjax()` ;
2. puis supprimer `processColisScan()` et la route `/colis/process-scan`.

**Cas voisin, `processPaletteForScan()`** (activation de palette classique, route `POST /colis/process-palette-scan`) : le formulaire [scan.php:236](../src/Views/colis/scan.php#L236) a bien une `action`, mais le JavaScript l'intercepte aussi et appelle `/colis/process-palette-scan-ajax` ([scan.php:645](../src/Views/colis/scan.php#L645)). Cette méthode ne sert que si JavaScript est désactivé. Elle n'est pas morte au sens strict, mais elle fait doublon : **à garder ou supprimer selon que le mode sans JavaScript doit être supporté.**

### 3.4 `HomeController` : jamais routé, code de test

**Où :** [HomeController.php](../src/Controllers/HomeController.php)

| Méthode | Ce qu'elle fait |
|---|---|
| `index` | Rend le tableau de bord (doublon de `DashboardController`) |
| `loginPage` | Rend la page de connexion (doublon de `AuthController`) |
| `testDb` | Affiche « Test de Connexion à la Base de Données » |
| `greet` | Affiche « Bonjour, {nom} ! » |
| `redirectToHome` | Redirection de test |
| `jsonData` | Réponse JSON de test |

**Pourquoi c'est mort :** la classe est importée dans [index.php:36](../public/index.php#L36), mais **aucune route** ne l'utilise. La route `/` pointe vers `DashboardController`.

**Action :** supprimer le contrôleur et la ligne `use App\Controllers\HomeController;` dans `index.php`.

### 3.5 `ColisSearchService` : utilisé seulement par un test, et cassé

**Où :** [ColisSearchService.php:13](../src/Services/ColisSearchService.php#L13)

**Pourquoi c'est mort :** aucun contrôleur ni service ne l'utilise. Seul [tests/ColisSearchServiceTest.php](../tests/ColisSearchServiceTest.php) l'instancie.

**Problème supplémentaire :** il appelle `Colis::search()` avec trois arguments (`array, int, int`), alors que la vraie signature est `search(string $fulltextQuery, int $limit)`. Tout appel lève une **TypeError** : le test associé ne peut pas passer.

**Action :** supprimer le service et son test. La recherche active passe par `SearchService`.

### 3.6 Vues orphelines

| Fichier | Problème |
|---|---|
| [colis/search.php](../src/Views/colis/search.php) | Aucun contrôleur ne la rend. Elle pointe vers une route `/colis/search` qui n'existe pas ([lignes 106 et 115](../src/Views/colis/search.php#L106)) |
| [colis/search_v2.php](../src/Views/colis/search_v2.php) | Aucun contrôleur ne la rend |

La recherche active utilise `search/index.php` (rendue par `SearchController`).

**Action :** supprimer les deux fichiers.

### 3.7 Fichiers vides (0 octet)

- `src/Lib/Helpers.php`
- `src/Views/layouts/app.php`
- `src/Views/layouts/auth_layout.php`
- `src/Views/partials/_flash_messages.php`

**Action :** supprimer, après avoir vérifié qu'aucun `require` ne les inclut.

### 3.8 Fichiers d'essai à la racine

| Fichier | Contenu |
|---|---|
| [generate_qr.php](../generate_qr.php) | Génère un QR code « Hello world » (exemple de la librairie `endroid/qr-code`) |
| `Example_generate_qr.txt` | Copie du précédent |

**Risque :** le projet étant entièrement sous `htdocs`, `generate_qr.php` est **exécutable depuis le navigateur** (`/packinglist/generate_qr.php`).

**Action :** supprimer les deux fichiers.

### 3.9 Bloc de synthèse dupliqué dans l'export Excel des Packing Lists

**Où :** [PackingListController.php:505-546](../src/Controllers/PackingListController.php#L505-L546), méthode `exportColisToXlsx()`

```php
// Bloc 1 (lignes 505-524)
$totalQuantity = 0;
foreach ($colisList as $colis) {
    ... Palette::findById($colis->palette_id) ...   // si le code palette n'est pas déjà chargé
    $totalQuantity += (int)$colis->quantity;
}
$summaryData = [ ... ];

// Bloc 2 (lignes 526-546) : IDENTIQUE, recalcule tout et écrase $summaryData
$paletteCodes = [];
$totalQuantity = 0;
foreach ($colisList as $colis) { ... }
$summaryData = [ ... ];
```

**Pourquoi c'est mort :** le résultat du bloc 1 est écrasé immédiatement par le bloc 2, identique.

**Effets :**
- **calcul inutile** : la liste des colis est parcourue deux fois. Si le code palette n'est pas déjà chargé, chaque passage peut appeler `Palette::findById()` pour chaque colis, soit des requêtes SQL en double ;
- **origine probable du bug M1** : l'export PDF ([ligne 749](../src/Controllers/PackingListController.php#L749)) utilise `$summaryData` sans jamais le calculer, ce qui explique pourquoi son résumé affiche toujours 0. Ce bloc devait très probablement être **copié dans la méthode PDF**, mais il a été collé deux fois dans la méthode Excel.

**Action :** créer une seule méthode privée, par exemple `buildSummary(array $colisList): array`, appelée par les deux exports, en utilisant `(float)` au lieu de `(int)`. Cela :
- supprime la duplication ;
- corrige **M1** (résumé PDF à 0) ;
- corrige **M3** (quantités décimales tronquées dans l'export).

## 4. Autres restes à nettoyer (non bloquants)

- **Commentaires de copier-coller** : `// ... TOUTES LES AUTRES MÉTHODES (findById, create, etc.) RESTENT INCHANGÉES ...` dans [PackingList.php:38-39](../src/Models/PackingList.php#L38-L39) et [Palette.php:45-46](../src/Models/Palette.php#L45-L46).
- **Traces de débogage** : journaux de progression dans `QrCodeService` (« Début », « Objet créé »…), requête SQL complète journalisée à chaque affichage de la liste des palettes (`PaletteController::indexAll`), `console.log` dans `app.js`, blocs `// error_log("DEBUG …")` commentés dans `index.php` et `config.php`.

## 5. Ordre de nettoyage conseillé

1. **D'abord les tests** (§3.3) : faire pointer `ColisGroupageTest` sur `processScanAjax()` et supprimer `ColisSearchServiceTest`. Les tests vérifient alors le vrai comportement avant toute suppression.
2. **Puis le code du scan** : `processColisScan()` et sa route, `showMoveForm()`, puis la correction du message (§3.1).
3. **Puis l'export** (§3.9) : la méthode `buildSummary()` commune, qui corrige au passage M1 et M3.
4. **Enfin les fichiers** : `HomeController`, `ColisSearchService`, vues orphelines, fichiers vides, fichiers d'essai à la racine.

Après chaque étape, tester sur la base locale : un scan simple, un groupage, un déplacement, l'export Excel et l'export PDF d'une Packing List.
