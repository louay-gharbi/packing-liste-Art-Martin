<?php
// src/Views/client_palettes/_remaining.php — colis du client pas encore sur une palette client, groupés par palette Tunisie
// Variables attendues: $progress (cf. ClientPaletteController::clientProgress)

$byTunisiaPalette = [];
foreach ($progress['remaining'] as $l) {
    $byTunisiaPalette[$l['palette_code'] . ' · ' . $l['pl_reference']][] = $l;
}
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-search-location me-2"></i>Reste à placer</h6>
        <span class="badge bg-<?= $progress['remaining'] ? 'secondary' : 'success' ?>"><?= count($progress['remaining']) ?> colis</span>
    </div>
    <?php if (!$progress['remaining']): ?>
        <div class="card-body text-success small"><i class="fas fa-check-circle me-1"></i>Tous les colis reçus du client sont sur une palette client.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
            <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th>Colis</th><th>Commande / ligne</th><th>Libellé</th><th>Qté</th></tr></thead>
                <tbody>
                <?php foreach ($byTunisiaPalette as $where => $lines): ?>
                    <tr class="table-secondary">
                        <td colspan="4"><i class="fas fa-pallet me-1"></i><strong><?= htmlspecialchars($where) ?></strong> — <?= count($lines) ?> colis</td>
                    </tr>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td><?= htmlspecialchars($l['package_number']) ?><?= $l['groupage_number'] ? '<br><span class="text-muted">groupage ' . htmlspecialchars($l['groupage_number']) . '</span>' : '' ?></td>
                            <td><?= htmlspecialchars((string)($l['order_number'] ?? '')) ?> / <?= htmlspecialchars((string)($l['line_number'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
                            <td><?= $l['quantity'] !== null ? htmlspecialchars((string)$l['quantity']) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
