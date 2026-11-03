# Plan d'implémentation — Module « Réception France »

> **Destinataire : agent IA d'exécution.**
> Ajoute une nouvelle fenêtre de **réception en France** des Packing Lists expédiées depuis la Tunisie.
> Mêmes conventions que les plans précédents : modèles statiques, `getPDO()`, requêtes préparées,
> `$this->authorize()`, `$this->flash()`, `Response::json()`, `Csrf::field()` / en-tête `X-CSRF-Token`,
> vues PHP + Bootstrap 5 dans le layout `layouts/admin_app.php`.
> **Ne modifier aucun comportement existant du flux de colisage Tunisie**, sauf les deux points listés explicitement (Tâches B.2 et B.3).
> **Règle de méthode : aucune tâche n'est terminée tant que son test fonctionnel (Phase F) n'a pas été exécuté et consigné.**

> Branche de travail : `feature/reception-france`. Un commit atomique par tâche.
> Livrable final : `RAPPORT_EXECUTION_reception_france.md` (même format que les rapports précédents).

---

## 1. Contexte et décisions validées

Flux actuel (Tunisie) : Packing List `open` → palettes `generated → open → closed` → colis scannés sur les palettes → PL `closed` (= expédiée). Rien n'existe après la fermeture.

Décisions validées avec le métier (2026-10-30) :

| Sujet | Décision |
|---|---|
| Niveau de scan | **Palettes puis colis** : on scanne chaque palette (arrivée), puis on peut scanner les colis pour un contrôle détaillé. |
| Écarts | **Rapport d'écarts** : attendu vs reçu en temps réel ; à la clôture, rapport listant manquants / inattendus / endommagés + commentaire libre. La clôture n'est **pas** bloquée par les écarts. |
| Endommagé | Une palette ou un colis peut être marqué **endommagé** avec un commentaire. |
| Accès | **Nouveau site France** + nouvelles permissions `reception_*`. Les utilisateurs France voient les PL fermées **de tous les sites tunisiens**. |
| Ergonomie | Même UX que `colis/scan.php` (douchette, champ à focus permanent, toasts, flash rouge sur erreur, AJAX). |

---

## 2. Règles métier (référence pour toutes les tâches)

**R1 — PL éligible** : seule une PL `status = 'closed'` peut être réceptionnée. Une seule réception par PL (`UNIQUE packing_list_id`).

**R2 — Statuts de réception** : `in_progress` (démarrée au premier accès « Démarrer la réception ») → `closed` (clôturée avec rapport). Une PL sans ligne dans `receptions` est « À réceptionner ».

**R3 — Palettes attendues** : `palettes WHERE packing_list_id = PL AND status = 'closed'` (les palettes vides sont supprimées à la fermeture de la PL, cf. `PackingList::close()`).

**R4 — Colis attendus** : colis de premier niveau des palettes attendues (`parent_colis_id IS NULL`). Un colis parent (groupage) couvre ses enfants.

**R5 — Scan d'une palette** :
- palette de la PL → marquée reçue et devient la **palette active** (session) ;
- déjà reçue → avertissement (409), pas de doublon, elle redevient active ;
- palette existante mais d'une **autre PL** → enregistrée comme **inattendue** (`is_unexpected = 1`) + toast d'avertissement ;
- code inconnu → l'entrée est traitée comme un colis (R6).

**R6 — Scan d'un colis** (payload QR complet *ou* simple numéro de colis) :
- extraire `package_number` via `Colis::parseQrPayload()` ; si le parsing échoue, utiliser la chaîne brute comme `package_number` ;
- colis trouvé et appartenant à la PL → reçu ; **si sa palette n'a pas encore été scannée, elle est marquée reçue automatiquement** ;
- colis enfant → il compte pour son parent (le parent est considéré reçu) ;
- colis appartenant à la PL mais à une palette différente de la palette active → reçu + toast d'avertissement « trouvé sur la palette X au lieu de Y » (information seulement) ;
- colis existant d'une autre PL, ou inconnu en base → enregistré **inattendu** ;
- déjà scanné dans cette réception → 409, pas de doublon.

