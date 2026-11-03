<?php
// src/Views/receptions/report.php
// Variables attendues: $reception, $packingList, $state, $baseUrl
use App\Lib\Auth;

$counts = $state['counts'];
$nbMissingColis = array_sum(array_map('count', $state['missing_colis']));
$nbMissingColisOfMissingPalettes = array_sum(array_map(fn($mp) => $mp['nb_colis'], $state['missing_palettes']));
?>
<style>
    .report-kpi { text-align: center; }
    .report-kpi .value { font-size: 1.6rem; font-weight: 700; }
    .report-kpi .label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; }
    @media print {
        .admin-sidebar, .admin-main-header, .admin-main-footer, .no-print, .alert-dismissible { display: none !important; }
        .admin-main-content { margin: 0 !important; padding: 0 !important; }
        .card { break-inside: avoid; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 no-print">
    <a href="<?= htmlspecialchars($baseUrl) ?>/receptions" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Réceptions</a>
    <div>
        <?php if (!$reception->isClosed() && Auth::can('reception_scan')): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/scan/<?= $reception->id ?>" class="btn btn-sm btn-warning"><i class="fas fa-qrcode me-1"></i>Reprendre le scan</a>
        <?php endif; ?>
        <?php if ($reception->isClosed() && $packingList): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/client-packing?week=<?= (new DateTime($packingList->ship_date))->format('o-\WW') ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-boxes me-1"></i>Colisage client S<?= (new DateTime($packingList->ship_date))->format('W') ?>
            </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/<?= $reception->id ?>/export/xlsx" class="btn btn-sm btn-success"><i class="fas fa-file-excel me-1"></i>Exporter XLSX</a>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Imprimer</button>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h5 class="mb-2">Packing List <?= htmlspecialchars($packingList->reference ?? '') ?></h5>
                <dl class="row mb-0 small">
                    <dt class="col-5">Site d'origine</dt><dd class="col-7"><?= htmlspecialchars($packingList->site->name ?? 'N/D') ?></dd>
                    <dt class="col-5">Expédiée le</dt><dd class="col-7"><?= $packingList ? (new DateTime($packingList->ship_date))->format('d/m/Y') : '' ?></dd>
                    <dt class="col-5">Réception démarrée</dt>
                    <dd class="col-7"><?= (new DateTime($reception->started_at))->format('d/m/Y H:i') ?> <?= $reception->startedByName ? '— ' . htmlspecialchars($reception->startedByName) : '' ?></dd>
                    <dt class="col-5">Statut</dt>
                    <dd class="col-7">
                        <?php if ($reception->isClosed()): ?>
                            <span class="badge bg-dark">Clôturée</span>
                            le <?= (new DateTime($reception->closed_at))->format('d/m/Y H:i') ?> <?= $reception->closedByName ? '— ' . htmlspecialchars($reception->closedByName) : '' ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">En cours</span> <small class="text-muted">(écarts provisoires)</small>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
            <div class="col-md-6 mt-3 mt-md-0">
                <?php if ($reception->comment): ?>
                    <div class="border rounded p-2 small bg-light"><strong>Commentaire :</strong><br><?= nl2br(htmlspecialchars($reception->comment)) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-around gap-3">
        <div class="report-kpi"><div class="value"><?= $counts['palettes_received'] ?> / <?= $counts['palettes_expected'] ?></div><div class="label">Palettes reçues</div></div>
        <div class="report-kpi"><div class="value"><?= $counts['colis_received'] ?> / <?= $counts['colis_expected'] ?></div><div class="label">Colis reçus</div></div>
        <div class="report-kpi"><div class="value text-secondary"><?= count($state['missing_palettes']) ?></div><div class="label">Palettes manquantes</div></div>
        <div class="report-kpi"><div class="value text-secondary"><?= $nbMissingColis + $nbMissingColisOfMissingPalettes ?></div><div class="label">Colis manquants</div></div>
        <div class="report-kpi"><div class="value text-warning"><?= $counts['unexpected'] ?></div><div class="label">Inattendus</div></div>    </div>
</div>

<?php if ($state['discrepancy_count'] === 0): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Réception conforme : aucun écart.</div>
<?php endif; ?>

<?php if (!empty($state['missing_palettes'])): ?>
<div class="card mb-3 border-secondary">
    <div class="card-header"><strong>Palettes manquantes (<?= count($state['missing_palettes']) ?>)</strong>
        <small class="text-muted">— <?= $nbMissingColisOfMissingPalettes ?> colis concernés</small></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 small">
            <thead><tr><th>Palette</th><th>Colis</th><th>OF</th><th>Article</th><th>Libellé</th><th>Qté</th></tr></thead>
            <tbody>
            <?php foreach ($state['missing_palettes'] as $mp): ?>
                <?php if (empty($mp['colis'])): ?>
                    <tr><td><strong><?= htmlspecialchars($mp['code']) ?></strong></td><td colspan="5" class="text-muted">aucun colis</td></tr>
                <?php endif; ?>
                <?php foreach ($mp['colis'] as $i => $c): ?>
                    <tr>
                        <td><?= $i === 0 ? '<strong>' . htmlspecialchars($mp['code']) . '</strong>' : '' ?></td>
                        <td><?= htmlspecialchars($c['package_number']) ?></td>
                        <td><?= htmlspecialchars($c['of_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['article_reference'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['label'] ?? '') ?></td>
                        <td><?= $c['quantity'] !== null ? htmlspecialchars((string)(float)$c['quantity']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($state['missing_colis'])): ?>
<div class="card mb-3 border-secondary">
    <div class="card-header"><strong>Colis manquants (<?= $nbMissingColis ?>)</strong>
        <small class="text-muted">— sur des palettes reçues et contrôlées en détail</small></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 small">
            <thead><tr><th>Palette</th><th>Colis</th><th>OF</th><th>Article</th><th>Libellé</th><th>Qté</th></tr></thead>
            <tbody>
            <?php foreach ($state['missing_colis'] as $paletteCode => $list): ?>
                <?php foreach ($list as $i => $c): ?>
                    <tr>
                        <td><?= $i === 0 ? '<strong>' . htmlspecialchars($paletteCode) . '</strong>' : '' ?></td>
                        <td><?= htmlspecialchars($c['package_number']) ?></td>
                        <td><?= htmlspecialchars($c['of_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['article_reference'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['label'] ?? '') ?></td>
                        <td><?= $c['quantity'] !== null ? htmlspecialchars((string)(float)$c['quantity']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($state['unexpected_palettes']) || !empty($state['unexpected_colis'])): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning text-dark"><strong>Inattendus (<?= $counts['unexpected'] ?>)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 small">
            <thead><tr><th>Type</th><th>Code / N° colis</th><th>Origine</th><th>Scanné le</th></tr></thead>
            <tbody>
            <?php foreach ($state['unexpected_palettes'] as $up): ?>
                <tr><td>Palette</td><td><?= htmlspecialchars($up['code']) ?></td>
                    <td>PL <?= htmlspecialchars($up['origin_pl_reference']) ?></td>
                    <td><?= (new DateTime($up['scanned_at']))->format('d/m/Y H:i') ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($state['unexpected_colis'] as $uc): ?>
                <tr><td>Colis</td><td><?= htmlspecialchars($uc['package_number']) ?></td>
                    <td><?= $uc['origin_pl_reference'] ? 'PL ' . htmlspecialchars($uc['origin_pl_reference']) : '<em>Inconnu en base</em>' ?></td>
                    <td><?= (new DateTime($uc['scanned_at']))->format('d/m/Y H:i') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
