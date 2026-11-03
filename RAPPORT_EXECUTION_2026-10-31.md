# Rapport d'exécution — Réception par palette, colisage client, palettes client, QR à la volée, BLC AMF

> Période : 30 et 31 octobre 2026, environnement local (XAMPP, PHP 8.2, base `packing_list_db`, poste LPTTU-038).
> Fait suite à `RAPPORT_EXECUTION_reception_france.md` (création du module Réception).
> Git n'est pas installé sur le poste : les modifications sont sur disque, non versionnées.

---

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Réception](#2-réception)
3. [Colisage client](#3-colisage-client)
4. [Palettes client et étiquette](#4-palettes-client-et-étiquette)
5. [QR codes des palettes générés à la volée](#5-qr-codes-des-palettes-générés-à-la-volée)
6. [Export PDF « Liste des Colis »](#6-export-pdf--liste-des-colis-)
7. [BLC AMF par commande](#7-blc-amf-par-commande)
8. [Base de données](#8-base-de-données)
9. [Routes ajoutées ou supprimées](#9-routes-ajoutées-ou-supprimées)
10. [Tests réalisés](#10-tests-réalisés)
11. [Déploiement sur un autre poste (pdttu-060)](#11-déploiement-sur-un-autre-poste-pdttu-060)
12. [Points d'attention](#12-points-dattention)

---

## 1. Vue d'ensemble

Le flux en France est désormais :

1. **Réception** d'une Packing List en scannant chaque **palette Tunisie complète** (filmée). Les colis ne sont plus scannés à cette étape.
2. **Colisage client** : après la clôture des réceptions, les colis reçus sont regroupés par client et par semaine d'expédition. Les groupages sont remplacés par les colis qu'ils contiennent.
3. **Palettes client** : les colis d'un client sont scannés un par un sur une ou plusieurs palettes d'expédition vers ce client. Chaque colis garde sa palette Tunisie et sa PL d'origine.
4. **Étiquette** de la palette client clôturée, avec QR code, imprimée directement par le navigateur.

S'y ajoutent :
- les QR codes des palettes Tunisie générés à l'affichage (plus de fichiers stockés) ;
- la correction de l'export PDF « Liste des Colis » ;
- la saisie des BLC AMF par commande.

| Thème | Demande | Résultat |
|---|---|---|
| Libellés | « juste reception » | « Réception France » devient « Réception » partout |
| Colisage client | décomposer les colis reçus par client et par semaine | écran, export XLSX et PDF |
| Colis endommagés | retirer cette option | supprimée de toute la réception |
| Scans inattendus | codes PL ou palette erronés comptés en écarts | ces codes sont refusés sans rien enregistrer |
| Mode de réception | réception par palette complète, pas par colis | scan des colis refusé en réception |
| Liste des réceptions | filtre par semaine au lieu des dates | liste des semaines d'expédition |
| Palettes client | une palette par client, chaque colis avec son emplacement Tunisie | création, scan, clôture, exports |
| Étiquette palette client | QR code, sans PDF, imprimable directement | page HTML 100 × 150 mm |
| PDF Liste des Colis | tableau coupé à droite | colonnes à largeur fixe, récapitulatif corrigé |
| QR palettes | générés à l'instant, pas stockés, code écrit dessous, imprimables | génération SVG à la volée |
| BLC AMF | saisir un BLC unique par commande | page de saisie et exports remplis |

---

## 2. Réception

### 2.1 Libellés

« Réception France » est remplacé par « Réception » dans :
- le titre des pages ;
- la section du menu ;
- le badge de la fiche PL (« Réception : … ») ;
- le type de site (création et modification) ;
- le message de refus de réouverture d'une PL.

### 2.2 Suppression de la notion de colis endommagé

- Modèle : `Reception::setCondition()` est supprimé. `getState()` ne lit plus `condition` ni `comment`, et ne compte plus de colis endommagés.
- Le nombre d'écarts vaut maintenant : palettes manquantes + colis manquants + inattendus.
- Contrôleur : l'action AJAX de changement d'état et sa route sont supprimées, ainsi que les lignes « Endommagés » de l'export XLSX du rapport.
- Vues : boutons, badges, fenêtre de saisie et compteurs « endommagé » retirés de l'écran de scan, du tableau des palettes, des inattendus et du rapport.
- Les colonnes `condition` et `comment` restent dans les tables `reception_palettes` et `reception_colis`, mais ne sont plus utilisées.

### 2.3 Codes refusés en réception

Avant, un code inconnu était enregistré comme « colis inattendu » et comptait comme écart. Maintenant :
- une **référence de Packing List** (ex. `PL-BK2-2026-S42-160`) est refusée (HTTP 400) ;
- un code au format palette (`PAL-…` ou `PL-…`) **qui n'existe pas** (ex. `PAL-BK4-2026-S42-0729` au lieu de `…S41…`) est refusé (HTTP 400).

Dans les deux cas, rien n'est enregistré.

### 2.4 Réception par palette complète

- Scanner un colis est refusé (HTTP 400). Le message indique la palette à scanner, par exemple : *« "AR10656" est un colis : la réception se fait par palette complète. Scannez l'étiquette de la palette PAL-BK1-2026-S42-0733. »*
- Un code inconnu est refusé avec le message *« scannez l'étiquette d'une palette »*.
- Suppressions :
  - la notion de « palette active » (session, bandeau, bouton et route « Désactiver ») ;
  - `Reception::scanColis()` et `Reception::getReceptionPaletteCode()`.
- Ajout de `Reception::findPaletteCodeOfColis()`.
- Nouveau compteur `colis_received` dans `getState()` : écran de scan, résumé de clôture, rapport et export XLSX affichent « Colis reçus » au lieu de « Colis scannés ».
- Le tableau des palettes affiche « À scanner » ou « Reçue » avec l'heure du scan. On peut toujours déplier une palette pour voir ses colis.
- Une palette non scannée est déclarée manquante avec tous ses colis.

### 2.5 Filtre par semaine sur la liste des réceptions

- Les champs « Expédiée du / au » sont remplacés par une liste **Semaine d'expédition** : semaines ISO des PL fermées, avec le nombre de PL.
- Paramètre d'URL : `?week=2026-W42`.
- Sans semaine choisie, le comportement reste le même qu'avant (PL des 90 derniers jours et réceptions en cours).
- Nouvelle méthode `Reception::getShipWeeks()`. `ReceptionController::validDate()` est supprimée.

### 2.6 Nettoyage des données de réception locales

- À la demande, les tables `receptions`, `reception_palettes` et `reception_colis` ont été vidées en local et leurs compteurs `AUTO_INCREMENT` remis à 1.
- Sauvegarde préalable : `backup_receptions_avant_nettoyage.sql`, dans le dossier temporaire de la session.
- Depuis, l'utilisateur a refait les réceptions des PL 160 et 158.

---

## 3. Colisage client

**Écran** : `/receptions/client-packing?week=2026-W42` (menu Réception → Colisage client). Il est aussi accessible depuis le rapport d'une réception clôturée (bouton « Colisage client S42 »).

**Règles**
- On choisit une **semaine d'expédition ISO**. Tous les sites de production sont regroupés.
- Seules les réceptions **clôturées** alimentent le colisage. Si toutes les réceptions de la semaine ne sont pas clôturées, un avertissement « partiel » s'affiche.
- Colis pris en compte : ceux des palettes reçues. Pour les anciennes réceptions contrôlées colis par colis, seuls les colis scannés comptent.
- Les **groupages sont décomposés** : le colis parent est remplacé par ses colis enfants. La colonne « Groupage » indique le numéro du parent.
- Le client est celui du colis. Un colis sans client apparaît sous « (Sans client) ».
- Les données sont calculées à l'affichage, rien n'est stocké.

**Contenu**
- La liste des PL de la semaine avec leur état de réception.
- Le tableau des clients : commandes, colis, quantité, colonne « Palettes client » (placés / total, nombre de palettes), et actions (Palettes, détail, XLSX, PDF).
- Le détail d'un client : commande, ligne, colis, groupage, OF, article, libellé, référence client, quantité, code postal, palette Tunisie, Packing List, palette client.

**Exports**
- XLSX : un onglet par client, avec ligne de total, filtre automatique et en-tête figé.
- PDF : une page par client, avec récapitulatif.
- Les exports sont disponibles pour un client ou pour tous.

**Code** : `Reception::getWeeksWithReceptions()` et `Reception::getClientPackingForWeek()` (chaque ligne porte `colis_id`). Dans le contrôleur : `clientPacking()`, `exportClientPackingXlsx()` et `exportClientPackingPdf()`.

---

## 4. Palettes client et étiquette

### 4.1 Fonctionnement

1. Depuis le colisage client, le bouton **Palettes** d'un client ouvre la page de ses palettes pour la semaine, avec une barre « colis placés X / Y ».
2. **Nouvelle palette client** crée un code automatique `PALC-<CLIENT>-<AAAA>-S<SS>-<NN>`, par exemple `PALC-CHELIX-2026-S42-01`. La numérotation se fait par préfixe de code, ce qui évite les collisions entre deux clients au code abrégé identique.
3. **Scan des colis** sur la palette. Pour chaque colis accepté, le message indique son origine : *« posé (palette Tunisie PAL-… · PL-…) »*.
4. La liste **Reste à placer** affiche les colis du client pas encore placés, regroupés par palette Tunisie, pour guider la préparation.
5. Les actions possibles sont : retirer un colis (palette ouverte), clôturer, rouvrir, supprimer une palette vide.
6. **Exports** de la liste de colisage de la palette en XLSX et en PDF, avec les colonnes Palette Tunisie et Packing List.

**Scans refusés (rien n'est enregistré)**
- un colis d'un autre client (le message nomme le client) ;
- un colis déjà sur une autre palette client (le message donne son code) ;
- un groupage parent (le message liste ses colis enfants) ;
- une palette Tunisie, une référence de PL, une palette client ;
- un colis non reçu (palette manquante ou réception non clôturée) ;
- un code inconnu.

**Droits** : `reception_view` pour consulter et exporter, `reception_scan` pour créer, scanner, retirer et supprimer, `reception_close` pour clôturer et rouvrir.

### 4.2 Étiquette

- Elle n'est disponible qu'une fois la palette **clôturée** : bouton « Étiquette QR » sur la palette et dans la liste des palettes du client.
- C'est une page HTML au format **100 × 150 mm** (`@page`) qui lance **l'impression directe** au chargement. Il n'y a pas de PDF. Les boutons « Imprimer » et « Retour » ne s'impriment pas.
- Contenu (validé par l'utilisateur) :
  - logo et mention « PALETTE CLIENT » ;
  - nom du client (police réduite si le nom est long) ;
  - semaine et numéro de palette ;
  - QR code du code `PALC-…`, avec ce code écrit en clair dessous.

---

## 5. QR codes des palettes générés à la volée

**Avant** : à la création d'une palette, un fichier PNG était enregistré dans `public/qrcodes/palettes/`, et son chemin dans `palettes.qr_code_path`. Il fallait l'extension PHP GD. Si le fichier manquait (poste où il n'a pas été copié, ou GD désactivé), l'icône QR menait à une image introuvable.

**Après**
- `QrCodeService` est réécrit : `svg()` et `svgDataUri()` produisent un QR **SVG**, sans GD et **sans rien stocker**.
- Le code de la palette est **écrit sous le QR**, comme sur les anciennes images. La librairie Endroid ignore les libellés en SVG, le texte est donc ajouté par le service.
- Nouvelles adresses :
  - `/palettes/qr/{id}` : image SVG. `?download=1` pour télécharger, `?label=0` pour le QR sans texte.
  - `/palettes/qr/{id}/print` : page qui lance l'impression directe.
- Le contrôle d'accès par site reste appliqué (`isAccessibleBy`).
- `Palette::create()` ne génère plus de fichier et ne renseigne plus `qr_code_path`.
- Pages concernées :
  - **Toutes les Palettes** : l'icône QR ouvre la page d'impression ;
  - **Détail palette** : image du QR, liens « Imprimer » et « Télécharger » ;
  - **Impression groupée** : images générées à la volée. Le texte reste piloté par la case existante, sans doublon.
- L'étiquette des palettes client utilise le même service.

---

## 6. Export PDF « Liste des Colis »

Fichiers : `PackingListController::exportColisToPdf` et la vue `packing_lists/export_colis_table.php`.

**Problèmes corrigés**
1. Le tableau de 16 colonnes **dépassait de la page** à droite.
2. Le **récapitulatif affichait 0** : la variable `$summaryData` n'était jamais calculée (avertissement PHP silencieux).
3. La ligne « Aucun colis » utilisait `colspan="10"` au lieu de 16.

**Corrections**
- `table-layout: fixed` avec une largeur pour chaque colonne. Les mots longs sont coupés dans leur cellule (`overflow-wrap: break-word`, géré par Dompdf).
- Police du tableau à 6,5 pt, marges de page à 10–12 mm, intitulés raccourcis (Qté, N° lot, CP livr., Réf. client…).
- Récapitulatif calculé avec les mêmes règles que l'export XLSX. Exemple pour la PL-BK2-2026-S42-160 : 9 palettes, 143 colis, quantité 6 836.
- La colonne BLC AMF est remplie (voir § 7).

---

## 7. BLC AMF par commande

**Page** : menu **Gestion Colisage → BLC AMF commandes** (`/commandes-blc`).
- Une ligne par commande (N° CDE AMTD = `colis.order_number`) : client, nombre de colis, Packing List(s), date d'expédition, champ BLC AMF, date et auteur de la dernière modification.
- Filtres :
  - semaine d'expédition (par défaut la plus récente, ou « Toutes ») ;
  - « Sans BLC » ou « Avec BLC » ;
  - recherche par commande, client ou BLC.
- Saisie :
  - on tape le BLC puis **Entrée** : il est enregistré en AJAX et le curseur passe à la commande suivante (compatible douchette) ;
  - le champ est aussi enregistré quand on clique ailleurs ;
  - **Échap** annule la saisie en cours ;
  - un champ vidé retire le BLC.
- Le compteur « X sans BLC » se met à jour pendant la saisie. Le navigateur avertit si on quitte la page avec une saisie non enregistrée.

**Règles**
- **Un BLC par commande** (clé primaire `order_number`). Une commande répartie sur plusieurs PL (266 commandes, jusqu'à 23 PL pour une seule) a un seul BLC.
- **Un BLC n'appartient qu'à une commande** (contrôle applicatif + `UNIQUE`, insensible à la casse). En cas de doublon, la saisie est refusée avec le numéro de la commande qui a déjà ce BLC.
- Le BLC est limité à 100 caractères. Une commande inexistante est refusée.

**Exports** : la colonne « BLC AMF » des exports « Liste des Colis » (XLSX et PDF) est remplie. Dans le PDF, elle passe de 3,5 % à 5 % de la largeur (pris sur la colonne Libellé).

**Droit** : nouvelle permission `order_blc_manage`, attribuée au rôle Administrateur.

---

## 8. Base de données

| Migration | Contenu | État en local |
|---|---|---|
| `database/migrations/2026_10_30_client_palettes.sql` | Tables `client_palettes` (code unique, client, année et semaine ISO, numéro, statut, création et clôture) et `client_palette_colis` (`UNIQUE colis_id` : un colis sur une seule palette client ; `ON DELETE CASCADE` depuis la palette) | appliquée le 30/10 |
| `database/migrations/2026_10_31_order_blc.sql` | Table `order_blc` (`PRIMARY KEY order_number`, `UNIQUE blc_amf`, auteur et date de modification) + permission `order_blc_manage` | appliquée le 31/10 |

- Aucune donnée existante n'est modifiée par ces migrations. `database/migrations/README.md` est mis à jour.
- Colonnes devenues inutilisées, conservées volontairement : `reception_palettes.condition` et `.comment`, `reception_colis.condition` et `.comment`, `palettes.qr_code_path`. La table `reception_colis` n'est plus alimentée depuis la réception par palette.

---

## 9. Routes ajoutées ou supprimées

Toutes les routes sont dans `public/index.php`.

**Ajoutées**

| Méthode | Route | Action |
|---|---|---|
| GET | `/receptions/client-packing` | Colisage client (déclaré avant `/receptions/{id}/export/xlsx`) |
| GET | `/receptions/client-packing/export/xlsx` · `/pdf` | Exports du colisage client |
| GET | `/client-palettes` | Palettes d'un client pour une semaine |
| POST | `/client-palettes/create` | Création d'une palette client |
| GET | `/client-palettes/{id}` | Écran de scan ou de consultation |
| POST | `/client-palettes/{id}/scan-ajax` | Scan d'un colis |
| POST | `/client-palettes/{id}/remove/{lineId}` | Retrait d'un colis |
| POST | `/client-palettes/{id}/close` · `/reopen` · `/delete` | Clôture, réouverture, suppression d'une palette vide |
| GET | `/client-palettes/{id}/export/xlsx` · `/pdf` | Liste de colisage de la palette |
| GET | `/client-palettes/{id}/label` | Étiquette imprimable |
| GET | `/palettes/qr/{id}` | QR palette en SVG |
| GET | `/palettes/qr/{id}/print` | Impression directe du QR palette |
| GET | `/commandes-blc` | Saisie des BLC AMF |
| POST | `/commandes-blc/save` | Enregistrement AJAX d'un BLC |

**Supprimées** : la route de changement d'état « endommagé » en réception, et `GET /receptions/{id}/deactivate-palette`.

---

## 10. Tests réalisés

Les tests sont des scripts PHP (curl + PDO) lancés avec `C:\xampp\php\php.exe`. Chaque script crée un compte administrateur temporaire et supprime tout ce qu'il a créé, en ciblant les lignes par leur identifiant.

| Domaine | Résultat |
|---|---|
| Colisage client (réception temporaire, décomposition, exports, ordre des routes) | 20/20 |
| Codes PL ou palette inexistants refusés en réception | 4 codes refusés, 0 ligne écrite |
| Réception par palette (colis refusé avec la bonne palette, compteurs, rapport, export) | 9/9 |
| Filtre par semaine des réceptions | S42 2026, S41 2026 et S30 2025 conformes à la base |
| Palettes client (création, scans acceptés et refusés, retrait, clôture, réouverture, suppression, exports, étiquette) | 33/33 |
| QR palettes à la volée (SVG, texte, `?label=0`, impression, téléchargement, 404, palette sans chemin, liste, impression groupée, aucun fichier créé) | 11/11 |
| Export PDF Liste des Colis | Bordure du tableau sur la marge droite (813,5 pt), aucun texte hors page sur les PL 160 et 158, ni avec les valeurs les plus longues des 28 514 colis |
| BLC AMF (page, filtres, saisie, unicité, modification, retrait, 404, 400, exports XLSX et PDF) | 15/15 |

**Limites des tests**
- Le rendu des PDF n'a pas pu être vu en image (pas d'outil de conversion sur le poste). Il a été vérifié par les positions dans le fichier PDF.
- Les pages HTML (étiquette, QR) ont été vérifiées par capture d'écran.
- Les impressions réelles et le scan des QR imprimés restent à valider sur le terrain.
- Depuis que l'utilisateur a créé une vraie réception sur la PL 158, le test complet des palettes client s'arrête de lui-même pour ne pas toucher aux données réelles. L'étiquette a été retestée en lecture seule.

---

## 11. Déploiement sur un autre poste (pdttu-060)

1. **Sauvegarder** la base.
2. Appliquer les migrations, dans l'ordre :
   1. `database/migrations/2026_10_30_client_palettes.sql`
   2. `database/migrations/2026_10_31_order_blc.sql`
3. **Copier les fichiers** :

| Statut | Fichier |
|---|---|
| Nouveau | `src/Models/ClientPalette.php` |
| Nouveau | `src/Models/OrderBlc.php` |
| Nouveau | `src/Controllers/ClientPaletteController.php` |
| Nouveau | `src/Controllers/OrderBlcController.php` |
| Nouveau | `src/Views/client_palettes/index.php` |
| Nouveau | `src/Views/client_palettes/show.php` |
| Nouveau | `src/Views/client_palettes/_lines.php` |
| Nouveau | `src/Views/client_palettes/_remaining.php` |
| Nouveau | `src/Views/client_palettes/export_pdf.php` |
| Nouveau | `src/Views/client_palettes/label.php` |
| Nouveau | `src/Views/order_blc/index.php` |
| Nouveau | `src/Views/palettes/print_qrcode_single.php` |
| Nouveau | `src/Views/receptions/client_packing.php` |
| Nouveau | `src/Views/receptions/export_client_packing.php` |
| Nouveau | `database/migrations/2026_10_30_client_palettes.sql` |
| Nouveau | `database/migrations/2026_10_31_order_blc.sql` |
| Modifié | `public/index.php` |
| Modifié | `src/Services/QrCodeService.php` (réécrit) |
| Modifié | `src/Models/Reception.php` |
| Modifié | `src/Models/Palette.php` |
| Modifié | `src/Controllers/ReceptionController.php` |
| Modifié | `src/Controllers/PackingListController.php` |
| Modifié | `src/Controllers/PaletteController.php` |
| Modifié | `src/Views/receptions/index.php` |
| Modifié | `src/Views/receptions/scan.php` |
| Modifié | `src/Views/receptions/_palettes_table.php` |
| Modifié | `src/Views/receptions/_unexpected.php` |
| Modifié | `src/Views/receptions/report.php` |
| Modifié | `src/Views/packing_lists/show.php` |
| Modifié | `src/Views/packing_lists/export_colis_table.php` |
| Modifié | `src/Views/palettes/index_all.php` |
| Modifié | `src/Views/palettes/show.php` |
| Modifié | `src/Views/palettes/print_qrcodes.php` |
| Modifié | `src/Views/partials/_admin_sidebar.php` |
| Modifié | `src/Views/sites/create.php` |
| Modifié | `src/Views/sites/edit.php` |
| Modifié | `database/migrations/README.md` |
| À supprimer s'il a été copié | `src/Views/client_palettes/label_pdf.php` (remplacé par `label.php`) |

4. Pour les rôles autres qu'Administrateur, ajouter si besoin les permissions `order_blc_manage` et `reception_*` depuis l'écran Rôles.

---

## 12. Points d'attention

- **Extension GD désactivée** sur LPTTU-038 (`;extension=gd` dans `C:\xampp\php\php.ini`). Elle n'est plus nécessaire pour les QR, qui sont en SVG.
- **Extension zip** non active pour PHP en ligne de commande. Les exports XLSX via Apache fonctionnent ; seuls les scripts de test en CLI sont concernés.
- **Anciens QR** : les 755 fichiers de `public/qrcodes/palettes/` (environ 1,4 Mo) et la colonne `palettes.qr_code_path` ne servent plus. Ils ne sont pas supprimés.
- **Colis sans client** : 40 colis de la PL-BK2-2026-S42-160 n'ont pas de `client_name`. Ils apparaissent sous « (Sans client) » dans le colisage client. Il faut corriger la donnée à la source, ou les traiter comme tels.
- **Compte de test** `test_reception` (site REC) : il existe encore en local.
- **Scripts de test anciens** (`smoke.php`, `test_reception.php`, `test_http.php` du dossier temporaire de session) : ils suppriment des réceptions sur les PL 157 et 160. **Ne pas les relancer** sur des données réelles.
- **Pistes non réalisées**, proposées mais pas demandées :
  - afficher le BLC AMF dans le colisage client et dans les listes de colisage des palettes client ;
  - masquer ou renommer la colonne « Groupage » quand elle est vide ;
  - supprimer les anciens fichiers QR.
