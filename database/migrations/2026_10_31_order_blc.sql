-- Migration : BLC AMF par commande (N° CDE AMTD = colis.order_number).
-- Une commande a un seul BLC AMF, et un BLC AMF n'appartient qu'à une seule commande.
-- A appliquer manuellement apres sauvegarde de la base (aucune donnée existante modifiée).

CREATE TABLE order_blc (
  order_number VARCHAR(255) NOT NULL COMMENT 'N° CDE AMTD (colis.order_number)',
  blc_amf VARCHAR(100) NOT NULL,
  updated_at DATETIME NOT NULL,
  updated_by_user_id INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (order_number),
  UNIQUE KEY uq_order_blc_amf (blc_amf),
  CONSTRAINT fk_order_blc_user FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='BLC AMF des commandes';

-- Permission + attribution au rôle Administrateur (Auth::can n'a PAS de bypass admin)
INSERT IGNORE INTO permissions (name, description) VALUES
  ('order_blc_manage', 'Saisir et modifier les BLC AMF des commandes');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'order_blc_manage'
WHERE r.name = 'Administrateur';
