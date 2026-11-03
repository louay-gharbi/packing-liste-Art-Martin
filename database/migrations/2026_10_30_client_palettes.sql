-- Migration : palettes client (expédition France -> client), après décomposition des réceptions par client.
-- A appliquer manuellement apres sauvegarde de la base (aucune donnée existante modifiée).
-- Permissions réutilisées : reception_view (consulter / exporter), reception_scan (créer, scanner, retirer),
-- reception_close (clôturer / rouvrir).

-- 1. Palettes client : une palette = un client + une semaine d'expédition ISO, numérotée 01, 02...
CREATE TABLE client_palettes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL COMMENT 'PALC-<CLIENT>-<AAAA>-S<SS>-<NN>',
  client_name VARCHAR(255) NOT NULL,
  ship_year SMALLINT UNSIGNED NOT NULL COMMENT 'Année ISO de la semaine d''expédition Tunisie',
  ship_week TINYINT UNSIGNED NOT NULL COMMENT 'Semaine ISO d''expédition Tunisie',
  number SMALLINT UNSIGNED NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  created_by_user_id INT UNSIGNED DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  closed_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_palette_code (code),
  KEY idx_client_palette_week (ship_year, ship_week, client_name),
  CONSTRAINT fk_cp_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Palettes d''expédition client (France)';

-- 2. Colis posés sur une palette client : un colis ne peut être que sur une seule palette client.
CREATE TABLE client_palette_colis (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_palette_id INT UNSIGNED NOT NULL,
  colis_id INT UNSIGNED NOT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cpc_colis (colis_id),
  KEY idx_cpc_palette (client_palette_id),
  CONSTRAINT fk_cpc_palette FOREIGN KEY (client_palette_id) REFERENCES client_palettes (id) ON DELETE CASCADE,
  CONSTRAINT fk_cpc_colis FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cpc_user FOREIGN KEY (scanned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
