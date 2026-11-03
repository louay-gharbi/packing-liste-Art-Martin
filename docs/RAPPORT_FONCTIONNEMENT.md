# PackingList Pro — Rapport de fonctionnement

> Analyse du code source au 23/10/2026. Les chemins sont relatifs à la racine du projet.

## 1. En bref

PackingList Pro est une application web qui gère le **colisage avant expédition**. Le flux est le suivant :

1. On crée une **Packing List (PL)** pour un site et une date d'expédition.
2. On génère des **palettes** pour cette PL. Chaque palette reçoit un code unique et une étiquette QR.
3. Un opérateur **scanne la palette**, puis **scanne chaque colis** posé dessus. Chaque colis porte un QR code avec toutes ses informations.
4. On **compare** ce qui a été scanné avec le **théorique** (fichier CSV importé). L'application affiche les écarts.
5. On **ferme** la PL et on **exporte** le contenu en Excel ou en PDF.

L'application ne se connecte à aucun système externe (ni ERP, ni CODESOFT, ni API). Les données entrent par trois voies seulement : la saisie dans les écrans, le **scan des QR codes** et l'**import CSV**.

## 2. Stack technique

| Élément | Choix |
|---|---|
| Langage | PHP ≥ 8.2, sans framework (MVC fait maison) |
| Base de données | MySQL, base `packing_list_db`, accès par PDO |
| Serveur | Apache (XAMPP/Laragon), point d'entrée `public/index.php` |
| Librairies (Composer) | `endroid/qr-code` (QR palettes), `phpoffice/phpspreadsheet` (export XLSX), `dompdf/dompdf` (export PDF) |
| Front | Vues PHP + JavaScript (`public/js/app.js`, `public/js/search.js`) avec appels AJAX pour le scan et la recherche |
| Tests | PHPUnit (`tests/`) |

### Arborescence

```
config/        config.php (URLs, session, env) · database.php (connexion PDO)
public/        index.php (routes + dispatch), css/, js/, qrcodes/palettes/*.png
src/
  Controllers/ un contrôleur par module (PackingList, Palette, Colis, ...)
  Models/      accès BDD : requêtes SQL + hydratation en objets
  Services/    ReferenceGenerator, QrCodeService, SearchService
  Lib/         Router, Request, Response, Auth, Csrf
  Views/       templates PHP par module + layouts/partials
database/      dump SQL complet + migrations
docs/          documentation fonctionnelle et technique (HTML)
```

## 3. Cycle d'une requête

```
Navigateur ──► public/index.php
                 1. autoload Composer + config/config.php
                 2. démarrage de la session (cookie HttpOnly, SameSite=Lax)
                 3. config/database.php  → getPDO()
                 4. déclaration des routes  ($router->get / ->post)
                 5. contrôle CSRF sur tout POST (champ _csrf_token ou en-tête X-CSRF-Token)
                 6. Router::dispatch() ──► Controller::méthode()
                                              ├─ authorize('permission')
                                              ├─ denyIfNotAccessible(objet)  (cloisonnement par site)
                                              ├─ Model::requête()  ──► MySQL
                                              └─ view('module/page.php', $data)  ou  Response::json()
                 7. Response->send()   (exception → page 404/500)
```

