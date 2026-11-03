# Rapport d'analyse — Application « PackingList Pro »

> Audit technique : fonctionnement, vulnérabilités, faiblesses et pistes d'amélioration.
> Périmètre : code PHP fourni (framework MVC maison, PHP 8.2 / MariaDB).

---

## 1. Vue d'ensemble et fonctionnement

PackingList Pro est une application de **gestion logistique d'expédition** structurée autour d'une hiérarchie métier claire :

```
Site → Packing List → Palette → Colis (parent / produit)
```

Le flux principal est un **workflow de scan** : un opérateur active une palette ouverte, scanne les QR codes des colis, et l'application enregistre chaque colis (avec sa charge utile JSON) en le rattachant à la palette active stockée en session. Un module d'**import théorique** (CSV) permet ensuite de comparer le scanné au prévu (« suivi des écarts »).

### Architecture technique

L'application repose sur un framework MVC développé en interne, sans dépendance lourde de type Laravel/Symfony :

- **Front controller** : `public/index.php` charge l'autoloader Composer, la config, instancie `Request` et `Router`, déclare toutes les routes, puis dispatche dans un `try/catch` global qui rend des vues d'erreur 404/500.
- **Router** (`src/Lib/Router.php`) : routes statiques converties en regex, paramètres `{id}` capturés via groupes nommés. Le handler peut être une closure ou un couple `[Controller::class, 'method']`.
- **Contrôleurs** : héritent de `BaseController`, qui fournit `view()`, `renderPartial()`, `redirect()`, `json()`, `flash()` et surtout `authorize()`.
- **Authentification** (`src/Lib/Auth.php`) : classe statique, session PHP, `password_verify`, `session_regenerate_id(true)` à la connexion.
- **Autorisation** : RBAC granulaire à trois niveaux — `users` ↔ `roles` ↔ `permissions` (tables de liaison). Trois rôles métier : Administrateur, Superviseur, Opérateur de Scan.
- **Modèles** : classes avec méthodes statiques `findById/getAll/create/...`, accès via une connexion PDO singleton (`getPDO()`).
- **Multi-site** : chaque utilisateur peut être rattaché à un `site_id` ; le listing des Packing Lists est filtré par site pour les non-administrateurs.

### Points déjà bien traités (à conserver)

L'application montre une réelle conscience sécurité sur plusieurs aspects, ce qui constitue une bonne base :

