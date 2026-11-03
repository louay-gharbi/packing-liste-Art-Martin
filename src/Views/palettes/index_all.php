<?php
// src/Views/palettes/index_all.php
// Variables: $pageTitle, $palettesWithDetails, $baseUrl, $appName, $request
//            $availableSites, $currentFilterSiteId, $currentFilterStatus, $currentFilterPlReference
use App\Lib\Auth;
use App\Models\Site as SiteModel; // Pour type hinting dans le filtre

$currentUserForPaletteFilter = Auth::user(); // Pour déterminer si on affiche le filtre de site
?>

<div class="card mb-3">
    <div class="card-header">
        <i class="fas fa-filter"></i> Filtres
    </div>
    <div class="card-body">
        <form method="get" action="<?= htmlspecialchars($baseUrl) ?>/palettes" class="row gx-2 gy-2 align-items-end">
            <?php if ($currentUserForPaletteFilter && ($currentUserForPaletteFilter->site_id === null || $currentUserForPaletteFilter->hasRole('Administrateur')) && !empty($availableSites)): ?>
            <div class="col-md-3">
                <label for="filter_site_id_pal_all" class="form-label form-label-sm">Site :</label>
                <select name="filter_site_id" id="filter_site_id_pal_all" class="form-select form-select-sm">
                    <option value="">Tous les Sites</option>
                    <?php foreach ($availableSites as $site): ?>
                        <?php if (!$site instanceof SiteModel && is_object($site)) continue; // Vérif simple ?>
                        <option value="<?= $site->id ?>" <?= (isset($currentFilterSiteId) && $currentFilterSiteId == $site->id) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label for="filter_palette_status" class="form-label form-label-sm">Statut Palette :</label>
                <select name="filter_palette_status" id="filter_palette_status" class="form-select form-select-sm">
                    <option value="">Tous les Statuts</option>
                    <option value="generated" <?= (isset($currentFilterStatus) && $currentFilterStatus === 'generated') ? 'selected' : '' ?>>Générée</option>
                    <option value="open" <?= (isset($currentFilterStatus) && $currentFilterStatus === 'open') ? 'selected' : '' ?>>Ouverte</option>
                    <option value="closed" <?= (isset($currentFilterStatus) && $currentFilterStatus === 'closed') ? 'selected' : '' ?>>Fermée</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="filter_pl_reference" class="form-label form-label-sm">Réf. Packing List :</label>
                <input type="text" name="filter_pl_reference" id="filter_pl_reference" class="form-control form-control-sm" value="<?= htmlspecialchars($currentFilterPlReference ?? '') ?>" placeholder="Contient...">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="<?= htmlspecialchars($baseUrl) ?>/palettes" class="btn btn-sm btn-outline-secondary ms-1" title="Réinitialiser les filtres"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>


<?php if (isset($palettesWithDetails) && !empty($palettesWithDetails)): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover datatable" id="allPalettesTable" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:30px;">QR</th>
                    <th>Code Palette</th>
                    <th>Packing List</th>
                    <th>Site</th>
                    <th>Statut</th>
                    <th>Max Colis</th>
                    <th>Colis Scannés</th>
                    <th>Ouverte le</th>
                    <th>Fermée le</th>
                    <th>Créée le</th>
                    <th data-orderable="false" style="width: 50px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($palettesWithDetails as $p): // $p est un tableau associatif ?>
                    <tr>
                        <td class="text-center">
                            <a href="<?= htmlspecialchars($baseUrl) ?>/palettes/qr/<?= (int)$p['id'] ?>/print" data-bs-toggle="tooltip" data-bs-placement="right" title="Imprimer le QR Code <?= htmlspecialchars($p['code']) ?>" target="_blank">
                                <i class="fas fa-qrcode"></i>
                            </a>
                        </td>
                        <td>
                            <?php if (Auth::can('palette_read_details')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/palettes/show/<?= $p['id'] ?>" title="Détails <?= htmlspecialchars($p['code']) ?>">
                                    <?= htmlspecialchars($p['code']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($p['code']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['packing_list_reference']) && Auth::can('packinglist_read_one')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $p['packing_list_id'] ?>" title="Voir PL <?= htmlspecialchars($p['packing_list_reference']) ?>">
                                    <?= htmlspecialchars($p['packing_list_reference']) ?>
                                </a>
                            <?php elseif (!empty($p['packing_list_reference'])): ?>
                                <?= htmlspecialchars($p['packing_list_reference']) ?>
                            <?php else: echo '<span class="text-muted">N/A</span>'; endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($p['site_name'] ?? ($p['site_code'] ?? 'N/A')) ?>
                        </td>
                        <td>
                            <?php 
                                $status = $p['status'];
                                $sClass = 'light text-dark'; $sText = htmlspecialchars(ucfirst($status));
                                if ($status === 'generated') { $sClass = 'info text-dark'; $sText = 'Générée'; }
                                elseif ($status === 'open') { $sClass = 'success'; $sText = 'Ouverte'; }
                                elseif ($status === 'closed') { $sClass = 'secondary'; $sText = 'Fermée'; }
                            ?>
                            <span class="badge bg-<?= $sClass ?>"><?= $sText ?></span>
                        </td>
                        <td class="text-center"><?= htmlspecialchars($p['max_colis'] ?? 'N/D') ?></td>
                        <td class="text-center"><?= htmlspecialchars($p['colis_count']) ?></td>
                        <td data-order="<?= htmlspecialchars($p['open_date_time'] ?? '') ?>"><?= $p['open_date_time'] ? htmlspecialchars((new DateTime($p['open_date_time']))->format('d/m/y H:i')) : '-' ?></td>
                        <td data-order="<?= htmlspecialchars($p['close_date_time'] ?? '') ?>"><?= $p['close_date_time'] ? htmlspecialchars((new DateTime($p['close_date_time']))->format('d/m/y H:i')) : '-' ?></td>
                        <td data-order="<?= htmlspecialchars($p['palette_created_at']) ?>"><?= htmlspecialchars((new DateTime($p['palette_created_at']))->format('d/m/y H:i')) ?></td>
                        <td>
                            <?php if (Auth::can('palette_read_details')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/palettes/show/<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary py-0 px-1" title="Voir détails">
                                    <i class="fas fa-eye fa-xs"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">Aucune palette trouvée correspondant à vos critères de recherche.</div>
<?php endif; ?>

