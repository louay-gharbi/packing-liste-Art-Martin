# Rapport des modifications — du 23/10/2026 au 02/11/2026

> Projet `packinglist`, environnement local (XAMPP, PHP 8.2, base `packing_list_db`, poste LPTTU-038).
> Git n'est pas installé : cette liste est établie à partir des **dates de modification des fichiers** (hors `vendor/` et `public/qrcodes/`) et des rapports détaillés de la période.

**Rapports détaillés de la période**
- `RAPPORT_EXECUTION_reception_france.md` : création du module Réception (30/10).
- `RAPPORT_EXECUTION_2026-10-31.md` : réception par palette, colisage client, palettes client, QR à la volée, export PDF, BLC AMF (30–31/10).

---

## 1. Chronologie

| Date | Nature | Contenu |
|---|---|---|
| 23/10 | Documentation | `docs/RAPPORT_FONCTIONNEMENT.md` : description du fonctionnement de l'application |
| 23/10 | Configuration | `config/config.php` modifié à 16:35 (voir § 6, détail non tracé) |
| 26/10 | Documentation | `docs/RAPPORT_PROBLEME_NUMEROTATION.md` : semaine erronée dans les références autour du Nouvel An (analyse seule, pas de correctif) |
| 26/10 | Documentation | `docs/RAPPORT_PROBLEMES.md` : audit des problèmes du code (lecture seule) |
| 27/10 | Documentation | `docs/RAPPORT_CODE_MORT.md` : inventaire du code mort (lecture seule) |
| 30/10 | Fonctionnalité | Module **Réception** : plan, migration, modèles, contrôleur, écrans de scan et de rapport |
| 30/10 | Correctif | **Tri des dates** dans les tableaux |
| 30/10 | Correctif | **URL avec majuscule** (`/Packinglist/…`) : 404 |
| 30/10 | Ajustements | Libellé « Réception » ; suppression des colis endommagés ; refus des codes PL et palette inexistants |
| 30/10 | Fonctionnalité | **Colisage client** par semaine et par client |
| 30/10 | Fonctionnalité | **Palettes client** (création, scan des colis, clôture, exports) |
| 30/10 | Changement | **Réception par palette complète** (scan des colis refusé) ; filtre **par semaine** sur la liste des réceptions |
| 30/10 | Données | Tables de réception vidées en local (avec sauvegarde) |
| 31/10 | Fonctionnalité | **Étiquette palette client** avec QR, imprimable directement |
| 31/10 | Correctif | Export **PDF « Liste des Colis »** : tableau coupé à droite, récapitulatif à 0 |
| 31/10 | Changement | **QR des palettes générés à la volée** (plus de fichiers), code écrit dessous, impression directe |
| 31/10 | Fonctionnalité | **Saisie des BLC AMF** par commande, reportés dans les exports |
| 31/10 | Documentation | `RAPPORT_EXECUTION_2026-10-31.md`, `database/migrations/README.md` |

---

## 2. Détail par sujet

### 2.1 Module Réception (30/10)

Il permet de réceptionner en France les Packing Lists fermées (expédiées) de tous les sites de production.

- **Migration** `2026_10_30_reception_france.sql` :
  - `sites.type` (`production` / `reception`) et site `REC` ;
  - tables `receptions`, `reception_palettes`, `reception_colis` ;
  - permissions `reception_view`, `reception_scan`, `reception_close`.
- **Écrans** :
  - liste des PL à réceptionner ;
  - écran de scan à focus permanent, rafraîchi en AJAX ;
  - rapport d'écarts avec export XLSX.
- **Règles** :
  - une réception par PL ;
  - une PL réceptionnée ne peut plus être rouverte ;
  - les PL ne peuvent être créées que pour des sites de type `production` ;
  - le type de site est ajouté dans la gestion des sites.
- **Modifications manuelles** de l'utilisateur en base : site 7 renommé `REC` « Réception » (au lieu de `FR` « Réception France ») ; rôle 6 renommé « Opérateur Réception ».

### 2.2 Correctif — tri des dates (30/10)

Les dates affichées en `jj/mm/aaaa hh:mm` étaient triées comme du texte, donc par jour du mois. Chaque cellule de date porte maintenant un attribut `data-order` avec la date au format ISO.

