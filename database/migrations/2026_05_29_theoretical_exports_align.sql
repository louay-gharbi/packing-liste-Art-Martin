-- Migration: alignement du schema theoretical_exports avec le code applicatif.
--
-- CONTEXTE :
-- Le dump SQL initial (database/packing_list_db.sql) declare la table avec :
--   - colonne `sku`
--   - PAS de colonne `packing_list_id`
-- Mais le code applicatif (TheoreticalExport::insertBatch, processImport)
-- utilise :
--   - colonne `article_reference`
--   - colonne `packing_list_id` (FK vers packing_lists)
--
-- L application est en production et fonctionne, ce qui suggere que le dump
-- est obsolete et que la base reelle a deja ces colonnes. Cette migration
-- vise les deploiements qui partiraient du dump initial uniquement.
--
-- AVANT D APPLIQUER : verifier l etat reel via
--   DESCRIBE theoretical_exports;
-- Si les colonnes `article_reference` et `packing_list_id` existent deja,
-- IGNORER cette migration.

-- 1) Renommer sku en article_reference si la colonne sku existe encore.
-- (MariaDB 10.5+ : ALTER TABLE ... CHANGE garde le commentaire si fourni.)
ALTER TABLE `theoretical_exports`
    CHANGE COLUMN `sku` `article_reference` VARCHAR(100) NOT NULL
    COMMENT 'Reference article (ref_article du QR code) - ex sku';

-- 2) Ajouter packing_list_id si manquant.
ALTER TABLE `theoretical_exports`
    ADD COLUMN `packing_list_id` INT(10) UNSIGNED NULL
    COMMENT 'PL cible de cet export theorique' AFTER `site_id`;

-- 3) Index pour les jointures frequentes.
ALTER TABLE `theoretical_exports`
    ADD KEY `idx_theoretical_packing_list` (`packing_list_id`);
