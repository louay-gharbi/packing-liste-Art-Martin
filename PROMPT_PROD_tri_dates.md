Tu travailles sur l'application PHP **PackingList Pro** (MVC maison, PHP 8.2, XAMPP sous Windows), installée sur le serveur de **production**. Git n'est pas disponible. Les vues sont dans `src/Views/` et les tableaux utilisent DataTables, initialisé dans `public/js/app.js`.

## Objectif

Corriger le **tri des colonnes de dates** dans les tableaux. Les dates sont affichées au format français (`jj/mm/aaaa hh:mm`), et DataTables les trie comme du texte, donc par jour du mois : `01/10/2026` passe avant `03/03/2026`. Le classement est faux.

La correction standard de DataTables consiste à **garder l'affichage tel quel** et à ajouter sur la cellule un attribut `data-order` qui contient la valeur brute venant de la base (`AAAA-MM-JJ` ou `AAAA-MM-JJ HH:MM:SS`). DataTables trie alors sur cet attribut, qui se compare correctement comme du texte.

## Ce que tu dois faire, et seulement ça

Pour chacune des cellules ci-dessous, ajoute `data-order="<?= htmlspecialchars(<même valeur brute que celle affichée>) ?>"` sur la balise `<td>`, sans rien changer d'autre dans la ligne.

| Fichier | Tableau (id) | Colonne | Valeur brute à mettre dans `data-order` |
|---|---|---|---|
| `src/Views/packing_lists/index.php` | `#packingListsTable` | Date d'expédition | `$pl->ship_date` |
| `src/Views/packing_lists/index.php` | `#packingListsTable` | Créée le | `$pl->created_at` |
| `src/Views/packing_lists/show.php` | `#palettesTableOnPLShowPage` | Créée le (palettes de la PL) | `$palette->created_at` |
| `src/Views/palettes/index_all.php` | `#allPalettesTable` | Ouverte le | `$p['open_date_time'] ?? ''` |
| `src/Views/palettes/index_all.php` | `#allPalettesTable` | Fermée le | `$p['close_date_time'] ?? ''` |
| `src/Views/palettes/index_all.php` | `#allPalettesTable` | Créée le | `$p['palette_created_at']` |
| `src/Views/sites/index.php` | `#sitesTable` | Créé le | `$site->created_at` |
| `src/Views/sites/index.php` | `#sitesTable` | Modifié le | `$site->updated_at` |

### Exemples avant / après (version de référence)

```php
// AVANT
<td><?= htmlspecialchars((new DateTime($pl->ship_date))->format('d/m/Y')) ?></td>
// APRÈS
<td data-order="<?= htmlspecialchars($pl->ship_date) ?>"><?= htmlspecialchars((new DateTime($pl->ship_date))->format('d/m/Y')) ?></td>
```

```php
// AVANT (colonne pouvant être vide : on garde le "?? ''" pour éviter un avertissement sur null)
<td><?= $p['open_date_time'] ? htmlspecialchars((new DateTime($p['open_date_time']))->format('d/m/y H:i')) : '-' ?></td>
// APRÈS
<td data-order="<?= htmlspecialchars($p['open_date_time'] ?? '') ?>"><?= $p['open_date_time'] ? htmlspecialchars((new DateTime($p['open_date_time']))->format('d/m/y H:i')) : '-' ?></td>
```

## Règles

1. **Commence par sauvegarder** les 4 fichiers (copie `.bak` à côté, ou dans un dossier de sauvegarde daté), puis **lis chaque fichier en entier** avant de le modifier.
2. Les fichiers de production peuvent être **plus anciens** que la version de référence : les numéros de ligne, les noms de variables ou le format d'affichage peuvent différer. **Repère les cellules par leur contenu** (la `<td>` qui affiche la date de la colonne indiquée dans le `<thead>` du tableau). Utilise dans `data-order` la variable réellement utilisée dans cette cellule.
3. Si une cellule a **déjà** un attribut `data-order`, ne la modifie pas. Si une colonne de la liste **n'existe pas** dans le fichier de production, ne l'invente pas : note-la dans ton compte rendu.
4. **Ne modifie rien d'autre** : pas de reformatage, pas de nettoyage, pas de changement dans `app.js`, pas d'autre fichier. Cette mise en production ne concerne que le tri des dates. Les autres évolutions de la version de développement (module Réception, QR, BLC…) ne doivent **pas** être appliquées ici.
5. Si tu trouves autre chose de suspect, signale-le dans le compte rendu sans le corriger.

## Vérification

1. Lance `C:\xampp\php\php.exe -l <fichier>` sur chacun des 4 fichiers modifiés. Les 4 doivent répondre « No syntax errors detected ».
2. Vérifie dans `public/js/app.js` que les 4 tableaux (`#packingListsTable`, `#palettesTableOnPLShowPage`, `#allPalettesTable`, `#sitesTable`) sont bien initialisés avec `initializeDataTable(...)`. Ne modifie pas ce fichier.
3. Si tu peux ouvrir l'application : sur la page Packing Lists, trie par « Date d'expédition » puis par « Créée le ». L'ordre doit être chronologique (par exemple `28/09/2026` avant `03/10/2026`). Sinon, indique que la vérification visuelle reste à faire par l'utilisateur.

## Compte rendu attendu

- la liste des fichiers modifiés, avec pour chacun les lignes « avant / après » ;
- les cellules non trouvées ou déjà corrigées ;
- le résultat des `php -l` ;
- l'emplacement des sauvegardes et la façon de revenir en arrière (restaurer les `.bak`).
