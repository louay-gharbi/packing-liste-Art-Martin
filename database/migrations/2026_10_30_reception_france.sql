-- Migration : module Réception France (cf. plan_reception_france.md, Phase A)
-- A appliquer manuellement apres sauvegarde de la base.

-- 1. Type de site : distinguer les sites de production (Tunisie) du site de réception (France)
ALTER TABLE sites
  ADD COLUMN type ENUM('production','reception') NOT NULL DEFAULT 'production' AFTER address;

INSERT INTO sites (code, name, address, type, is_active)
VALUES ('REC', 'Réception', NULL, 'reception', 1)
ON DUPLICATE KEY UPDATE type = 'reception';

-- 2. Réceptions (une par PL)
CREATE TABLE receptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  packing_list_id INT UNSIGNED NOT NULL,
  site_id INT UNSIGNED DEFAULT NULL COMMENT 'Site de réception (France)',
  status ENUM('in_progress','closed') NOT NULL DEFAULT 'in_progress',
  started_at DATETIME NOT NULL,
  started_by_user_id INT UNSIGNED DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  closed_by_user_id INT UNSIGNED DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  discrepancy_count INT UNSIGNED DEFAULT NULL COMMENT 'Nombre d''écarts figé à la clôture',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reception_pl (packing_list_id),
  KEY idx_reception_status (status),
  CONSTRAINT fk_reception_pl FOREIGN KEY (packing_list_id) REFERENCES packing_lists (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reception_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_reception_started_by FOREIGN KEY (started_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_reception_closed_by FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Réceptions France des Packing Lists';

-- 3. Palettes scannées en réception
CREATE TABLE reception_palettes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reception_id INT UNSIGNED NOT NULL,
  palette_id INT UNSIGNED NOT NULL,
  is_unexpected TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = palette d''une autre PL',
  is_auto TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = reçue implicitement via le scan d''un de ses colis',
  `condition` ENUM('ok','damaged') NOT NULL DEFAULT 'ok',
  comment TEXT DEFAULT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rp (reception_id, palette_id),
  CONSTRAINT fk_rp_reception FOREIGN KEY (reception_id) REFERENCES receptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_palette FOREIGN KEY (palette_id) REFERENCES palettes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rp_user FOREIGN KEY (scanned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Colis scannés en réception
CREATE TABLE reception_colis (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reception_id INT UNSIGNED NOT NULL,
  colis_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = colis inconnu en base',
  package_number VARCHAR(255) NOT NULL,
  reception_palette_id INT UNSIGNED DEFAULT NULL COMMENT 'Palette active au moment du scan',
  is_unexpected TINYINT(1) NOT NULL DEFAULT 0,
  `condition` ENUM('ok','damaged') NOT NULL DEFAULT 'ok',
  comment TEXT DEFAULT NULL,
  raw_payload TEXT DEFAULT NULL,
  scanned_at DATETIME NOT NULL,
  scanned_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rc (reception_id, package_number),
  KEY idx_rc_colis (colis_id),
  CONSTRAINT fk_rc_reception FOREIGN KEY (reception_id) REFERENCES receptions (id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_colis FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE SET NULL,
  CONSTRAINT fk_rc_rpalette FOREIGN KEY (reception_palette_id) REFERENCES reception_palettes (id) ON DELETE SET NULL,
  CONSTRAINT fk_rc_user FOREIGN KEY (scanned_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Permissions + attribution au rôle Administrateur (Auth::can n'a PAS de bypass admin)
INSERT IGNORE INTO permissions (name, description) VALUES
  ('reception_view',  'Voir les réceptions France et leurs rapports d''écarts'),
  ('reception_scan',  'Démarrer une réception et scanner palettes/colis en France'),
  ('reception_close', 'Clôturer une réception France');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.name IN ('reception_view','reception_scan','reception_close')
WHERE r.name = 'Administrateur';
