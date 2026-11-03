<?php
// src/Views/receptions/client_packing.php
// Variables attendues: $year, $week, $weekParam, $weeks, $data (cf. Reception::getClientPackingForWeek), $selectedClient, $baseUrl,
//                      $placed (cf. ClientPalette::getPlacedMap), $clientPalettes (ClientPalette[] de la semaine)

$clients = $data['clients'];
$pls = $data['packing_lists'];
$nbClosed = count(array_filter($pls, fn($pl) => $pl['reception_status'] === 'closed'));
$exportUrl = fn(string $format, ?string $client = null) => htmlspecialchars($baseUrl . '/receptions/client-packing/export/' . $format
    . '?week=' . urlencode($weekParam) . ($client !== null ? '&client=' . urlencode($client) : ''));
$pageUrl = fn(?string $client = null) => htmlspecialchars($baseUrl . '/receptions/client-packing?week=' . urlencode($weekParam)
    . ($client !== null ? '&client=' . urlencode($client) : ''));
$palettesUrl = fn(string $client) => htmlspecialchars($baseUrl . '/client-palettes?week=' . urlencode($weekParam) . '&client=' . urlencode($client));
$nbPalettesByClient = [];
foreach ($clientPalettes as $cp) { $nbPalettesByClient[$cp->destinationKey()] = ($nbPalettesByClient[$cp->destinationKey()] ?? 0) + 1; }
$weekInList = false;
foreach ($weeks as $w) { if ($w['year'] === $year && $w['week'] === $week) $weekInList = true; }
?>

<div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-2">
    <form method="get" action="<?= htmlspecialchars($baseUrl) ?>/receptions/client-packing" class="d-flex align-items-end gap-2">
        <div>
            <label for="week" class="form-label form-label-sm mb-0 small">Semaine d'expédition</label>
            <select name="week" id="week" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php if (!$weekInList): ?>
                    <option value="<?= htmlspecialchars($weekParam) ?>" selected>S<?= sprintf('%02d', $week) ?> <?= $year ?></option>
                <?php endif; ?>
                <?php foreach ($weeks as $w): ?>
                    <?php $val = sprintf('%d-W%02d', $w['year'], $w['week']); ?>
                    <option value="<?= $val ?>" <?= $val === $weekParam ? 'selected' : '' ?>>
                        S<?= sprintf('%02d', $w['week']) ?> <?= $w['year'] ?> — <?= $w['nb_closed'] ?> réception(s) clôturée(s)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div>
        <a href="<?= htmlspecialchars($baseUrl) ?>/receptions" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Réceptions</a>
        <?php if ($clients): ?>
            <a href="<?= $exportUrl('xlsx') ?>" class="btn btn-sm btn-success"><i class="fas fa-file-excel me-1"></i>XLSX tous clients</a>
            <a href="<?= $exportUrl('pdf') ?>" class="btn btn-sm btn-danger"><i class="fas fa-file-pdf me-1"></i>PDF tous clients</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <strong>Packing Lists expédiées du <?= $data['monday']->format('d/m/Y') ?> au <?= $data['sunday']->format('d/m/Y') ?></strong>
        <small class="text-muted">— seules les réceptions clôturées alimentent le colisage (<?= $nbClosed ?> / <?= count($pls) ?>)</small>
    </div>
    <?php if ($pls): ?>
        <div class="card-body py-2 d-flex flex-wrap gap-2">
            <?php foreach ($pls as $pl): ?>
                <?php
                    if ($pl['reception_id'] === null)                { $b = ['secondary', 'À réceptionner']; }
                    elseif ($pl['reception_status'] === 'in_progress') { $b = ['warning text-dark', 'En cours']; }
                    else                                               { $b = ['success', 'Clôturée']; }
                ?>
                <span class="badge bg-<?= $b[0] ?> p-2">
                    <?php if ($pl['reception_id'] !== null): ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/report/<?= (int)$pl['reception_id'] ?>" class="text-reset text-decoration-none">
                    <?php endif; ?>
                    <?= htmlspecialchars($pl['reference']) ?> : <?= $b[1] ?>
                    <?php if ($pl['reception_id'] !== null): ?></a><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php if ($nbClosed < count($pls)): ?>
            <div class="card-footer small text-warning-emphasis bg-warning-subtle">
                <i class="fas fa-exclamation-triangle me-1"></i>Toutes les réceptions de la semaine ne sont pas clôturées : le colisage est partiel.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card-body text-muted small">Aucune Packing List expédiée cette semaine.</div>
    <?php endif; ?>
