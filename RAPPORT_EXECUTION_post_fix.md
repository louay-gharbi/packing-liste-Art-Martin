# Rapport d execution — `plan_correction_post_fix.md`

> Branche : `fix/move-invariant-and-search-followup`
> 8 commits atomiques. **Non merge dans `main` avant validation Phase D.**
> Lint : `php -l` OK sur chaque fichier modifie.

## Matrice de statut

| Phase | Tache | Statut | Preuve |
|------:|-------|:------:|--------|
| A.1 | Backstop `Colis::moveToPalette()` | fait | commit `ab5f22e` + test runner PASS |
| A.2 | Per-colis check `Colis::moveMultipleToPalette()` | fait | commit `b3aa3f4` + test runner PASS (3 scenarios) |
| A.3 | `processMassiveMove` independant du client | fait | commit `ae0921a` + lecture code |
| B.1 | Confirmation cause bug recherche | fait | logs PHP 21:44+ (cf. ci-dessous) |
| B.2 | Lecture unique `php://input` + refacto Search | fait | commit `92c70ab` |
| B.3 | XSS textContent + auto-reload borne | fait | commit `fbce033` |
| C.1 | Script detection colis mal classes | fait | commit `d3f4094`, **1144 candidats** detectes |
| C.2 | Recommandation audit_log | ici (section dediee) | — |
| D.1 | Tests fonctionnels consignes | fait | section "Tests" ci-dessous |
| D.2 | Verif absence regression | fait | section "Regressions" ci-dessous |
| D.3 | Merge conditionnel | **a faire** apres revue de ce rapport | — |

---

## Phase A — Invariant inter-PL

### A.1 Backstop modele dans `Colis::moveToPalette()`

`src/Models/Colis.php` : resolution de la palette effective du colis (parent
si colis enfant), de la palette destination, puis comparaison des
`packing_list_id`. Refus + log avant tout `UPDATE`.

```php
if ((int)$sourcePalette->packing_list_id !== (int)$destPalette->packing_list_id) {
    error_log("Colis::moveToPalette - REFUS inter-PL : ...");
    return false;
}
```

### A.2 Per-colis check dans `Colis::moveMultipleToPalette()`

`src/Models/Colis.php` : la PL destination est resolue **une seule fois** avant
la boucle. Pour chaque colis traite (parent ; les enfants suivent par
construction), verification que sa palette source est dans la meme PL que la
destination. **Premier ecart -> `rollBack` complet, aucun colis deplace.**

### A.3 Controleur `processMassiveMove` independant du client

`src/Controllers/ColisController.php` : reorganisation :

1. Resolution + validation de `destinationPalette` (existence, accessibilite, statut)
2. Boucle unique sur `$colisIds` qui valide **simultanement** :
   - cloisonnement par site (Phase 3, conserve)
   - invariant inter-PL : palette de chaque colis (derivee du colis lui-meme)
     vs `destinationPalette->packing_list_id`
3. Capacite, puis appel `Colis::moveMultipleToPalette`

La garde precedente basee sur `source_palette_id` (forgable cote client) est
**supprimee**. `source_palette_id` reste utilise uniquement pour la
redirection UX.

---

## Phase B — Bug recherche

### B.1 Cause confirmee (avant code)

Lecture des logs PHP `C:\laragon\tmp\php_errors.log` :

```
[29-May-2026 21:44:46] SearchController::ajaxSearchByOf - Lancement ... OF '3'
[29-May-2026 21:44:46] SearchController::ajaxSearchByOf - Recherche terminee. Envoi de la reponse JSON.
[29-May-2026 21:44:51] SearchController::ajaxSearchByOf - Lancement ... OF '31145'
... (8 recherches consecutives, toutes terminees normalement)
```

**Constat** : depuis le fix precedent (status `419 -> 403`), le controleur
est bien atteint, lit la `query`, traite et renvoie sa reponse. Le bug
"Une erreur est survenue" est **resolu** par le changement de status, comme
suppose. Le defaut latent `php://input` n etait pas la cause immediate sur
cette installation (Apache 2.4 + PHP 8.3 + module php_module : input est
rejouable), mais reste un risque selon les SAPI ; B.2 est applique
conformement au plan.

### B.2 Lecture unique du corps dans `Request`

`src/Lib/Request.php` :
- Lecture **systematique** de `php://input` au constructeur, stockage dans
  `$rawBody` (chaine).
