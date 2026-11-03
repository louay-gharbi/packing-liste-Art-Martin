-- Migration: table de suivi des tentatives de connexion (anti-bruteforce).
-- A appliquer manuellement sur la base de production via:
--   mysql -u<user> -p<password> <db> < 2026_05_29_login_attempts.sql

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`username`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