- **Connexion BDD** ([config/database.php](../config/database.php)) : une seule instance PDO par requête (`getPDO()`), avec des requêtes réellement préparées. Les identifiants viennent des variables d'environnement `DB_*`. En local, à défaut, l'application utilise `root` sans mot de passe ; en production, ce repli est interdit.
- **Modèles** : chaque modèle écrit son SQL à la main (pas d'ORM) et transforme les lignes en objets via `hydrate()`.

## 4. Modèle de données

```
sites ──< users                       roles >──< permissions   (role_permissions)
  │                                   users >──< roles         (user_roles)
  └──< packing_lists ──< palettes ──< colis ──< colis enfants   (parent_colis_id)
           └──< theoretical_exports
settings · dynamic_counters · login_attempts
```

| Table | Rôle | Champs clés |
|---|---|---|
| `sites` | Sites logistiques | `code` (unique), `name`, `is_active` |
| `packing_lists` | Une expédition | `reference` (unique), `ship_date`, `status` = `open`/`closed`, `site_id` |
| `palettes` | Supports physiques d'une PL | `code` (unique), `status` = `generated`/`open`/`closed`, `max_colis`, `qr_code_path` |
| `colis` | Colis scannés | `package_number` (unique), `type` = `parent`/`product`, `palette_id` **ou** `parent_colis_id`, données du QR (client, OF, commande, SKU, article, qté, CP, ligne) + `payload_json` brut |
| `theoretical_exports` | Quantités attendues | `of_number`, `order_number`, `article_reference`, `expected_quantity`, liés à une PL |
| `settings` | Paramètres clé/valeur | notamment les patrons de référence |
| `dynamic_counters` | Compteurs de numérotation | `counter_key`, `current_value` |
| `users`, `roles`, `permissions` | Droits d'accès | `users.site_id` sert au cloisonnement |
| `login_attempts` | Journal des tentatives de connexion | `username`, `ip_address`, `attempted_at` |

**Colis parent / enfant (groupage).** Un colis *parent* est posé sur une palette (`palette_id`). Ses colis *enfants* n'ont pas de `palette_id` : ils pointent vers leur parent via `parent_colis_id`. Pour cette raison, tous les calculs de quantité d'une palette ou d'une PL additionnent deux ensembles : les colis posés directement sur la palette **et** les enfants de ces colis.

## 5. Fonctionnement métier, module par module

### 5.1 Packing Lists — [PackingListController.php](../src/Controllers/PackingListController.php), [PackingList.php](../src/Models/PackingList.php)

| Action | Route | Ce qui se passe |
|---|---|---|
| Liste | `GET /packing-lists` | `PackingList::getAll()` (jointure avec `sites`) filtré par site ; tri limité à une liste blanche de colonnes |
| Création | `POST /packing-lists/store` | Contrôle de la date et du site actif. Dans une transaction : insertion avec une référence temporaire, puis calcul de la vraie référence par `ReferenceGenerator` (jusqu'à 3 essais en cas de doublon) |
| Détail | `GET /packing-lists/show/{id}` | PL + site + palettes + quantité totale scannée + quantités par palette + synthèse agrégée (client / CP / commande / ligne / article, avec ligne « TOTAL GÉNÉRAL ») |
| Fermeture | `POST /packing-lists/close/{id}` | Dans une transaction : les palettes **vides** (`generated` ou `open`) sont **supprimées** ; les palettes **ouvertes non vides** passent à `closed` |
| Réouverture | `POST /packing-lists/reopen/{id}` | Statut remis à `open` |
| Suppression | `POST /packing-lists/delete/{id}` | Refusée si la PL est fermée ou si une palette contient des colis ou est fermée |
| Exports | `.../export-colis/xlsx`, `.../export-colis/pdf`, `.../export-synthesis/xlsx` | Détail des colis ou synthèse, via PhpSpreadsheet ou Dompdf |

### 5.2 Palettes — [PaletteController.php](../src/Controllers/PaletteController.php), [Palette.php](../src/Models/Palette.php)

```
 generated ──(1er scan + saisie max_colis)──► open ──(fermeture)──► closed
     ▲                                          ▲                       │
     └─ création (code + QR PNG)                └────── réouverture ────┘
```

- **Création** : uniquement sur une PL ouverte, à l'unité ou en lot (`generate-multiple`). Le code est calculé par `ReferenceGenerator` (patron `palette_code_pattern`), puis `QrCodeService` produit un PNG dans `public/qrcodes/palettes/`. Le QR contient le code de la palette, avec ce code imprimé en légende.
- **Ouverture** : l'opérateur indique un `max_colis` (entier > 0). La PL doit être ouverte.
- **Capacité** : on ne compte que les colis de premier niveau (`parent_colis_id IS NULL`). Un scan ou un déplacement est refusé dès que `max_colis` est atteint.
- **Impression** : `POST /palettes/print-qrcodes` affiche une page d'impression des étiquettes sélectionnées.

### 5.3 Scan des colis — [ColisController.php](../src/Controllers/ColisController.php)

C'est le cœur opérationnel. Il se fait en AJAX depuis `GET /colis/scan`.

**Étape 1 — Activer une palette** (`POST /colis/process-palette-scan-ajax`)
- On scanne le code palette. L'application vérifie le périmètre du site, que la PL est ouverte et que la palette n'est pas fermée.
- Palette `generated` → redirection vers sa fiche pour l'ouvrir (saisie de `max_colis`).
- Palette `open` → elle devient la palette active, mémorisée en session (`active_scan_palette_id`).

**Étape 2 — Scanner les colis** (`POST /colis/process-scan-ajax`)
1. Le texte du QR est découpé par [`Colis::parseQrPayload()`](../src/Models/Colis.php#L795), qui accepte deux formats :
   - **actuel** : 12 valeurs séparées par `;`, dans cet ordre
     `package_number ; qty ; of_number ; ref_interne ; client_name ; ref_article ; label ; sku ; order_number ; unit ; code_postal ; ligne`
   - **ancien** : `clé|valeur;clé|valeur;…`
2. Contrôles : `package_number` présent, **pas de doublon** (`package_number` unique dans toute la base), capacité de la palette.
3. Enregistrement :
   - **Colis produit** : une ligne `colis` de type `product`, rattachée à la palette (ou au parent si un groupage est en cours), avec tous les champs du QR et le JSON brut.
   - **Colis parent (groupage)** : un QR qui ne contient **que** `package_number` crée un colis `parent` et ouvre le mode groupage (`active_parent_colis_id` en session). Les colis scannés ensuite deviennent ses enfants. **Re-scanner le parent** termine le groupage.
4. La réponse JSON renvoie le compteur `colis_count / max_colis`, le dernier scan et l'état du groupage.

**Autres actions sur les colis**
- **Dé-scanner** : `POST /colis/delete/{id}`.
- **Déplacer** un colis (`/colis/move/{id}`) ou un lot (`/colis/massive-move`) vers une autre palette. Pour un colis enfant, c'est le parent qui est déplacé. Conditions : palettes source et destination **ouvertes**, appartenant à la **même PL**, et capacité disponible.
- **Commenter** : `POST /colis/update-comment/{id}`.

### 5.4 Théorique et écarts — [TheoreticalExportController.php](../src/Controllers/TheoreticalExportController.php)

- **Import** (`POST /theoretical-exports/process-import`) : un fichier CSV séparé par `;`, avec une ligne d'en-tête, et 4 colonnes `OF ; N° commande ; Réf. article ; Qté attendue`.
  - Contrôles : extension et type MIME, BOM UTF-8 retiré, 50 000 lignes maximum, PL ouverte et dans le périmètre de l'utilisateur.
  - L'import **remplace** le théorique de la PL dans une transaction : suppression puis insertion. En cas d'erreur, l'ancien théorique est conservé.
- **Écarts** (`GET /theoretical-exports/deviations?packing_list_id=…`) : l'application rapproche, pour chaque clé `OF | commande | article`, la quantité attendue et la quantité scannée, et affiche l'écart = **scanné − attendu**. Une ligne présente d'un seul côté apparaît avec 0 de l'autre.

### 5.5 Références automatiques — [ReferenceGenerator.php](../src/Services/ReferenceGenerator.php)

Les codes des PL et des palettes suivent des **patrons** stockés dans `settings` (`packing_list_reference_pattern`, `palette_code_pattern`) et modifiables depuis `/settings`. Exemple de code obtenu : `PAL-BK1-2026-S19-0281`.

| Placeholder | Valeur |
|---|---|
| `{ID}`, `{ID_PL}`, `{ID_PALETTE}` | ID technique de l'enregistrement |
| `{SITE_CODE}` | Code du site |
| `{YYYY} {YY} {MM} {DD} {WW} {HH} {II} {SS}`… | Éléments de date : date d'expédition pour une PL, date du jour pour une palette |
| `{COUNTER_<PORTÉE>[_SITE]_<N>DIGITS}` | Compteur incrémenté dans `dynamic_counters`, complété par des zéros. Portées : `GLOBAL`, `YEARLY`, `MONTHLY`, `WEEKLY`, `DAILY` ; option `_SITE` pour un compteur par site |

### 5.6 Recherche — [SearchService.php](../src/Services/SearchService.php)

Une seule barre de recherche (Ctrl+K). La requête est traitée selon sa forme :
1. Elle contient `;` ou `|` → c'est un QR colis : recherche exacte sur `package_number`.
2. Elle commence par `PAL` → recherche par préfixe sur les palettes.
3. Elle commence par `PL` → recherche par préfixe sur les PL.
4. Sinon → recherche FULLTEXT (mode booléen) sur les colis, les palettes et les PL.

Il existe aussi des recherches dédiées par OF (`/search/by-of`) et par SKU (`/search/by-sku`).

### 5.7 Tableau de bord — `GET /`

Il affiche : le nombre de PL ouvertes et de PL fermées aujourd'hui, les palettes générées ou ouvertes, les colis scannés aujourd'hui, une courbe des scans par jour, l'activité récente et les PL en attente. Les chiffres sont filtrés par site.

## 6. Sécurité et droits

- **Authentification** ([Auth.php](../src/Lib/Auth.php)) : mot de passe vérifié par `password_verify` (le hash est mis à jour automatiquement si nécessaire), ID de session régénéré à la connexion, compte inactif refusé. La table `login_attempts` journalise les tentatives de connexion.
- **Permissions** : chaque action appelle `authorize('<permission>')`. L'utilisateur reçoit ses permissions par ses rôles (`user_roles` → `role_permissions`). Permissions utilisées dans le code :
  - PL : `packinglist_read_all`, `_create`, `_update`, `_close`, `_reopen`, `_delete`, `_export_colis_xlsx`, `_export_colis_pdf`
  - Palettes : `palette_create`, `_read_all`, `_read_details`, `_open`, `_update_max_colis`, `_close`, `_reopen`, `_delete`
  - Colis : `colis_scan`, `colis_move`, `colis_delete`, `colis_update_comment`
  - Autres : `theoretical_import_csv`, `theoretical_view_deviations`, `dashboard_view`, `site_manage`
- **Cloisonnement par site** : `isAccessibleBy()` sur PackingList, Palette et Colis. Un utilisateur rattaché à un site ne voit **que** ce site. Un **Administrateur** ou un utilisateur **sans site** voit tout.
- **CSRF** : contrôle central sur tous les POST. Un appel AJAX refusé reçoit une erreur 403 en JSON avec `reload: true`.
- **SQL** : toutes les requêtes passent par des paramètres liés ; la clause `ORDER BY` est limitée à une liste blanche de colonnes.

## 7. Configuration et déploiement

| Variable d'environnement | Défaut | Effet |
|---|---|---|
| `APP_ENV` | `development` | `production` masque les erreurs et impose `DB_USER` / `DB_PASSWORD` |
| `DB_HOST`, `DB_PORT`, `DB_NAME` | `localhost`, `3306`, `packing_list_db` | Connexion MySQL |
| `DB_USER`, `DB_PASSWORD` | `root` / vide (hors prod) | Identifiants MySQL |

- Les URL de base (`APP_URL` / `BASE_URL`) sont calculées automatiquement à partir du protocole, de l'hôte et du dossier du script.
- Fuseau horaire : `Europe/Paris`. Les logs sont dans `logs/` (dossier créé automatiquement).
- Installation : `composer install`, import de `database/packing_list_db.sql`, puis application des scripts de `database/migrations/` (voir leur `README.md`).

## 8. Points d'attention relevés pendant l'analyse

1. **Compteur hebdomadaire autour du Nouvel An** : `ReferenceGenerator` construit la clé du compteur `WEEKLY` avec `format('YW')`, c'est-à-dire l'année civile et la semaine ISO. Pour une date comme le 30/12/2025 (semaine ISO 01 de 2026), la clé devient `202501`, qui est déjà celle de début janvier 2025. Le compteur de janvier 2025 repart donc de là où il s'était arrêté. L'import du théorique, lui, utilise bien `format('o')` (année ISO).
2. **Écarts arrondis** : la quantité scannée est convertie en entier (`(int)`) dans le calcul des écarts, alors que `colis.quantity` est en `DECIMAL(10,3)`. Les quantités décimales sont donc tronquées.
3. **Index en double** : `colis.package_number`, `packing_lists.reference` et `palettes.code` ont chacun deux index `UNIQUE` identiques (l'index d'origine et celui de la migration `unique_constraints`). Ce n'est pas bloquant, mais cela alourdit les écritures.
4. **Fermeture d'une PL** : les palettes vides sont **supprimées** sans confirmation spécifique. Il faut le savoir avant de fermer.
5. **Utilisateur sans site** : il a la même visibilité qu'un administrateur sur tous les sites. Il faut le prendre en compte lors de la création des comptes.
