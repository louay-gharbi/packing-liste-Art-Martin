# Plan de correction — défauts du fix 2026-05-29

> **Destinataire : agent IA d'exécution.**
> Corrige les défauts relevés en revue du `RAPPORT_FIX_2026-05-29.md`.
> Mêmes conventions que les plans précédents : ne pas changer le comportement métier hors de ce qui est décrit,
> respecter le style du code (modèles statiques, `getPDO()`, requêtes préparées, `$this->flash()`, `Response::json()`),
> réutiliser la définition canonique du cloisonnement par site déjà en place.
> **Règle de méthode imposée par cette itération : aucun correctif n'est considéré comme terminé tant que son test fonctionnel n'a pas été exécuté et consigné (pas seulement décrit).** C'est précisément ce qui a manqué au fix précédent.

> Branche de travail : `fix/move-invariant-and-search-followup`. Un commit atomique par tâche. **Ne pas merger dans `main` avant la Phase D (tests).**

---

## Constat de départ (ce que la revue a établi)

1. **L'invariant inter-PL n'est correctement appliqué que sur le chemin unitaire (`processMove`).** Sur le chemin massif (`processMassiveMove`), la garde ajoutée par le fix est (a) contournable car elle dépend de `source_palette_id` fourni par le **client**, et saute entièrement si cette valeur est vide/absente ; (b) vérifie le **mauvais invariant** : elle compare une palette source *supposée commune* à la destination, au lieu de vérifier **chaque colis réellement déplacé** contre la PL de destination.
2. **L'invariant n'est pas porté par la couche modèle.** `Colis::moveToPalette()` et `Colis::moveMultipleToPalette()` exécutent l'`UPDATE` sans aucune connaissance de la notion de Packing List. Tout chemin qui appelle ces méthodes (présent ou futur) peut donc violer la règle. La règle doit être un **backstop dans le modèle**, dans la transaction, en plus des messages UX dans les contrôleurs.
3. **Bug 1 (recherche) : cause unique non confirmée.** Le fix traite le code HTTP 419→403 mais n'a jamais écarté l'hypothèse alternative de la **double lecture de `php://input`** : si le middleware CSRF consomme le flux et que les contrôleurs de recherche le relisent vide, on obtient le même symptôme, non résolu par le changement de status.
4. **Échappement XSS faible dans `search.js`** : `replace(/[<>]/g,'')` ne protège pas en contexte d'attribut. À remplacer par une affectation `textContent`.
5. **Auto-reload à 800 ms** : peut faire perdre une saisie en cours. À conserver mais à border.
6. **Détection des données déjà corrompues abandonnée à tort.** Une heuristique de détection existe via le croisement `payload_json` (OF/article) ↔ `theoretical_exports`. À fournir, en escalade humaine, sans correction automatique.

---

## Phase A — Invariant « un colis reste dans sa Packing List » (priorité 1)

> Objectif : rendre l'invariant **inviolable quel que soit le chemin d'appel**, et corriger le chemin massif pour qu'il vérifie chaque colis réellement déplacé.

### Tâche A.1 — Porter l'invariant dans `Colis::moveToPalette()` (backstop modèle)

Modifier `src/Models/Colis.php`. La méthode reçoit un `colisId` et un `newPaletteId` ; avant l'`UPDATE`, résoudre la PL courante du colis et la PL de destination, et refuser si elles diffèrent.

```php
public static function moveToPalette(int $colisId, int $newPaletteId): bool
{
    try {
        $pdo = getPDO();

        // Resolution de la PL source (palette actuelle du colis ; si colis enfant,
        // remonter au parent pour trouver la palette effective).
        $colis = self::findById($colisId);
        if (!$colis) {
            error_log("Colis::moveToPalette - colis {$colisId} introuvable.");
            return false;
        }
        $sourcePaletteId = $colis->palette_id;
        if ($sourcePaletteId === null && $colis->parent_colis_id !== null) {
            $parent = self::findById($colis->parent_colis_id);
            $sourcePaletteId = $parent?->palette_id;
        }

        $sourcePalette = $sourcePaletteId !== null ? Palette::findById((int)$sourcePaletteId) : null;
        $destPalette   = Palette::findById($newPaletteId);

        if (!$sourcePalette || !$destPalette) {
            error_log("Colis::moveToPalette - palette source ou destination introuvable.");
            return false;
        }

        // INVARIANT METIER : pas de deplacement inter-Packing-List.
        if ((int)$sourcePalette->packing_list_id !== (int)$destPalette->packing_list_id) {
            error_log("Colis::moveToPalette - REFUS inter-PL : colis {$colisId} "
                . "(PL {$sourcePalette->packing_list_id}) -> palette {$newPaletteId} "
                . "(PL {$destPalette->packing_list_id}).");
            return false;
        }

        $stmt = $pdo->prepare("UPDATE colis SET palette_id = :pid, updated_at = NOW() WHERE id = :cid");
        $stmt->bindValue(':pid', $newPaletteId, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $colisId, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Erreur Colis::moveToPalette pour colis ID {$colisId} vers palette ID {$newPaletteId}: " . $e->getMessage());
        return false;
    }
}
```

