-- Detection heuristique des colis potentiellement mal classes par d anciens
-- deplacements inter-Packing-List (avant la garde du fix 2026-05-29).
--
-- ATTENTION : c est une LISTE DE CANDIDATS, pas une liste d actions automatiques.
-- A executer en LECTURE SEULE et a transmettre a un decideur metier.
-- Aucune correction automatique ne doit etre derivee de ce script.
--
-- Schema confirme via DESCRIBE le 2026-05-29 :
--   theoretical_exports : packing_list_id, of_number, article_reference (presents)
--   colis               : of_number, article_reference, palette_id, parent_colis_id
--
-- Heuristique :
--   un colis est SUSPECT si l OF/article qu il porte (de_number, article_reference)
--   n est PAS attendu dans le theorique de sa PL actuelle, alors qu il l est dans
--   une AUTRE PL via theoretical_exports.
--
-- Pre-requis pour utilite : un theorique a ete importe pour la PL actuelle.
-- Sans theorique, le candidat n est pas detecte (faux negatif silencieux).
--
-- Faux positifs possibles :
--   - Un OF/article legitimement present dans plusieurs PL (multi-commandes).
--   - Theorique incomplet pour la PL actuelle (oubli d import partiel).
-- Le decideur doit examiner chaque ligne avant toute action.

SELECT
    c.id                AS colis_id,
    c.package_number,
    c.of_number,
    c.article_reference,
    p.id                AS palette_actuelle_id,
    p.code              AS palette_actuelle_code,
    pl.id               AS pl_actuelle_id,
    pl.reference        AS pl_actuelle_ref,
    -- Lister la (les) PL ou cet OF/article ETAIT attendu dans le theorique
    (
        SELECT GROUP_CONCAT(DISTINCT pl2.reference ORDER BY pl2.reference SEPARATOR ', ')
        FROM theoretical_exports te2
        JOIN packing_lists pl2 ON pl2.id = te2.packing_list_id
        WHERE te2.of_number       = c.of_number
          AND te2.article_reference = c.article_reference
          AND te2.packing_list_id <> p.packing_list_id
    ) AS pl_attendues_autre
FROM colis c
JOIN palettes p       ON c.palette_id = p.id
JOIN packing_lists pl ON p.packing_list_id = pl.id
WHERE c.of_number IS NOT NULL
  AND c.article_reference IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM theoretical_exports te
        WHERE te.packing_list_id   = p.packing_list_id
          AND te.of_number         = c.of_number
          AND te.article_reference = c.article_reference
      )
  AND EXISTS (
        SELECT 1 FROM theoretical_exports te2
        WHERE te2.of_number       = c.of_number
          AND te2.article_reference = c.article_reference
          AND te2.packing_list_id <> p.packing_list_id
      )
ORDER BY pl.reference, p.code, c.package_number;

-- Variante de comptage (pour avoir un ordre de grandeur sans afficher la liste) :
-- SELECT COUNT(*) AS suspects FROM colis c
-- JOIN palettes p ON c.palette_id = p.id
-- WHERE c.of_number IS NOT NULL AND c.article_reference IS NOT NULL
--   AND NOT EXISTS (SELECT 1 FROM theoretical_exports te
--                   WHERE te.packing_list_id = p.packing_list_id
--                     AND te.of_number = c.of_number
--                     AND te.article_reference = c.article_reference)
--   AND EXISTS     (SELECT 1 FROM theoretical_exports te2
--                   WHERE te2.of_number = c.of_number
--                     AND te2.article_reference = c.article_reference
--                     AND te2.packing_list_id <> p.packing_list_id);
