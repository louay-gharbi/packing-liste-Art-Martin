# Rapport de correction — 2026-05-29

> Branche : `fix/move-and-search-bugs` (`1986c9e`)
> Merge dans `main` : commit `608593d` (`b028ddc..608593d`)
> Trois bugs signales suite a la phase de securisation.

---

## Bug 1 — Recherche : "Une erreur est survenue" sur tous les cas

### Symptome
A `/packinglist/public/search`, toute saisie dans les champs OF / SKU / Globale
declenche l affichage du message generique `Une erreur est survenue.` dans le
conteneur de resultats, sans aucun resultat valide.

### Cause racine
Deux defauts qui se combinent depuis le deploiement Phase 1 (CSRF) :

1. **Status HTTP non-standard**.
   Le middleware CSRF central ([public/index.php](public/index.php)) renvoyait
   `419 Page Expired` (convention Laravel mais non-IANA).
   Apache 2.4 sur Laragon, ne connaissant pas ce code, l affichait en
   `500 Internal Server Error` dans la status line meme si le body PHP etait
   bien envoye en JSON.
   Cote JS, `response.ok` etait `false` (donc throw) et le `catch` masquait
   l erreur reelle (`payload.error`) au profit d un texte generique.

2. **Token CSRF possiblement absent cote client**.
   `window.csrfToken()` lit la balise `<meta name="csrf-token">`. Si l onglet
   du navigateur avait ete charge **avant** le deploiement de la Phase 1
   (pas de balise meta dans le HTML cache), le token envoye etait vide, donc
   la validation CSRF echouait systematiquement. L utilisateur ne voyait pas
   le besoin de recharger la page : l erreur etait silencieuse.

### Correctifs

**`public/index.php`** (middleware CSRF central)
- Status passe de **419 -> 403 Forbidden** : standard, accepte par Apache,
  conserve par les proxies.
- Le JSON de reponse inclut desormais `"reload": true` pour signaler au
  client AJAX qu un rechargement de la page resoudra le probleme (nouveau
  token genere).

```php
$resp = Response::json([
    'error' => 'Jeton de sécurité invalide ou expiré. Rechargez la page.',
    'reload' => true,
], 403);
```

**`public/js/search.js`** (handler de recherche)
- Detection **token vide en amont** : si `window.csrfToken()` renvoie une
  chaine vide, on ne tente meme pas la requete, on affiche un message court
  et on declenche `window.location.reload()` apres 800ms.
- Parsing du body JSON **meme quand `response.ok === false`** : le
  middleware CSRF emet du JSON valide, on lit `payload.error` et on
  l affiche reellement plutot que de masquer derriere "Une erreur est
  survenue.".
- Sur `payload.reload === true`, auto-reload de la page apres 800ms.
- Le message d erreur affiche est echappe (`replace(/[<>]/g, '')`) pour
  eviter une XSS via un message serveur mal formate.

### Verification
1. Recharger la page de recherche (Ctrl+F5) pour avoir la balise meta CSRF.
2. Lancer une recherche : doit fonctionner.
3. Si l erreur revient, la console navigateur affiche desormais le message
   precis du serveur (CSRF, BDD, etc.).
4. Si le token CSRF a expire (session relancee cote serveur), la page se
   recharge automatiquement au lieu d echouer silencieusement.

---

## Bug 2 — Message d erreur affichait "Array" au lieu du nombre

### Symptome
Lors d un deplacement massif vers une palette qui n a pas assez de capacite :
```
Capacité insuffisante sur la palette destination.
Il reste 0 places mais Array colis sélectionnés.
```
Le mot `Array` apparait au lieu du nombre de colis selectionnes.

### Cause racine
Bug **pre-existant a la securisation** (deja present dans le code initial).
Dans `ColisController::processMassiveMove`, le message etait construit par
interpolation directe du tableau `$colisIds` :

```php
$this->flash(FLASH_ERROR, "... mais {$colisIds} colis sélectionnés.");
```

PHP convertit un tableau en chaine sous forme litterale `"Array"` et emet
le warning `Array to string conversion` (visible dans `php_errors.log`
ligne 834).

### Correctif

**`src/Controllers/ColisController.php`** (processMassiveMove)
Introduction d une variable explicite et utilisation dans le message :

```php
$nbColis = count($colisIds);
// ...
$this->flash(FLASH_ERROR, "Capacité insuffisante sur la palette destination. Il reste {$remaining} places mais {$nbColis} colis sélectionnés.");
```

### Verification
1. Selectionner N colis sur une palette source.
2. Tenter de les deplacer vers une palette destination dont la capacite
   restante est strictement inferieure a N.
3. Le message affiche desormais le bon nombre.

---

## Bug 3 — CRITIQUE METIER : deplacement de colis entre Packing Lists differentes