</div>

<?php if (!$clients): ?>
    <div class="alert alert-info">Aucun colis reçu dans une réception clôturée pour cette semaine.</div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-header"><strong>Clients (<?= count($clients) ?>)</strong></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Client</th><th>Code postal</th><th>Commandes</th><th>Colis</th><th>Quantité</th><th>Palettes client</th><th style="width: 260px;">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($clients as $client => $lines): ?>
                    <?php
                        $nbOrders = count(array_unique(array_map(fn($l) => (string)$l['order_number'], $lines)));
                        $qty = array_sum(array_map(fn($l) => $l['quantity'] ?? 0, $lines));
                        $nbPlaced = count(array_filter($lines, fn($l) => isset($placed[$l['colis_id']])));
                    ?>
                    <tr class="<?= $client === $selectedClient ? 'table-primary' : '' ?>">
                        <td><strong><?= htmlspecialchars($lines[0]['client_name']) ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($lines[0]['postal_code'] !== '' ? $lines[0]['postal_code'] : 'sans CP') ?></span></td>
                        <td><?= $nbOrders ?></td>
                        <td><?= count($lines) ?></td>
                        <td><?= htmlspecialchars((string)$qty) ?></td>
                        <td>
                            <span class="badge bg-<?= $nbPlaced === count($lines) ? 'success' : ($nbPlaced > 0 ? 'warning text-dark' : 'secondary') ?>"><?= $nbPlaced ?> / <?= count($lines) ?> placés</span>
                            <small class="text-muted"><?= $nbPalettesByClient[$client] ?? 0 ?> palette(s)</small>
                        </td>
                        <td>
                            <a href="<?= $palettesUrl($client) ?>" class="btn btn-sm btn-primary py-0" title="Palettes client"><i class="fas fa-pallet me-1"></i>Palettes</a>
                            <a href="<?= $pageUrl($client) ?>#detail" class="btn btn-sm btn-outline-primary py-0" title="Voir le détail"><i class="fas fa-eye"></i></a>
                            <a href="<?= $exportUrl('xlsx', $client) ?>" class="btn btn-sm btn-outline-success py-0" title="Export XLSX"><i class="fas fa-file-excel"></i></a>
                            <a href="<?= $exportUrl('pdf', $client) ?>" class="btn btn-sm btn-outline-danger py-0" title="Export PDF"><i class="fas fa-file-pdf"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($selectedClient !== null): ?>
        <div class="card mb-3" id="detail">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Colisage <?= htmlspecialchars($selectedClient) ?> — S<?= sprintf('%02d', $week) ?> <?= $year ?></strong>
                <a href="<?= $pageUrl() ?>" class="btn btn-sm btn-outline-secondary py-0" title="Fermer"><i class="fas fa-times"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 small">
                    <thead>
                        <tr><th>N° commande</th><th>Ligne</th><th>Colis</th><th>Groupage</th><th>OF</th><th>Code article</th><th>Libellé</th>
                            <th>Réf. client</th><th>Qté</th><th>Code postal</th><th>Palette Tunisie</th><th>Packing List</th><th>Palette client</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients[$selectedClient] as $l): ?>
                        <tr>
                            <td><?= htmlspecialchars($l['order_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['line_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['package_number']) ?></td>
                            <td><?= htmlspecialchars($l['groupage_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['of_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['article_reference'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['sku'] ?? '') ?></td>
                            <td><?= $l['quantity'] !== null ? htmlspecialchars((string)$l['quantity']) : '' ?></td>
                            <td><?= htmlspecialchars($l['postal_code'] ?? '') ?></td>
                            <td><?= htmlspecialchars($l['palette_code']) ?></td>
                            <td><?= htmlspecialchars($l['pl_reference']) ?></td>
                            <td>
                                <?php if (isset($placed[$l['colis_id']])): ?>
                                    <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $placed[$l['colis_id']]['id'] ?>"><?= htmlspecialchars($placed[$l['colis_id']]['code']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
