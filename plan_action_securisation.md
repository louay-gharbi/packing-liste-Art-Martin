# Plan d'action — Sécurisation de PackingList Pro

> **Destinataire : agent IA d'exécution.**
> Ce plan corrige l'ensemble des vulnérabilités et faiblesses identifiées dans le rapport d'audit.
> Il est **séquentiel** : l'ordre des phases est contraint par des dépendances. Ne pas réordonner.
> Chaque tâche fournit : objectif, fichiers concernés, modifications précises, pièges à éviter, et critères d'acceptation.

---

## Conventions et règles globales (à respecter sur TOUTES les tâches)

1. **Ne pas modifier le comportement métier existant.** Les corrections sont additives ou de durcissement. Aucune route, signature de méthode publique ou format de données métier ne doit changer sans que ce soit explicitement demandé ici.
2. **Respecter le style du code existant** : classes statiques pour les modèles, `getPDO()` pour la BDD, requêtes préparées, `$this->flash(FLASH_*, ...)`, `$this->redirect(...)`, `$this->json(...)`, `Response::json(...)`, échappement `htmlspecialchars()` dans les vues.
3. **API existante à réutiliser (ne pas réinventer)** :
   - `App\Lib\Request` : `->post($k,$d)`, `->get($k,$d)`, `->server($k,$d)`, `->getMethod()`, `->allPost()`.
   - `App\Lib\Auth` : `Auth::user()` (→ `?User`), `Auth::id()`, `Auth::isLoggedIn()`, `Auth::can($perm)`.
   - `App\Models\User` : propriétés `->id`, `->site_id` (`?int`), méthode `->hasRole('Administrateur')`.
   - `BaseController` : `authorize($perm,$redirect,$msg)`, `view()`, `flash()`, `redirect()`, `json()`.
   - Constantes : `FLASH_ERROR`, `FLASH_SUCCESS`, `FLASH_INFO`, `FLASH_WARNING`, `BASE_URL`, `SESSION_NAME`, `SESSION_LIFETIME`, `SESSION_SECURE`, `SESSION_HTTP_ONLY`.
4. **Définition canonique d'un « utilisateur cloisonné par site »** (à réutiliser à l'identique partout, pour ne PAS diverger de la logique de `PackingList::getAll()`) :
   ```php
   $isScoped = ($user->site_id !== null && !$user->hasRole('Administrateur'));
   ```
   Un utilisateur **non cloisonné** (admin global, ou sans `site_id`) voit/agit sur tous les sites.
5. **Migrations BDD** : l'application n'a pas de système de migration. Pour tout changement de schéma, créer un fichier `.sql` horodaté dans `database/migrations/` (à créer si absent). **L'agent ne doit pas exécuter ces scripts sur une base de production** ; il les fournit, documente la procédure, et les teste sur une base locale jetable si disponible.
6. **Après chaque phase**, exécuter la procédure de vérification de la phase avant de passer à la suivante. Si un test échoue, corriger avant d'avancer.
7. **Travailler sur une branche dédiée** `feature/securisation`. Commit atomique par tâche.

---

## Phase 0 — Préparation et inventaire (aucune modification fonctionnelle)

### Tâche 0.1 — Branche et sauvegarde
- Créer la branche `feature/securisation`.
- Sauvegarder le dump SQL actuel sous `database/backup_avant_securisation.sql`.

### Tâche 0.2 — Inventaire exhaustif des points POST (CRITIQUE pour la Phase 1)
La protection CSRF doit couvrir **tous** les points d'entrée modifiant l'état. Ne pas se fier à une liste mémorisée : générer la liste réelle.

- Lister toutes les routes POST :
  ```bash
  grep -rn "\$router->post(" public/index.php
  ```
- Lister tous les formulaires HTML POST :
  ```bash
  grep -rln "method=\"post\"" src/Views/
  ```
- Lister tous les appels JS modifiant l'état :
  ```bash
  grep -rn "method: 'POST'\|method: \"POST\"" public/js/ src/Views/
  ```
- **Produire un fichier `database/migrations/INVENTAIRE_POST.md`** consignant : route, fichier de vue/JS émetteur, type d'envoi (form classique / fetch form-urlencoded / fetch JSON). Ce fichier sert de checklist pour les tâches 1.3 et 1.4 ; chaque ligne devra être cochée.

