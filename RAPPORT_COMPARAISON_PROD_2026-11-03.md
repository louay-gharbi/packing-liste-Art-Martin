# Comparaison PROD ↔ LOCAL — PackingList

| | |
|---|---|
| **Date du rapport** | 03/11/2026 |
| **Référence PROD** | `C:\Users\Louay_Gharbi\Downloads\xampp02112026prodcopie\htdocs\packinglist` (copie du 02/11/2026) |
| **Version LOCALE** | `C:\xampp\htdocs\packinglist` (LPTTU-038) |
| **Méthode** | Comparaison fichier par fichier (empreinte MD5), puis ligne par ligne pour chaque fichier modifié |
| **Exclus** | `vendor/`, `logs/`, `storage/`, `tmp/`, `cache/` |

## 1. Synthèse

| Catégorie | Nombre | Commentaire |
|---|---:|---|
| Fichiers **ajoutés** en local | 40 | 25 fichiers de code, 4 migrations SQL, 11 documents |
| Fichiers **modifiés** | 25 | dont **5 modifiés aussi en prod le 02/11** (voir § 2) |
| Fichiers **présents uniquement en prod** | 43 | images PNG de QR de palettes (S43–S44), voir § 6 |
| Migrations SQL à appliquer | 4 | voir § 7 |

Fonctionnalités apportées par la version locale :
1. **Réception France** : réception d'une PL par palette complète, contrôle détaillé, rapport d'écarts, export XLSX.
2. **Colisage client** : décomposition des palettes reçues par destination (client + code postal), exports XLSX et PDF.
3. **Palettes client (PALC)** : création, scan des colis, clôture, réouverture, suppression, exports, étiquette 100×150 avec QR.
4. **BLC AMF par commande** : saisie (boutons Ajouter / Modifier), BLC unique, repris dans les exports.
5. **QR palettes générés à la volée** (SVG, code écrit sous le QR) : plus de fichier PNG stocké.
6. **PDF « Liste des Colis »** corrigé (largeurs fixes, récapitulatif, colonne BLC).
7. **PDF du détail palette** généré côté serveur, avec chaque groupage suivi de ses colis fils.
8. **Mon profil** : informations, mot de passe, photo ; menu profil en haut à droite.
9. **Interface** : menu de gauche compact sans barre de défilement, mise en page qui tient à zoom 100 %, Paramètres en bas du menu.
10. **Type de site** (production ou réception) : un site de réception ne peut pas porter de Packing List.

## 2. ⚠️ Points d'attention avant de copier sur la prod

### 2.1 Fichiers modifiés des deux côtés (prod le 02/11 et local)

La prod a reçu le 02/11 la correction du tri des dates (`data-order` au format `Y-m-d H:i:s`). Le local trie de la même façon pour 4 de ces fichiers, mais pas pour le 5ᵉ :

| Fichier | Tri des dates en local | Copier le fichier local ? |
|---|---|---|
| `src/Views/packing_lists/index.php` | Oui (date brute de la base, même format) | Oui, aucune perte |
| `src/Views/packing_lists/show.php` | Oui | Oui, aucune perte |
| `src/Views/palettes/index_all.php` | Oui | Oui, aucune perte |
| `src/Views/sites/index.php` | Oui | Oui, aucune perte |
| `src/Views/palettes/show.php` | **Non pour la colonne « Scanné le »** | **Non, pas tel quel** |

**À faire avant de copier `palettes/show.php`** : reprendre la ligne de la prod pour la colonne « Scanné le » :

```php
<?php $scanDt = new DateTime($colis->scan_date_time); ?>
<td data-order="<?= $scanDt->format('Y-m-d H:i:s') ?>"><?= htmlspecialchars($scanDt->format('d/m/Y H:i')) ?></td>
```

à la place de la ligne locale :

```php
<td><?= htmlspecialchars((new DateTime($colis->scan_date_time))->format('d/m/Y H:i')) ?></td>
```

### 2.2 Lignes présentes uniquement en prod dans les autres fichiers modifiés

Elles ont toutes été vérifiées : ce sont des lignes **volontairement remplacées** en local, pas des corrections de la prod.

