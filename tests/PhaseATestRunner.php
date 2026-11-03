<?php
/**
 * Tests fonctionnels Phase A du plan_correction_post_fix.md
 * (invariant inter-PL : modele Colis et controleur).
 *
 * Execution :
 *   php tests/PhaseATestRunner.php
 *
 * Aucune modification persistante. Pour chaque test :
 *  - on capture l etat avant
 *  - on execute le scenario
 *  - on RESTAURE l etat (UPDATE direct) si l action a reellement modifie quelque chose
 * Pas de transaction PHP wrapping (moveMultipleToPalette ouvre sa propre transaction).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

use App\Models\Colis;

function out(string $tag, string $msg): void
{
    echo str_pad($tag, 7) . ' ' . $msg . PHP_EOL;
}

$pdo = getPDO();

/**
 * Restaure le palette_id d un colis a une valeur connue. UPDATE direct,
 * pas de model pour ne pas re-declencher les checks.
 */
function restoreColisPalette(PDO $pdo, int $colisId, ?int $originalPaletteId): void
{
    $stmt = $pdo->prepare("UPDATE colis SET palette_id = :pid WHERE id = :cid");
    $stmt->bindValue(':pid', $originalPaletteId, $originalPaletteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':cid', $colisId, PDO::PARAM_INT);
    $stmt->execute();
}

function currentPaletteOf(PDO $pdo, int $colisId): ?int
{
    $v = $pdo->query("SELECT palette_id FROM colis WHERE id = {$colisId}")->fetchColumn();
    return $v === null || $v === false ? null : (int) $v;
}

