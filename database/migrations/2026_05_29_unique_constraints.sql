-- Migration: contraintes d unicite sur les references metier.
--
-- ATTENTION : avant d appliquer cette migration, verifier l absence de doublons :
--
--   SELECT code, COUNT(*) c FROM palettes GROUP BY code HAVING c > 1;
--   SELECT reference, COUNT(*) c FROM packing_lists GROUP BY reference HAVING c > 1;
--   SELECT package_number, COUNT(*) c FROM colis GROUP BY package_number HAVING c > 1;
--
-- Si une de ces requetes retourne des lignes, NE PAS appliquer la migration :
-- escalader pour arbitrage metier (decider quel enregistrement renommer).
--
-- L application ajoute deja un IF NOT EXISTS implicite via le nom des contraintes :
-- si les UNIQUE existent deja, ALTER TABLE echoue avec ERROR 1061 (Duplicate key name)
-- qui peut etre traite manuellement (l idempotence MySQL pour ADD UNIQUE n est pas
-- standard avant 8.0.29). En pratique, on applique une seule fois.

ALTER TABLE `palettes`      ADD UNIQUE KEY `uq_palettes_code`   (`code`);
ALTER TABLE `packing_lists` ADD UNIQUE KEY `uq_pl_reference`    (`reference`);
ALTER TABLE `colis`         ADD UNIQUE KEY `uq_colis_package`   (`package_number`);