### Symptome
Un colis appartenant a la palette `PAL-BK3-2026-S17-0237` (PL `PL-BK3-2026-S17-001`)
pouvait etre deplace vers une palette `PAL-BK3-2026-S18-0261` (PL `PL-BK3-2026-S18-002`).
Resultat : un colis se retrouvait rattache a une PL **differente** de celle
de sa palette d origine, brisant l invariant metier
*"un colis appartient a une et une seule Packing List"*.

### Cause racine
Aucune verification metier n existait dans `processMove` ni
`processMassiveMove`. Seules les verifications suivantes etaient faites :
- destination accessible par l utilisateur (cloisonnement par site, ajoute
  en Phase 3)
- destination != source
- destination ouverte
- destination a de la capacite

Mais rien ne forcait `destination.packing_list_id == source.packing_list_id`.

### Correctifs

**`src/Controllers/ColisController.php`**

`processMove(int $colisId)` — insertion **apres** la verification
`destination != source` et **avant** la verification de statut :

```php
if ((int)$destinationPalette->packing_list_id !== (int)$sourcePalette->packing_list_id) {
    $this->flash(FLASH_ERROR, "Le deplacement est interdit : la palette de destination \""
        . htmlspecialchars($destinationPaletteCode)
        . "\" appartient a une autre Packing List que la palette source.");
    $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
    $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
    return $this->redirect('/palettes/show/' . $sourcePaletteId);
}
```

`processMassiveMove()` — meme garde, basee sur `source_palette_id`
declaree en POST :

```php
if ($sourcePaletteId) {
    $sourcePalette = Palette::findById($sourcePaletteId);
    if ($sourcePalette && (int)$destinationPalette->packing_list_id !== (int)$sourcePalette->packing_list_id) {
        $this->flash(FLASH_ERROR, "Le deplacement est interdit : ...");
        // re-affiche la modale + ids selectionnes
        return $this->redirect('/palettes/show/' . $sourcePaletteId);
    }
}
```

### Verification
1. Creer deux PL distinctes (ex. `PL-A`, `PL-B`) avec au moins une palette
   ouverte chacune.
2. Scanner un colis sur une palette de `PL-A`.
3. Tenter de le deplacer vers une palette de `PL-B` (via modale unitaire OU
   massive).
4. Le deplacement doit etre **refuse** avec le message clair, et le colis
   reste sur sa palette d origine.
5. Le deplacement vers une autre palette de la meme `PL-A` reste autorise.

### Impact securite/donnees
- Aucune fuite de donnees (le colis restait dans le site de l utilisateur
  grace au cloisonnement Phase 3).
- Mais incoherence des compteurs / exports : un export de `PL-B` aurait
  liste un colis qui n a jamais appartenu a `PL-B` selon les regles metier.
- Pour les deplacements **passes** non controles : un script de detection
  est recommande :
  ```sql
  SELECT c.id, c.package_number, p.code AS palette_code,
         p.packing_list_id AS palette_pl, pl_via_colis.id AS colis_pl
  FROM colis c
  JOIN palettes p ON c.palette_id = p.id
  -- comparer p.packing_list_id avec ce qui etait attendu
  -- (necessite un log applicatif des deplacements, non-existant actuellement)
  ```
  En pratique, sans log, les anciens deplacements croises sont invisibles.

---

## Fichiers modifies

| Fichier | Lignes modifiees | Phase concernee |
|---------|:----------------:|-----------------|
| `public/index.php` | +5 / -1 | Bug 1 (CSRF status + reload flag) |
| `public/js/search.js` | +38 / -5 | Bug 1 (UX + parsing) |
| `src/Controllers/ColisController.php` | +25 / -2 | Bugs 2 et 3 |

Total : **3 fichiers, +60 / -8 lignes**.

## Workflow Git

```
fix/move-and-search-bugs (1986c9e)
   |
   +-- merge --no-ff into main
   |
main (608593d) -> push origin main : b028ddc..608593d
```

Branche `fix/move-and-search-bugs` conservee sur le remote pour reference.
Peut etre supprimee via `git push origin --delete fix/move-and-search-bugs`.

## Recommandations de suivi

1. **Test fonctionnel en condition reelle** : se connecter avec un compte
   Superviseur ou Operateur, jouer les 3 scenarios de verification.
2. **Surveillance des logs** : verifier que `php_errors.log` ne contient
   plus l avertissement `Array to string conversion`.
3. **Bug 1 si persiste** : ouvrir la console navigateur (F12 -> Network)
   et observer la requete `POST /search/ajax` : status HTTP et body de la
   reponse. Le message reel sera maintenant visible.
4. **Bug 3 - retro-analyse optionnelle** : si un audit metier est demande
   sur les colis mal classes par le passe, prevoir une migration de
   nettoyage. Sans log de deplacement, seul un croisement payload QR <->
   palette actuelle <-> PL actuelle peut donner une heuristique.
