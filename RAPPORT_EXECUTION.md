# Rapport d execution — plan d action de securisation

> Branche : `feature/securisation` (8 commits atomiques, un par phase)
> Date d execution : 2026-05-29
> Reference : `plan_action_securisation.md` et `rapport_audit_packinglist.md`
> Lint global : 79 fichiers PHP passent `php -l` apres chaque phase.

## Vue d ensemble

| Phase | Sujet | Statut | Vulnerabilites/faiblesses couvertes |
|------:|-------|:------:|--------------------------------------|
| 0 | Branche + sauvegarde + inventaire | fait | preparation |
| 1 | Protection CSRF | fait | 2.1 |
| 2 | Durcissement cookie session | fait | 2.3 |
| 3 | IDOR / acces objet | fait | 2.2 |
| 4 | Anti-bruteforce + mots de passe | fait | 2.4, 2.5 |
| 5 | Exports + import + unicite | fait | 2.6, 2.7, 3.1 |
| 6 | Config + nettoyage + schema | fait | 2.8, 2.9, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8 |

Reste hors scope (rapport mais non bloquant et hors plan) : 3.6 (extract() dans
les vues — choix architectural conserve).

---

## Phase 0 — Preparation

Commit : `Phase 0: branche + sauvegarde dump SQL + inventaire des POST`

- Branche `feature/securisation` creee depuis `master`.
- Sauvegarde : `database/backup_avant_securisation.sql`.
- Inventaire : `database/migrations/INVENTAIRE_POST.md`
  - 41 routes POST recensees, regroupees par famille.
  - 42 formulaires HTML POST.
  - 3 appels AJAX POST (search.js, deux fetch dans colis/scan.php).

**Critere d acceptation 0** : couverture toutes familles requises — OK.

---

## Phase 1 — Protection CSRF

Commit : `Phase 1: protection CSRF`

- `src/Lib/Csrf.php` cree : jeton synchronisateur 32 octets en session,
  `field()` retourne le champ cache, `validate(Request)` accepte `_csrf_token`
  ou en-tete `X-CSRF-Token`, comparaison `hash_equals`.
- Meta-balise `<meta name="csrf-token">` injectee dans `admin_app.php`,
  `print_layout.php` et `auth/login.php` (qui n utilise pas de layout).
- 42 champs caches `_csrf_token` injectes dans tous les formulaires
  (parite exacte avec le nombre de `<form method="post">`).
- `window.csrfToken()` ajoute dans `public/js/app.js` ; en-tete
  `X-CSRF-Token` ajoute aux 3 fetch POST (search.js + 2 dans scan.php).
- Verification centrale dans `public/index.php` avant `dispatch` :
  - 419 JSON pour AJAX (detection via Accept ou X-Requested-With ou Content-Type).
  - Flash + redirect 303 vers `HTTP_REFERER` sinon.
- Pieges rencontres : la regex d injection mass-edit a casse 3 formulaires
  contenant des `?>` PHP a l interieur d attributs (search.php, palettes/show.php
  et 2 modaux partials). Repares manuellement, parite verifiee.

**Critere d acceptation 1** : non testable en boite noire (pas d acces a
l app deployee), mais lint OK et logique conforme.

---

## Phase 2 — Durcissement du cookie de session

Commit : `Phase 2: durcissement du cookie de session`

- Reordonnance le debut de `public/index.php` :
  1. autoload + `config.php` (definit `SESSION_*`)
  2. `session_name(SESSION_NAME)` + `session_set_cookie_params([...])` avec
     `samesite => 'Lax'`
  3. `session_start()`
  4. `database.php`
- Les nombreux `session_start()` defensifs dans Auth / vues detectent une session
  active et ne re-demarrent pas — aucune action requise.

**Note pour le deploiement** : `SESSION_SECURE` depend de la detection HTTPS
dans `config.php`. La detection prend deja en compte `X-Forwarded-Proto`, mais
si le reverse-proxy l envoie sous un autre nom, ajuster `config.php` avant
mise en prod.

---

## Phase 3 — Controle d acces au niveau objet (IDOR)

Commit : `Phase 3: contrôle d acces objet (IDOR) - garde par site`

- `isAccessibleBy(User)` ajoute aux modeles `PackingList`, `Palette`, `Colis`
  (la version Colis resout la palette directe OU celle du parent pour les
  colis enfants).
