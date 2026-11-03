# PackingList : corrections et évolutions

**Application** : PackingList (gestion du colisage Tunisie, réception France AMF)
**Période** : octobre – novembre 2026
**Rédigé par** : Louay Gharbi
**Date** : 03/11/2026

---

## Synthèse

| # | Domaine | Sujet | Type |
|---|---|---|---|
| 1 | Utilisateurs | Filtrage des utilisateurs, correction des rôles et permissions | Correction |
| 2 | Utilisateurs | Accompagnement des utilisateurs sur le principe de l'application | Assistance |
| 3 | Sécurité | Envoi des rapports journaliers à des destinataires externes | Correction sécurité |
| 4 | Sécurité | Base de données protégée par un utilisateur dédié (non `root`) et un mot de passe | Correction sécurité |
| 5 | Ergonomie | Tri par date de création et de fermeture | Correction |
| 6 | Fonctionnalité | Saisie centralisée du N° de BLC AMF par commande | Nouveauté |
| 7 | Fonctionnalité | Profil utilisateur | Nouveauté |
| 8 | Fonctionnalité | Suppression d'une palette ouverte complète (admin seulement) | Nouveauté |
| 9 | Fonctionnalité | Réception AMF et création des palettes client | Nouveauté |
| 10 | Divers | Autres corrections et améliorations (QR, PDF, interface, routage) | Correction / amélioration |

---

## 1. Filtrage des utilisateurs, rôles et permissions

- **Filtrage** : la liste des utilisateurs peut être filtrée par site. Un responsable rattaché à un site ne voit que les utilisateurs de son site ; l'administrateur voit tous les sites.
- **Rôles et permissions** : revue des rôles existants et correction des permissions attribuées, pour que chaque utilisateur n'ait accès qu'aux écrans de son métier (production Tunisie, réception France, administration).
- **Permissions ajoutées avec les nouveaux modules** :
  - `reception_view`, `reception_scan`, `reception_close` : réception France et palettes client ;
  - `order_blc_manage` : saisie des BLC AMF.
- **Règle** : les permissions s'attribuent **par rôle**, jamais utilisateur par utilisateur (Administrateur, Opérateur réception, etc.).

## 2. Accompagnement des utilisateurs

Les utilisateurs ont été accompagnés pour mieux comprendre le principe de l'application :
- le cycle **Packing List → palettes → scan des colis → clôture** côté Tunisie ;
- la notion de **colis parent (groupage) et de colis fils** ;
- le rôle des sites (production ou réception) et des permissions ;
- les nouveaux écrans : réception, colisage client, palettes client, BLC AMF, profil.

## 3. Sécurité : rapports journaliers envoyés à l'extérieur

- **Problème** : des rapports journaliers étaient envoyés à des adresses externes à l'entreprise.
- **Correction** : la liste des destinataires a été limitée aux utilisateurs internes autorisés. Les données de production ne sortent plus vers des destinataires non prévus.

## 4. Sécurité de la base de données

- **Avant** : l'application se connectait à MySQL avec le compte `root`.
- **Après** : un **utilisateur MySQL dédié**, avec un mot de passe et seulement les droits nécessaires sur la base de l'application.
- Les identifiants ne sont plus écrits dans le code : ils sont lus dans les variables d'environnement `DB_USER` et `DB_PASSWORD`.
- **En production, l'application refuse de démarrer** si ces variables manquent. Il n'y a plus de repli possible sur `root` sans mot de passe.

## 5. Tri par date de création et de fermeture

- **Problème** : les dates étaient triées comme du texte (`jj/mm/aaaa`), par exemple « 02/11 » avant « 30/10 ».
- **Correction** : la date affichée reste au format français, mais le tri utilise la date réelle (`aaaa-mm-jj hh:mm:ss`).
- **Écrans corrigés** :
  - liste des Packing Lists (expédition, création) ;
  - fiche PL (création des palettes) ;
  - toutes les palettes (ouverture, fermeture, création) ;
  - détail palette (« Scanné le ») ;
  - sites (création, modification).

## 6. N° de BLC AMF centralisé par commande

- **Page** : « BLC AMF commandes », menu Gestion Colisage, permission `order_blc_manage`.
- **Lignes** : une ligne par commande, avec le client, le code postal, le nombre de colis, les PL et la date d'expédition.
- **Filtres** :
  - semaine d'expédition ;
  - commandes avec ou sans BLC ;
  - recherche par commande, client, code postal ou BLC.
- **Saisie** : bouton **+ Ajouter** ou **✎ Modifier**. La touche Entrée enregistre et ouvre directement la commande suivante sans BLC, pour la saisie à la chaîne.
- **Règles** :
  - un seul BLC par commande ;
  - un BLC ne peut appartenir qu'à une seule commande ;
  - chaque saisie est tracée : qui l'a faite et quand.
- **Exports** : le BLC est repris dans les exports Liste des Colis (Excel et PDF).