**R7 — Calcul des écarts** (à la clôture et en temps réel) :
- **Palette manquante** : palette attendue jamais scannée (directement ou via un de ses colis). Tous ses colis sont manquants.
- **Contrôle détaillé** : une palette reçue dont **au moins un colis** a été scanné est « contrôlée en détail » ; ses colis non scannés sont alors **manquants**.
- Une palette reçue sans aucun colis scanné est « reçue globalement » : ses colis sont considérés reçus (pas d'écart colis).
- **Inattendus** : lignes `is_unexpected = 1`.
- **Endommagés** : lignes `condition = 'damaged'`.

**R8 — Réception clôturée** : plus aucun scan ni modification ; consultation du rapport uniquement.

**R9 — Verrou Tunisie** : une PL ayant une réception (en cours ou clôturée) **ne peut plus être réouverte** (sinon les palettes/colis attendus changeraient sous les pieds de la réception).

---

## Phase A — Base de données

### Tâche A.1 — Migration `database/migrations/2026_10_30_reception_france.sql`

À appliquer manuellement après sauvegarde (même procédure que `database/migrations/README.md`, à mettre à jour avec une ligne pour ce script).

```sql
-- 1. Type de site : distinguer les sites de production (Tunisie) du site de réception (France)
ALTER TABLE sites
  ADD COLUMN type ENUM('production','reception') NOT NULL DEFAULT 'production' AFTER address;

INSERT INTO sites (code, name, address, type, is_active)
VALUES ('FR', 'Réception France', NULL, 'reception', 1)
ON DUPLICATE KEY UPDATE type = 'reception';

-- 2. Réceptions (une par PL)
CREATE TABLE receptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  packing_list_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED DEFAULT NULL COMMENT 'Site de réception (France)',
  status ENUM('in_progress','closed') NOT NULL DEFAULT 'in_progress',
  started_at DATETIME NOT NULL,
  started_by_user_id INT UNSIGNED DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  closed_by_user_id INT UNSIGNED DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reception_pl (packing_list_id),
  KEY idx_reception_status (status),
  CONSTRAINT fk_reception_pl FOREIGN KEY (packing_list_id) REFERENCES packing_lists (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reception_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_reception_started_by FOREIGN KEY (started_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_reception_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Réceptions France des Packing Lists';

-- 3. Palettes scannées en réception
CREATE TABLE reception_palettes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reception_id INT UNSIGNED NOT NULL,
  palette_id INT UNSIGNED NOT NULL,
  is_unexpected TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = palette d''une autre PL',
  is_auto TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = reçue implicitement via le scan d''un de ses colis',
  `condition` ENUM('ok','damaged') NOT NULL DEFAULT 'ok',
  comment TEXT DEFAULT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rp (reception_id, palette_id),
  CONSTRAINT fk_rp_reception FOREIGN KEY (reception_id) REFERENCES receptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_palette FOREIGN KEY (palette_id) REFERENCES palettes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rp_user FOREIGN KEY (scanned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Colis scannés en réception
CREATE TABLE reception_colis (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reception_id INT UNSIGNED NOT NULL,
  colis_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = colis inconnu en base',
  package_number VARCHAR(255) NOT NULL,
  reception_palette_id INT UNSIGNED DEFAULT NULL COMMENT 'Palette active au moment du scan',
  is_unexpected TINYINT(1) NOT NULL DEFAULT 0,
  `condition` ENUM('ok','damaged') NOT NULL DEFAULT 'ok',
  comment TEXT DEFAULT NULL,
  raw_payload TEXT DEFAULT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rc (reception_id, package_number),
  KEY idx_rc_colis (colis_id),
  CONSTRAINT fk_rc_reception FOREIGN KEY (reception_id) REFERENCES receptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_colis FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE SET NULL,
  CONSTRAINT fk_rc_rpalette FOREIGN KEY (reception_palette_id) REFERENCES reception_palettes (id) ON DELETE SET NULL,
  CONSTRAINT fk_rc_user FOREIGN KEY (scanned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Permissions + attribution au rôle Administrateur (Auth::can n'a PAS de bypass admin)
INSERT IGNORE INTO permissions (name, description) VALUES
  ('reception_view',  'Voir les réceptions France et leurs rapports d''écarts'),
  ('reception_scan',  'Démarrer une réception et scanner palettes/colis en France'),
  ('reception_close', 'Clôturer une réception France');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.name IN ('reception_view','reception_scan','reception_close')
WHERE r.name = 'Administrateur';
```

> Note : `condition` est un mot réservé MySQL → toujours l'écrire entre backticks dans le SQL applicatif.
> La création d'un rôle « Opérateur Réception France » et le rattachement des utilisateurs français au site `FR` se font ensuite **par l'interface** (Rôles / Utilisateurs), pas dans la migration.

---

## Phase B — Modèles et ajustements de l'existant

### Tâche B.1 — `src/Models/Reception.php` (nouveau)

Classe statique, même style que `Palette` / `PackingList` (propriétés typées, `hydrate()` privé, `try/catch PDOException` + `error_log`). Méthodes :

| Méthode | Rôle |
|---|---|
| `findById(int)`, `findByPackingListId(int)` | Lecture. |
| `start(int $plId, ?int $userId, ?int $siteId): Reception\|false` | Vérifie R1 (PL `closed`), insère ; si la réception existe déjà, la retourne (idempotent, gérer le `23000`). |
| `close(int $id, ?int $userId, ?string $comment): bool` | `UPDATE ... WHERE status = 'in_progress'`. |
| `getEligiblePackingLists(array $filters): array` | PL fermées **tous sites** (pas de filtre `Auth::user()->site_id`, cf. C.1) + `LEFT JOIN receptions` ; renvoie référence, site d'origine, date d'expédition, nb palettes, nb colis, statut de réception, progression. Filtres : `reception_status` (`todo` / `in_progress` / `closed`), `site_id`, période sur `ship_date`. Par défaut : PL fermées depuis 90 jours + toutes les réceptions en cours. |
| `scanPalette(Reception, Palette, ?int $userId, bool $auto = false): array` | Applique R5 ; renvoie `['status' => 'received'\|'duplicate'\|'unexpected', 'reception_palette_id' => …]`. |
| `scanColis(Reception, string $packageNumber, ?string $rawPayload, ?int $activeReceptionPaletteId, ?int $userId): array` | Applique R6 (dont réception automatique de la palette, `is_auto = 1`) ; renvoie statut + infos d'affichage (palette d'appartenance, avertissement de palette différente). Toute la séquence dans **une transaction**. |
| `setCondition(int $receptionId, string $kind /* palette\|colis */, int $lineId, string $condition, ?string $comment): bool` | Marquer endommagé / remettre ok. Refuse si réception `closed`. |
| `getProgress(Reception): array` | Compteurs : palettes attendues / reçues, colis attendus / scannés, inattendus, endommagés. |
| `computeDiscrepancies(Reception): array` | Applique R7 ; renvoie `missing_palettes`, `missing_colis` (groupés par palette), `unexpected_palettes`, `unexpected_colis`, `damaged`. Utilisé par l'écran de scan **et** le rapport. |

