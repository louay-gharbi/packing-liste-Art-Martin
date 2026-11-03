<?php
use App\Lib\Auth;
use App\Models\PackingList as PLModel;
use App\Models\Palette as PaletteModel;

if (!isset($packingList) || !$packingList instanceof PLModel) {
    echo "<div class='alert alert-danger'>Erreur: Les données de la Packing List n'ont pas pu être chargées.</div>";
    if (isset($baseUrl)) {
        echo "<a href='" . htmlspecialchars($baseUrl) . "/packing-lists' class='btn btn-secondary mt-2'>Retour à la liste des Packing Lists</a>";
    }
    return; 
}

// --- LOGIQUE DE CALCUL DES FUSIONS (ROWSPAN) POUR L'AFFICHAGE ---
$synthesisData = [];
$grandTotal = null;
if (!empty($aggregationSummary)) {
    foreach ($aggregationSummary as $row) {
        if ($row['is_total']) {
            $grandTotal = $row;
        } else {
            $synthesisData[] = $row;
        }
    }
}

$rowspans = ['client' => [], 'cp' => [], 'order' => [], 'line' => []];
if (!empty($synthesisData)) {
    $last = ['client' => null, 'cp' => null, 'order' => null, 'line' => null];
    $idx = ['client' => 0, 'cp' => 0, 'order' => 0, 'line' => 0];

    foreach ($synthesisData as $i => $row) {
        if ($row['client'] !== $last['client']) {
            $last['client'] = $row['client']; $idx['client'] = $i; $rowspans['client'][$i] = 1;
            $last['cp'] = $last['order'] = $last['line'] = null;
        } else { $rowspans['client'][$idx['client']]++; }

        if ($row['cp_livraison'] !== $last['cp']) {
            $last['cp'] = $row['cp_livraison']; $idx['cp'] = $i; $rowspans['cp'][$i] = 1;
            $last['order'] = $last['line'] = null;
        } else { $rowspans['cp'][$idx['cp']]++; }

        if ($row['num_commande'] !== $last['order']) {
            $last['order'] = $row['num_commande']; $idx['order'] = $i; $rowspans['order'][$i] = 1;
            $last['line'] = null;
        } else { $rowspans['order'][$idx['order']]++; }

        if ($row['ligne'] !== $last['line']) {
            $last['line'] = $row['ligne']; $idx['line'] = $i; $rowspans['line'][$i] = 1;
        } else { $rowspans['line'][$idx['line']]++; }
    }
}
?>