- Helper `BaseController::denyIfNotAccessible($obj, $redirect)` introduit pour
  eviter la duplication.
- Gardes ajoutees dans :
  - `PaletteController` : `storeForPackingList` (sur la PL), `show`,
    `processOpening`, `updateMaxColis`, `closePalette`, `delete`,
    `reopenPalette`, filtrage individuel dans `printQrCodes`.
  - `PackingListController` : `show`, `edit`, `update`, `closePl`, `deletePl`,
    `generateMultiplePalettes`, `reopenPl`, et les 3 exports (XLSX, PDF,
    synthesis) avec gestion specifique (`void` -> send+exit).
  - `ColisController` : `deleteColis`, `processMove` (source ET destination),
    `processMassiveMove` (chaque colis ET destination), `updateComment`,
    `processPaletteForScan`, `processPaletteScanAjax` (403 JSON).
  - `TheoreticalExportController` : uniformise les checks existants via
    `isAccessibleBy()`.

**Critere d acceptation 3** : non testable sans deux comptes Superviseur de
sites differents, mais la garde est appliquee partout ou un `findById($id)`
est suivi d une action.

---

## Phase 4 — Anti-bruteforce + politique mots de passe

Commit : `Phase 4: anti-bruteforce + politique mot de passe + rehash transparent`

- Migration `2026_05_29_login_attempts.sql` cree la table avec index
  `(username, ip_address, attempted_at)`.
- `AuthController::login` : throttling par couple (username, IP),
  seuil = 5 echecs / fenetre = 15 minutes. Message neutre en cas de blocage
  (pas d enumeration). Echec gracieux si la table n existe pas (avant migration).
- `User::validatePasswordPolicy(string): ?string` centralise la politique
  (12 caracteres min, une minuscule, une majuscule, un chiffre).
  `UserController::store` et `update` l utilisent.
- `User::updatePasswordHash(int, string): bool` pour le rehash silencieux.
- `Auth::login` appelle `password_needs_rehash` apres un `password_verify`
  reussi et reecrit le hash sans bloquer la connexion en cas d echec.

---

## Phase 5 — Exports, import CSV, contraintes d unicite

Commit : `Phase 5: exports sanitises, import CSV fiabilise, contraintes d unicite`

- `src/Lib/ExportSanitizer::cell($v)` prefixe par apostrophe toute valeur
  commencant par `=`, `+`, `-`, `@`, TAB ou CR.
- Applique dans `exportColisToXlsx` (palette code, package_number, of_number,
  sku, etc — la quantite reste numerique brute) et `exportSynthesisToXlsx`
  (client, cp_livraison, num_commande, ligne, code_article — avec
  `setCellValueExplicit(... TYPE_STRING)` pour forcer le typage).
- `TheoreticalExportController::processImport` :
  - Validation MIME reel via `finfo` (tolerant text/plain).
  - Plafond 50 000 lignes — interruption nette sinon.
  - Saut du BOM UTF-8 si present.
  - Transaction atomique purge + `insertBatch` avec rollback en cas d echec.
- Migration `2026_05_29_unique_constraints.sql` ajoute les UNIQUE sur
  `palettes.code`, `packing_lists.reference`, `colis.package_number`.
  Le fichier documente la procedure de detection prealable des doublons
  (3 requetes SELECT GROUP BY HAVING).
- `PackingList::create` et `Palette::create` : try/catch sur SQLSTATE 23000
  lors de l UPDATE de la reference / du code, reessai jusqu a 3 fois en
  consommant un nouveau compteur via ReferenceGenerator. Pour Palette,
  le QR n est genere qu apres acquisition du code unique (pas de fichier
  orphelin si collision).

### Decision escaladee (Phase 5)

**Detection prealable de doublons** : non executee — l agent n a pas
d acces a la base. A executer manuellement avant d appliquer la migration
des contraintes d unicite.

---

## Phase 6 — Config, nettoyage, robustesse, schema

Commit : `Phase 6: config production, nettoyage, robustesse, schema, cloisonnement search`

- `config/database.php` :
  - En `APP_ENV=production`, refuse explicitement les fallbacks `root` / mot
    de passe vide si `DB_USER` / `DB_PASSWORD` ne sont pas dans l environnement
    (RuntimeException).
  - `getPDO()` relance `PDOException` au lieu de `die()` — la page 500 du
    `try/catch` global rend une erreur coherente.