Requête de référence pour « colis de premier niveau reçus » (un enfant scanné couvre son parent, R4/R6) :

```sql
SELECT c.id
FROM colis c
JOIN palettes p ON p.id = c.palette_id
WHERE p.packing_list_id = :pl AND p.status = 'closed' AND c.parent_colis_id IS NULL
  AND EXISTS (
    SELECT 1 FROM reception_colis rc
    WHERE rc.reception_id = :rid
      AND (rc.colis_id = c.id OR rc.colis_id IN (SELECT ch.id FROM colis ch WHERE ch.parent_colis_id = c.id))
  )
```

### Tâche B.2 — `src/Models/Site.php` : type de site

- Ajouter la propriété `public string $type = 'production';` + hydratation.
- Ajouter `getAllActiveProduction(): array` (`WHERE is_active = TRUE AND type = 'production'`).
- Dans `PackingListController` : remplacer `Site::getAllActive()` par `Site::getAllActiveProduction()` **pour la création de PL (ligne ~53) et le filtre de liste (ligne ~79)**, afin que le site France ne soit jamais proposé comme site de production. Ne pas toucher aux autres appels (utilisateurs, dashboard, sites).
- Formulaire Sites (`SiteController` create/edit + vues `sites/`) : ajouter un `<select>` « Type » (Production / Réception), avec `production` par défaut.