<!-- Fil d'Ariane -->
<div class="row mb-3">
    <div class="col-md-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php if (Auth::can('packinglist_read_all')): ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists">Packing Lists</a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active" aria-current="page">Détail Packing List: <?= htmlspecialchars($packingList->reference) ?></li>
            </ol>
        </nav>
    </div>
    <div class="col-md-4 text-end">
        <?php if (Auth::can('packinglist_read_all')): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-sm btn-outline-secondary" title="Retour à la liste des Packing Lists">
                <i class="fas fa-list"></i> Liste PL
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <?php if ($packingList->status === 'open' && Auth::can('packinglist_update')): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/edit/<?= $packingList->id ?>" class="btn btn-sm btn-primary" title="Modifier la date d'expédition">
                        <i class="fas fa-edit"></i> Modifier Date
                    </a>
                <?php endif; ?>

                <?php if ($packingList->status === 'open' && Auth::can('packinglist_close')): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/close/<?= $packingList->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir fermer cette Packing List ? Les palettes ouvertes et non vides seront fermées, et les palettes générées et vides seront supprimées.');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-warning" title="Fermer la Packing List">
                            <i class="fas fa-lock"></i> Fermer PL
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($packingList->status === 'closed' && Auth::can('packinglist_reopen')): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/reopen/<?= $packingList->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir réouvrir cette Packing List ?');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-success" title="Réouvrir la Packing List">
                            <i class="fas fa-undo"></i> Réouvrir PL
                        </button>
                    </form>
                <?php endif; ?>
                
                <?php if ($packingList->status === 'open' && Auth::can('packinglist_delete')): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/delete/<?= $packingList->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette Packing List ? Cette action est irréversible.');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-danger" title="Supprimer la Packing List">
                            <i class="fas fa-trash-alt"></i> Supprimer PL
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (Auth::can('packinglist_export_colis_xlsx') || Auth::can('packinglist_export_colis_pdf')): ?>
                    <div class="btn-group ms-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Exporter la liste des colis">
                            <i class="fas fa-file-export"></i> Exporter Colis
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <?php if (Auth::can('packinglist_export_colis_xlsx')): ?>
                                <li><a class="dropdown-item" href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/<?= $packingList->id ?>/export-colis/xlsx" target="_blank"><i class="fas fa-file-excel fa-fw me-2 text-success"></i>Format XLSX</a></li>
                            <?php endif; ?>
                            <?php if (Auth::can('packinglist_export_colis_pdf')): ?>
                                <li><a class="dropdown-item" href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/<?= $packingList->id ?>/export-colis/pdf" target="_blank"><i class="fas fa-file-pdf fa-fw me-2 text-danger"></i>Format PDF</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (Auth::can('theoretical_view_deviations')): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/theoretical-exports/deviations?packing_list_id=<?= $packingList->id ?>" class="btn btn-sm btn-outline-info ms-1" title="Voir les écarts pour cette Packing List">
                        <i class="fas fa-balance-scale"></i> Voir Écarts
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 border-end">
                <p class="mb-2"><strong>Référence :</strong> <?= htmlspecialchars($packingList->reference) ?></p>
                <p class="mb-2"><strong>Date d'expédition :</strong> <?= htmlspecialchars((new DateTime($packingList->ship_date))->format('d/m/Y')) ?></p>
                <p class="fw-bold fs-5 text-primary mb-0">Total Pièces Scannées : <?= htmlspecialchars($totalScannedQuantityPL ?? 0) ?></p>
            </div>
            <div class="col-md-6">
                <p class="mb-2"><strong>Statut :</strong>
                    <?php 
                        $statusClass = $packingList->status === 'open' ? 'success' : 'secondary';
                        $statusText = $packingList->status === 'open' ? 'Ouverte' : 'Fermée';
                    ?>
                    <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                </p>
                <p class="mb-0"><strong>Créée le :</strong> <?= htmlspecialchars((new DateTime($packingList->created_at))->format('d/m/Y H:i')) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Onglets -->
<ul class="nav nav-tabs mb-3" id="plDetailTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="palettes-tab" data-bs-toggle="tab" data-bs-target="#palettes-pane" type="button" role="tab">
            <i class="fas fa-pallet me-2"></i>Palettes Associées
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary-pane" type="button" role="tab">
            <i class="fas fa-table me-2"></i>Synthèse d'expédition
        </button>
    </li>
</ul>

