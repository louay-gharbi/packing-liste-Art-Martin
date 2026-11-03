# Rapport — Semaine erronée dans les références autour du Nouvel An

> Analyse du code et de la base locale au 26/10/2026. Aucune modification de code n'a été faite.

## 1. Résumé

Les références des Packing Lists et des palettes contiennent une partie **année-semaine** (`2026-S19`). Autour du Nouvel An, cette partie peut être **fausse** : l'application colle l'**année civile** à un **numéro de semaine ISO**, et ces deux valeurs ne vont pas toujours ensemble.

- **Gravité : faible à moyenne.** Les références restent **uniques** et aucune opération n'est bloquée. Le libellé est en revanche trompeur, et la recherche ou le tri par semaine mélangent des semaines différentes.
- **Fréquence** : de 1 à 3 jours par an, fin décembre ou début janvier selon les années.
- **Déjà arrivé** : oui, en décembre 2025 (voir §4).
- **Prochaine occurrence** : du **vendredi 01/01/2027 au dimanche 03/01/2027**.

## 2. Où se trouve le problème

Fichier : [src/Services/ReferenceGenerator.php](../src/Services/ReferenceGenerator.php)

```php
// Lignes 94 à 100 — placeholders de date
'{YYYY}' => $now->format('Y'), // Année sur 4 chiffres      ← année CIVILE
'{WW}'   => $now->format('W'), // Semaine ISO-8601 (01-53)  ← semaine ISO
```

Patrons actuellement configurés (table `settings`) :

| Clé | Patron |
|---|---|
| `packing_list_reference_pattern` | `PL-{SITE_CODE}-{YYYY}-S{WW}-{COUNTER_GLOBAL}` |
| `palette_code_pattern` | `PAL-{SITE_CODE}-{YYYY}-S{WW}-{COUNTER_GLOBAL_4DIGITS}` |

Les deux patrons utilisent la combinaison `{YYYY}-S{WW}`.