| Fichier | Lignes prod-only | Explication |
|---|---|---|
| `PackingListController.php` | `Site::getAllActive()` (×2), test `$site->is_active` | Remplacés par `getAllActiveProduction()` et un refus des sites de réception |
| `PaletteController.php` | test sur `qr_code_path` à l'impression | Le QR n'est plus un fichier : il est généré à la volée |
| `PackingList.php` | `s.code as site_code` (sans virgule) | Une colonne `reception_status` est ajoutée juste après |
| `Palette.php` | 15 lignes de génération du PNG | Supprimées : plus de fichier QR à la création |
| `admin_app.php` | liens CSS et JS | Remplacés par les mêmes liens avec `?v=` (anti-cache) |
| `app.js` | initialisation de `#colisTableOnPaletteDetail` | Déplacée dans `palettes/show.php` |

## 3. Fichiers ajoutés (40)

### 3.1 Réception France

| Fichier | Lignes | Rôle |
|---|---:|---|
| `src/Controllers/ReceptionController.php` | 463 | Liste des PL à réceptionner, démarrage, scan AJAX, clôture, rapport, export XLSX, colisage client (écran + XLSX + PDF) |
| `src/Models/Reception.php` | 525 | Réception par palette complète ou en détail, état et écarts, colisage client par destination `CLIENT — CP`, semaines ISO |
| `src/Views/receptions/index.php` | 120 | Liste des PL avec filtres statut, site et semaine ISO |
| `src/Views/receptions/scan.php` | 283 | Écran de scan des palettes, progression, clôture |
| `src/Views/receptions/report.php` | 156 | Rapport de réception (reçu, manquant, inattendu) |
| `src/Views/receptions/client_packing.php` | 157 | Colisage client de la semaine, colonne Code postal, lien vers les palettes client |
| `src/Views/receptions/export_client_packing.php` | 75 | PDF du colisage client (une page par destination) |
| `src/Views/receptions/_palettes_table.php` | 61 | Partiel : tableau des palettes de la réception |
| `src/Views/receptions/_recent_scans.php` | 19 | Partiel : derniers scans |
| `src/Views/receptions/_unexpected.php` | 24 | Partiel : palettes inattendues |

### 3.2 Palettes client (PALC)

| Fichier | Lignes | Rôle |
|---|---:|---|
| `src/Controllers/ClientPaletteController.php` | 444 | Création, scan AJAX (contrôle client + code postal, groupages), retrait, clôture, réouverture, suppression, exports XLSX et PDF, étiquette HTML |
| `src/Models/ClientPalette.php` | 296 | Table `client_palettes`, numérotation `PALC-<CLIENT>-<AAAA>-S<SS>-NN`, colis posés |
| `src/Views/client_palettes/index.php` | 84 | Palettes d'une destination (client + CP) pour une semaine |
| `src/Views/client_palettes/show.php` | 200 | Écran de scan d'une palette client |
| `src/Views/client_palettes/label.php` | 58 | Étiquette 100×150 mm imprimable (logo, client, CP, semaine, QR, code) |
| `src/Views/client_palettes/export_pdf.php` | 71 | PDF de la liste de colisage de la palette |
| `src/Views/client_palettes/_lines.php` | 36 | Partiel : colis posés |
| `src/Views/client_palettes/_remaining.php` | 39 | Partiel : colis restant à poser |

### 3.3 BLC AMF par commande

| Fichier | Lignes | Rôle |
|---|---:|---|
| `src/Controllers/OrderBlcController.php` | 84 | Page `/commandes-blc` et enregistrement AJAX (BLC unique, contrôle des doublons) |
| `src/Models/OrderBlc.php` | 147 | Commandes avec client, code postal, colis, PL ; enregistrement ; correspondance pour les exports |
| `src/Views/order_blc/index.php` | 216 | Filtres semaine, statut, recherche ; boutons **+ Ajouter** / **✎ Modifier**, saisie à la chaîne avec Entrée |

### 3.4 Profil utilisateur

| Fichier | Lignes | Rôle |
|---|---:|---|
| `src/Controllers/ProfileController.php` | 130 | Informations, changement de mot de passe (ancien mot de passe exigé), envoi et suppression de la photo, affichage de la photo |
| `src/Views/profile/index.php` | 126 | Page « Mon profil » |

### 3.5 Palettes Tunisie

