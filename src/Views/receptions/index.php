<?php
// src/Views/receptions/index.php
// Variables attendues: $rows (PL fermées + état de réception), $filters, $productionSites, $baseUrl
use App\Lib\Auth;

$canScan = Auth::can('reception_scan');
?>

<form method="get" action="<?= htmlspecialchars($baseUrl) ?>/receptions" class="row gx-2 gy-2 align-items-end mb-3">
    <div class="col-md-3">
        <label for="reception_status" class="form-label form-label-sm mb-0 small">Statut de réception</label>
        <select name="reception_status" id="reception_status" class="form-select form-select-sm">
            <option value="">Tous</option>
            <option value="todo" <?= $filters['reception_status'] === 'todo' ? 'selected' : '' ?>>À réceptionner</option>
            <option value="in_progress" <?= $filters['reception_status'] === 'in_progress' ? 'selected' : '' ?>>En cours</option>
            <option value="closed" <?= $filters['reception_status'] === 'closed' ? 'selected' : '' ?>>Réceptionnée</option>
        </select>
    </div>
    <div class="col-md-3">
        <label for="site_id" class="form-label form-label-sm mb-0 small">Site d'origine</label>
        <select name="site_id" id="site_id" class="form-select form-select-sm">
            <option value="">Tous les sites</option>
            <?php foreach ($productionSites as $site): ?>
                <option value="<?= $site->id ?>" <?= (int)$filters['site_id'] === $site->id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label for="week" class="form-label form-label-sm mb-0 small">Semaine d'expédition</label>
        <select name="week" id="week" class="form-select form-select-sm">
            <option value="">90 derniers jours</option>
            <?php foreach ($shipWeeks as $w): ?>
                <?php $val = sprintf('%d-W%02d', $w['year'], $w['week']); ?>
                <option value="<?= $val ?>" <?= $filters['week'] === $val ? 'selected' : '' ?>>
                    S<?= sprintf('%02d', $w['week']) ?> <?= $w['year'] ?> — <?= $w['nb_pl'] ?> PL
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i> Filtrer</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/receptions" class="btn btn-sm btn-outline-secondary" title="Réinitialiser"><i class="fas fa-times"></i></a>
    </div>
</form>
<?php if ($filters['week'] === ''): ?>
    <p class="text-muted small mb-2"><i class="fas fa-info-circle me-1"></i>Par défaut : PL expédiées depuis 90 jours et réceptions en cours. Choisissez une semaine pour remonter plus loin.</p>
<?php endif; ?>

<?php if (!empty($rows)): ?>
    <div class="table-responsive">
        <table id="receptionsTable" class="table table-striped table-hover datatable" style="width:100%">
            <thead>
                <tr>
                    <th>Packing List</th>
                    <th>Site d'origine</th>
                    <th>Date d'expédition</th>
                    <th>Palettes</th>
                    <th>Colis</th>
                    <th>Réception</th>
                    <th data-orderable="false" style="min-width: 130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $nbPalettes = (int)$row['nb_palettes'];
                        $nbRecues = (int)$row['nb_palettes_recues'];
                        if ($row['reception_id'] === null) {
                            $badge = ['secondary', 'À réceptionner'];
                        } elseif ($row['reception_status'] === 'in_progress') {
                            $badge = ['warning text-dark', 'En cours'];
                        } elseif ((int)$row['discrepancy_count'] > 0) {
                            $badge = ['danger', 'Réceptionnée avec écarts (' . (int)$row['discrepancy_count'] . ')'];
                        } else {
                            $badge = ['success', 'Réceptionnée'];
                        }
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['reference']) ?></strong></td>
                        <td><?= htmlspecialchars($row['site_name'] ?? 'N/D') ?></td>
                        <td data-order="<?= htmlspecialchars($row['ship_date']) ?>"><?= (new DateTime($row['ship_date']))->format('d/m/Y') ?></td>
                        <td><?= $nbPalettes ?></td>
                        <td><?= (int)$row['nb_colis'] ?></td>
                        <td>
                            <span class="badge bg-<?= $badge[0] ?>"><?= htmlspecialchars($badge[1]) ?></span>
                            <?php if ($row['reception_status'] === 'in_progress' && $nbPalettes > 0): ?>
                                <?php $pct = min(100, round($nbRecues / $nbPalettes * 100)); ?>
                                <div class="progress mt-1" style="height: 6px;" title="<?= $nbRecues ?> / <?= $nbPalettes ?> palettes reçues">
                                    <div class="progress-bar bg-warning" style="width: <?= $pct ?>%;"></div>
                                </div>
                                <small class="text-muted"><?= $nbRecues ?> / <?= $nbPalettes ?> palettes</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['reception_id'] === null): ?>
                                <?php if ($canScan): ?>
                                    <form action="<?= htmlspecialchars($baseUrl) ?>/receptions/start/<?= (int)$row['id'] ?>" method="post" style="display:inline;">
                                        <?= \App\Lib\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-play me-1"></i>Démarrer</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($row['reception_status'] === 'in_progress'): ?>
                                <?php if ($canScan): ?>
                                    <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/scan/<?= (int)$row['reception_id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-qrcode me-1"></i>Continuer</a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/report/<?= (int)$row['reception_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Écarts en cours"><i class="fas fa-file-alt"></i></a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/report/<?= (int)$row['reception_id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-file-alt me-1"></i>Rapport</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">Aucune Packing List expédiée ne correspond aux filtres.</div>
<?php endif; ?>