Tableaux corrigés :
- liste des Packing Lists ;
- détail d'une PL ;
- Toutes les Palettes ;
- liste des réceptions ;
- liste des sites.

### 2.3 Correctif — URL avec majuscule (30/10)

`src/Lib/Router.php` compare désormais le chemin de base sans tenir compte de la casse. `/Packinglist/…` ne renvoie plus 404 sous Windows/Apache.

### 2.4 Réception : ajustements (30/10)

- « Réception France » devient « Réception » partout.
- **Colis endommagés** : la fonctionnalité est supprimée (modèle, action AJAX, écran, rapport, export).
- **Codes refusés** : une référence de PL, ou un code `PAL-`/`PL-` inexistant, est refusé et rien n'est enregistré. Avant, ces codes devenaient des « inattendus » comptés en écarts.
- **Réception par palette complète** :
  - on scanne uniquement l'étiquette de la palette filmée ;
  - un scan de colis est refusé, avec l'indication de la palette à scanner ;
  - la « palette active » est supprimée ;
  - le compteur « Colis scannés » devient « Colis reçus ».
- **Filtre par semaine d'expédition** sur la liste des réceptions, à la place des champs de date.

### 2.5 Colisage client (30/10)

- Écran `/receptions/client-packing` : les colis reçus des réceptions **clôturées** sont regroupés par semaine d'expédition, puis par client.
- Les groupages sont décomposés en leurs colis enfants.
- Exports XLSX (un onglet par client) et PDF (une page par client).

### 2.6 Palettes client et étiquette (30–31/10)

- **Migration** `2026_10_30_client_palettes.sql` : tables `client_palettes` et `client_palette_colis`.
- **Code de palette** : `PALC-<CLIENT>-<AAAA>-S<SS>-<NN>`.
- **Scan des colis** du client. Chaque colis affiche sa palette Tunisie et sa PL d'origine. Les colis d'un autre client, déjà placés, non reçus, ainsi que les groupages parents, sont refusés.
- La liste « Reste à placer » est classée par palette Tunisie.
- Actions : retirer un colis, clôturer, rouvrir, supprimer une palette vide.
- Exports XLSX et PDF de la liste de colisage de chaque palette.
- **Étiquette** 100 × 150 mm, disponible après clôture :
  - page HTML à impression directe, sans PDF ;
  - logo, client, semaine et numéro de palette, QR et code en clair.

### 2.7 Export PDF « Liste des Colis » (31/10)

- Colonnes à largeur fixe : le tableau tient dans la page, les mots longs sont coupés dans leur cellule.
- Le récapitulatif (palettes, colis, quantité) est maintenant calculé. Il affichait toujours 0.

### 2.8 QR des palettes générés à la volée (31/10)

- `QrCodeService` est réécrit : QR en **SVG**, sans extension GD, **rien n'est stocké**. Le code de la palette est écrit sous le QR.
- Nouvelles adresses :
  - `/palettes/qr/{id}` : image ;
  - `/palettes/qr/{id}/print` : impression directe.
- Utilisées par : la liste des palettes, le détail d'une palette, l'impression groupée et l'étiquette palette client.
- La création d'une palette ne produit plus de fichier PNG.

### 2.9 BLC AMF par commande (31/10)

- **Migration** `2026_10_31_order_blc.sql` : table `order_blc` et permission `order_blc_manage`.
- Page **Gestion Colisage → BLC AMF commandes** :
  - filtres par semaine, par statut (avec ou sans BLC) et recherche ;
  - saisie au clavier ou à la douchette (Entrée = enregistrer et passer à la commande suivante).
- **Règles** : un BLC par commande (valable pour toutes ses PL), et un BLC n'appartient qu'à une seule commande.
- La colonne BLC AMF des exports « Liste des Colis » (XLSX et PDF) est remplie.

---

## 3. Base de données

**Migrations**, à appliquer dans l'ordre (toutes appliquées en local) :

| Migration | Appliquée | Contenu |
|---|---|---|
| `2026_10_30_reception_france.sql` | 30/10 | `sites.type`, site `REC`, tables de réception, permissions `reception_*` |
| `2026_10_30_client_palettes.sql` | 30/10 | `client_palettes`, `client_palette_colis` |
| `2026_10_31_order_blc.sql` | 31/10 | `order_blc`, permission `order_blc_manage` |

