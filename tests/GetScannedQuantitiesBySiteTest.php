<?php
/**
 * Test : comparer les totaux scannes par site AVANT (INNER JOIN c.palette_id)
 * vs APRES (COALESCE via parent) pour mesurer l impact reel sur les chiffres.
 *
 * Lecture seule.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getPDO();

$sites = $pdo->query("SELECT id, name FROM sites ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$sites) { echo "Aucun site.\n"; exit(0); }

foreach ($sites as $site) {
    $siteId = (int) $site['id'];

    $sqlOld = "SELECT COALESCE(SUM(c.quantity),0) AS tot
               FROM colis c
               INNER JOIN palettes pal ON c.palette_id = pal.id
               INNER JOIN packing_lists pl ON pal.packing_list_id = pl.id
               WHERE pl.site_id = :sid AND pal.status IN ('open','closed')";
    $sqlNew = "SELECT COALESCE(SUM(c.quantity),0) AS tot
               FROM colis c
               LEFT JOIN colis parent ON c.parent_colis_id = parent.id
               INNER JOIN palettes pal ON pal.id = COALESCE(c.palette_id, parent.palette_id)
               INNER JOIN packing_lists pl ON pal.packing_list_id = pl.id
               WHERE pl.site_id = :sid AND pal.status IN ('open','closed')";

    $stOld = $pdo->prepare($sqlOld); $stOld->bindValue(':sid', $siteId, PDO::PARAM_INT); $stOld->execute();
    $stNew = $pdo->prepare($sqlNew); $stNew->bindValue(':sid', $siteId, PDO::PARAM_INT); $stNew->execute();
    $old = (int) $stOld->fetchColumn();
    $new = (int) $stNew->fetchColumn();
    $delta = $new - $old;
    printf("Site %d (%s) : avant=%d apres=%d delta=%+d%s\n",
        $siteId, $site['name'], $old, $new, $delta,
        $delta > 0 ? "  (enfants ajoutes)" : ($delta < 0 ? "  ANOMALIE" : ""));
}
echo "DONE\n";