| Fichier | Lignes | Rôle |
|---|---:|---|
| `src/Views/palettes/export_pdf.php` | 96 | PDF du détail palette (Dompdf, A4 paysage, groupage puis fils, récapitulatif, n° de page) |
| `src/Views/palettes/print_qrcode_single.php` | 33 | Impression du QR d'une palette |

### 3.6 Migrations SQL

| Fichier | Lignes | Contenu |
|---|---:|---|
| `database/migrations/2026_10_30_reception_france.sql` | 84 | Colonne `sites.type`, site `REC`, tables `receptions` / `reception_palettes` / `reception_colis`, permissions `reception_view` / `reception_scan` / `reception_close` |
| `database/migrations/2026_10_30_client_palettes.sql` | 39 | Tables `client_palettes` et `client_palette_colis` (un colis sur une seule palette client) |
| `database/migrations/2026_10_31_order_blc.sql` | 21 | Table `order_blc` (BLC unique) et permission `order_blc_manage` |
| `database/migrations/2026_11_02_client_palettes_postal_code.sql` | 22 | Colonne `client_palettes.postal_code`, reprise depuis les colis, index |

### 3.7 Documents (ne pas déployer en prod)

`RAPPORT_EXECUTION_2026-10-31.md`, `RAPPORT_EXECUTION_reception_france.md`, `RAPPORT_MODIFICATIONS_2026-10-23_au_2026-11-02.md`, `PROMPT_PROD_tri_dates.md`, `plan_reception_france.md`, `docs/RAPPORT_CODE_MORT.md`, `docs/RAPPORT_FONCTIONNEMENT.md`, `docs/RAPPORT_PROBLEME_NUMEROTATION.md`, `docs/RAPPORT_PROBLEMES.md`, `docs/QR_TEST_colisage_reception.pdf`, `database/backup_pre_reception_20261030.sql` (sauvegarde de la base locale).

## 4. Fichiers modifiés (25), en détail

Colonnes : lignes ajoutées en local / lignes de la prod retirées / taille prod → local.

### 4.1 Cœur de l'application

| Fichier | + | − | Lignes | Modifications |
|---|---:|---:|---|---|
| `public/index.php` | 45 | 0 | 241 → 286 | 32 routes ajoutées (liste au § 5) et `use` des nouveaux contrôleurs |
| `src/Lib/Router.php` | 3 | 1 | 196 → 198 | Comparaison du chemin de base **insensible à la casse** (`/Packinglist/` = `/packinglist/` sous Windows/Apache) |
| `public/js/app.js` | 3 | 1 | 552 → 554 | Le tableau du détail palette n'est plus initialisé ici (la page `palettes/show.php` le fait, avec le bouton PDF serveur) |
| `public/css/admin_layout.css` | 25 | 8 | 184 → 201 | Menu de gauche : hauteur des lignes calculée sur la hauteur de l'écran, barre de défilement masquée, en-tête compact ; contenu principal `min-width: 0` (plus de débordement à droite) |

### 4.2 Modèles et services

| Fichier | + | − | Lignes | Modifications |
|---|---:|---:|---|---|
| `src/Models/User.php` | 67 | 0 | 508 → 574 | Photo de profil : `avatarFile()`, `avatarUrl()`, `saveAvatar()` (JPG, PNG ou WEBP, 2 Mo, type vérifié sur le contenu), `deleteAvatar()` ; photo supprimée avec le compte |
| `src/Models/Site.php` | 14 | 3 | 179 → 190 | Propriété `type` (`production` / `reception`), `getAllActiveProduction()`, `isReceptionSite()`, `normalizeType()` ; `type` enregistré à la création et à la modification |
| `src/Models/PackingList.php` | 7 | 2 | 483 → 488 | `reception_status` dans la liste ; une PL en réception ne peut plus être modifiée |
| `src/Models/Palette.php` | 0 | 15 | 422 → 408 | Plus de génération ni d'enregistrement de PNG de QR à la création d'une palette |
| `src/Services/QrCodeService.php` | 36 | 147 | 168 → 57 | Réécrit : `svg()` et `svgDataUri()` avec Endroid SvgWriter (sans GD), texte du code sous le QR |

### 4.3 Contrôleurs