**Sauvegardes**
- `database/backup_pre_reception_20261030.sql` : base complète avant le module Réception.
- `backup_receptions_avant_nettoyage.sql` (dossier temporaire de session) : tables de réception avant le vidage du 30/10.

**Données de recette laissées en place** : rôle « Opérateur Réception » (id 6) et utilisateur `test_reception`, rattaché au site `REC`.

**Colonnes conservées mais plus utilisées** : `reception_palettes.condition` et `.comment`, `reception_colis.condition` et `.comment`, `palettes.qr_code_path`.

---

## 4. Fichiers modifiés sur la période

Classés par date de dernière modification.

| Dernière modif. | Fichier | Statut | Sujet |
|---|---|---|---|
| 23/10 08:39 | `docs/RAPPORT_FONCTIONNEMENT.md` | nouveau | documentation |
| 23/10 16:35 | `config/config.php` | modifié | voir § 6 |
| 26/10 08:57 | `docs/RAPPORT_PROBLEME_NUMEROTATION.md` | nouveau | documentation |
| 26/10 10:57 | `docs/RAPPORT_PROBLEMES.md` | nouveau | documentation |
| 27/10 10:15 | `docs/RAPPORT_CODE_MORT.md` | nouveau | documentation |
| 30/10 10:14 | `plan_reception_france.md` | nouveau | réception |
| 30/10 10:20 | `database/backup_pre_reception_20261030.sql` | nouveau | sauvegarde (17 Mo) |
| 30/10 10:23 | `src/Models/Site.php` | modifié | réception (type de site) |
| 30/10 10:26 | `public/js/app.js` | modifié | réception (tableau des réceptions) |
| 30/10 10:26 | `src/Views/receptions/_recent_scans.php` | nouveau | réception |
| 30/10 10:28 | `src/Models/PackingList.php` | modifié | réception (réouverture refusée, statut) |
| 30/10 10:29 | `src/Controllers/SiteController.php` | modifié | réception (type de site) |
| 30/10 10:34 | `database/migrations/INVENTAIRE_POST.md` | modifié | inventaire CSRF |
| 30/10 10:39 | `database/migrations/2026_10_30_reception_france.sql` | nouveau | réception |
| 30/10 10:39 | `RAPPORT_EXECUTION_reception_france.md` | nouveau | documentation |
| 30/10 11:04 | `src/Views/packing_lists/index.php` | modifié | réception + tri des dates |
| 30/10 11:04 | `src/Views/sites/index.php` | modifié | type de site + tri des dates |
| 30/10 11:06 | `src/Lib/Router.php` | modifié | URL avec majuscule |
| 30/10 13:32 | `src/Views/sites/create.php`, `src/Views/sites/edit.php` | modifiés | type de site, libellé |
| 30/10 13:33 | `src/Views/packing_lists/show.php` | modifié | badge réception, tri des dates |
| 30/10 14:37 | `src/Views/receptions/export_client_packing.php` | nouveau | colisage client |
| 30/10 14:45 | `src/Views/receptions/_unexpected.php` | nouveau | réception |
| 30/10 16:55 | `database/migrations/2026_10_30_client_palettes.sql` | nouveau | palettes client |
| 30/10 16:55 | `src/Models/ClientPalette.php` | nouveau | palettes client |
| 30/10 16:57 | `src/Views/client_palettes/_remaining.php`, `_lines.php` | nouveaux | palettes client |
| 30/10 16:58 | `src/Views/client_palettes/export_pdf.php` | nouveau | palettes client |
| 30/10 16:58 | `src/Views/receptions/client_packing.php` | nouveau | colisage client |
| 30/10 17:03 | `src/Views/receptions/report.php`, `_palettes_table.php`, `scan.php` | nouveaux | réception |
| 30/10 17:18 | `src/Models/Reception.php` | nouveau | réception, colisage |
| 30/10 17:18 | `src/Controllers/ReceptionController.php` | nouveau | réception, colisage |
| 30/10 17:18 | `src/Views/receptions/index.php` | nouveau | réception (filtre semaine) |
| 31/10 10:50 | `src/Views/client_palettes/show.php`, `index.php` | nouveaux | palettes client, étiquette |
| 31/10 11:00 | `src/Views/client_palettes/label.php` | nouveau | étiquette |
| 31/10 11:37 | `src/Models/Palette.php` | modifié | QR à la volée |
| 31/10 11:38 | `src/Controllers/ClientPaletteController.php` | nouveau | palettes client |
| 31/10 11:45 | `src/Services/QrCodeService.php` | réécrit | QR à la volée |
| 31/10 11:45 | `src/Controllers/PaletteController.php` | modifié | QR à la volée |
| 31/10 11:45 | `src/Views/palettes/print_qrcode_single.php` | nouveau | QR imprimable |
| 31/10 11:45 | `src/Views/palettes/index_all.php`, `show.php`, `print_qrcodes.php` | modifiés | QR à la volée, tri des dates |
| 31/10 11:49 | `database/migrations/2026_10_31_order_blc.sql` | nouveau | BLC AMF |
| 31/10 11:50 | `src/Models/OrderBlc.php` | nouveau | BLC AMF |
| 31/10 11:50 | `src/Controllers/OrderBlcController.php` | nouveau | BLC AMF |
| 31/10 11:50 | `src/Views/order_blc/index.php` | nouveau | BLC AMF |
| 31/10 11:50 | `public/index.php` | modifié | routes (réception, colisage, palettes client, QR, BLC) |
| 31/10 11:50 | `src/Views/partials/_admin_sidebar.php` | modifié | menus Réception, Colisage client, BLC AMF |
| 31/10 11:51 | `src/Controllers/PackingListController.php` | modifié | réception, export PDF, BLC |
| 31/10 11:51 | `src/Views/packing_lists/export_colis_table.php` | modifié | export PDF, BLC |
| 31/10 12:00 | `database/migrations/README.md` | modifié | registre des migrations |
| 31/10 12:01 | `RAPPORT_EXECUTION_2026-10-31.md` | nouveau | documentation |