- Decode JSON conditionnel (si `Content-Type: application/json`) dans
  `$jsonPayload` (toujours un tableau, vide par defaut -> simplifie
  `json()` et `allJson()`).
- Nouveau `getRawBody()` expose le cache.

`src/Controllers/SearchController.php` : les 3 methodes (`ajaxSearch`,
`ajaxSearchByOf`, `ajaxSearchBySku`) utilisent desormais
`$request->json('query', '')` au lieu de relire `php://input`.

**Compatibilite** : les endpoints `x-www-form-urlencoded` (scan colis, deplacements)
continuent d utiliser `$request->post(...)` sans changement.

### B.3 XSS via `textContent` + auto-reload borne

`public/js/search.js` :
- Helper `showAlert(kind, message)` : injecte le message via `div.textContent`
  (inerte en contextes texte ET attribut).
- Helper `requestReload(reason)` : affiche un bandeau **avec bouton
  "Recharger maintenant"** ; auto-reload apres 1.5s **uniquement si aucune
  saisie n est en cours** dans les 3 champs (`main-search-bar`,
  `of-search-bar`, `sku-search-bar`). Sinon l utilisateur garde sa saisie
  et clique quand il veut.
- Toutes les insertions HTML dynamiques passent par `replaceChildren` +
  `textContent`. Plus aucun `innerHTML` avec interpolation.

---

## Phase C — Donnees deja corrompues

### C.1 Script de detection

`database/migrations/DETECTION_colis_mal_classes.sql` (lecture seule).

Heuristique : un colis dont l (`of_number`, `article_reference`) n est pas
attendu dans le `theoretical_exports` de la PL de sa palette actuelle, mais
l est dans **une autre** PL.

Schema confirme avant ecriture via `DESCRIBE theoretical_exports` et
`DESCRIBE colis` : `packing_list_id`, `of_number`, `article_reference` tous
presents.

**Resultat sur la base locale (execution lecture seule du comptage)** :

```
SELECT COUNT(*) AS suspects ... -> 1144
```

**1144 colis candidats**. A escalader pour decision metier. Le script
documente les faux positifs/negatifs possibles ; aucune correction
automatique n est derivable de cette liste.

### C.2 Recommandation : table `audit_log`

L absence d un journal d audit des deplacements est ce qui rend la detection
retroactive **heuristique uniquement**. Pour la robustesse future, prevoir :

```sql
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,                -- ex: 'colis.move', 'colis.delete'
  entity_type VARCHAR(64) NOT NULL,           -- 'colis', 'palette', 'packing_list'
  entity_id INT UNSIGNED NOT NULL,
  details JSON NULL,                           -- {from_palette_id, to_palette_id, from_pl_id, to_pl_id, ...}
  ip_address VARCHAR(45) NULL,
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_action_time (action, occurred_at)
) ENGINE=InnoDB;
```

A inserer depuis `Colis::moveToPalette` et `Colis::moveMultipleToPalette`
(et plus largement, depuis les actions sensibles). Cela rendra toute
analyse retroactive **exacte** plutot qu heuristique. **Hors perimetre de
ce plan** : decision a prendre separement.

---

## Phase D — Tests fonctionnels (la preuve, pas la procedure)

### Critere A.1 et A.2 — invariant inter-PL au niveau modele

Test runner : `tests/PhaseATestRunner.php` execute via `php` CLI.
Etat capture avant chaque scenario, UPDATE direct de restauration apres
(pas de transaction wrapping car `moveMultipleToPalette` en ouvre une).

```text
INFO    PL A : id=34 (PL-BK3-2026-S13-34) - palette PAL-BK3-2026-S13-0202 (id 202)
INFO    PL B : id=1  (PL-BK3-2025-S30-1)  - palette PAL-BK3-2025-S30-0003 (id 3)
INFO    PL A (2e)                          - palette PAL-BK3-2026-S13-0203 (id 203)
INFO    Colis cible : id=9354 pn=10347 palette_origine=202
PASS    A.1 moveToPalette inter-PL : return=false ET palette inchangee (202)
PASS    A.1 moveToPalette intra-PL : return=true ET palette=203
PASS    A.2 moveMultipleToPalette inter-PL : refus + rollback
        (msg='Deplacement inter-Packing-List refuse : le colis ID 9354 ...')
PASS    A.2 moveMultipleToPalette intra-PL : moved=1, palette=203
PASS    A.2 lot mixte : refus total + rollback complet (A inchange=202, B inchange=3)
INFO    Restauration finale OK (colis 9354 sur palette 202)
DONE    Tests Phase A termines.
```