<div class="tab-content" id="plDetailTabsContent">
    
    <!-- ONGLET 1 : PALETTES -->
    <div class="tab-pane fade show active" id="palettes-pane" role="tabpanel">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Palettes de la PL</h5>
                    <div>
                        <?php if ($packingList->status === 'open' && Auth::can('palette_create')): ?>
                            <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/<?= $packingList->id ?>/palettes/store" method="post" style="display:inline-block;" class="me-1">
            <?= \App\Lib\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-success" title="Nouvelle palette unique"><i class="fas fa-plus"></i> Palette Unique</button>
                            </form>
                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#generateMultiplePalettesModal" title="Générer plusieurs palettes"><i class="fas fa-cubes"></i> Multi-Palettes</button>
                        <?php endif; ?>
                        <?php if (isset($palettes) && !empty($palettes) && Auth::can('palette_read_details')): ?>
                            <button type="button" class="btn btn-sm btn-secondary ms-1" data-bs-toggle="modal" data-bs-target="#printQrCodesModal" title="Imprimer QR codes sélectionnés"><i class="fas fa-print"></i> Imprimer QR</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($palettes)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle datatable" id="palettesTableOnPLShowPage">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="selectAllPalettesForPrint"></th>
                                    <th>Code Palette</th>
                                    <th>Statut</th>
                                    <th class="text-center">Max Colis</th>
                                    <th class="text-center">Colis Scannés</th>
                                    <th class="text-center">Total Pièces</th>
                                    <th>Créée le</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($palettes as $palette): ?>
                                    <tr>
                                        <td><input type="checkbox" class="palette-print-checkbox" value="<?= $palette->id ?>"></td>
                                        <td><a href="<?= htmlspecialchars($baseUrl) ?>/palettes/show/<?= $palette->id ?>" class="fw-bold"><?= htmlspecialchars($palette->code) ?></a></td>
                                        <td>
                                            <?php 
                                                $pStatClass = $palette->status === 'open' ? 'success' : ($palette->status === 'closed' ? 'secondary' : 'info text-dark');
                                            ?>
                                            <span class="badge bg-<?= $pStatClass ?>"><?= ucfirst($palette->status) ?></span>
                                        </td>
                                        <td class="text-center"><?= htmlspecialchars($palette->max_colis ?? 'N/D') ?></td>
                                        <td class="text-center"><?= $palette->getColisCount() ?></td>
                                        <td class="text-center fw-bold"><?= $quantitiesByPalette[$palette->id] ?? 0 ?></td>
                                        <?php $paletteCreatedAt = new DateTime($palette->created_at); ?>
                                        <td data-order="<?= $paletteCreatedAt->format('Y-m-d H:i:s') ?>"><?= htmlspecialchars($paletteCreatedAt->format('d/m/Y H:i')) ?></td>
                                        <td class="text-end">
                                            <a href="<?= htmlspecialchars($baseUrl) ?>/palettes/show/<?= $palette->id ?>" class="btn btn-xs btn-outline-primary py-0 px-1" title="Voir détail"><i class="fas fa-eye fa-xs"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-3">Aucune palette associée.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ONGLET 2 : SYNTHÈSE (SANS DATATABLE POUR ÉVITER LES WARNINGS) -->
    <div class="tab-pane fade" id="summary-pane" role="tabpanel">
        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Synthèse agrégée par Commande</h5>
                <div class="btn-group">
                    <?php if (Auth::can('packinglist_export_colis_xlsx')): ?>
                        <a href="<?= $baseUrl ?>/packing-lists/<?= $packingList->id ?>/export-synthesis/xlsx" class="btn btn-sm btn-light shadow-sm">
                            <i class="fas fa-file-excel text-success me-1"></i> Export Excel Fusionné
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($synthesisData)): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0" id="aggregationSummaryTableStatic">
                            <thead class="table-dark text-center">
                                <tr>
                                    <th>Client</th>
                                    <th>CP Livraison</th>
                                    <th>N° Commande</th>
                                    <th>N° Ligne</th>
                                    <th>Code Article</th>
                                    <th style="width: 150px;">Quantité Totale</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($synthesisData as $i => $row): ?>
                                    <tr>
                                        <?php if (isset($rowspans['client'][$i])): ?>
                                            <td rowspan="<?= $rowspans['client'][$i] ?>" class="fw-bold bg-light border-end-2"><?= htmlspecialchars($row['client']) ?></td>
                                        <?php endif; ?>

                                        <?php if (isset($rowspans['cp'][$i])): ?>
                                            <td rowspan="<?= $rowspans['cp'][$i] ?>" class="text-center"><?= htmlspecialchars($row['cp_livraison']) ?></td>
                                        <?php endif; ?>

                                        <?php if (isset($rowspans['order'][$i])): ?>
                                            <td rowspan="<?= $rowspans['order'][$i] ?>" class="text-center fw-bold"><?= htmlspecialchars($row['num_commande']) ?></td>
                                        <?php endif; ?>

                                        <?php if (isset($rowspans['line'][$i])): ?>
                                            <td rowspan="<?= $rowspans['line'][$i] ?>" class="text-center"><?= htmlspecialchars($row['ligne']) ?></td>
                                        <?php endif; ?>

                                        <td><?= htmlspecialchars($row['code_article']) ?></td>
                                        <td class="text-end fw-bold px-3"><?= number_format($row['quantite_totale'], 2, ',', ' ') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($grandTotal): ?>
                            <tfoot class="table-info fw-bold fs-5 border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end pr-3">TOTAL GÉNÉRAL</td>
                                    <td class="text-end px-3 text-primary"><?= number_format($grandTotal['quantite_totale'], 0, ',', ' ') ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning text-center">Aucun produit scanné pour générer la synthèse.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="generateMultiplePalettesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/<?= $packingList->id ?>/palettes/generate-multiple" method="post">
            <?= \App\Lib\Csrf::field() ?>
        <div class="modal-header">
          <h5 class="modal-title">Générer Plusieurs Palettes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-3">
            <label class="form-label">Nombre de palettes (1-50) :</label>
            <input type="number" class="form-control" name="number_of_palettes_to_generate" min="1" max="50" value="1" required>
          </div>
        </div>
        <div class="modal-footer border-top-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary btn-sm">Générer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="printQrCodesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="printQrForm" action="<?= htmlspecialchars($baseUrl) ?>/palettes/print-qrcodes" method="post" target="_blank">
            <?= \App\Lib\Csrf::field() ?>
        <div class="modal-header">
          <h5 class="modal-title">Impression Étiquettes Palettes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="packing_list_id_for_print" value="<?= $packingList->id ?>">
          <input type="hidden" name="selected_palettes_for_print" id="selectedPalettesForPrintInput">
          <h6 class="border-bottom pb-2 mb-3">Options des étiquettes (mm)</h6>
          <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label small">Largeur:</label><input type="number" class="form-control form-control-sm" name="label_width_mm" value="100"></div>
            <div class="col-md-4 mb-3"><label class="form-label small">Hauteur:</label><input type="number" class="form-control form-control-sm" name="label_height_mm" value="50"></div>
            <div class="col-md-4 mb-3"><label class="form-label small">QR par ligne:</label><input type="number" class="form-control form-control-sm" name="palettes_per_row" value="2"></div>
            <div class="col-md-4 mb-3"><label class="form-label small">Taille QR (mm):</label><input type="number" class="form-control form-control-sm" name="qr_size_mm" value="35"></div>
            <div class="col-md-8 mb-3 align-self-center">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="print_with_label_text" id="print_text" checked>
                    <label class="form-check-label small" for="print_text">Inclure le code palette sous le QR</label>
                </div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Préparer l'impression</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialisation DataTables uniquement pour l'onglet Palettes
    if (typeof window.initializeDataTable === 'function') {
        window.initializeDataTable('#palettesTableOnPLShowPage', [[1, "asc"]], [{ "orderable": false, "targets": [0, 7] }]);
    }

    // 2. Gestion de la sélection massive pour impression
    const selectAll = document.getElementById('selectAllPalettesForPrint');
    const checkboxes = document.querySelectorAll('.palette-print-checkbox');
    if (selectAll) {
        selectAll.addEventListener('change', () => checkboxes.forEach(c => c.checked = selectAll.checked));
    }
    
    const printForm = document.getElementById('printQrForm');
    if (printForm) {
        printForm.addEventListener('submit', () => {
            const ids = Array.from(checkboxes).filter(c => c.checked).map(c => c.value);
            document.getElementById('selectedPalettesForPrintInput').value = ids.join(',');
        });
    }
});
</script>

<?php
// Nettoyage session (RESTAURÉ)
if (isset($_SESSION['show_move_colis_modal_for_colis_id'])) unset($_SESSION['show_move_colis_modal_for_colis_id']);
if (isset($_SESSION['open_palette_error_max_colis'])) unset($_SESSION['open_palette_error_max_colis']);
if (isset($_SESSION['edit_max_colis_error'])) unset($_SESSION['edit_max_colis_error']);
?>