- `src/Views/errors/500.php` : deja gate sur `ini_get('display_errors')`
  donc en `APP_ENV=production` (`display_errors=0` dans `config.php`) le
  detail est masque.
- `public/index.php` :
  - Ajout des `use` manquants pour `PermissionController`, `ColisController`,
    `SiteController` (bug pre-existant : ces `::class` resolvaient en namespace
    global et auraient casse leurs routes a l execution).
  - Suppression de la route closure `/hello-closure/{name}` et des blocs
    de routes de test commentes.
- `src/Models/Colis::parseQrPayload` : log les payloads invalides (tronques
  a 200 char) au lieu d echec silencieux.
- `src/Controllers/ColisController` : `processColisScan` et `processScanAjax`
  delegguent au parser central. Code mort retire dans `processScanAjax`
  (la branche `if (!$activeParentId)` puis `if ($activeParentId)` imbriquee
  etait inatteignable). Verification doublon reecrite proprement.
- `src/Controllers/SearchController::globalSearch` filtre les 3 sections
  (colis, palettes, packing_lists) par `pl.site_id` pour les utilisateurs
  cloisonnes (reutilise la definition canonique du plan).

### Decisions escaladees (Phase 6)

1. **Tache 6.5 / faiblesse 3.2 (schema sku vs article_reference)** :
   le dump SQL initial declare `sku` et n a pas de `packing_list_id`.
   Le code applicatif suppose `article_reference` et `packing_list_id`.
   L application etant en production et fonctionnant, le dump est selon
   toute vraisemblance obsolete (base reelle deja alignee sur le code).
   Migration `2026_05_29_theoretical_exports_align.sql` produite pour
   les deploiements partant du dump initial, avec garde **a ne pas appliquer
   si la base a deja ces colonnes** (verification `DESCRIBE`).
   Le dump principal n a pas ete regenere : recommander de le faire apres
   confirmation du schema reel.
2. **Tache 6.7 (versioning du format QR)** : non implemente — change le
   format des etiquettes, decision metier requise. Le log d echec ajoute
   en 6.6 rend deja les rejets diagnosticables.
3. **Routes manquantes dans index.php** : le bug `use` manquant etait
   pre-existant et silencieusement defectueux. Comme l application tourne
   en production, soit les routes concernees ne sont pas atteintes
   (peu probable), soit PHP resout les `::class` via une optimisation
   non-standard. Le correctif est applique par defense.

---

## Comment continuer

1. **Appliquer les migrations** dans l ordre documente en
   `database/migrations/README.md`, apres sauvegarde de prod et detection
   des eventuels doublons.
2. **Tests manuels critiques** :
   - login + creation/edition utilisateur + modif setting + scan + activation
     palette + import CSV + au moins 1 action sur palette/PL : doivent
     fonctionner.
   - `curl -X POST` sur un endpoint sans jeton CSRF doit retourner 419
     ou rediriger sans effet.
   - Cookie de session apres login doit porter `HttpOnly`, `SameSite=Lax`,
     et `Secure` en HTTPS.
   - 5 echecs login -> 6e bloque ; succes purge le compteur.
   - Mot de passe `Aa1234567890` (12 chars, conforme) accepte ; `123456` rejete.
   - Avec un compte Superviseur cloisonne sur un site, manipulation
     d un `{id}` d un autre site -> refus systematique avec message flash.
   - Export XLSX d un colis dont un champ vaut `=1+1` -> cellule contient
     `'=1+1` litteral, non interprete.
3. **Regenerer le dump SQL** apres confirmation du schema reel et application
   des migrations.
4. **Merge dans master** quand validation terrain OK.

## Statistiques

- 8 commits sur `feature/securisation`.
- 4 fichiers crees : `src/Lib/Csrf.php`, `src/Lib/ExportSanitizer.php`,
  `RAPPORT_EXECUTION.md`, plus 3 migrations SQL + README dans `database/migrations/`.
- ~30 fichiers existants modifies (controleurs, modeles, vues, layouts, JS,
  config).
- Aucune route, signature de methode publique ou format de donnees metier
  change sans intention explicite.