**Pièges** :
- `findById` doit déjà être disponible dans le modèle (il l'est). Ne pas introduire de requête redondante : une lecture du colis + deux `Palette::findById` suffisent.
- Ne PAS supprimer la garde UX du contrôleur (`processMove`) : elle donne un message clair à l'opérateur. Le modèle est le filet, pas le substitut. Défense en profondeur.

### Tâche A.2 — Corriger `Colis::moveMultipleToPalette()` : vérifier CHAQUE colis dans la transaction

Modifier `src/Models/Colis.php`. La méthode boucle déjà sur les colis dans une transaction. Ajouter, **pour chaque colis traité (parent comme enfant)**, la vérification d'appartenance à la même PL que la destination. Au premier écart, `rollBack` complet du lot.

Insérer la résolution de la PL de destination **une seule fois avant la boucle** :

```php
$destPalette = Palette::findById($newPaletteId);
if (!$destPalette) {
    $result['success'] = false;
    $result['errors'][] = "Palette de destination introuvable.";
    return $result;
}
$destPlId = (int)$destPalette->packing_list_id;
```

Puis, dans la boucle, **avant** l'`UPDATE` de chaque colis (après avoir résolu le colis et son éventuel parent) :

```php
// INVARIANT METIER : refuser tout le lot si un colis n'est pas dans la meme PL que la destination.
$colisSourcePaletteId = $colis->palette_id;
if ($colisSourcePaletteId === null && $colis->parent_colis_id !== null) {
    $p = self::findById($colis->parent_colis_id);
    $colisSourcePaletteId = $p?->palette_id;
}
$colisSourcePalette = $colisSourcePaletteId !== null ? Palette::findById((int)$colisSourcePaletteId) : null;
if (!$colisSourcePalette || (int)$colisSourcePalette->packing_list_id !== $destPlId) {
    $pdo->rollBack();
    $result['success'] = false;
    $result['errors'][] = "Déplacement inter-Packing-List refusé : le colis ID {$colisId} "
        . "n'appartient pas à la même Packing List que la palette de destination. Aucun colis n'a été déplacé.";
    return $result;
}
```

**Pièges** :
- La vérification doit être faite **avant** le premier `UPDATE` effectif, ou au pire avant le `commit`, mais comme on veut « tout ou rien », la rejeter dès le premier écart avec `rollBack` est correct. Vérifier que `$pdo->inTransaction()` est vrai au moment du `rollBack` (la transaction est ouverte en début de méthode).
- Ne PAS se contenter de vérifier la première palette : c'est l'erreur du fix précédent. Chaque `colisId` du tableau peut théoriquement être sur une palette différente (le POST le permet même si l'UI ne le propose pas couramment). La vérification est **par colis**.
- Les enfants suivent le parent et sont par construction sur la même palette : il suffit de vérifier le colis de tête (parent) de chaque groupe, ce que fait la boucle existante. Ne pas dupliquer la vérification sur chaque enfant (inutile et coûteux).

### Tâche A.3 — Rendre la garde contrôleur de `processMassiveMove` indépendante du client

Modifier `src/Controllers/ColisController.php`, méthode `processMassiveMove`. La Phase 3 a déjà introduit une boucle sur `$colisIds` pour le cloisonnement par site (chaque colis est chargé et testé par `isAccessibleBy`). **Réutiliser cette même boucle** pour vérifier l'appartenance PL, en dérivant la PL source **du colis lui-même**, jamais de `source_palette_id` du POST.

Dans la boucle de validation des colis (celle qui appelle déjà `Colis::findById($cid)` et `isAccessibleBy`), résoudre la palette du colis et comparer sa PL à celle de la destination :

```php
// (dans la boucle existante sur $colisIds, apres avoir charge $c = Colis::findById($cid))
$cPaletteId = $c->palette_id;
if ($cPaletteId === null && $c->parent_colis_id !== null) {
    $cParent = Colis::findById($c->parent_colis_id);
    $cPaletteId = $cParent?->palette_id;
}
$cPalette = $cPaletteId !== null ? Palette::findById((int)$cPaletteId) : null;
if (!$cPalette || (int)$cPalette->packing_list_id !== (int)$destinationPalette->packing_list_id) {
    $this->flash(FLASH_ERROR, "Déplacement refusé : un ou plusieurs colis sélectionnés "
        . "n'appartiennent pas à la même Packing List que la palette de destination.");
    $_SESSION['show_massive_move_modal'] = true;
    $_SESSION['massive_move_colis_ids'] = $colisIds;
    return $this->redirect($sourcePaletteId ? '/palettes/show/' . $sourcePaletteId : $redirectUrl);
}
```

**Pièges** :
- Cette vérification suppose que `$destinationPalette` est déjà résolue. Vérifier l'ordre : la destination est résolue (`Palette::findByCode`) avant ou après cette boucle ? Si la boucle de cloisonnement par site s'exécute **avant** la résolution de la destination, déplacer le contrôle PL juste après la résolution de la destination, dans une seconde courte boucle, ou réordonner. Lire la méthode et placer le contrôle là où `$destinationPalette` ET la liste de colis nettoyée sont disponibles.
- **Supprimer ou neutraliser** la garde ajoutée par le fix précédent qui s'appuie sur `source_palette_id` (`if ($sourcePaletteId) { $sourcePalette = Palette::findById... }`). Elle est redondante et porteuse du défaut (sautée si `source_palette_id` vide). La remplacer par la vérification par-colis ci-dessus.
- `source_palette_id` reste utilisable pour la **redirection** (UX), mais plus jamais comme base d'une décision de sécurité/intégrité.

**Critère d'acceptation A** :
1. Deux PL distinctes, chacune avec une palette ouverte. Déplacement unitaire d'un colis de PL-A vers une palette de PL-B → **refusé**, colis inchangé. Déplacement vers une autre palette de PL-A → autorisé.
2. Déplacement **massif** de PL-A vers PL-B → refusé, **aucun** colis déplacé (vérifier qu'il n'y a pas de déplacement partiel).
3. Test du backstop modèle : forger un POST massif **sans** `source_palette_id` (champ vidé) visant une palette de PL-B → toujours refusé (prouve l'indépendance au client).
4. Test « lot mixte » : si l'on parvient à soumettre des `colis_ids` issus de deux palettes différentes, le lot entier est refusé dès qu'un colis n'est pas dans la PL de destination.

---

## Phase B — Bug 1 (recherche) : confirmer la cause et fermer l'hypothèse `php://input`

### Tâche B.1 — Reproduire et confirmer la (les) cause(s) AVANT de coder

Ne pas supposer. Établir les faits :
1. Avec la console réseau (F12), déclencher une recherche et **observer le status HTTP réel** et le body de `POST /search/ajax`. Confirmer si le 403/419 apparaît, et si le body JSON est bien reçu.
2. Tester en `curl` un POST `/search/ajax` avec un token CSRF **valide** (récupéré depuis la meta-balise d'une page chargée) et un corps JSON. Observer si la réponse contient des résultats ou une erreur.
3. **Vérifier explicitement la lecture du corps** : `Csrf::validate()` lit `_csrf_token` en POST puis l'en-tête. Les contrôleurs `SearchController::ajaxSearch/ajaxSearchByOf/ajaxSearchBySku` relisent `file_get_contents('php://input')`. Tracer (log temporaire) la valeur lue par le contrôleur : est-elle vide ou complète ?

Consigner le résultat dans le rapport d'exécution. Les tâches B.2/B.3 ne s'appliquent que selon ce constat, mais **B.2 doit être faite dans tous les cas** (robustesse), même si la cause immédiate était le status code.

### Tâche B.2 — Lecture unique du corps de requête dans `Request` (corrige le défaut latent)

Modifier `src/Lib/Request.php` pour que le corps brut soit lu **une seule fois** au constructeur et mis en cache, avec un accesseur JSON. Refactorer les trois méthodes de `SearchController` pour utiliser cet accesseur au lieu de relire `php://input`.

Dans `Request` (vérifier ce qui existe déjà — le code mentionnait un `jsonPayload` partiel ; consolider) :

```php
private ?string $rawBody = null;
private ?array $jsonPayload = null;

public function __construct()
{
    // ... existant ...
    $this->rawBody = file_get_contents('php://input') ?: '';
    $decoded = json_decode($this->rawBody, true);
    $this->jsonPayload = is_array($decoded) ? $decoded : [];
}

/** Valeur issue du corps JSON (corps lu une seule fois au constructeur). */
public function json(string $key, $default = null)
{
    return $this->jsonPayload[$key] ?? $default;
}
```

Dans `SearchController`, remplacer dans `ajaxSearch`, `ajaxSearchByOf`, `ajaxSearchBySku` :

```php
// AVANT
$postData = json_decode(file_get_contents('php://input'), true);
$query = trim($postData['query'] ?? '');

// APRES
$query = trim($request->json('query', ''));
```

**Pièges** :
- `php://input` n'est pas relisable de façon fiable dans toutes les configurations (selon SAPI / consommation amont). La lecture unique au constructeur supprime la dépendance à ce comportement : c'est la vraie correction du point 1.2a signalé en revue.
- Garder une compatibilité : si une méthode attend un corps `application/x-www-form-urlencoded` (scan colis), elle continue d'utiliser `$request->post(...)` — ne pas tout basculer en JSON aveuglément. Ne refactorer que les trois méthodes de recherche qui lisaient `php://input`.

### Tâche B.3 — Corriger l'échappement XSS et borner l'auto-reload dans `search.js`

Modifier `public/js/search.js` :
1. Remplacer l'échappement par regex (`replace(/[<>]/g,'')`) par une insertion via `textContent` (ou un élément créé puis `textContent`), qui neutralise tout contexte (texte ET attribut) :
   ```js
   // au lieu d'injecter une chaine "echappee" dans innerHTML :
   const div = document.createElement('div');
   div.className = 'alert alert-danger';
   div.textContent = payload.error || 'Une erreur est survenue.';
   resultsContainer.replaceChildren(div);
   ```
2. Auto-reload : conserver le comportement sur `payload.reload === true` / token vide, mais ne le déclencher que si le champ de recherche est vide ou non modifié, afin de ne pas effacer une saisie en cours sans prévenir. À défaut, afficher un message « la page va se recharger » avant le reload.

**Critère d'acceptation B** :
1. La recherche (globale, par OF, par SKU) renvoie des résultats après rechargement de page (meta CSRF présente).
2. Un corps JSON volumineux ou un second appel consécutif ne provoque plus d'erreur due à un corps vide (test ciblé du point `php://input`).
3. Un message d'erreur serveur contenant `<script>` ou `" onerror=` s'affiche comme texte inerte, sans exécution.
4. Une saisie en cours n'est pas perdue silencieusement par l'auto-reload.

---

## Phase C — Détection des colis déjà mal classés (heuristique, escalade humaine)

> Le rapport a conclu à tort que les déplacements inter-PL passés étaient « invisibles ». Une heuristique existe via le théorique. **Aucune correction automatique** : produire un rapport de candidats pour décision humaine.

### Tâche C.1 — Requête de détection des candidats

Principe : un colis est *suspect* si l'OF / article qu'il porte (dans `payload_json`, ou dans les colonnes dédiées `of_number` / `article_reference`) n'a **jamais** été attendu dans la PL de sa palette actuelle, alors qu'il l'était dans une **autre** PL.

Produire un script `database/migrations/DETECTION_colis_mal_classes.sql` (lecture seule, à exécuter manuellement) :

```sql
-- Candidats : colis dont l'OF/article n'est pas attendu dans la PL de leur palette actuelle.
-- Heuristique : necessite un theorique present pour la PL. Faux positifs possibles
-- (OF partage entre PL, theorique incomplet). NE PAS corriger automatiquement.
SELECT
    c.id                AS colis_id,
    c.package_number,
    c.of_number,
    c.article_reference,
    p.code              AS palette_actuelle,
    p.packing_list_id   AS pl_actuelle,
    pl.reference        AS pl_actuelle_ref
FROM colis c
JOIN palettes p       ON c.palette_id = p.id
JOIN packing_lists pl ON p.packing_list_id = pl.id
WHERE NOT EXISTS (
        SELECT 1 FROM theoretical_exports te
        WHERE te.packing_list_id = p.packing_list_id
          AND te.of_number       = c.of_number
          AND te.article_reference = c.article_reference
      )
  AND EXISTS (
        SELECT 1 FROM theoretical_exports te2
        WHERE te2.of_number = c.of_number
          AND te2.article_reference = c.article_reference
          AND te2.packing_list_id <> p.packing_list_id
      );
```

**Pièges et cadrage** :
- Vérifier d'abord les noms réels de colonnes (`of_number`, `article_reference`, `packing_list_id` dans `theoretical_exports`) — la revue avait relevé une incohérence historique `sku`/`article_reference`. Exécuter `DESCRIBE theoretical_exports;` et `DESCRIBE colis;` et adapter.
- Heuristique imparfaite : la documenter clairement (faux positifs si un OF/article est légitimement présent dans plusieurs PL ; faux négatifs si le théorique manque). Le résultat est une **liste de candidats à examiner**, pas une liste d'actions.
- **Ne pas modifier de données.** Le résultat est remonté à l'humain. Une éventuelle remédiation fera l'objet d'une décision et d'un script séparé, hors de ce plan.

### Tâche C.2 — Recommandation de prévention durable

Documenter (dans le rapport d'exécution, pas de code requis ici) que l'absence de **journal d'audit des déplacements** est ce qui rend la détection rétroactive incertaine. Renvoyer à la proposition de table `audit_log` déjà formulée (traçabilité de qui déplace quoi, quand, source→destination). C'est la vraie réponse de fond : sans log, toute détection passée reste heuristique.

---

## Phase D — Tests et clôture (bloquant avant merge)

> Cette phase corrige le défaut de méthode du fix précédent : un correctif critique avait été mergé dans `main` sans test fonctionnel consigné.

### Tâche D.1 — Exécuter et consigner les tests
Exécuter **réellement** tous les critères d'acceptation des Phases A et B, avec un compte de test par profil concerné (au moins un Superviseur cloisonné). Pour chaque critère : noter le résultat observé (réussi / échoué) dans `RAPPORT_EXECUTION_post_fix.md`. Un critère non exécuté = correctif non terminé.

### Tâche D.2 — Vérifier l'absence de régression sur les chemins voisins
- Déplacement légitime intra-PL (unitaire et massif) : toujours fonctionnel.
- Scan colis (les deux endpoints AJAX) : non impacté par le refactor `Request` (vérifier, car le constructeur lit désormais systématiquement `php://input` — s'assurer que cela ne perturbe pas la lecture `post()` des requêtes `x-www-form-urlencoded`).
- Cloisonnement par site (Phase 3) : toujours actif sur les déplacements.

### Tâche D.3 — Merge conditionnel
Merger dans `main` **uniquement** après D.1 et D.2 au vert. Conserver la branche pour référence.

---

## Ordre d'exécution

| Phase | Contenu | Dépend de | Bloquant |
|------|---------|-----------|----------|
| A | Invariant inter-PL (modèle + contrôleur massif) | — | Oui (cœur du sujet) |
| B | Bug 1 : confirmer cause + lecture unique du corps + XSS | — | Oui |
| C | Détection heuristique des données déjà corrompues | schéma réel `theoretical_exports` | Non (escalade) |
| D | Tests fonctionnels consignés + merge | A, B | Oui — **avant tout merge** |

## Livrables
1. Code sur `fix/move-invariant-and-search-followup`, un commit par tâche, **non mergé** avant Phase D.
2. `database/migrations/DETECTION_colis_mal_classes.sql` (lecture seule, non exécuté en prod par l'agent).
3. `RAPPORT_EXECUTION_post_fix.md` : par tâche, statut + fichiers touchés + **résultat réel** de chaque critère d'acceptation (la preuve d'exécution, pas la procédure), et toute décision escaladée (candidats C.1, choix strict vs. dérogation admin pour l'inter-PL).

## Points à confirmer avec l'humain
- **Inter-PL strictement interdit pour tous, ou dérogation pour les administrateurs ?** Le plan applique l'interdiction stricte (choix du fix précédent, et défaut le plus sûr). Si une dérogation admin est souhaitée pour corriger des erreurs, elle devra passer par un chemin distinct, explicite et journalisé — pas par l'assouplissement de la garde générale.
- **Candidats de la Phase C** : la remédiation des colis déjà mal classés (le cas échéant) est une décision métier, hors périmètre de ce plan.