La date utilisée n'est pas la même selon l'objet :
- **Packing List** : la **date d'expédition** ([PackingList.php:80](../src/Models/PackingList.php#L80)) ;
- **Palette** : la **date de création** de la palette ([Palette.php:97](../src/Models/Palette.php#L97)).

## 3. Explication

La norme ISO 8601 définit des semaines qui commencent le **lundi**. La **semaine 01** d'une année est celle qui contient le **premier jeudi de janvier**. En conséquence :

- les derniers jours de décembre peuvent appartenir à la **semaine 01 de l'année suivante** ;
- les premiers jours de janvier peuvent appartenir à la **semaine 52 ou 53 de l'année précédente**.

PHP propose deux formats d'année :

| Format | Valeur | Va avec la semaine ISO `W` ? |
|---|---|---|
| `Y` | année civile | ❌ non, pas aux jours de bascule |
| `o` | année ISO | ✅ oui |

Le code utilise `Y`. Les jours de bascule produisent donc une année-semaine qui n'existe pas, ou qui désigne une autre semaine.

## 4. Cas réel présent dans la base

| Référence | Date | Semaine ISO réelle | Libellé généré |
|---|---|---|---|
| `PL-BK3-2025-S01-20` | expédition le mardi 30/12/2025 | **2026-S01** | `2025-S01` ❌ |
| `PAL-BK3-2025-S01-0127` | palette de cette PL | 2026-S01 | `2025-S01` ❌ |
| `PAL-BK3-2025-S01-0128` | palette de cette PL | 2026-S01 | `2025-S01` ❌ |
| `PAL-BK3-2025-S01-0129` | palette de cette PL | 2026-S01 | `2025-S01` ❌ |

Le libellé `2025-S01` désigne normalement la semaine du **30/12/2024 au 05/01/2025**. La même année-semaine renvoie donc à deux semaines différentes, à un an d'écart.

## 5. Prochaines occurrences

L'année 2026 compte **53** semaines ISO, car le 1er janvier 2026 est un jeudi.

| Date | Semaine ISO réelle | Libellé généré | Correct ? |
|---|---|---|---|
| lun. 28/12/2026 → jeu. 31/12/2026 | 2026-S53 | `2026-S53` | ✅ |
| **ven. 01/01/2027** | 2026-S53 | `2027-S53` | ❌ (2027 n'a pas de semaine 53) |
| **sam. 02/01/2027** | 2026-S53 | `2027-S53` | ❌ |
| **dim. 03/01/2027** | 2026-S53 | `2027-S53` | ❌ |
| lun. 04/01/2027 | 2027-S01 | `2027-S01` | ✅ |
| **sam. 01/01/2028** | 2027-S52 | `2028-S52` | ❌ |
| **dim. 02/01/2028** | 2027-S52 | `2028-S52` | ❌ |

## 6. Conséquences

| Conséquence | Impact |
|---|---|
| Références en double | **Aucune** : le compteur global (`{COUNTER_GLOBAL…}`) garantit l'unicité |
| Blocage de création | **Aucun** |
| Libellé trompeur | Un opérateur qui lit `2025-S01` pense à janvier 2025 et non à fin décembre 2025 |
| Recherche par préfixe | `PAL-BK3-2025-S01` renvoie à la fois les palettes de janvier 2025 et celles de décembre 2025 |
| Rapprochement externe | Si un autre système (ERP, transporteur) raisonne en semaine ISO, la semaine ne correspond pas pour ces quelques jours |

## 7. Problème lié : le compteur hebdomadaire

Ce point n'a **aucun impact aujourd'hui**, mais il vient de la même cause. La clé du compteur `{COUNTER_WEEKLY…}` est construite elle aussi avec l'année civile :

```php
// ReferenceGenerator.php, ligne 81
case 'weekly': $dynamicCounterKey .= 'weekly_' . $now->format('YW'); break;
```

Pour le 30/12/2025, la clé vaut `…weekly_202501`, la même que pour début janvier 2025. Un patron qui utiliserait un compteur `WEEKLY` **reprendrait la numérotation de janvier 2025** au lieu de repartir à 1. Les patrons actuels utilisent un compteur `GLOBAL` et ne sont donc pas concernés.

> Le rapport général ([RAPPORT_FONCTIONNEMENT.md](RAPPORT_FONCTIONNEMENT.md), §8 point 1) présente ce cas de compteur comme le problème principal. C'est inexact avec la configuration actuelle : le vrai effet est le libellé faux décrit dans ce rapport.

## 8. Correction proposée (non appliquée)

Il ne faut **pas** simplement remplacer `{YYYY}` par l'année ISO : un patron en `{YYYY}-{MM}-{DD}` afficherait alors `2026-12-30` pour le 30/12/2025. La correction proposée est la suivante.

**1. Ajouter un placeholder d'année ISO** dans `ReferenceGenerator.php` :

```php
'{ISO_YYYY}' => $now->format('o'), // Année ISO-8601, à utiliser avec {WW}
```

**2. Modifier les deux patrons** depuis l'écran `/settings` :

```
PL-{SITE_CODE}-{ISO_YYYY}-S{WW}-{COUNTER_GLOBAL}
PAL-{SITE_CODE}-{ISO_YYYY}-S{WW}-{COUNTER_GLOBAL_4DIGITS}
```

**3. Corriger la clé du compteur hebdomadaire** (ligne 81) :

```php
case 'weekly': $dynamicCounterKey .= 'weekly_' . $now->format('oW'); break;
```

**À noter :**
- Les références **déjà créées** (par exemple `PL-BK3-2025-S01-20`) **ne changent pas**. Les modifier casserait les QR codes déjà imprimés.
- Le compteur global n'est pas touché : la numérotation continue normalement.
- Il faut appliquer la correction **avant le 01/01/2027**.

## 9. Tests à prévoir

| Date d'entrée | Résultat attendu avec `{ISO_YYYY}-S{WW}` |
|---|---|
| 30/12/2025 | `2026-S01` |
| 31/12/2026 | `2026-S53` |
| 02/01/2027 | `2026-S53` |
| 04/01/2027 | `2027-S01` |
| 01/01/2028 | `2027-S52` |
| 15/06/2026 (cas normal) | `2026-S25` |

Pour tester sur la base locale, il suffit de créer une Packing List avec l'une de ces **dates d'expédition** et de vérifier la référence générée.
