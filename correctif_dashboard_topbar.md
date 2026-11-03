# Correctif — Remplacement de la bannière du dashboard par un bandeau professionnel

> **Destinataire : agent IA d'exécution.**
> Objectif : remplacer la « welcome bar » actuelle du tableau de bord (dégradé violet, emoji, « Bonjour {prénom} ! ») par un bandeau sobre et professionnel, sans message de bienvenue personnalisé.
> Périmètre : **un seul fichier de vue**, `src/Views/dashboard/index.php`. Aucune logique métier, aucun contrôleur, aucune requête concernés.
> Conventions habituelles : ne modifier que ce qui est décrit, conserver le style du code, un commit atomique. Merge + push en fin de procédure, uniquement si la vérification visuelle est concluante.

> Branche de travail : `chore/dashboard-topbar-redesign`.

---

## Contexte

Le dashboard affiche en haut une bannière `.welcome-bar` avec :
- un dégradé violet (`linear-gradient(135deg, #667eea 0%, #764ba2 100%)`) ;
- une icône « main » (`fa-hand-peace`) et le texte « Bonjour {first_name|username} ! » ;
- une ligne site / rôle ;
- une horloge (heure + date) à droite.

Le rendu est jugé trop *consumer* / peu professionnel. On remplace ce bloc par un bandeau institutionnel sobre, **sans salutation ni emoji**, en conservant le contexte utile (site, rôle) et l'horloge.

**Important** : l'horloge est animée par un script existant qui cible les `id` `dashboardClock` et `dashboardDate`. Ces deux `id` **doivent être conservés à l'identique** pour ne pas casser l'horloge. Aucune modification JS n'est nécessaire ni souhaitée.

---

## Tâche 1 — Remplacer le bloc CSS de la bannière

Fichier : `src/Views/dashboard/index.php`, dans le `<style>` en tête de vue.

**Repérer** le bloc CSS commençant par le commentaire `/* Welcome bar */` et regroupant toutes les règles `.welcome-bar` (de `.welcome-bar {` jusqu'à et y compris `.welcome-bar .welcome-time .current-date { ... }`).

**Le supprimer entièrement** et le remplacer par le bloc suivant :

```css
/* Top bar (bandeau dashboard) */
.dash-topbar {
    position: relative;
    background: #1e293b;            /* slate 800 — neutre, institutionnel */
    border-radius: 10px;
    padding: 22px 28px;
    color: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    overflow: hidden;
}
/* Filet d'accent discret à gauche */
.dash-topbar::before {
    content: "";
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 4px;
    background: #3b82f6;            /* bleu accent sobre */
}
.dash-topbar .topbar-main { min-width: 0; }
.dash-topbar .topbar-title {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0;
    letter-spacing: 0.01em;
    color: #f8fafc;
}
.dash-topbar .topbar-meta {
    margin: 6px 0 0;
    font-size: 0.8rem;
    color: #94a3b8;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
}
.dash-topbar .topbar-meta .meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.dash-topbar .topbar-meta .meta-sep { color: #475569; }
.dash-topbar .topbar-meta .meta-value { color: #cbd5e1; font-weight: 500; }
.dash-topbar .topbar-time { text-align: right; flex-shrink: 0; }
.dash-topbar .topbar-time .current-time {
    font-size: 1.5rem;
    font-weight: 600;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    color: #f1f5f9;
}
.dash-topbar .topbar-time .current-date {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 2px;
}
```

**Pièges** :
- Ne supprimer **que** les règles `.welcome-bar` et ses descendants. Ne pas toucher aux autres blocs du `<style>` (`.dashboard-grid`, `.dashboard-widget`, `.kpi-*`, `.active-palette-card`, `.quick-actions`, `@media`, etc.).
- Vérifier qu'aucune autre vue ne réutilise la classe `.welcome-bar`. Recherche rapide :
  ```bash
  grep -rn "welcome-bar\|welcome-text\|welcome-time" src/Views/
  ```
  Si `.welcome-bar` n'est utilisée que dans `dashboard/index.php` (attendu), la suppression est sans effet de bord. Si elle apparaît ailleurs, **ne pas supprimer le CSS** : signaler dans le rapport et escalader (procédure d'anomalie).

---

## Tâche 2 — Remplacer le bloc HTML de la bannière

Fichier : `src/Views/dashboard/index.php`, dans le corps de la vue.