| Fichier | + | − | Lignes | Modifications |
|---|---:|---:|---|---|
| `src/Controllers/PaletteController.php` | 82 | 3 | 499 → 578 | `qrCode()` (SVG à la volée, `?download=1`, `?label=0`), `qrCodePrint()`, `exportPdf()` (PDF du détail palette) ; impression groupée des QR sans fichier |
| `src/Controllers/PackingListController.php` | 33 | 5 | 893 → 921 | Seuls les sites de production peuvent porter une PL ; réouverture refusée si la PL est en réception ; badge réception sur la fiche PL ; BLC AMF dans les exports XLSX et PDF ; `$summaryData` calculé pour le PDF Liste des Colis |
| `src/Controllers/SiteController.php` | 6 | 2 | 265 → 269 | Champ `type` en création et en modification |

### 4.4 Vues

| Fichier | + | − | Lignes | Modifications |
|---|---:|---:|---|---|
| `src/Views/layouts/admin_app.php` | 35 | 2 | 367 → 400 | Menu profil en haut à droite (photo ou initiales, Mon profil, Déconnexion) ; en-tête sur une seule ligne ; `?v=` sur `admin_layout.css` et `app.js` |
| `src/Views/partials/_admin_sidebar.php` | 24 | 14 | 92 → 102 | Section **Réception** (Réceptions, Colisage client), lien **BLC AMF commandes**, **Paramètres** dans une section « Système » en bas, plus de Déconnexion en bas du menu |
| `src/Views/packing_lists/export_colis_table.php` | 38 | 32 | 197 → 203 | PDF Liste des Colis : largeurs fixes, retour à la ligne des textes longs, colonne BLC (5 %) |
| `src/Views/packing_lists/show.php` | 16 | 3 | 399 → 412 | Badge réception qui mène au rapport ; bouton Réouvrir masqué si la PL est en réception ; tri des dates (équivalent à la prod) |
| `src/Views/packing_lists/index.php` | 3 | 5 | 148 → 146 | Réouverture masquée si la PL est en réception ; tri des dates (équivalent à la prod) |
| `src/Views/palettes/show.php` | 30 | 23 | 545 → 552 | QR à la volée (Imprimer / Télécharger) ; badge « Groupage · N colis » ; tri qui garde chaque groupage avec ses fils ; bouton PDF côté serveur ; colonnes case à cocher et Actions exclues des exports. **Manque le tri « Scanné le » de la prod (§ 2.1)** |
| `src/Views/palettes/index_all.php` | 6 | 16 | 140 → 130 | Lien vers l'impression du QR ; tri des dates (équivalent à la prod) |
| `src/Views/palettes/print_qrcodes.php` | 4 | 10 | 72 → 66 | QR des palettes en SVG à la volée |
| `src/Views/sites/create.php` | 9 | 0 | 67 → 76 | Choix du type : Production ou Réception |
| `src/Views/sites/edit.php` | 9 | 0 | 72 → 81 | Choix du type : Production ou Réception |
| `src/Views/sites/index.php` | 2 | 6 | 100 → 96 | Tri des dates (équivalent à la prod) |

### 4.5 Documentation

| Fichier | + | − | Modifications |
|---|---:|---:|---|
| `database/migrations/README.md` | 27 | 0 | État d'application et ordre des migrations 4 à 7 |
| `database/migrations/INVENTAIRE_POST.md` | 3 | 0 | Nouveaux points POST de la réception (protection CSRF) |

## 5. Routes ajoutées (32)

