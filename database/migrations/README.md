# Migrations de la phase de securisation

Ces scripts SQL sont **a appliquer manuellement** dans l ordre indique ci-dessous,
apres sauvegarde de la base de production.

## Etat d application (base locale `packing_list_db`)

> Applique le **2026-05-29 11:06** apres sauvegarde
> `database/backup_pre_migrations_20260529_110559.sql` (7.1 Mo).

| Script | Statut | Verification |
|--------|:------:|--------------|
| `2026_05_29_login_attempts.sql` | applique | table creee + index `idx_lookup` present |
| `2026_05_29_unique_constraints.sql` | applique | 3 UNIQUE creees (`uq_palettes_code` 234 entrees, `uq_pl_reference` 45, `uq_colis_package` 10697) — aucun doublon prealable |
| `2026_05_29_theoretical_exports_align.sql` | **NON applique (skip volontaire)** | `DESCRIBE theoretical_exports` confirme `article_reference` et `packing_list_id` deja en place |

| `2026_10_30_reception_france.sql` | applique le 2026-10-30 | sauvegarde `database/backup_pre_reception_20261030.sql` ; `sites.type` + site `REC` (renomme manuellement depuis `FR` le 2026-10-30), tables `receptions` / `reception_palettes` / `reception_colis`, 3 permissions `reception_*` attribuees au role Administrateur |
| `2026_10_30_client_palettes.sql` | applique le 2026-10-30 | tables `client_palettes` / `client_palette_colis` creees (aucune donnee existante modifiee) |
| `2026_10_31_order_blc.sql` | applique le 2026-10-31 | table `order_blc` creee + permission `order_blc_manage` attribuee au role Administrateur |
| `2026_11_02_client_palettes_postal_code.sql` | applique le 2026-11-02 | colonne `client_palettes.postal_code` ; 2 palettes reprises de leurs colis, palette vide CARTMART completee a la main (69100) |

A appliquer separement en production en suivant la meme procedure.

## Ordre d execution

1. `2026_05_29_login_attempts.sql`
   - Cree la table `login_attempts` utilisee par le throttling anti-bruteforce
     (Phase 4). Le code applicatif degrade gracefully si la table est absente
     (l echec est juste log), mais le throttling ne sera actif qu apres
     cette migration.

2. `2026_05_29_unique_constraints.sql`
   - Ajoute des `UNIQUE KEY` sur `palettes.code`, `packing_lists.reference`,
     `colis.package_number` pour eliminer le risque de doublons silencieux
     en cas de course (Phase 5).
   - **AVANT D APPLIQUER**, executer les 3 requetes de detection de doublons
     listees en commentaire du fichier. Si une retourne des lignes,
     escalader pour decision metier (le renommage des doublons ne doit
     pas etre fait par l agent).

3. `2026_05_29_theoretical_exports_align.sql`
   - Aligne le schema `theoretical_exports` avec ce qu attend le code
     (renomme `sku` -> `article_reference`, ajoute `packing_list_id`).
   - **A NE PAS APPLIQUER** si la base de production a deja ces colonnes
     (verifier via `DESCRIBE theoretical_exports;`). L application tournant
     en production avec le code actuel, c est l hypothese la plus probable :
     ce script est destine aux nouveaux deploiements partant du dump initial.

4. `2026_10_30_reception_france.sql` (module Reception France, cf. `plan_reception_france.md`)
   - Ajoute `sites.type` (`production` / `reception`) et cree le site `REC` « Réception ».
   - Cree `receptions`, `reception_palettes`, `reception_colis`.
   - Cree les permissions `reception_view`, `reception_scan`, `reception_close`
     et les attribue au role Administrateur (Auth::can n a pas de bypass admin).
   - Apres application : creer par l interface un role pour les operateurs France
     (en local : « Opérateur Réception ») et rattacher leurs comptes au site `REC`.

5. `2026_10_30_client_palettes.sql` (palettes client, cf. `RAPPORT_EXECUTION_2026-10-31.md`)
   - Cree `client_palettes` (une palette = un client + une semaine d expedition ISO)
     et `client_palette_colis` (un colis sur une seule palette client).
   - Aucune nouvelle permission : reutilise `reception_view` / `reception_scan` / `reception_close`.

6. `2026_10_31_order_blc.sql` (BLC AMF par commande)
   - Cree `order_blc` (un BLC par commande, un BLC unique).
   - Cree la permission `order_blc_manage` et l attribue au role Administrateur.

7. `2026_11_02_client_palettes_postal_code.sql` (destination = client + code postal)
   - Ajoute `client_palettes.postal_code` et le reprend des colis deja poses.
   - La requete finale liste les palettes restees sans code postal (palettes vides) :
     les supprimer depuis l ecran puis les recreer depuis le colisage client.

## Sauvegarde prealable

Le dump pre-securisation a ete copie dans
`database/backup_avant_securisation.sql` lors de la Phase 0.

## Inventaire CSRF

`INVENTAIRE_POST.md` enumere tous les points POST de l application avec leur
statut de couverture CSRF. Sert de checklist pour les audits futurs.