**Repérer** le bloc HTML de la bannière, délimité par le commentaire `<!-- ===== WELCOME BAR ===== -->` suivi de `<div class="dashboard-full welcome-bar"> ... </div>` (jusqu'à la balise fermante `</div>` correspondante, juste avant le commentaire `<!-- ===== FILTRE SITE ===== -->`).

**Le remplacer entièrement** par :

```php
<!-- ===== BANDEAU DASHBOARD ===== -->
<div class="dashboard-full dash-topbar">
    <div class="topbar-main">
        <h2 class="topbar-title">Tableau de bord opérationnel</h2>
        <p class="topbar-meta">
            <span class="meta-item">
                <i class="fas fa-warehouse"></i>
                <span class="meta-value"><?= htmlspecialchars($currentUserSiteName) ?></span>
            </span>
            <span class="meta-sep">|</span>
            <span class="meta-item">
                <i class="fas fa-user-shield"></i>
                <span class="meta-value"><?= htmlspecialchars(implode(', ', array_map(function($r) { return $r->name; }, $currentUser->roles ?? []))) ?></span>
            </span>
        </p>
    </div>
    <div class="topbar-time">
        <div class="current-time" id="dashboardClock">--:--:--</div>
        <div class="current-date" id="dashboardDate">--</div>
    </div>
</div>
```

**Pièges** :
- **Conserver impérativement** les `id="dashboardClock"` et `id="dashboardDate"` (l'horloge JS en dépend). Ne pas les renommer.
- Conserver la classe `dashboard-full` sur le conteneur : c'est elle qui fait occuper toute la largeur de la grille (`grid-column: 1 / -1`).
- Vérifier que `$currentUserSiteName` et `$currentUser` sont bien disponibles dans la vue (ils l'étaient pour l'ancienne bannière, donc OK — ne pas réintroduire `$currentUser->first_name`, désormais inutilisé ici).
- Supprimer entièrement la salutation : aucun « Bonjour », aucun nom d'utilisateur, aucun emoji / icône `fa-hand-peace` ne doit subsister.

---

## Tâche 3 — Vérification visuelle (obligatoire avant merge)

1. Charger le dashboard avec un compte ayant accès aux KPIs. Vérifier :
   - le bandeau s'affiche en sombre (ardoise) avec le filet bleu à gauche ;
   - le titre « Tableau de bord opérationnel » est présent ;
   - le site et le ou les rôles s'affichent en métadonnées ;
   - **aucune** salutation « Bonjour », aucun emoji ;
   - l'horloge (heure + date) **s'incrémente** correctement (preuve que les `id` sont intacts).
2. Tester avec un second profil (ex. opérateur cloisonné vs administrateur) pour confirmer que le site/rôle s'affiche correctement dans les deux cas, et que le bandeau reste cohérent quand l'utilisateur a plusieurs rôles.
3. Vérifier le rendu **responsive** : sur largeur mobile (<768px), le bandeau ne doit pas déborder ; l'horseloge passe en dessous ou reste lisible (le `flex-wrap` l'autorise).
4. Vérifier qu'aucune autre zone du dashboard n'a bougé (KPIs, palette active, graphique, accès rapides, tâches) — la modification doit être strictement limitée au bandeau.

Consigner le résultat réel de chaque point dans `RAPPORT_EXECUTION_dashboard_topbar.md`.

---

## Procédure en cas d'anomalie

1. **`.welcome-bar` réutilisée ailleurs** (Tâche 1) : ne pas supprimer le CSS partagé ; isoler la nouvelle classe `.dash-topbar` et documenter. Escalader.
2. **Horloge cassée après modification** : vérifier en priorité que les `id` `dashboardClock` / `dashboardDate` sont bien présents et identiques. Si le script horloge est introuvable ou ciblait d'autres sélecteurs, **ne pas improviser** : documenter et escalader.
3. **Variable manquante** (`$currentUserSiteName` ou `$currentUser` non définie dans certains cas de permission) : vérifier le `DashboardController`. Si une branche de permission ne fournit pas ces variables, prévoir un repli sûr (`?? ''`) plutôt que de laisser une erreur PHP, et le documenter.
4. **Régression de mise en page** (bandeau qui casse la grille) : annuler le commit concerné (`git revert`) sans toucher au reste, documenter le symptôme.
5. Toute anomalie va dans `RAPPORT_EXECUTION_dashboard_topbar.md` (emplacement, constat, action prise ou escalade). Une anomalie non résolue sur le cœur du correctif **bloque le merge**.
6. **Ne jamais modifier de données ni d'autres fichiers** pour « faire passer » le correctif.

---

## Finalisation : merge + push GitHub

> À exécuter **uniquement** si la vérification visuelle (Tâche 3) est concluante et qu'aucune anomalie bloquante ne subsiste.

1. Commit atomique sur `chore/dashboard-topbar-redesign`, message clair :
   ```
   chore(dashboard): remplace la welcome bar par un bandeau professionnel sobre
   ```
2. Mettre à jour la cible et vérifier l'absence de conflit :
   ```bash
   git checkout main
   git pull origin main
   git checkout chore/dashboard-topbar-redesign
   git rebase main        # résoudre tout conflit, puis re-vérifier le rendu
   ```
   Conflit non trivial → ne pas forcer, documenter, escalader.
3. Merge **--no-ff** dans `main` :
   ```bash
   git checkout main
   git merge --no-ff chore/dashboard-topbar-redesign -m "Merge: bandeau dashboard professionnel"
   ```
4. Push :
   ```bash
   git push origin main
   git push origin chore/dashboard-topbar-redesign   # conserver la branche pour référence
   ```
5. Consigner dans le rapport : hash du commit de merge, plage de commits poussés (`<ancien>..<nouveau>`), confirmation `git status` propre et branche locale à jour avec `origin/main`.

**Pièges du merge** :
- **Jamais** de `push --force` sur `main`.
- Push rejeté (remote a avancé) → `git pull --rebase` sur `main`, re-vérifier rapidement le rendu du dashboard, puis re-pousser. Ne pas écraser le travail d'autrui.
- Échec d'authentification GitHub (token/SSH) → **s'arrêter** et le signaler dans le rapport ; ne pas contourner ni stocker de credentials.

---

## Livrables
1. `src/Views/dashboard/index.php` modifié sur `chore/dashboard-topbar-redesign`, mergé dans `main` et poussé (si Tâche 3 OK).
2. `RAPPORT_EXECUTION_dashboard_topbar.md` : statut + résultat réel de chaque point de la Tâche 3, rubrique anomalies/escalades éventuelles, et récapitulatif Git final (hash de merge, plage de commits poussés).

---

## Note (personnalisation optionnelle, hors exécution automatique)
Les couleurs sont neutres et passe-partout : fond `#1e293b`, accent `#3b82f6`. Si une charte graphique avec une couleur primaire définie existe, ces deux valeurs peuvent être alignées dessus. **Ne pas le faire de la propre initiative de l'agent** : c'est une décision de design à confirmer par l'humain. À mentionner en fin de rapport comme suggestion, sans l'appliquer.
