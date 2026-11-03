<?php
/**
 * Test fonctionnel : verifier que la requete corrigee de globalSearch
 * resout bien la palette d un colis enfant via son parent.
 *
 * Execution :
 *   php tests/GlobalSearchChildColisTest.php
 *
 * Lecture seule, aucune modification persistante.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function out(string $tag, string $msg): void
{
    echo str_pad($tag, 7) . ' ' . $msg . PHP_EOL;
}

$pdo = getPDO();

// 1) Trouver un colis enfant (palette_id NULL, parent_colis_id NOT NULL)
//    dont le parent est lui meme sur une palette.
$row = $pdo->query("
    SELECT c.id, c.package_number, c.parent_colis_id, parent.palette_id AS parent_palette_id, p.code AS parent_palette_code
    FROM colis c
    INNER JOIN colis parent ON c.parent_colis_id = parent.id
    INNER JOIN palettes p ON parent.palette_id = p.id
    WHERE c.palette_id IS NULL
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    out('SKIP', "Aucun colis enfant trouve avec parent place sur palette.");
    exit(0);
}

out('INFO', "Colis enfant cible : id={$row['id']} pn={$row['package_number']}");
out('INFO', "Parent : id={$row['parent_colis_id']} palette={$row['parent_palette_id']} ({$row['parent_palette_code']})");

// 2) Executer la requete EXACTE du globalSearch corrige (sans le filtre site, sans LIMIT)
$pn = $row['package_number'];
$query = '%' . $pn . '%';

$sql = "SELECT c.id, c.package_number, c.of_number, c.sku, c.label, c.client_name,
               p.id AS palette_id, p.code AS palette_code,
               pl.id AS pl_id, pl.reference AS pl_reference
        FROM colis c
        LEFT JOIN colis parent ON c.parent_colis_id = parent.id
        LEFT JOIN palettes p ON p.id = COALESCE(c.palette_id, parent.palette_id)
        LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
        WHERE c.package_number LIKE :q
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':q', $query);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3) Verifier que le colis enfant figure et qu il porte bien palette_id / palette_code
$found = null;
foreach ($results as $r) {
    if ((int) $r['id'] === (int) $row['id']) {
        $found = $r;
        break;
    }
}

if (!$found) {
    out('FAIL', "Colis enfant ABSENT des resultats globalSearch (ancien bug de regression).");
    exit(1);
}

if ((int) $found['palette_id'] === (int) $row['parent_palette_id']
    && $found['palette_code'] === $row['parent_palette_code']) {
    out('PASS', "Resolution palette enfant -> parent OK : palette_id={$found['palette_id']} ({$found['palette_code']})");
} else {
    out('FAIL', "Palette mal resolue : attendu={$row['parent_palette_id']} ({$row['parent_palette_code']}), recu={$found['palette_id']} ({$found['palette_code']})");
    exit(1);
}

if ($found['pl_id'] !== null && $found['pl_reference'] !== null) {
    out('PASS', "PL renseignee : pl_id={$found['pl_id']} ({$found['pl_reference']})");
} else {
    out('FAIL', "PL non renseignee pour colis enfant.");
    exit(1);
}

// 4) Non-regression : un colis avec palette directe doit toujours sortir avec sa propre palette
$rowDirect = $pdo->query("
    SELECT c.id, c.package_number, c.palette_id, p.code AS palette_code
    FROM colis c
    INNER JOIN palettes p ON c.palette_id = p.id
    WHERE c.palette_id IS NOT NULL AND c.parent_colis_id IS NULL
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($rowDirect) {
    $stmt2 = $pdo->prepare($sql);
    $stmt2->bindValue(':q', '%' . $rowDirect['package_number'] . '%');
    $stmt2->execute();
    $results2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $foundDirect = null;
    foreach ($results2 as $r) {
        if ((int) $r['id'] === (int) $rowDirect['id']) { $foundDirect = $r; break; }
    }
    if ($foundDirect && (int) $foundDirect['palette_id'] === (int) $rowDirect['palette_id']) {
        out('PASS', "Non-regression colis direct : palette_id inchangee ({$foundDirect['palette_id']}).");
    } else {
        out('FAIL', "Regression colis direct : attendu={$rowDirect['palette_id']}, recu=" . ($foundDirect['palette_id'] ?? 'null'));
        exit(1);
    }
}

// 5) Verification cloisonnement : le filtre AND pl.site_id = :sid doit maintenant
//    laisser passer un enfant, car pl est correctement resolu via parent.
$rowSited = $pdo->query("
    SELECT c.id, c.package_number, pl2.site_id
    FROM colis c
    INNER JOIN colis parent ON c.parent_colis_id = parent.id
    INNER JOIN palettes p2 ON parent.palette_id = p2.id
    INNER JOIN packing_lists pl2 ON p2.packing_list_id = pl2.id
    WHERE c.palette_id IS NULL AND pl2.site_id IS NOT NULL
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($rowSited) {
    $sqlSited = "SELECT c.id, p.code AS palette_code, pl.id AS pl_id
                 FROM colis c
                 LEFT JOIN colis parent ON c.parent_colis_id = parent.id
                 LEFT JOIN palettes p ON p.id = COALESCE(c.palette_id, parent.palette_id)
                 LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                 WHERE c.package_number LIKE :q AND pl.site_id = :sid
                 LIMIT 10";
    $stmt3 = $pdo->prepare($sqlSited);
    $stmt3->bindValue(':q', '%' . $rowSited['package_number'] . '%');
    $stmt3->bindValue(':sid', (int) $rowSited['site_id'], PDO::PARAM_INT);
    $stmt3->execute();
    $results3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    $found3 = null;
    foreach ($results3 as $r) {
        if ((int) $r['id'] === (int) $rowSited['id']) { $found3 = $r; break; }
    }
    if ($found3) {
        out('PASS', "Cloisonnement site : enfant present dans resultats site_id={$rowSited['site_id']}.");
    } else {
        out('FAIL', "Cloisonnement site : enfant absent (regression).");
        exit(1);
    }
} else {
    out('SKIP', "Pas d enfant avec PL cloisonnee site.");
}

out('DONE', "Tests globalSearch enfants OK.");
