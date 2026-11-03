-- Migration : une palette client est destinée à un client ET une adresse (code postal).
-- Un même client peut avoir plusieurs adresses de livraison (108 clients = 148 couples client / code postal) :
-- le colisage client et les palettes client sont regroupés par client + code postal.
-- A appliquer manuellement apres sauvegarde de la base, APRES 2026_10_30_client_palettes.sql.

ALTER TABLE client_palettes
  ADD COLUMN postal_code VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'Code postal de livraison (colis.postal_code)' AFTER client_name;

-- Palettes existantes : code postal repris de leurs colis.
UPDATE client_palettes cp
JOIN (SELECT cpc.client_palette_id, MIN(TRIM(c.postal_code)) AS postal_code
      FROM client_palette_colis cpc JOIN colis c ON c.id = cpc.colis_id
      GROUP BY cpc.client_palette_id) x ON x.client_palette_id = cp.id
SET cp.postal_code = COALESCE(x.postal_code, '');

ALTER TABLE client_palettes
  DROP INDEX idx_client_palette_week,
  ADD KEY idx_client_palette_week (ship_year, ship_week, client_name, postal_code);

-- Contrôle : palettes encore sans code postal (palettes vides créées avant cette migration).
-- Les supprimer depuis l'écran (palette vide) puis les recréer depuis le colisage client.
SELECT id, code, client_name, status FROM client_palettes WHERE postal_code = '';