- **Requêtes préparées PDO partout** : toutes les requêtes inspectées utilisent `prepare()` + `bindValue/bindParam` ou `execute($params)`. C'est cohérent et solide contre l'injection SQL classique.
- `PDO::ATTR_EMULATE_PREPARES => false` : vraie préparation côté serveur, durcissement contre l'injection.
- **Validation de la clause `ORDER BY`** dans `PackingList::getAll()` via une liste blanche de colonnes — exactement la bonne approche pour un tri dynamique.
- **Liste blanche des clés** dans `SettingController::update()` (`array_intersect_key`) — protège contre l'écrasement de paramètres arbitraires.
- **Échappement de sortie** : `htmlspecialchars()` est utilisé largement dans les vues (protection XSS sur la majorité des points d'affichage).
- **Identifiants BDD via `getenv()`** plutôt qu'en dur.
- **Affichage des erreurs conditionné** à `ini_get('display_errors')`.
- **Sanitisation FULLTEXT** : `prepareFullTextQuery()` retire les opérateurs booléens avant la recherche, et la valeur reste passée en paramètre lié.

---

## 2. Vulnérabilités

Classées par sévérité. La sévérité tient compte du contexte : application interne d'entrepôt, mais accessible via navigateur avec sessions.

### 2.1 — CRITIQUE : Absence totale de protection CSRF

Aucun jeton anti-CSRF n'existe dans l'application. **Toutes** les actions modifiant l'état sont en POST sans aucune vérification d'origine :

- création/suppression d'utilisateurs, assignation de rôles et permissions ;
- modification des paramètres applicatifs (`/settings`) ;
- ouverture/fermeture/suppression de palettes et de packing lists ;
- scan, déplacement et suppression de colis ;
- import CSV.

Les appels AJAX envoient bien un en-tête `X-Requested-With: XMLHttpRequest` côté JavaScript, **mais le serveur ne le vérifie jamais**. Un attaquant peut donc forger une page tierce qui soumet automatiquement un formulaire POST (ou un `fetch` simple) vers, par exemple, `/users/store` ou `/settings`, et l'action s'exécutera avec les droits de la victime connectée.

**Impact** : un administrateur piégé sur un lien malveillant pendant qu'il est connecté peut se voir créer un compte pirate, modifier les patterns de référence, ou supprimer des données métier.

### 2.2 — ÉLEVÉ : Contrôle d'accès au niveau objet insuffisant (IDOR)

Le cloisonnement par site est appliqué au **listing** (`PackingList::getAll()` filtre par `site_id`), mais **pas à l'accès direct par identifiant**. Des méthodes comme `PaletteController::show/delete`, `ColisController::deleteColis` ou la logique de déplacement chargent l'objet via `findById($id)` puis agissent **sans vérifier qu'il appartient au site de l'utilisateur**.

**Impact** : un Superviseur rattaché au site BK2, en modifiant l'ID dans l'URL, peut potentiellement consulter, supprimer ou déplacer des palettes/colis du site BK3. C'est une élévation de privilège horizontale. La permission RBAC est vérifiée (« a-t-il le droit de supprimer une palette ? ») mais pas l'appartenance de l'objet (« cette palette-là le concerne-t-il ? »).

### 2.3 — ÉLEVÉ : Cookie de session non durci à l'exécution

Le fichier `config.php` définit bien les constantes `SESSION_HTTP_ONLY`, `SESSION_SECURE`, etc., **mais `index.php` appelle simplement `session_start()` sans jamais appeler `session_set_cookie_params()`**. Les constantes ne sont donc pas appliquées : le cookie de session n'a pas `HttpOnly`, `Secure` ni `SameSite` garantis. Conjugué à l'absence de CSRF (2.1), l'absence de `SameSite=Lax/Strict` rend les attaques CSRF d'autant plus faciles.

### 2.4 — MOYEN : Absence de protection contre la force brute sur le login

`AuthController::login()` n'a aucun mécanisme de limitation : pas de comptage des tentatives, pas de temporisation, pas de verrouillage de compte, pas de CAPTCHA. Combiné à une politique de mot de passe faible (2.5), cela ouvre la porte au credential stuffing et au bruteforce.

### 2.5 — MOYEN : Politique de mot de passe faible

`UserController` impose un minimum de **6 caractères** sans exigence de complexité. Insuffisant pour un système de gestion d'entrepôt avec comptes administrateurs.

### 2.6 — MOYEN : Injection de formules (CSV/Excel injection) à l'export

Les données scannées (charge utile QR) et importées (CSV théorique) sont stockées **brutes**. L'application exporte ensuite des colis en XLSX et PDF (`exportColisToXlsx`, etc.). Une valeur commençant par `=`, `+`, `-` ou `@` sera interprétée comme une **formule** à l'ouverture du fichier Excel par un utilisateur — vecteur connu d'exécution de commande / exfiltration côté poste client. La donnée provenant d'un QR code est entièrement contrôlée par celui qui génère l'étiquette.

### 2.7 — FAIBLE : Validation d'upload CSV par extension seulement

`processImport()` valide le type de fichier via `pathinfo($name, PATHINFO_EXTENSION)`, c'est-à-dire **le nom fourni par le client**, et non le contenu/MIME réel. Le risque d'exécution est faible (le fichier reste dans `tmp_name`, n'est jamais déplacé en racine web ni exécuté, simplement lu via `fgetcsv`), mais la validation devrait porter sur le contenu et borner le nombre de lignes.

### 2.8 — FAIBLE : Identifiants BDD par défaut dangereux

`config/database.php` retombe sur `root` / mot de passe vide si les variables d'environnement sont absentes. En production, un déploiement où les variables ne seraient pas définies tournerait avec le compte root sans mot de passe.

### 2.9 — FAIBLE : Routes de debug/test laissées en place

La route `/hello-closure/{name}` et des blocs de routes de test commentés subsistent dans `index.php`. À retirer du code de production. Vérifier aussi que `display_errors` est bien désactivé en production (sinon la page 500 expose le message d'exception, le fichier et la ligne).

---

## 3. Faiblesses (qualité, robustesse, maintenabilité)

Ces points ne sont pas des failles exploitables mais affectent la fiabilité et l'évolutivité.

### 3.1 — Concurrence sur la génération des références/codes

Les patterns de référence (`PL-{SITE_CODE}-{YYYY}-{COUNTER_GLOBAL}`) et de code palette (`PAL{YYYY}-{COUNTER_GLOBAL_4DIGITS}`) reposent sur des compteurs. Si deux opérateurs créent une palette **simultanément**, une condition de course peut produire deux codes identiques. L'application vérifie l'unicité en PHP (`findByCode`) avant insertion, mais ce contrôle « lire puis écrire » n'est pas atomique. **Il faut une contrainte d'unicité en base** (`UNIQUE` sur `palettes.code`, `packing_lists.reference`, `colis.package_number`) couplée à une stratégie de réessai, sinon des doublons silencieux ou des erreurs d'insertion surviendront en charge.

### 3.2 — Incohérence schéma / modèle sur le théorique

La table `theoretical_exports` du dump SQL contient une colonne `sku`, alors que `TheoreticalExport::insertBatch()` et le modèle utilisent `article_reference`. Soit le dump est obsolète (colonne renommée mais dump non régénéré), soit il y a un vrai bug d'insertion. **À vérifier en priorité** : exécuter un import réel et confirmer que les lignes s'insèrent. Régénérer le dump SQL après vérification.

### 3.3 — Code mort dans la détection de doublon de scan

Dans `processScanAjax()`, la logique de vérification de doublon pour le mode « groupage » contient une branche inatteignable :

```php
if (!$activeParentId) {           // ici $activeParentId est forcément falsy
    $currentParentNumber = null;
    if ($activeParentId) {        // ne peut jamais être vrai → code mort
        ...
    }
}
```

La gestion du colis parent en mode groupage est donc incomplète/cassée. À reprendre.

### 3.4 — Duplication du parsing de la charge utile QR

La logique de parsing du payload (ancien format `clé|valeur;...` et nouveau format positionnel) est **dupliquée trois fois** : dans `processColisScan()`, dans `processScanAjax()` et dans `Colis::parseQrPayload()`. Toute évolution du format devra être répercutée à trois endroits — source de bugs. À centraliser sur `Colis::parseQrPayload()`.

### 3.5 — Fragilité du format QR positionnel

Le nouveau format repose sur un **nombre exact de champs** séparés par `;` (12 valeurs). Une étiquette avec un champ vide en fin, un séparateur dans une valeur, ou une version différente fait échouer le parsing silencieusement (`count($values) === count($expectedKeys)` faux → payload vide). Envisager un format versionné et une validation explicite plutôt qu'un test d'égalité strict du nombre de champs.

### 3.6 — Rendu de vue via `extract()` et lecture directe de `$_SESSION`

Le `BaseController::view()` injecte les données dans la portée de la vue (mécanisme type `extract`). C'est acceptable car les données sont construites par les contrôleurs, mais les vues lisent aussi directement `$_SESSION` pour les messages flash (ex. `login.php`), ce qui couple fortement la vue à l'implémentation. Centraliser la lecture des flashs dans `BaseController` / une variable injectée améliorerait la testabilité.

### 3.7 — `getPDO()` fait `die()` en cas d'échec

En cas d'échec de connexion, `getPDO()` appelle `die()`, court-circuitant le gestionnaire d'erreurs central de `index.php`. Préférer lancer une `PDOException` (déjà attrapée par le `try/catch` global) pour un rendu d'erreur cohérent.

### 3.8 — Granularité de la permission de recherche

`SearchController` est entièrement protégé par la permission `colis_scan`. La recherche globale (qui traverse colis, palettes et packing lists, tous sites confondus dans `globalSearch`) est donc accessible à tout opérateur de scan, **sans filtrage par site**. À recouper avec le cloisonnement métier souhaité.

---

## 4. Pistes d'amélioration (dans la logique de l'application)

Les recommandations respectent l'architecture MVC maison existante et s'appuient sur les bonnes pratiques déjà présentes dans le code.

### Priorité 1 — Sécurité essentielle

**1. Implémenter la protection CSRF.** Créer une petite classe `App\Lib\Csrf` qui génère/stocke un jeton en session. Ajouter un helper `csrf_field()` à injecter dans tous les formulaires POST, et exposer le jeton via une balise `<meta>` pour les appels AJAX (à envoyer en en-tête `X-CSRF-Token`). Centraliser la vérification dans `BaseController` : sur toute requête POST, comparer le jeton reçu au jeton de session avec `hash_equals()` et rejeter sinon. C'est cohérent avec l'approche centralisée déjà adoptée pour `authorize()`.

**2. Durcir le cookie de session.** Appeler `session_set_cookie_params()` **avant** `session_start()`, en utilisant les constantes déjà définies, et ajouter `'samesite' => 'Lax'`. Le faire dans un point d'entrée unique (un bootstrap) plutôt que dans chaque fichier qui démarre la session.

**3. Ajouter le contrôle d'accès au niveau objet.** Introduire une méthode de garde réutilisable, par exemple `PackingList::isAccessibleBy(User $u)` / `Palette::isAccessibleBy(User $u)`, qui vérifie l'appartenance au site (sauf administrateur global). L'appeler systématiquement après chaque `findById()` dans les contrôleurs, juste après le `authorize()` de permission. Cela complète le RBAC existant par la dimension « propriété de l'objet ».

**4. Limiter les tentatives de connexion.** Compter les échecs par utilisateur/IP (en session pour une première version, idéalement en base ou cache pour être robuste), introduire une temporisation progressive et un verrouillage temporaire au-delà d'un seuil.

**5. Renforcer la politique de mot de passe.** Porter le minimum à 10–12 caractères avec exigences de base, et appeler `password_needs_rehash()` à la connexion pour migrer en douceur les anciens hash si le coût de hachage évolue.

### Priorité 2 — Intégrité des données et exports

**6. Neutraliser l'injection de formules à l'export.** Avant écriture XLSX/PDF, préfixer d'une apostrophe (`'`) toute valeur de cellule commençant par `=`, `+`, `-`, `@`, tabulation ou retour chariot. Centraliser dans une fonction d'assainissement appliquée à toutes les cellules exportées.

**7. Fiabiliser l'upload CSV.** Valider le contenu réel (lecture des premières lignes, encodage UTF-8, séparateur attendu) plutôt que la seule extension du nom, borner le nombre de lignes traitées, et envelopper l'insertion de lot dans une transaction pour un import atomique (tout ou rien).

**8. Ajouter les contraintes d'unicité en base** sur `palettes.code`, `packing_lists.reference`, `colis.package_number`, et gérer le cas de collision applicativement (réessai avec incrément). Cela résout structurellement le risque de course de la section 3.1.

### Priorité 3 — Robustesse et maintenabilité

**9. Corriger l'incohérence `sku` / `article_reference`** sur le théorique (3.2) puis régénérer le dump SQL de référence.

**10. Centraliser le parsing QR** sur `Colis::parseQrPayload()` et supprimer les deux duplications, puis nettoyer le code mort de `processScanAjax()` (3.3).

**11. Versionner et valider le format QR** plutôt que de se reposer sur le comptage strict des champs, pour absorber les évolutions d'étiquettes sans casse silencieuse.

**12. Nettoyer le code de production** : retirer les routes de test (`/hello-closure`, blocs commentés), garantir `display_errors=0`, supprimer la possibilité de retomber sur `root`/mot de passe vide (échouer explicitement si les variables d'environnement de prod manquent), et remplacer le `die()` de `getPDO()` par une exception.

**13. Externaliser la lecture des messages flash** du `$_SESSION` direct dans les vues vers une variable injectée par `BaseController`.

---

## 5. Synthèse

L'application a une **architecture propre et cohérente** pour un framework maison, avec de réels réflexes sécurité côté base de données (requêtes préparées systématiques, liste blanche de tri, échappement de sortie). La fondation est saine.

Les deux risques à traiter en priorité absolue sont **l'absence de protection CSRF** (2.1) et **le contrôle d'accès au niveau objet** (2.2) : ce sont les seuls défauts qui permettent une compromission concrète dans un usage normal de l'application. Le durcissement du cookie de session (2.3) est le complément indispensable du premier. Le reste relève d'un durcissement progressif et de corrections de robustesse qui s'inscrivent naturellement dans la logique métier existante (cloisonnement par site, intégrité des références, fiabilité du scan).

| # | Élément | Sévérité | Priorité |
|---|---------|----------|----------|
| 2.1 | Absence de CSRF | Critique | 1 |
| 2.2 | IDOR / accès objet non cloisonné par site | Élevé | 1 |
| 2.3 | Cookie session non durci | Élevé | 1 |
| 2.4 | Pas d'anti-bruteforce login | Moyen | 1 |
| 2.5 | Mot de passe faible (6 car.) | Moyen | 1 |
| 2.6 | Injection de formules à l'export | Moyen | 2 |
| 2.7 | Validation upload par extension | Faible | 2 |
| 2.8 | Identifiants BDD par défaut (root) | Faible | 2/3 |
| 2.9 | Routes de debug / verbosité erreurs | Faible | 3 |
| 3.1 | Course sur génération des codes | Robustesse | 2 |
| 3.2 | Incohérence schéma `sku`/`article_reference` | Bug potentiel | 3 |
| 3.3–3.7 | Code mort, duplication, format QR fragile | Maintenabilité | 3 |