### Tâche B.3 — Verrou de réouverture (R9)

Dans `PackingList::reopen()`, avant l'`UPDATE` : si `Reception::findByPackingListId($id)` n'est pas null → `return false`.
Dans `PackingListController::reopenPl`, message flash explicite : « Cette Packing List est en cours de réception / réceptionnée en France : réouverture impossible. »
Dans les vues `packing_lists/index.php` et `show.php`, masquer le bouton « Réouvrir » dans ce cas.

---

## Phase C — Contrôleur et routes

### Tâche C.1 — `src/Controllers/ReceptionController.php` (nouveau)

- Constructeur : `parent::__construct(); Auth::protect();`
- **Cloisonnement** : les écrans de réception **ne doivent pas** appeler `denyIfNotAccessible()` sur la PL (un utilisateur du site FR n'a pas accès aux PL tunisiennes par `isAccessibleBy`). L'accès est contrôlé **uniquement par les permissions `reception_*`**. Documenter ce choix en commentaire en tête de classe.
- Session : palette active stockée par réception, `$_SESSION['reception_active_palette'][$receptionId] = $receptionPaletteId`.

| Méthode | Permission | Comportement |
|---|---|---|
| `index` | `reception_view` | Liste des PL éligibles (B.1 `getEligiblePackingLists`) avec filtres. |
| `start($plId)` (POST) | `reception_scan` | `Reception::start()` avec `site_id = Auth::user()->site_id` ; redirige vers `scan`. |
| `scan($id)` | `reception_scan` | Écran de scan ; si réception `closed` → redirige vers `report`. |
| `processScanAjax($id)` (POST AJAX) | `reception_scan` | Champ unique `scan_input`. Ordre de résolution : 1) `Palette::findByCode()` → R5 ; 2) sinon colis → R6. Réponse JSON : `success`, `type` (`palette`/`colis`), `result` (`received`/`duplicate`/`unexpected`/`auto_palette`), `message`, `warning` éventuel, `progress` (B.1 `getProgress`), `active_palette` (code + compteurs). Codes HTTP : 200 ok, 409 doublon, 422 inattendu (**enregistré quand même** → le JS affiche un avertissement orange, pas le flash rouge), 400 entrée vide / réception clôturée. |
| `setConditionAjax($id)` (POST AJAX) | `reception_scan` | Endommagé / ok + commentaire sur une ligne palette ou colis. |
| `deactivatePalette($id)` (GET) | `reception_scan` | Vide la palette active. |
| `close($id)` (POST) | `reception_close` | Commentaire facultatif ; confirmation JS affichant le résumé des écarts ; redirige vers `report`. |
| `report($id)` | `reception_view` | Rapport (D.3). |
| `exportReportXlsx($id)` | `reception_view` | Export du rapport via PhpSpreadsheet (déjà utilisé par `PackingListController::exportColisToXlsx`, réutiliser le même style + `ExportSanitizer`). |

### Tâche C.2 — Routes (`public/index.php`)

Ajouter `use App\Controllers\ReceptionController;` et, après le bloc « SITES » :

```php
// --- ROUTES POUR LA RÉCEPTION FRANCE ---
$router->get('/receptions', [ReceptionController::class, 'index']);
$router->post('/receptions/start/{plId}', [ReceptionController::class, 'start']);
$router->get('/receptions/scan/{id}', [ReceptionController::class, 'scan']);
$router->post('/receptions/{id}/scan-ajax', [ReceptionController::class, 'processScanAjax']);
$router->post('/receptions/{id}/condition-ajax', [ReceptionController::class, 'setConditionAjax']);
$router->get('/receptions/{id}/deactivate-palette', [ReceptionController::class, 'deactivatePalette']);
$router->post('/receptions/close/{id}', [ReceptionController::class, 'close']);
$router->get('/receptions/report/{id}', [ReceptionController::class, 'report']);
$router->get('/receptions/{id}/export/xlsx', [ReceptionController::class, 'exportReportXlsx']);
```