// 1) Trouver deux PL distinctes, chacune avec au moins une palette OUVERTE.
$rows = $pdo->query("
    SELECT pl.id AS pl_id, pl.reference, p.id AS palette_id, p.code AS palette_code
    FROM packing_lists pl
    JOIN palettes p ON p.packing_list_id = pl.id AND p.status = 'open'
    ORDER BY pl.id DESC, p.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$byPl = [];
foreach ($rows as $r) {
    $byPl[$r['pl_id']][] = $r;
}
$plIds = array_keys($byPl);
if (count($plIds) < 2) {
    out('SKIP', "Besoin de >= 2 PL avec palettes ouvertes (trouve " . count($plIds) . ").");
    exit(0);
}
$plA = $plIds[0];
$plB = $plIds[1];
$paletteA  = $byPl[$plA][0];
$paletteB  = $byPl[$plB][0];
$paletteA2 = $byPl[$plA][1] ?? null;

out('INFO', "PL A : id={$plA} ({$paletteA['reference']}) - palette {$paletteA['palette_code']} (id {$paletteA['palette_id']})");
out('INFO', "PL B : id={$plB} ({$paletteB['reference']}) - palette {$paletteB['palette_code']} (id {$paletteB['palette_id']})");
if ($paletteA2) out('INFO', "PL A (2e)        : palette {$paletteA2['palette_code']} (id {$paletteA2['palette_id']})");

// 2) Trouver un colis (parent ou seul) sur paletteA
$stmt = $pdo->prepare("
    SELECT id, package_number, palette_id, parent_colis_id
    FROM colis WHERE palette_id = :pid AND parent_colis_id IS NULL
    LIMIT 1
");
$stmt->execute([':pid' => $paletteA['palette_id']]);
$colis = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$colis) {
    out('SKIP', "Aucun colis sur palette {$paletteA['palette_code']}");
    exit(0);
}
$colisId = (int) $colis['id'];
$origColisPalette = (int) $colis['palette_id'];
out('INFO', "Colis cible : id={$colisId} pn={$colis['package_number']} palette_origine={$origColisPalette}");

// =========================================================================
// A.1.1 : moveToPalette INTER-PL -> doit refuser (return false)
// =========================================================================
$ok = Colis::moveToPalette($colisId, (int) $paletteB['palette_id']);
$after = currentPaletteOf($pdo, $colisId);
if ($ok === false && $after === $origColisPalette) {
    out('PASS', "A.1 moveToPalette inter-PL : return=false ET palette inchangee ({$after})");
} else {
    out('FAIL', "A.1 INATTENDU inter-PL : return=" . var_export($ok, true) . " palette_apres={$after}");
    restoreColisPalette($pdo, $colisId, $origColisPalette); // securite
}

// =========================================================================
// A.1.2 : moveToPalette INTRA-PL -> doit accepter (return true)
// =========================================================================
if ($paletteA2) {
    $ok = Colis::moveToPalette($colisId, (int) $paletteA2['palette_id']);
    $after = currentPaletteOf($pdo, $colisId);
    if ($ok === true && $after === (int) $paletteA2['palette_id']) {
        out('PASS', "A.1 moveToPalette intra-PL : return=true ET palette={$after}");
    } else {
        out('FAIL', "A.1 INATTENDU intra-PL : return=" . var_export($ok, true) . " palette_apres={$after}");
    }
    // Restauration systematique
    restoreColisPalette($pdo, $colisId, $origColisPalette);
} else {
    out('SKIP', "A.1 intra-PL : pas de 2e palette ouverte dans PL A");
}

// =========================================================================
// A.2.1 : moveMultipleToPalette INTER-PL -> refus total + rollback
// =========================================================================
$result = Colis::moveMultipleToPalette([$colisId], (int) $paletteB['palette_id']);
$after = currentPaletteOf($pdo, $colisId);
$errStr = !empty($result['errors']) ? implode(' | ', $result['errors']) : '';
$msgOk = stripos($errStr, 'inter-Packing-List') !== false;
if ($result['success'] === false && $after === $origColisPalette && $msgOk) {
    out('PASS', "A.2 moveMultipleToPalette inter-PL : refus + rollback (msg='" . trim($result['errors'][0]) . "')");
} else {
    out('FAIL', "A.2 INATTENDU inter-PL : result=" . json_encode($result) . " palette_apres={$after}");
    restoreColisPalette($pdo, $colisId, $origColisPalette);
}

// =========================================================================
// A.2.2 : moveMultipleToPalette INTRA-PL -> accepte
// =========================================================================
if ($paletteA2) {
    $result = Colis::moveMultipleToPalette([$colisId], (int) $paletteA2['palette_id']);
    $after = currentPaletteOf($pdo, $colisId);
    if ($result['success'] === true && $after === (int) $paletteA2['palette_id'] && $result['moved_count'] >= 1) {
        out('PASS', "A.2 moveMultipleToPalette intra-PL : moved={$result['moved_count']}, palette={$after}");
    } else {
        out('FAIL', "A.2 INATTENDU intra-PL : result=" . json_encode($result) . " palette_apres={$after}");
    }
    restoreColisPalette($pdo, $colisId, $origColisPalette);
}

// =========================================================================
// A.2.3 : LOT MIXTE (un colis sur PL A + un sur PL B, destination dans PL A)
//         -> refus total, AUCUN colis deplace
// =========================================================================
$stmt2 = $pdo->prepare("SELECT id, palette_id FROM colis WHERE palette_id = :pid AND parent_colis_id IS NULL LIMIT 1");
$stmt2->execute([':pid' => $paletteB['palette_id']]);
$colisOnBRow = $stmt2->fetch(PDO::FETCH_ASSOC);
if ($colisOnBRow) {
    $colisIdB = (int) $colisOnBRow['id'];
    $origColisBPalette = (int) $colisOnBRow['palette_id'];
    $target = $paletteA2 ? (int) $paletteA2['palette_id'] : (int) $paletteA['palette_id'];

    $result = Colis::moveMultipleToPalette([$colisId, $colisIdB], $target);
    $aAfter = currentPaletteOf($pdo, $colisId);
    $bAfter = currentPaletteOf($pdo, $colisIdB);

    if ($result['success'] === false
        && $aAfter === $origColisPalette
        && $bAfter === $origColisBPalette) {
        out('PASS', "A.2 lot mixte : refus total + rollback complet (A inchange={$aAfter}, B inchange={$bAfter})");
    } else {
        out('FAIL', "A.2 lot mixte INATTENDU : result=" . json_encode($result) . " a={$aAfter} b={$bAfter}");
        restoreColisPalette($pdo, $colisId, $origColisPalette);
        restoreColisPalette($pdo, $colisIdB, $origColisBPalette);
    }
} else {
    out('SKIP', "A.2 lot mixte : pas de colis disponible sur palette B");
}

// Verification finale : colis cible bien restaure
$final = currentPaletteOf($pdo, $colisId);
if ($final === $origColisPalette) {
    out('INFO', "Restauration finale OK (colis {$colisId} sur palette {$final})");
} else {
    out('WARN', "ATTENTION : colis {$colisId} non restaure (palette={$final}, origine={$origColisPalette})");
    restoreColisPalette($pdo, $colisId, $origColisPalette);
}

out('DONE', "Tests Phase A termines.");