| Module | Méthode et route | Contrôleur |
|---|---|---|
| Profil | `GET /profile` | `ProfileController::index` |
| | `POST /profile/update` | `ProfileController::update` |
| | `POST /profile/password` | `ProfileController::updatePassword` |
| | `POST /profile/photo` | `ProfileController::uploadPhoto` |
| | `POST /profile/photo/delete` | `ProfileController::deletePhoto` |
| | `GET /profile/photo/{id}` | `ProfileController::photo` |
| Palettes Tunisie | `GET /palettes/show/{id}/export/pdf` | `PaletteController::exportPdf` |
| | `GET /palettes/qr/{id}` | `PaletteController::qrCode` |
| | `GET /palettes/qr/{id}/print` | `PaletteController::qrCodePrint` |
| Réception | `GET /receptions` | `ReceptionController::index` |
| | `POST /receptions/start/{plId}` | `ReceptionController::start` |
| | `GET /receptions/scan/{id}` | `ReceptionController::scan` |
| | `POST /receptions/{id}/scan-ajax` | `ReceptionController::processScanAjax` |
| | `POST /receptions/close/{id}` | `ReceptionController::close` |
| | `GET /receptions/report/{id}` | `ReceptionController::report` |
| | `GET /receptions/{id}/export/xlsx` | `ReceptionController::exportReportXlsx` |
| Colisage client | `GET /receptions/client-packing` | `ReceptionController::clientPacking` |
| | `GET /receptions/client-packing/export/xlsx` | `ReceptionController::exportClientPackingXlsx` |
| | `GET /receptions/client-packing/export/pdf` | `ReceptionController::exportClientPackingPdf` |
| BLC AMF | `GET /commandes-blc` | `OrderBlcController::index` |
| | `POST /commandes-blc/save` | `OrderBlcController::saveAjax` |
| Palettes client | `GET /client-palettes` | `ClientPaletteController::index` |
| | `POST /client-palettes/create` | `ClientPaletteController::create` |
| | `GET /client-palettes/{id}` | `ClientPaletteController::show` |
| | `POST /client-palettes/{id}/scan-ajax` | `ClientPaletteController::scanAjax` |
| | `POST /client-palettes/{id}/remove/{lineId}` | `ClientPaletteController::removeLine` |
| | `POST /client-palettes/{id}/close` | `ClientPaletteController::close` |
| | `POST /client-palettes/{id}/reopen` | `ClientPaletteController::reopen` |
| | `POST /client-palettes/{id}/delete` | `ClientPaletteController::delete` |
| | `GET /client-palettes/{id}/export/xlsx` | `ClientPaletteController::exportXlsx` |
| | `GET /client-palettes/{id}/export/pdf` | `ClientPaletteController::exportPdf` |
| | `GET /client-palettes/{id}/label` | `ClientPaletteController::label` |

L'ordre des routes compte : `/palettes/show/{id}/export/pdf` est déclarée avant `/palettes/show/{id}`, et les routes `client-packing` avant `/receptions/{id}/export/xlsx`.

## 6. Fichiers présents uniquement en prod (43)

Tous dans `public/qrcodes/palettes/` : PNG des QR des palettes créées en prod en S43 et S44 (`PAL-BK1…BK4-2026-S43/S44-07xx`, ids 753 à 795).

- Le nouveau code **ne les utilise plus** : le QR est généré à la volée depuis le code de la palette.
- **Ne pas les supprimer pendant le déploiement** : ils pourront être purgés plus tard, une fois la nouvelle version validée.
- La colonne `palettes.qr_code_path` reste en base mais n'est plus lue.

## 7. Base de données : migrations à appliquer en prod

À exécuter **dans cet ordre**, après une sauvegarde complète de la base de prod :

1. `2026_10_30_reception_france.sql`
2. `2026_10_30_client_palettes.sql`
3. `2026_10_31_order_blc.sql`
4. `2026_11_02_client_palettes_postal_code.sql` : sa requête de contrôle finale ne doit rien renvoyer en prod, car il n'y a encore aucune palette client.

Après les migrations :
- créer le rôle des opérateurs de réception France et y rattacher leurs comptes (site `REC`) ;
- vérifier que le rôle Administrateur a bien les permissions `reception_*` et `order_blc_manage`.

## 8. Procédure de déploiement conseillée

1. **Sauvegarder** la base de prod et le dossier `htdocs/packinglist` de prod.
2. **Reporter** dans `src/Views/palettes/show.php` (local) la ligne de tri « Scanné le » de la prod (§ 2.1).
3. **Appliquer** les 4 migrations SQL (§ 7).
4. **Copier** les 25 fichiers de code ajoutés (§ 3.1 à 3.5) et les 25 fichiers modifiés (§ 4), sans les documents du § 3.7.
5. **Créer** `storage/avatars/` avec le droit d'écriture pour Apache.
6. **Vérifier** :
   - connexion, Packing Lists, détail palette (QR affiché, bouton PDF) ;
   - Réceptions, Colisage client, BLC AMF commandes ;
   - Mon profil, en haut à droite.
7. **Ne pas supprimer** les 43 PNG de `public/qrcodes/palettes/`.