Mettre à jour `database/migrations/INVENTAIRE_POST.md` avec les 4 nouvelles routes POST (toutes couvertes par le CSRF central).

### Tâche C.3 — Menu (`src/Views/partials/_admin_sidebar.php`)

Nouvelle section, après « Gestion Colisage » :

```php
<?php if (App\Lib\Auth::can('reception_view') || App\Lib\Auth::can('reception_scan')): ?>
    <li class="nav-section-title">Réception France</li>
    <li><a href="<?= htmlspecialchars($baseUrl) ?>/receptions" class="<?= isActiveLink('/receptions', $activePath) ?>"><i class="fas fa-truck-loading"></i> <span>Réceptions</span></a></li>
<?php endif; ?>
```

---

## Phase D — Vues (`src/Views/receptions/`)

### Tâche D.1 — `index.php` : liste des PL à réceptionner

Table DataTables (classe `datatable`, comme `packing_lists/index.php`) :
Référence PL · Site d'origine · Date d'expédition · Nb palettes · Nb colis · **Statut réception** (badge : `À réceptionner` gris, `En cours` orange avec barre de progression palettes, `Réceptionnée` vert ou `Réceptionnée avec écarts` rouge) · Actions (`Démarrer` [POST + CSRF] / `Continuer` / `Rapport`).
Filtres en haut : statut de réception, site d'origine (sites `production`), période.

### Tâche D.2 — `scan.php` : fenêtre de réception

Reprendre la structure et le CSS de `colis/scan.php` (toasts, overlay rouge, focus permanent, spinner, AJAX `X-CSRF-Token`). **Ne pas inclure** ce fichier : copier les blocs nécessaires (les deux écrans évolueront séparément).

