<?php
// src/Views/client_palettes/index.php
// Variables attendues: $client (destination "client — code postal"), $clientName, $postalCode, $year, $week, $weekParam,
//                      $palettes (ClientPalette[]), $progress, $baseUrl
use App\Lib\Auth;

$pct = $progress['total'] > 0 ? round($progress['placed'] * 100 / $progress['total']) : 0;
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h5 class="mb-0"><i class="fas fa-pallet me-2"></i>Palettes client — <?= htmlspecialchars($clientName) ?>
            <span class="badge bg-light text-dark border align-middle">CP <?= htmlspecialchars($postalCode !== '' ? $postalCode : '—') ?></span></h5>
        <div class="text-muted small">Semaine d'expédition Tunisie S<?= sprintf('%02d', $week) ?> <?= $year ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/client-packing?week=<?= urlencode($weekParam) ?>&client=<?= urlencode($client) ?>#detail" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Colisage client
        </a>
        <?php if (Auth::can('reception_scan') && $progress['placed'] < $progress['total']): ?>
            <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/client-palettes/create" class="d-inline">
                <?= \App\Lib\Csrf::field() ?>
                <input type="hidden" name="week" value="<?= htmlspecialchars($weekParam) ?>">
                <input type="hidden" name="client" value="<?= htmlspecialchars($client) ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>Nouvelle palette client</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex justify-content-between small mb-1">
            <span>Colis placés sur une palette client</span>
            <strong><?= $progress['placed'] ?> / <?= $progress['total'] ?></strong>
        </div>
        <div class="progress" style="height: 8px;">
            <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : '' ?>" style="width: <?= $pct ?>%"></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><strong>Palettes (<?= count($palettes) ?>)</strong></div>
    <?php if (!$palettes): ?>
        <div class="card-body text-muted small">Aucune palette client pour l'instant : créez-en une puis scannez ses colis.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Palette client</th><th>Statut</th><th>Colis</th><th>Créée le</th><th style="width: 200px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($palettes as $p): ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $p->id ?>"><strong><?= htmlspecialchars($p->code) ?></strong></a></td>
                        <td>
                            <?php if ($p->isClosed()): ?>
                                <span class="badge bg-success">Clôturée</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Ouverte</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $p->nbColis ?></td>
                        <td class="small"><?= (new DateTime($p->created_at))->format('d/m/Y H:i') ?> <?= $p->createdByName ? '· ' . htmlspecialchars($p->createdByName) : '' ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $p->id ?>" class="btn btn-sm btn-outline-primary py-0" title="<?= $p->isClosed() ? 'Voir' : 'Scanner' ?>">
                                <i class="fas <?= $p->isClosed() ? 'fa-eye' : 'fa-barcode' ?>"></i>
                            </a>
                            <?php if ($p->isClosed()): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $p->id ?>/label" target="_blank" class="btn btn-sm btn-outline-dark py-0" title="Étiquette QR"><i class="fas fa-qrcode"></i></a>
                            <?php endif; ?>
                            <?php if ($p->nbColis > 0): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $p->id ?>/export/xlsx" class="btn btn-sm btn-outline-success py-0" title="Export XLSX"><i class="fas fa-file-excel"></i></a>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $p->id ?>/export/pdf" class="btn btn-sm btn-outline-danger py-0" title="Export PDF"><i class="fas fa-file-pdf"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_remaining.php'; ?>