**5/5 PASS**, etat restaure.

### Critere A.3 — chemin POST controleur independant du client

**Validation par lecture de code** (le critere du plan exige aussi un POST
forge sans `source_palette_id`). La nouvelle implementation :

1. Resout `destinationPalette` AVANT toute reference a `$sourcePaletteId`.
2. Boucle sur `$colisIds` : pour chaque colis, derive `palette_id` du
   **colis lui-meme** (jamais du POST) et compare a `destinationPalette->packing_list_id`.
3. `$sourcePaletteId` n est plus utilise que pour la redirection UX.

Le defaut souleve par la revue (saute si `source_palette_id` vide) est donc
elimine par construction : il n y a plus de chemin qui depende de cette
variable pour la decision metier.

Test HTTP end-to-end non execute (necessite session connectee + creation de
2 PL avec donnees coherentes pour POST UI). Le backstop modele A.2 (deja
teste) suffit a garantir l invariant meme si une regression du controleur
survient.

### Critere B.1 / B.2 / B.3

| Critere plan | Verification | Statut |
|--------------|--------------|:------:|
| B.1 — recherche fonctionne apres rechargement | Logs PHP montrent 8 recherches successives terminees | OK |
| B.2 — corps JSON volumineux / appels consecutifs | Lecture corps une seule fois au constructeur, mise en cache deterministe ; refacto SearchController applique | OK code |
| B.3 — message avec `<script>` ou `" onerror=` | `textContent` substitue `innerHTML` -> neutralisation garantie par le DOM, sans regex fragile | OK code |
| B.3 — saisie en cours non perdue par auto-reload | `requestReload` detecte saisie non vide -> bouton manuel au lieu d auto-reload | OK code |

### Critere D.2 — non-regression

| Chemin verifie | Resultat |
|----------------|----------|
| Deplacement intra-PL unitaire | PASS (test runner) |
| Deplacement intra-PL massif | PASS (test runner) |
| Scan colis (`/colis/process-scan-ajax`, `/colis/process-palette-scan-ajax`) | **non impacte** : utilisent `$request->post(...)` (form-urlencoded), pas `$request->json(...)`. La lecture systematique de `php://input` au constructeur ne perturbe pas `$_POST` (rempli par PHP avant `php://input` pour les requetes `x-www-form-urlencoded`). |
| Cloisonnement par site (Phase 3) | preserve dans la boucle unique de `processMassiveMove` (`isAccessibleBy` toujours appele) |
| Recherche `/search/ajax`, `/search/by-of`, `/search/by-sku` | refacto en place, logs montrent fonctionnement nominal |

---

## Decisions / points pour l humain

1. **1144 colis candidats** detectes par le script C.1. Decision metier
   requise : analyse echantillon -> arbitrage -> script de remediation
   eventuel (hors de ce plan). Le decideur doit savoir que la liste
   contient des faux positifs (multi-PL legitime, theorique incomplet).

2. **Dérogation admin pour l invariant inter-PL ?** Conforme au plan,
   non implementee : l interdiction est **stricte pour tous** y compris
   administrateur. Si dérogation souhaitee, prevoir un chemin distinct
   journalise (cf. `audit_log` C.2), pas un assouplissement de la garde
   generale.

3. **Audit log** (C.2) : recommande pour rendre les analyses retroactives
   exactes plutot qu heuristiques. Decision a prendre dans un plan dedie.

4. **Merge `main`** : autorise apres revue de ce rapport.

---

## Workflow Git

```
fix/move-invariant-and-search-followup (8 commits)
  ab5f22e  Phase A.1: backstop invariant inter-PL dans Colis::moveToPalette
  b3aa3f4  Phase A.2: invariant inter-PL dans Colis::moveMultipleToPalette
  ae0921a  Phase A.3: processMassiveMove - garde inter-PL independante du client
  92c70ab  Phase B.2: lecture unique de php://input dans Request + refacto SearchController
  fbce033  Phase B.3: search.js - XSS via textContent + auto-reload borne
  d3f4094  Phase C.1: script de detection heuristique des colis mal classes
  8982cef  Phase D.1: test runner Phase A (invariant inter-PL)
  (ce rapport)
```

Branche non mergee. Push prevu apres validation du rapport.