**Supprimé** : `src/Views/client_palettes/label_pdf.php`, remplacé par `label.php` le 31/10.

---

## 5. Déploiement sur un autre poste (pdttu-060)

1. **Sauvegarder** la base.
2. **Appliquer les migrations** dans l'ordre du § 3 (sauter celles déjà appliquées sur ce poste).
3. **Copier** tous les fichiers `src/`, `public/` et `database/migrations/` du § 4. Supprimer `src/Views/client_palettes/label_pdf.php` s'il existe.
4. **Ne pas copier** sans vérification `config/config.php` (voir § 6), ni la sauvegarde `database/backup_pre_reception_20261030.sql`.
5. **Droits** : créer ou adapter les rôles (« Opérateur Réception » pour la France) et y ajouter les permissions `reception_*` et, si besoin, `order_blc_manage`.
6. Ne pas copier l'utilisateur de recette `test_reception`.

---

## 6. Points d'attention

- **`config/config.php` (23/10)** : le fichier a été modifié le 23/10 à 16:35, en dehors des sessions documentées. Sans git, le détail de la modification n'est pas connu. À comparer avec la version de pdttu-060 avant de le copier.
- **Extension GD** désactivée sur LPTTU-038. Elle n'est plus nécessaire pour les QR (SVG).
- **Anciens QR** : `public/qrcodes/palettes/` (755 fichiers) ne sert plus. Les fichiers ne sont pas supprimés.
- **Colis sans client** : 40 colis de la PL-BK2-2026-S42-160 apparaissent sous « (Sans client) » dans le colisage client.
- **Numérotation au Nouvel An** (`docs/RAPPORT_PROBLEME_NUMEROTATION.md`) : le problème est analysé mais **non corrigé**. Prochaine occurrence du 01/01/2027 au 03/01/2027.
- **Audits du 26–27/10** (`docs/RAPPORT_PROBLEMES.md`, `docs/RAPPORT_CODE_MORT.md`) : aucun des correctifs proposés dans ces rapports n'a été appliqué sur la période, en dehors de ceux listés ici.
- **Tests** : tous les tests de la période sont des scripts PHP de recette. Ils sont décrits dans les deux rapports détaillés. L'impression réelle des étiquettes et des QR, et leur scan, restent à valider sur le terrain.