## 7. Profil utilisateur

- **Accès** : menu **en haut à droite** (photo ou initiales, puis le nom), qui donne aussi la déconnexion.
- **Ce que l'utilisateur peut modifier** :
  - son prénom, son nom et son e-mail ;
  - son **mot de passe** : l'ancien est exigé, avec 12 caractères minimum, une minuscule, une majuscule et un chiffre ;
  - sa **photo de profil** : JPG, PNG ou WEBP, 2 Mo maximum, avec un contrôle que le fichier est bien une image.
- **Ce qu'il voit sans pouvoir le modifier** : son site et ses rôles, qui restent gérés par l'administrateur.
- **Intérêt** : chacun garde son propre compte, et les actions (scans, réceptions, BLC) sont tracées au bon nom.

## 8. Suppression d'une palette ouverte complète

- **Bouton** : il supprime une palette **ouverte** avec tout son contenu.
- **Accès** : **administrateurs uniquement**.
- **Usage** : corriger une palette créée ou remplie par erreur, sans devoir dé-scanner les colis un par un.

## 9. Réception AMF et palettes client

### 9.1 Réception France (AMF)
- Un site de type **Réception** (`REC`) est créé. Il ne peut pas porter de Packing List.
- **Réception par palette complète** : on scanne le QR de la palette Tunisie, pas les colis un par un.
- **Contrôle** : écarts détectés (palette manquante ou inattendue), clôture avec un commentaire, **rapport de réception** exportable en Excel.
- Une PL en réception **ne peut plus être modifiée ni réouverte** côté Tunisie.
- La liste des réceptions est filtrée par **semaine d'expédition**.

### 9.2 Colisage client
- Les palettes reçues sont **éclatées par destination**, une destination étant un **client + un code postal** : un même client à deux adresses donne deux destinations.
- Un groupage (colis parent) est remplacé par ses **colis fils**, chacun rattaché à son client.
- **Exports** : Excel avec un onglet par destination, PDF avec une page par destination.

### 9.3 Palettes client (PALC)
- **Création** : une palette par destination et par semaine, avec une numérotation automatique `PALC-<CLIENT>-<AAAA>-S<SS>-NN`.
- **Scan des colis avec contrôle** :
  - un colis d'un autre client est **refusé** ;
  - un colis du même client mais d'une autre adresse est refusé ;
  - un groupage doit être scanné fils par fils ;
  - un colis ne peut être que sur une seule palette client.
- **Chaque colis indique sa provenance** : la palette Tunisie et la PL d'origine.
- **Suivi** : liste des colis restant à poser ; clôture, réouverture, suppression (si la palette est vide).
- **Documents** : liste de colisage en Excel et PDF ; **étiquette 100×150 mm** imprimable directement (logo, client, code postal, semaine, QR, code).

---

## 10. Autres corrections et améliorations, à ajouter au rapport

| Sujet | Détail |
|---|---|
| **QR codes des palettes** | Générés **à la volée** (SVG, avec le code écrit sous le QR) au lieu d'images PNG enregistrées sur le disque. Plus de fichiers manquants ou orphelins. Page d'impression directe d'un QR. |
| **PDF « Liste des Colis »** | Mise en page corrigée (colonnes à largeur fixe, textes longs qui passent à la ligne, récapitulatif juste), colonne BLC ajoutée. |
| **PDF du détail palette** | Généré **par le serveur** en A4 paysage : chaque groupage est suivi de ses colis fils, avec récapitulatif et numéro de page. Avant, le PDF fait par le navigateur coupait des colonnes. |
| **Affichage des groupages** | Badge « Groupage · N colis » et tri qui garde toujours un colis parent avec ses fils. |
| **Menu et mise en page** | Menu de gauche compact qui tient à l'écran sans barre de défilement, Paramètres déplacé en bas, pages qui tiennent à zoom 100 % sans débordement à droite. |
| **Cache du navigateur** | Les fichiers CSS et JavaScript sont rechargés automatiquement après une mise à jour, sans Ctrl+F5. |
| **Routage** | Les adresses sont reconnues quelle que soit la casse (`/Packinglist/` = `/packinglist/`). |
| **Type de site** | Chaque site est de type Production (Tunisie) ou Réception (France). Seuls les sites de production proposent la création de PL. |
| **Gestion du code source** | La version en production est enregistrée sur GitHub (`louay-gharbi/packing-liste-Art-Martin`), **sans les sauvegardes de la base** ni les données réelles. |

---

## Prochaines étapes proposées
1. **Déployer** la réception AMF, les palettes client, le BLC et le profil en prod : 4 migrations SQL, dans l'ordre, après une sauvegarde.
2. **Créer** le rôle « Opérateur réception » et les comptes de l'équipe France.
3. **Former** l'équipe France au scan des palettes et à la création des palettes client.
4. **Purger** plus tard les anciennes images PNG de QR, qui ne servent plus.