**Critère d'acceptation 0** : l'inventaire couvre au minimum les familles suivantes (vérifier qu'aucune ne manque) : `/login`, `/users/*`, `/roles/*`, `/permissions/*`, `/settings/*`, `/packing-lists/*`, `/palettes/*`, `/colis/*`, `/search/*`, `/theoretical-exports/process-import`, `/sites/*`.

---

## Phase 1 — Protection CSRF (vulnérabilité 2.1)

> **PIÈGE MAJEUR À ÉVITER** : la vérification centrale (tâche 1.5) **doit être la toute dernière** de cette phase. Si elle est activée avant que tous les formulaires et appels AJAX émettent un jeton (tâches 1.3 et 1.4), **l'application entière renverra des erreurs sur chaque POST**. Respecter scrupuleusement l'ordre 1.1 → 1.2 → 1.3 → 1.4 → 1.5.

### Tâche 1.1 — Créer la classe `App\Lib\Csrf`
Créer `src/Lib/Csrf.php` :

```php
<?php
namespace App\Lib;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /** Garantit l'existence d'un jeton en session et le retourne. */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Champ caché prêt à insérer dans un <form>. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /** Vérifie une requête POST. Retourne true si valide. Comparaison à temps constant. */
    public static function validate(Request $request): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        $sent = $request->post('_csrf_token', '');
        if ($sent === '' || $sent === null) {
            // Fallback en-tête pour les requêtes AJAX
            $sent = $request->server('HTTP_X_CSRF_TOKEN', '');
        }
        return is_string($sent) && hash_equals($_SESSION[self::SESSION_KEY], $sent);
    }
}
```

**Pièges** :
- Ne PAS régénérer le jeton à chaque requête (cela invaliderait les onglets ouverts). Le jeton vit le temps de la session.
- Utiliser `hash_equals` (obligatoire), jamais `==`.

### Tâche 1.2 — Exposer le jeton dans les layouts
Pour les deux layouts (`src/Views/layouts/admin_app.php` et tout layout public/login), ajouter dans le `<head>` :
```php
<meta name="csrf-token" content="<?= htmlspecialchars(\App\Lib\Csrf::token(), ENT_QUOTES) ?>">
```
**Note** : la page de login (`src/Views/auth/login.php`) n'utilise peut-être pas le layout admin. Vérifier son `<head>` et y ajouter la meta-balise aussi, sinon le POST `/login` échouera en Phase 1.5.

### Tâche 1.3 — Injecter `Csrf::field()` dans TOUS les formulaires POST
Pour **chaque** vue listée dans l'inventaire 0.2 comme formulaire classique, insérer `<?= \App\Lib\Csrf::field() ?>` immédiatement après la balise `<form ... method="post">`.

Checklist non exhaustive (compléter avec l'inventaire) : `auth/login.php`, `users/create.php`, `users/edit.php`, `roles/*`, `permissions/*`, `settings/edit.php`, `sites/create.php`, `sites/edit.php`, formulaires d'action (boutons fermer/supprimer/rouvrir) des vues `packing_lists/*` et `palettes/*`, `theoretical_exports/import.php` (formulaire `multipart/form-data` — le champ caché y fonctionne normalement).

**Piège** : certains boutons d'action (supprimer, fermer…) sont de mini-formulaires POST en ligne dans des tableaux. Les traiter aussi. Le `grep` de 0.2 doit tous les avoir capturés.

### Tâche 1.4 — Envoyer le jeton dans TOUS les appels AJAX POST
Stratégie uniforme : lire la meta-balise et l'envoyer en en-tête `X-CSRF-Token`.

- Ajouter un utilitaire JS partagé (dans `public/js/app.js` ou équivalent chargé partout) :
  ```js
  window.csrfToken = function () {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  };
  ```
- Pour **chaque** `fetch(..., { method: 'POST', ... })` de l'inventaire, ajouter l'en-tête `'X-CSRF-Token': window.csrfToken()` à l'objet `headers`.

Points connus à traiter (vérifier avec l'inventaire) :
- `src/Views/colis/scan.php` : deux `fetch` — activation palette (`/colis/process-palette-scan-ajax`) et scan colis (`/colis/process-scan-ajax`), en `application/x-www-form-urlencoded`. Ajouter l'en-tête, ne pas toucher au corps.
- `public/js/search.js` : `fetch` vers `/search/ajax`, `/search/by-of`, `/search/by-sku` en `application/json`. Ajouter l'en-tête.
- Recherche globale du header (`/search/global`) : c'est un **GET** → aucune action CSRF requise (ne PAS lui ajouter de jeton).

**Piège** : ne pas confondre GET et POST. Seuls les POST doivent porter le jeton. La recherche globale header est en GET.

### Tâche 1.5 — Activer la vérification centrale (DERNIÈRE de la phase)
Dans `public/index.php`, **après** la création de `$request` et **avant** `$router->dispatch($request)`, insérer :

```php
use App\Lib\Csrf;

// Vérification CSRF pour toute requête modifiant l'état.
if ($request->getMethod() === 'POST') {
    if (!Csrf::validate($request)) {
        // Détection d'un client attendant du JSON (AJAX)
        $accept = $request->server('HTTP_ACCEPT', '');
        $xrw    = $request->server('HTTP_X_REQUESTED_WITH', '');
        $isAjax = str_contains($accept, 'application/json')
               || strcasecmp($xrw, 'XMLHttpRequest') === 0;

        if ($isAjax) {
            $resp = Response::json(['error' => 'Jeton de sécurité invalide ou expiré. Rechargez la page.'], 419);
        } else {
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            $_SESSION[FLASH_ERROR] = "Votre session a expiré ou le jeton de sécurité est invalide. Veuillez réessayer.";
            $back = $request->server('HTTP_REFERER', BASE_URL . '/');
            $resp = (new Response())->redirect($back); // adapter à l'API réelle de Response/BaseController
        }
        $resp->send();
        exit;
    }
}
```

**Pièges et vérifications** :
- Adapter la construction de la redirection à l'API réelle de `Response` (vérifier comment `BaseController::redirect()` crée sa réponse, et reproduire le même mécanisme ; ne pas inventer une méthode inexistante).
- S'assurer que `session_start()` a déjà eu lieu en amont (c'est le cas en haut de `index.php`), sinon `$_SESSION` n'existe pas.
- **Ne pas exempter `/login`** : la CSRF sur le login protège contre le « login CSRF ». Le jeton est disponible car la page de login porte la meta-balise (tâche 1.2).

**Critère d'acceptation 1** :
1. Connexion, création/édition d'un utilisateur, modification d'un paramètre, scan d'un colis (AJAX), activation d'une palette (AJAX), import CSV, et au moins une action sur palette et packing list : **tous fonctionnent normalement**.
2. Une requête POST forgée sans jeton (tester avec `curl -X POST` sur `/settings/update`) renvoie 419/erreur et **ne modifie rien**.
3. Aucune régression visible sur les parcours métier.

---

## Phase 2 — Durcissement du cookie de session (vulnérabilité 2.3)

### Tâche 2.1 — Appliquer les paramètres de cookie AVANT le premier `session_start()`
**Problème actuel** : `public/index.php` appelle `session_start()` tout en haut, **avant** d'inclure `config.php` (où sont définies les constantes `SESSION_*`). Les paramètres ne sont donc jamais appliqués.

**Correction** — réordonner le début de `public/index.php` ainsi :
1. `require` de l'autoloader et de `config/config.php` **d'abord** (config ne produit aucune sortie HTML, donc c'est sûr).
2. **Puis** configurer et démarrer la session :
   ```php
   if (session_status() === PHP_SESSION_NONE) {
       session_name(SESSION_NAME);
       session_set_cookie_params([
           'lifetime' => SESSION_LIFETIME,
           'path'     => '/',
           'domain'   => '',
           'secure'   => SESSION_SECURE,     // true si HTTPS détecté
           'httponly' => SESSION_HTTP_ONLY,  // true
           'samesite' => 'Lax',              // défense CSRF complémentaire
       ]);
       session_start();
   }
   ```
3. Continuer avec le reste (`database.php`, routes, dispatch).

**Pièges** :
- Vérifier que `config.php` ne produit **aucune** sortie (echo, espace avant `<?php`, BOM) : toute sortie avant `session_start()` casserait l'envoi du cookie. Le fichier audité utilise `error_log`, c'est sûr, mais confirmer.
- Les nombreux `session_start()` défensifs ailleurs (Auth, vues) détecteront une session déjà active et ne la redémarreront pas : aucune action requise sur eux.
- `SESSION_SECURE` dépend du protocole détecté. Si l'app est derrière un reverse-proxy/HTTPS terminé en amont, vérifier que la détection de `https` dans `config.php` tient compte de `X-Forwarded-Proto` ; sinon le cookie ne sera jamais `Secure`. Documenter ce point sans forcer `Secure` en environnement HTTP de dev.

**Critère d'acceptation 2** : après connexion, inspecter le cookie de session dans le navigateur → présence de `HttpOnly`, `SameSite=Lax`, et `Secure` si HTTPS. La connexion et la persistance de session fonctionnent toujours.

---

## Phase 3 — Contrôle d'accès au niveau objet / IDOR (vulnérabilité 2.2)

> Cette phase ajoute une **garde d'appartenance au site** par-dessus le RBAC existant. Le RBAC répond « a-t-il le droit de faire X ? » ; la garde répond « cet objet-ci le concerne-t-il ? ».

### Tâche 3.1 — Ajouter `isAccessibleBy()` aux modèles
Ajouter une méthode d'instance à `PackingList`, `Palette` et `Colis`, en respectant **exactement** la définition canonique du cloisonnement (règle globale 4).

Dans `src/Models/PackingList.php` :
```php
public function isAccessibleBy(\App\Models\User $user): bool
{
    // Utilisateur non cloisonné : accès total.
    if ($user->site_id === null || $user->hasRole('Administrateur')) {
        return true;
    }
    return $this->site_id !== null && (int)$this->site_id === (int)$user->site_id;
}
```

Dans `src/Models/Palette.php` (délègue à sa Packing List parente) :
```php
public function isAccessibleBy(\App\Models\User $user): bool
{
    if ($user->site_id === null || $user->hasRole('Administrateur')) {
        return true;
    }
    $pl = \App\Models\PackingList::findById($this->packing_list_id);
    return $pl !== null && $pl->isAccessibleBy($user);
}
```

Dans `src/Models/Colis.php` (résout la palette directe OU celle du parent) :
```php
public function isAccessibleBy(\App\Models\User $user): bool
{
    if ($user->site_id === null || $user->hasRole('Administrateur')) {
        return true;
    }
    $paletteId = $this->palette_id;
    if ($paletteId === null && $this->parent_colis_id !== null) {
        $parent = self::findById($this->parent_colis_id);
        $paletteId = $parent?->palette_id;
    }
    if ($paletteId === null) {
        return false; // colis non rattaché : refuser par défaut pour un utilisateur cloisonné
    }
    $palette = \App\Models\Palette::findById((int)$paletteId);
    return $palette !== null && $palette->isAccessibleBy($user);
}
```

**Pièges** :
- Le cas colis enfant (`palette_id === null`, `parent_colis_id` défini) **doit** être géré — sinon faux refus. Le code ci-dessus le gère.
- Comparer en `(int)` pour éviter les pièges de type.

### Tâche 3.2 — Appliquer la garde dans les contrôleurs
Pour **chaque** action qui charge un objet par `{id}` et le lit/modifie, insérer la garde **juste après** le `authorize()` de permission et **juste après** le `findById()` (donc après avoir confirmé que l'objet existe).

Patron à appliquer :
```php
$obj = Palette::findById((int)$id);
if (!$obj) {
    $this->flash(FLASH_ERROR, "Élément introuvable.");
    return $this->redirect('/palettes');
}
$user = Auth::user();
if ($user && !$obj->isAccessibleBy($user)) {
    $this->flash(FLASH_ERROR, "Accès non autorisé à cet élément.");
    return $this->redirect('/'); // ou la page de listing pertinente
}
```

Actions à instrumenter (vérifier l'exhaustivité par lecture des contrôleurs) :
- **PaletteController** : `show`, `processOpening`, `updateMaxColis`, `closePalette`, `delete`, `reopenPalette`, `storeForPackingList` (vérifier la PL cible).
- **PackingListController** : `show`/détail, `closePl`, `deletePl`, `reopenPl`, `generateMultiplePalettes`, `updateShipDate`, et les exports `exportColisToXlsx/Pdf`, `exportSynthesisToXlsx`.
- **ColisController** : `deleteColis`, `processMove` (vérifier colis source ET palette de destination), `processMassiveMove` (vérifier chaque colis et la destination), `updateComment`.
- **TheoreticalExportController** : `showDeviations` (la garde sur la PL existe déjà partiellement — l'uniformiser avec `isAccessibleBy`).

**Pièges** :
- `processMove` / `processMassiveMove` : vérifier **les deux** extrémités (origine ET destination) appartiennent au périmètre de l'utilisateur. Un déplacement est une écriture sur deux objets.
- Pour les exports (GET avec `{id}`), la garde s'applique aussi : un export est une fuite de données s'il n'est pas cloisonné.
- Pour `storeForPackingList`, l'objet pivot est la **Packing List** indiquée dans l'URL : vérifier `PackingList::findById(...)->isAccessibleBy($user)` avant de créer la palette.

**Critère d'acceptation 3** :
1. Créer (ou utiliser) deux utilisateurs Superviseur, l'un sur BK2, l'autre sur BK3.
2. Avec le compte BK2, tenter d'accéder/supprimer/déplacer/exporter une palette, une PL et un colis appartenant à BK3 via manipulation de l'`{id}` dans l'URL → **refus systématique** avec message flash, aucune donnée modifiée.
3. Un Administrateur conserve l'accès total. Un Superviseur conserve l'accès complet à son propre site.

---

## Phase 4 — Anti-bruteforce et politique de mot de passe (vulnérabilités 2.4 et 2.5)

### Tâche 4.1 — Table de suivi des tentatives de connexion
Créer `database/migrations/2024_xx_login_attempts.sql` :
```sql
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`username`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tâche 4.2 — Logique de throttling dans `AuthController::login()`
Paramètres : seuil = 5 tentatives échouées, fenêtre = 15 minutes, par couple (username, IP).

Logique (pseudo-précis, à implémenter avec `getPDO()` et requêtes préparées) :
1. Récupérer `username` et l'IP (`$request->server('REMOTE_ADDR','')`).
2. **Avant** de tenter `Auth::login()` : compter les tentatives échouées de ce couple dans les 15 dernières minutes. Si `>= 5`, refuser immédiatement avec un flash neutre (« Trop de tentatives. Réessayez dans quelques minutes. ») et rediriger vers `/login`. Ne PAS révéler si le compte existe.
3. Si `Auth::login()` **échoue** : insérer une ligne dans `login_attempts`, puis flash d'échec habituel.
4. Si `Auth::login()` **réussit** : supprimer les tentatives de ce couple (`DELETE ... WHERE username = ? AND ip_address = ?`).

**Pièges** :
- Ne pas verrouiller définitivement un compte (déni de service possible via une IP tierce qui spamme un username). Le couple (username, IP) + fenêtre glissante limite ce risque ; documenter la limite.
- Messages d'erreur **uniformes** (ne pas distinguer « mauvais mot de passe » de « compte inexistant ») pour ne pas faciliter l'énumération.
- Compter **après** d'éventuels nettoyages : optionnellement, purger les lignes de plus de 24 h (requête de maintenance simple) pour ne pas faire grossir la table.

### Tâche 4.3 — Politique de mot de passe renforcée
Localiser **toutes** les validations de mot de passe (création utilisateur, édition/changement) dans `UserController` (et tout autre contrôleur concerné). Remplacer la règle « ≥ 6 caractères » par :
- longueur minimale **12** caractères ;
- au moins une minuscule, une majuscule, un chiffre (ajuster selon contrainte d'ergonomie terrain, mais ne pas descendre sous 10).

Centraliser dans une méthode unique pour éviter la divergence, par ex. `User::validatePasswordPolicy(string $pwd): ?string` retournant `null` si OK ou le message d'erreur sinon, et l'appeler partout.

### Tâche 4.4 — Rehash transparent à la connexion
Dans `Auth::login()`, après un `password_verify()` réussi, ajouter :
```php
if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
    User::updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
}
```
Créer `User::updatePasswordHash(int $id, string $hash): bool` (requête préparée UPDATE) si elle n'existe pas.

**Pièges** :
- Ne PAS bloquer la connexion si le rehash échoue (log de l'erreur, on continue) : l'utilisateur doit pouvoir se connecter.
- Conserver `PASSWORD_DEFAULT` partout pour cohérence (création ET rehash).

**Critère d'acceptation 4** :
1. 5 échecs de connexion consécutifs sur un même username/IP → 6ᵉ tentative bloquée par le throttle.
2. Une connexion réussie réinitialise le compteur.
3. Création d'un utilisateur avec mot de passe « 123456 » → rejet ; avec un mot de passe conforme → succès.
4. Connexion d'un compte existant : si le coût de hachage a changé, le hash est mis à jour silencieusement.

---

## Phase 5 — Intégrité des données : exports et import (vulnérabilités 2.6, 2.7 ; faiblesse 3.1)

### Tâche 5.1 — Neutraliser l'injection de formules à l'export (CSV/Excel injection)
Localiser le code d'écriture de cellules dans les exports :
```bash
grep -rn "exportColisToXlsx\|exportColisToPdf\|exportSynthesisToXlsx\|setCellValue\|fputcsv" src/
```
Créer un helper (ex. dans une classe `App\Lib\ExportSanitizer` ou méthode statique utilitaire) :
```php
public static function cell(?string $value): string
{
    $v = (string)($value ?? '');
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $v; // préfixe apostrophe : neutralise l'interprétation comme formule
    }
    return $v;
}
```
Appliquer `ExportSanitizer::cell(...)` à **toute valeur d'origine non maîtrisée** écrite dans une cellule (champs issus du scan QR, du CSV importé, commentaires, libellés). Ne pas l'appliquer aux valeurs numériques calculées par l'app (quantités agrégées, totaux) pour ne pas casser les formats.

**Pièges** :
- Bien traiter les exports XLSX **et** PDF si le PDF est généré à partir de données tabulaires affichées (le risque formule ne concerne que les tableurs, mais l'échappement HTML reste requis côté PDF si rendu HTML).
- Ne pas double-échapper les valeurs déjà passées par `htmlspecialchars` dans les vues : ici on parle des fichiers exportés, pas des vues.

### Tâche 5.2 — Fiabiliser l'upload CSV (`TheoreticalExportController::processImport`)
Renforcer la validation actuelle (qui ne contrôle que l'extension du nom) :
1. Vérifier le type réel via `finfo` :
   ```php
   $finfo = new \finfo(FILEINFO_MIME_TYPE);
   $mime = $finfo->file($uploadedFile['tmp_name']);
   $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
   if (!in_array($mime, $allowed, true)) { /* erreur */ }
   ```
   (Les CSV remontent souvent en `text/plain` ; conserver cette tolérance.)
2. Borner le traitement : refuser au-delà d'un plafond de lignes (ex. 50 000) pour éviter l'épuisement mémoire/temps.
3. Vérifier l'encodage / nettoyer le BOM UTF-8 en tête de fichier avant `fgetcsv`.
4. **Envelopper l'insertion dans une transaction** : actuellement `deleteByPackingListId()` purge l'ancien théorique **puis** `insertBatch()` insère. Si l'insertion échoue en cours, on perd l'ancien théorique sans avoir le nouveau. Encadrer purge + insertion par `beginTransaction()` / `commit()` / `rollBack()` pour un import atomique.

**Pièges** :
- Conserver le séparateur `;` existant (format métier).
- Conserver le comportement « écrase l'ancien théorique » mais le rendre atomique (cf. point 4).

### Tâche 5.3 — Contraintes d'unicité en base (faiblesse 3.1)
1. **Avant** d'ajouter les contraintes, détecter les doublons existants :
   ```sql
   SELECT code, COUNT(*) c FROM palettes GROUP BY code HAVING c > 1;
   SELECT reference, COUNT(*) c FROM packing_lists GROUP BY reference HAVING c > 1;
   SELECT package_number, COUNT(*) c FROM colis GROUP BY package_number HAVING c > 1;
   ```
   S'il existe des doublons, **ne pas appliquer la contrainte** : documenter et remonter à l'humain pour arbitrage métier (ne pas supprimer de données automatiquement).
2. Si aucun doublon, créer `database/migrations/2024_xx_unique_constraints.sql` :
   ```sql
   ALTER TABLE `palettes`      ADD UNIQUE KEY `uq_palettes_code` (`code`);
   ALTER TABLE `packing_lists` ADD UNIQUE KEY `uq_pl_reference` (`reference`);
   ALTER TABLE `colis`         ADD UNIQUE KEY `uq_colis_package` (`package_number`);
   ```
3. **Gérer la course applicative** : dans la génération de code palette / référence PL, encadrer l'insertion d'un `try/catch` sur violation de contrainte (SQLSTATE `23000`) et réessayer avec le compteur incrémenté (ex. jusqu'à 3 tentatives). Localiser la logique de génération (service de patterns / `SettingController` patterns appliqués dans `PaletteController`/`PackingListController`).

**Pièges** :
- La contrainte sur `colis.package_number` suppose une unicité **globale**. Confirmer avec la logique `Colis::packageNumberExists()` que c'est bien l'invariant métier attendu (l'app le suppose déjà). En cas de doute, escalader plutôt que forcer.

**Critère d'acceptation 5** :
1. Un colis dont un champ vaut `=1+1` s'exporte en XLSX avec la valeur littérale `=1+1` (préfixée), non interprétée comme formule.
2. Un upload non-CSV (ex. `.csv` renommé depuis un binaire) est rejeté ; un import interrompu ne détruit pas l'ancien théorique (rollback).
3. Après migration, une tentative d'insertion de code/référence dupliqué échoue proprement ou est réessayée avec succès.

---

## Phase 6 — Durcissement de configuration et nettoyage (vulnérabilités 2.8, 2.9 ; faiblesses 3.2 à 3.7)

### Tâche 6.1 — Notion d'environnement et identifiants BDD sûrs (2.8)
- Introduire `APP_ENV` (via `getenv('APP_ENV') ?: 'development'`) dans `config.php`.
- Dans `config/database.php` : si `APP_ENV === 'production'` et que `DB_USER`/`DB_PASSWORD` ne sont pas fournis par l'environnement, **échouer explicitement** (lever une exception / arrêter) au lieu de retomber sur `root` / mot de passe vide. Conserver le fallback pratique uniquement hors production.

### Tâche 6.2 — Verbosité des erreurs selon l'environnement (2.9)
- En production : `display_errors = 0` ; les vues `errors/500.php` ne doivent **jamais** afficher le message d'exception, le fichier ou la trace. Conditionner l'affichage détaillé à `APP_ENV !== 'production'`.
- Conserver le `error_log()` systématique côté serveur (utile au diagnostic) sans exposer au client.

### Tâche 6.3 — Retirer le code de debug/test (2.9)
- Supprimer la route closure `/hello-closure/{name}` et les blocs de routes de test commentés dans `public/index.php`.
- Retirer les commentaires `// error_log("DEBUG ...")` résiduels si souhaité (cosmétique, optionnel).

### Tâche 6.4 — `getPDO()` : exception au lieu de `die()` (faiblesse 3.7)
Dans `config/database.php`, remplacer le `die("...")` du `catch` par `throw $e;` (ou une exception applicative) afin que le `try/catch` global de `index.php` produise une page d'erreur cohérente. Vérifier que la page 500 ne divulgue pas les détails en production (cf. 6.2).

### Tâche 6.5 — Corriger l'incohérence schéma/modèle `sku` vs `article_reference` (faiblesse 3.2)
1. Inspecter le **schéma réel** de la base cible :
   ```sql
   DESCRIBE theoretical_exports;
   ```
2. Décision :
   - Si la colonne réelle est `article_reference` : le dump SQL fourni est obsolète → **régénérer le dump** et l'aligner. Le modèle et `insertBatch()` sont corrects.
   - Si la colonne réelle est `sku` : c'est un **bug d'insertion** → aligner le modèle `TheoreticalExport` et la requête `insertBatch()` sur le nom réel, OU ajouter une migration renommant la colonne en `article_reference` pour cohérence avec le reste du code. Choisir l'option qui minimise l'impact et la documenter.
3. Tester un import réel et confirmer que les lignes s'insèrent et s'affichent dans le suivi des écarts.

**Piège** : ne pas modifier le code en aveugle. Cette tâche **exige** d'observer le schéma réel avant d'agir. Si la base n'est pas accessible à l'agent, produire les deux variantes de correctif et escalader pour décision.

### Tâche 6.6 — Centraliser le parsing du payload QR (faiblesse 3.4) et corriger le code mort (3.3)
- Faire de `Colis::parseQrPayload()` la **seule** implémentation du parsing. Remplacer les blocs dupliqués dans `ColisController::processColisScan()` et `ColisController::processScanAjax()` par un appel à `Colis::parseQrPayload($colisPayloadString)`.
- Vérifier que le comportement reste identique (ancien format `clé|valeur;...` ET nouveau format positionnel 12 champs) — comparer la sortie avant/après sur des payloads de test représentatifs.
- Supprimer la branche morte de détection de doublon dans `processScanAjax()` :
  ```php
  if (!$activeParentId) {
      $currentParentNumber = null;
      if ($activeParentId) { ... }   // ← inatteignable : à retirer/réécrire
  }
  ```
  Réécrire correctement la logique de doublon pour le mode groupage : déterminer le numéro de colis parent courant **avant** le test, puis comparer. Documenter l'intention métier retenue (un colis enfant peut-il porter le même numéro qu'un autre ? Confirmer la règle avant de coder).

### Tâche 6.7 — Robustesse du format QR positionnel (faiblesse 3.5)
- Dans `Colis::parseQrPayload()`, ne pas échouer silencieusement si `count($values) !== 12`. Au minimum : journaliser le payload rejeté et renvoyer un tableau vide explicite que les contrôleurs traduisent déjà en message d'erreur (« format invalide »). Vérifier que ce message remonte bien à l'opérateur (écran rouge / toast déjà présents).
- Recommandation (à valider avec l'humain avant implémentation) : préfixer un numéro de version dans le payload QR pour les futures étiquettes, afin de gérer plusieurs formats sans test d'égalité strict. **Ne pas** changer le format des étiquettes existantes sans validation métier.

### Tâche 6.8 — Cloisonnement de la recherche globale (faiblesse 3.8)
- Dans `SearchController::globalSearch()` et `SearchService`, ajouter un filtrage par site pour les utilisateurs cloisonnés (règle globale 4) : restreindre les résultats colis/palettes/PL au `site_id` de l'utilisateur, sauf administrateur global. Réutiliser la même condition que `PackingList::getAll()`.

**Critère d'acceptation 6** :
1. En `APP_ENV=production` sans identifiants BDD → l'app refuse de démarrer avec une erreur claire (pas de fallback root).
2. Une erreur applicative en production affiche une page 500 générique sans trace ; en développement, le détail reste visible.
3. La route `/hello-closure/...` renvoie 404.
4. Le scan colis (les deux endpoints) fonctionne à l'identique après centralisation du parsing ; le code mort est supprimé.
5. Un import théorique réel insère correctement les lignes (cohérence schéma résolue).
6. Un Superviseur cloisonné ne voit dans la recherche globale que les éléments de son site.

---

## Récapitulatif de l'ordre d'exécution (non négociable)

| Phase | Contenu | Dépend de | Risque si mal ordonné |
|------|---------|-----------|------------------------|
| 0 | Préparation, inventaire POST | — | Couverture CSRF incomplète |
| 1 | CSRF (1.1→1.2→1.3→1.4→**1.5 en dernier**) | 0 | **App cassée si 1.5 avant 1.3/1.4** |
| 2 | Durcissement cookie session | — (idéalement après 1) | Cookie non sécurisé |
| 3 | Garde d'accès objet / IDOR | — | Fuite/altération inter-sites |
| 4 | Anti-bruteforce + mots de passe | migration 4.1 | — |
| 5 | Exports + import + contraintes unicité | check doublons 5.3 | Perte de données si pas de dédup préalable |
| 6 | Config, nettoyage, robustesse, schéma | observer schéma réel (6.5) | Correctif schéma en aveugle |

## Livrables attendus de l'agent
1. Code modifié sur la branche `feature/securisation`, un commit par tâche.
2. Dossier `database/migrations/` avec les scripts SQL (login_attempts, contraintes d'unicité, éventuelle correction de colonne) **non appliqués en prod**, accompagnés d'un `README` de procédure.
3. `database/migrations/INVENTAIRE_POST.md` complété et coché.
4. Un fichier `RAPPORT_EXECUTION.md` listant, par tâche : statut (fait / escaladé), fichiers touchés, résultats des critères d'acceptation, et toute décision escaladée à l'humain (notamment 5.3 si doublons, et 6.5 si schéma à arbitrer).