Mise en page :
1. **En-tête** : référence PL, site d'origine, date d'expédition, statut ; à droite les compteurs `Palettes x/y` · `Colis x/y` · `Inattendus n` · `Endommagés n`.
2. **Champ de scan unique** (grand, centré, focus permanent) : « Scanner une palette ou un colis… ». Soumission sur Entrée.
3. **Bandeau palette active** (vert) : code, `colis contrôlés x / y`, bouton « Désactiver ».
4. **Tableau des palettes attendues** (mis à jour en AJAX sans reload) : Code · Colis · Statut (`Manquante` gris / `Reçue` vert / `Reçue (auto)` vert clair / `Endommagée` rouge) · Contrôle (`global` ou `détaillé x/y`) · bouton « ⚠ Endommagé » (modale commentaire). Ligne de la palette active surlignée. Clic sur une palette → déplie la liste de ses colis avec leur état.
5. **Bloc « Inattendus »** (palettes et colis), visible seulement s'il y en a.
6. **Derniers scans** (5 derniers, comme l'écran Tunisie).
7. **Bouton « Clôturer la réception »** (si `reception_close`) → modale : résumé des écarts (appel à `computeDiscrepancies` rendu côté serveur dans la page, rafraîchi à l'ouverture) + champ commentaire + confirmation.

Retours visuels/sonores :
- reçu → flash vert + toast vert ;
- doublon (409) → toast orange, pas de flash rouge ;
- inattendu (422) → toast orange persistant 5 s + ligne ajoutée au bloc Inattendus ;
- erreur (400/500) → flash rouge + secousse (comportement `triggerErrorFlash` existant).

### Tâche D.3 — `report.php` : rapport de réception

- En-tête : PL, site d'origine, date d'expédition, réception démarrée par / le, clôturée par / le, commentaire.
- Synthèse : palettes attendues/reçues, colis attendus/reçus, nb d'écarts par type.
- Sections : **Palettes manquantes** · **Colis manquants** (groupés par palette, avec OF / SKU / quantité depuis `colis`) · **Inattendus** · **Endommagés** (avec commentaires).
- Si aucun écart : bandeau vert « Réception conforme ».
- Boutons : `Exporter XLSX`, `Imprimer` (`window.print()`, CSS `@media print` qui masque sidebar et boutons).

### Tâche D.4 — Visibilité côté Tunisie (lecture seule)

Dans `packing_lists/show.php`, si une réception existe pour la PL : badge « Réception France : En cours / Réceptionnée / Réceptionnée avec écarts » + lien vers le rapport si l'utilisateur a `reception_view`.

---

## Phase E — Données de recette locale

Sur la base locale `packing_list_db` (124 PL fermées existantes) :
1. Sauvegarde : `mysqldump packing_list_db > database/backup_pre_reception_20261030.sql`.
2. Appliquer la migration A.1, vérifier `DESCRIBE sites`, `SHOW CREATE TABLE receptions`, et la présence des 3 permissions sur le rôle Administrateur.
3. Créer via l'interface un utilisateur de test rattaché au site `FR` avec un rôle « Opérateur Réception France » (`reception_view`, `reception_scan`, `reception_close`).

---

## Phase F — Tests fonctionnels (à exécuter et consigner)

| # | Scénario | Résultat attendu |
|---|---|---|
| F1 | Utilisateur FR ouvre `/receptions` | Voit les PL fermées de **BK1, BK2, BK3, BK4**. Ne voit pas le menu Packing Lists s'il n'a pas les permissions. |
| F2 | Création d'une PL côté Tunisie | Le site `FR` n'apparaît **pas** dans la liste des sites. |
| F3 | Démarrer une réception sur une PL `open` (URL forcée) | Refusé (R1). |
| F4 | Démarrer deux fois la même PL (double clic / deux onglets) | Une seule ligne `receptions`. |
| F5 | Scanner toutes les palettes d'une PL, sans colis | Palettes `Reçue`, contrôle « global », clôture → rapport « conforme ». |
| F6 | Ne pas scanner 1 palette, clôturer | Rapport : 1 palette manquante + ses colis manquants. |
| F7 | Scanner 1 palette puis 2 de ses 5 colis, clôturer | Palette « détaillé 2/5 » ; rapport : 3 colis manquants sur cette palette. |
| F8 | Scanner directement un colis sans avoir scanné sa palette | Colis reçu + palette `Reçue (auto)`. |
| F9 | Scanner un colis enfant d'un groupage | Le parent compte comme reçu. |
| F10 | Scanner une palette d'une autre PL | Enregistrée inattendue (422, toast orange), visible dans le rapport. |
| F11 | Scanner un numéro de colis inconnu | Colis inattendu `colis_id NULL`. |
| F12 | Rescanner une palette / un colis | 409, aucun doublon en base. |
| F13 | Colis de la PL scanné alors qu'une autre palette est active | Reçu + avertissement « trouvé sur la palette X au lieu de Y ». |
| F14 | Marquer une palette et un colis endommagés avec commentaire | Visibles dans le rapport et l'export XLSX. |
| F15 | Scan / endommagé après clôture | Refusé (R8). |
| F16 | Réouvrir côté Tunisie une PL en réception | Refusé avec message (R9) ; bouton masqué. |
| F17 | POST sans jeton CSRF sur `/receptions/{id}/scan-ajax` | 403 JSON `reload: true`. |
| F18 | Utilisateur sans `reception_*` sur `/receptions` | Redirection + flash « Accès non autorisé ». |
| F19 | Écran de scan sur mobile / douchette | Focus permanent, soumission sur Entrée, mise en page lisible à 375 px. |
| F20 | Export XLSX du rapport | Fichier ouvert sans erreur, cellules nettoyées par `ExportSanitizer`. |

---

## Hors périmètre (à valider plus tard)

- Rattacher les colis reçus à un emplacement de stockage France.
- Notifications (e-mail) à la Tunisie en cas d'écart.
- KPI « réception » sur le tableau de bord.
- Réouverture d'une réception clôturée (nécessiterait une permission `reception_reopen`).
