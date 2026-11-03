<?php
// src/Views/palettes/show.php
// Variables attendues : $pageTitle, $palette, $packingList, $colisGrouped, $baseUrl, $appName, $request

use App\Lib\Auth;
use App\Models\Palette as PaletteModel;
use App\Models\PackingList as PLModel;
use App\Models\Colis as ColisModel;

if (!isset($palette) || !$palette instanceof PaletteModel) {
    echo "<div class='alert alert-danger'>Erreur: Les données de la Palette n'ont pas pu être chargées.</div>";
    if (isset($baseUrl)) { echo "<a href='" . htmlspecialchars($baseUrl) . "/packing-lists' class='btn btn-secondary mt-2'>Retour</a>"; }
    return;
}

$fullQrUrl = '';
if (!empty($palette->qr_code_path)) {
    $fullQrUrl = preg_match('#^https?://#', $palette->qr_code_path)
        ? $palette->qr_code_path
        : rtrim($baseUrl, '/') . $palette->qr_code_path;
}
?>

<div class="row mb-3">
    <div class="col-md-8">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php if (Auth::can('packinglist_read_all')): ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists">Packing Lists</a></li>
                <?php endif; ?>
                <?php if (isset($packingList) && $packingList instanceof PLModel): ?>
                    <?php if (Auth::can('packinglist_read_one')): ?>
                        <li class="breadcrumb-item"><a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingList->id ?>"><?= htmlspecialchars($packingList->reference) ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><?= htmlspecialchars($packingList->reference) ?></li>
                    <?php endif; ?>
                <?php endif; ?>
                <li class="breadcrumb-item active" aria-current="page">Palette <?= htmlspecialchars($palette->code) ?></li>
            </ol>
        </nav>
    </div>
    <div class="col-md-4 text-end">
        <?php if (isset($packingList) && $packingList instanceof PLModel && Auth::can('packinglist_read_one')): ?>
             <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingList->id ?>" class="btn btn-sm btn-outline-secondary" title="Retour à la Packing List parente">
                <i class="fas fa-arrow-left"></i> Retour à la PL
            </a>
        <?php elseif (Auth::can('packinglist_read_all')): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-sm btn-outline-secondary" title="Liste des Packing Lists">
                <i class="fas fa-list"></i> Liste des PL
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Détails de la Palette</h5>
            <div>
                <?php if ($palette->status === 'open' && Auth::can('colis_scan')): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan?palette_code=<?= htmlspecialchars($palette->code) ?>" class="btn btn-sm btn-success ms-1" title="Poursuivre le scan">
                        <i class="fas fa-qrcode"></i> Poursuivre le Scan
                    </a>
                <?php endif; ?>
                <?php if ($palette->status === 'open' && Auth::can('palette_close')): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/palettes/close/<?= $palette->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir fermer cette palette ? Aucun nouveau scan de colis ne sera plus possible.');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-warning" title="Fermer la palette"><i class="fas fa-lock"></i> Fermer</button>
                    </form>
                <?php endif; ?>
                <?php if ($palette->status === 'closed' && isset($packingList) && $packingList->status === 'open' && Auth::can('palette_reopen')): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/palettes/reopen/<?= $palette->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir réouvrir cette palette ?');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-success" title="Réouvrir la palette">
                            <i class="fas fa-undo"></i> Réouvrir
                        </button>
                    </form>
                <?php endif; ?>
                <?php
                    $canDeletePalette = false;
                    if ($palette->status === 'generated' && Auth::can('palette_delete')) $canDeletePalette = true;
                    if ($palette->status === 'open' && $palette->getColisCount() === 0 && Auth::can('palette_delete')) $canDeletePalette = true;
                ?>
                <?php if ($canDeletePalette): ?>
                     <form action="<?= htmlspecialchars($baseUrl) ?>/palettes/delete/<?= $palette->id ?>" method="post" style="display:inline-block;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette palette ?');">
            <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-danger" title="Supprimer la palette"><i class="fas fa-trash-alt"></i> Supprimer</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <p><strong>Code Palette :</strong> <?= htmlspecialchars($palette->code) ?></p>
                <p><strong>Packing List :</strong> 
                    <?php if (isset($packingList) && $packingList instanceof PLModel && Auth::can('packinglist_read_one')): ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingList->id ?>"><?= htmlspecialchars($packingList->reference) ?></a>
                    <?php elseif (isset($packingList) && $packingList instanceof PLModel): echo htmlspecialchars($packingList->reference); ?>
                    <?php else: echo '<span class="text-muted">N/A</span>'; endif; ?>
                </p>
                <p><strong>Statut :</strong> 
                    <?php 
                        $palStatusClassShow = 'light text-dark'; $palStatusTextShow = htmlspecialchars(ucfirst($palette->status));
                        if ($palette->status === 'generated') { $palStatusClassShow = 'info text-dark'; $palStatusTextShow = 'Générée'; }
                        elseif ($palette->status === 'open') { $palStatusClassShow = 'success'; $palStatusTextShow = 'Ouverte'; }
                        elseif ($palette->status === 'closed') { $palStatusClassShow = 'secondary'; $palStatusTextShow = 'Fermée'; }
                    ?>
                    <span class="badge bg-<?= $palStatusClassShow ?>"><?= $palStatusTextShow ?></span>
                </p>
                <p class="fw-bold fs-5"><strong>Total Pièces :</strong> <?= htmlspecialchars($totalScannedQuantityPalette ?? 0) ?></p>
            </div>

            <div class="col-md-4">
                
                <p class="mb-1"><strong>Max Colis :</strong> <?= htmlspecialchars($palette->max_colis ?? 'N/D') ?>
                    <?php if ($palette->status === 'open' && Auth::can('palette_update_max_colis')): ?>
                        <button type="button" class="btn btn-xs btn-outline-secondary ms-1 py-0 px-1" data-bs-toggle="modal" data-bs-target="#editMaxColisModal" title="Modifier Max Colis">
                            <i class="fas fa-edit fa-xs"></i>
                        </button>
                    <?php endif; ?>
                </p>
                <p class="mb-1"><strong>Colis Scannés :</strong> <span id="colisScannedCountOnPaletteDetailPage"><?= $palette->getColisCount() ?></span></p>
                 
                 <p class="mb-0"><strong>Créée le :</strong> <?= htmlspecialchars((new DateTime($palette->created_at))->format('d/m/Y H:i')) ?></p>
            </div>
            <div class="col-md-4 text-center">
                <?php if (!empty($palette->qr_code_path)): ?>
                <div class="mb-2">
                        <strong>QR Code Palette :</strong><br>
                        <img src="<?= $fullQrUrl ?>" alt="QR Code Palette <?= htmlspecialchars($palette->code) ?>" style="max-width: 150px; height: auto; border: 1px solid #ccc; margin-top: 5px;">
                        <p class="mt-1 mb-0"><small><a href="<?= $fullQrUrl ?>" download="qr_palette_<?= preg_replace('/[^a-zA-Z0-9_-]/', '_', $palette->code) ?>.png">Télécharger</a></small></p>
                    </div>
                <?php elseif ($palette->status === 'generated'): ?>
                    <p class="text-warning mt-3 small">QR Code non généré ou non disponible.</p>
                <?php endif; ?>
                <p class="mb-0"><small><strong>Ouverte le :</strong> <?= $palette->open_date_time ? htmlspecialchars((new DateTime($palette->open_date_time))->format('d/m/Y H:i')) : '<span class="text-muted">N/A</span>' ?></small></p>
                <p class="mb-0"><small><strong>Fermée le :</strong> <?= $palette->close_date_time ? htmlspecialchars((new DateTime($palette->close_date_time))->format('d/m/Y H:i')) : '<span class="text-muted">N/A</span>' ?></small></p>
            </div>
        </div>

        <?php if ($palette->status === 'generated' && isset($packingList) && $packingList->status === 'open' && Auth::can('palette_open')): ?>
            <hr class="my-3">
            <h5><i class="fas fa-box-open"></i> Ouvrir la Palette</h5>
            <p class="text-muted small">Cette palette est prête à être utilisée. Avant de scanner des colis, veuillez définir le nombre maximal de colis qu'elle peut contenir. Cette action la passera au statut "Ouverte".</p>
            <form action="<?= htmlspecialchars($baseUrl) ?>/palettes/open/<?= $palette->id ?>" method="post" class="row gx-2 gy-2 align-items-end">
            <?= \App\Lib\Csrf::field() ?>
                <div class="col-md-5 col-lg-4">
                    <label for="max_colis_open_form" class="form-label visually-hidden">Nombre max de colis :</label>
                    <input type="number" class="form-control <?= (isset($_SESSION['open_palette_error_max_colis']) && $_SESSION['open_palette_error_max_colis'] == $palette->id) ? 'is-invalid' : '' ?>" 
                           id="max_colis_open_form" name="max_colis" min="1" required 
                           value="<?= htmlspecialchars($request->post('max_colis', '')) ?>" placeholder="Max colis">
                     <?php if (isset($_SESSION['open_palette_error_max_colis']) && $_SESSION['open_palette_error_max_colis'] == $palette->id): ?>
                        <div class="invalid-feedback d-block"><small>Veuillez entrer un nombre valide.</small></div>
                        <?php unset($_SESSION['open_palette_error_max_colis']); ?>
                    <?php endif; ?>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Ouvrir & Enregistrer Max</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Modale pour Modifier Max Colis -->
<?php if ($palette->status === 'open' && Auth::can('palette_update_max_colis')): ?>
<div class="modal fade" id="editMaxColisModal" tabindex="-1" aria-labelledby="editMaxColisModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form action="<?= htmlspecialchars($baseUrl) ?>/palettes/update-max-colis/<?= $palette->id ?>" method="post">
            <?= \App\Lib\Csrf::field() ?>
        <div class="modal-header"><h5 class="modal-title" id="editMaxColisModalLabel">Modifier Max Colis</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="form-group mb-3">
            <label for="max_colis_edit_modal" class="form-label">Nouveau max colis pour <strong><?= htmlspecialchars($palette->code) ?></strong> :</label>
            <input type="number" class="form-control <?= (isset($_SESSION['edit_max_colis_error']) && $_SESSION['edit_max_colis_error'] == $palette->id) ? 'is-invalid' : '' ?>" 
                   id="max_colis_edit_modal" name="max_colis_edit" min="1" 
                   value="<?= htmlspecialchars($request->post('max_colis_edit', $palette->max_colis ?? '')) ?>" required>
            <small class="form-text text-muted">Doit être ≥ <?= $palette->getColisCount() ?> (colis déjà scannés).</small>
             <?php if (isset($_SESSION['edit_max_colis_error']) && $_SESSION['edit_max_colis_error'] == $palette->id): ?>
                <div class="invalid-feedback d-block"><small>Erreur. Vérifiez la valeur (doit être ≥ <?= $palette->getColisCount() ?>).</small></div>
                <?php unset($_SESSION['edit_max_colis_error']); ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($recapBySku)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Récapitulatif par Produit (SKU)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Réf. Article</th>
                        <th>Libellé</th>
                        <th class="text-end">Total Pièces Scannées</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recapBySku as $recapLine): ?>
                        <tr>
                            <td><?= htmlspecialchars($recapLine['sku']) ?></td>
                            <td><?= htmlspecialchars($recapLine['label']) ?></td>
                            <td class="text-end fw-bold"><?= htmlspecialchars($recapLine['total_quantity']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ======================= SECTION COLIS SUR CETTE PALETTE ======================= -->
<?php
   
    $totalQty = 0;
    $displayRows = [];
    if (isset($colisGrouped)) {
        foreach ($colisGrouped as $g) {
            if ($g->type === 'parent') {
                // Afficher systématiquement la ligne du colis parent
                $displayRows[] = ['parent' => $g, 'colis' => $g];

                if (is_numeric($g->quantity)) {
 		   $totalQty += (float)$g->quantity;
		}
                foreach ($g->children as $c) {
                    $displayRows[] = ['parent' => $g, 'colis' => $c];

                    if (is_numeric($c->quantity)) {
                        $totalQty += (float)$c->quantity;
                    }
                }
            } else {
                $displayRows[] = ['parent' => null, 'colis' => $g];
                
                if (is_numeric($g->quantity)) {
                    $totalQty += (int)$g->quantity;
                }
            }
        }
    }
?>
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">
            Colis sur cette Palette (<span id="colisTotalCountPalette"><?= $palette->getColisCount() ?></span>)
        </h5>
    </div>
    <div class="card-body">
        <?php if (isset($colisGrouped) && !empty($colisGrouped)): ?>
            <!-- Barre d'actions groupées -->
            <?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 bg-light p-2 rounded">
                <div>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="checkbox" id="selectAllColisForMove" title="Sélectionner/Désélectionner tous les colis">
                        <label class="form-check-label small" for="selectAllColisForMove">Tout sélectionner</label>
                    </div>
                    <span class="text-muted small ms-2" id="selectedColisCountDisplay">0 colis sélectionné(s)</span>
                </div>
                <button type="button" class="btn btn-info btn-sm text-white" id="massiveMoveBtn" onclick="openMassiveMoveModal()" disabled>
                    <i class="fas fa-truck-moving me-1"></i> Déplacer la sélection
                </button>
            </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm table-hover datatable" id="colisTableOnPaletteDetail">
                    <thead>
                        <tr>
                            <?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
                                <th data-orderable="false" style="width: 30px;">
                                    <input type="checkbox" id="selectAllColisHeader" title="Sélectionner tout">
                                </th>
                            <?php endif; ?>
                            <th># Colis</th>
                            <th>Colis fils</th>
                            <th>OF</th>
                            <th>Commande</th>
                            <th>Réf. Article</th>
                            <th>Libellé</th>
                            <th>Qté</th>
                            <th>Scanné le</th>
                            <th>Commentaire</th>
                            <?php if ($palette->status === 'open' && (Auth::can('colis_delete') || Auth::can('colis_move'))): ?>
                                <th data-orderable="false" style="min-width: 80px;">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <!-- Un seul TBODY pour que DataTables fonctionne correctement -->
                    <tbody>
                        <?php foreach ($displayRows as $row): ?>
                            <?php $parentColis = $row['parent']; $colis = $row['colis']; ?>
                            <?php 
                                // Déterminer l'ID à utiliser pour la sélection (toujours le parent si groupé)
                                $selectId = ($parentColis && $colis !== $parentColis) ? $parentColis->id : $colis->id;
                                // Cacher la checkbox pour les colis enfants (on coche automatiquement via le parent)
                                $isHiddenChild = ($parentColis && $colis !== $parentColis);
                            ?>
                            <tr data-colis-id="<?= $selectId ?>">
                                <?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
                                    <td class="text-center">
                                        <?php if (!$isHiddenChild): ?>
                                        <input type="checkbox" class="colis-massive-move-checkbox" value="<?= $colis->id ?>" data-parent-id="<?= $selectId ?>">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><strong><?= htmlspecialchars($parentColis ? $parentColis->package_number : $colis->package_number) ?></strong></td>
                                <td><?= $parentColis && $colis !== $parentColis ? htmlspecialchars($colis->package_number) : '' ?></td>
                                <td><?= htmlspecialchars($colis->of_number ?? '') ?></td>
                                <td><?= htmlspecialchars($colis->order_number ?? '') ?></td>
                                <td><?= htmlspecialchars($colis->article_reference ?? '') ?></td>
                                <td><?= htmlspecialchars($colis->label ?? '') ?></td>
				<td><?= isset($colis->quantity) ? (float)$colis->quantity : '' ?></td>
                                <?php $scanDt = new DateTime($colis->scan_date_time); ?>
                                <td data-order="<?= $scanDt->format('Y-m-d H:i:s') ?>"><?= htmlspecialchars($scanDt->format('d/m/Y H:i')) ?></td>
                                <td>
                                    <?= htmlspecialchars($colis->comment ?? '') ?>
                                    <button type="button" class="btn btn-xs btn-outline-secondary ms-1 py-0 px-1" data-bs-toggle="modal" data-bs-target="#commentModal_<?= $colis->id ?>">
                                        <i class="fas fa-edit fa-xs"></i>
                                    </button>
                                </td>
                                <?php if ($palette->status === 'open'): ?>
                                    <td>
                                        <?php if (Auth::can('colis_move')): ?>
                                            <?php
                                                // Utiliser la modale du parent pour les colis enfants
                                                $moveModalId = ($parentColis && $colis !== $parentColis)
                                                    ? $parentColis->id
                                                    : $colis->id;
                                            ?>
                                            <button type="button" class="btn btn-xs btn-outline-info me-1 py-0 px-1" data-bs-toggle="modal" data-bs-target="#moveColisModal_<?= $moveModalId ?>" title="Déplacer ce colis"><i class="fas fa-truck-moving fa-xs"></i></button>
                                        <?php endif; ?>
                                        <?php if (Auth::can('colis_delete')): ?>
                                            <form action="<?= htmlspecialchars($baseUrl) ?>/colis/delete/<?= $colis->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir dé-scanner ce colis N° <?= htmlspecialchars($colis->package_number) ?> ?');">
                                                <?= \App\Lib\Csrf::field() ?>
                                                <input type="hidden" name="palette_id_redirect" value="<?= $palette->id ?>">
                                                <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1" title="Dé-scanner ce colis"><i class="fas fa-times fa-xs"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
           
        <?php else: ?>
            <p class="text-muted">Aucun colis n'a encore été scanné sur cette palette.</p>
        <?php endif; ?>
        
        <!-- Boutons d'action sous la table -->
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <?php if ($palette->status === 'open' && Auth::can('colis_scan')): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan?palette_code=<?= htmlspecialchars($palette->code) ?>" class="btn btn-lg btn-success"><i class="fas fa-qrcode"></i> Poursuivre le Scan</a>
            <?php endif; ?>
            <?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
                <button type="button" class="btn btn-lg btn-info text-white" id="massiveMoveBtnBottom" onclick="openMassiveMoveModal()" disabled>
                    <i class="fas fa-truck-moving me-1"></i> Déplacer la sélection
                </button>
            <?php endif; ?>
        </div>
        <?php if($palette->status === 'generated' && Auth::can('palette_open') && isset($packingList) && $packingList->status === 'open'): ?>
             <p class="mt-3 alert alert-info">Veuillez d'abord ouvrir la palette (en définissant le "Max Colis" ci-dessus) pour y scanner des colis.</p>
        <?php elseif($palette->status === 'closed'): ?>
             <p class="mt-3 alert alert-secondary">Cette palette est fermée. Aucun nouveau scan de colis n'est autorisé.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ======================= MODALE DE DÉPLACEMENT MASSIF ======================= -->
<?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
    <?php
        $sourcePaletteId = $palette->id;
        $sourcePaletteCode = $palette->code;
        include dirname(__DIR__) . '/partials/_move_colis_massive_modal.php';
    ?>
<?php endif; ?>

<!-- ======================= SECTION DES MODALES (HORS DE LA TABLE) ======================= -->
<?php if (isset($colisGrouped)): ?>
    <?php foreach ($colisGrouped as $parentColis): ?>
        <?php if ($palette->status === 'open' && Auth::can('colis_move')): ?>
            <?php
                $modalId = 'moveColisModal_' . $parentColis->id;
                $formAction = $baseUrl . '/colis/move/' . $parentColis->id;
                $packageNumber = $parentColis->package_number;
                $sourcePaletteCode = $palette->code;
                $redirectUrl = '/palettes/show/' . $palette->id;
                include dirname(__DIR__) . '/partials/_move_colis_modal.php';
            ?>
        <?php endif; ?>
        <!-- Modal commentaire pour le colis parent -->
        <div class="modal fade" id="commentModal_<?= $parentColis->id ?>" tabindex="-1" aria-labelledby="commentModalLabel_<?= $parentColis->id ?>" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form action="<?= htmlspecialchars($baseUrl) ?>/colis/update-comment/<?= $parentColis->id ?>" method="post">
            <?= \App\Lib\Csrf::field() ?>
                <input type="hidden" name="redirect_url" value="/palettes/show/<?= $palette->id ?>">
                <div class="modal-header">
                  <h5 class="modal-title" id="commentModalLabel_<?= $parentColis->id ?>">Commentaire pour colis <?= htmlspecialchars($parentColis->package_number) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <textarea name="comment" class="form-control" rows="3"><?= htmlspecialchars($parentColis->comment ?? '') ?></textarea>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                  <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <?php foreach ($parentColis->children as $childColis): ?>
        <div class="modal fade" id="commentModal_<?= $childColis->id ?>" tabindex="-1" aria-labelledby="commentModalLabel_<?= $childColis->id ?>" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form action="<?= htmlspecialchars($baseUrl) ?>/colis/update-comment/<?= $childColis->id ?>" method="post">
            <?= \App\Lib\Csrf::field() ?>
                <input type="hidden" name="redirect_url" value="/palettes/show/<?= $palette->id ?>">
                <div class="modal-header">
                  <h5 class="modal-title" id="commentModalLabel_<?= $childColis->id ?>">Commentaire pour colis <?= htmlspecialchars($childColis->package_number) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <textarea name="comment" class="form-control" rows="3"><?= htmlspecialchars($childColis->comment ?? '') ?></textarea>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                  <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // === GESTION SÉLECTION MASSIVE DES COLIS POUR DÉPLACEMENT ===
    const checkboxes = document.querySelectorAll('.colis-massive-move-checkbox');
    const selectAllHeader = document.getElementById('selectAllColisHeader');
    const selectAllBar = document.getElementById('selectAllColisForMove');
    const countDisplay = document.getElementById('selectedColisCountDisplay');
    const massiveMoveBtn = document.getElementById('massiveMoveBtn');
    const massiveMoveBtnBottom = document.getElementById('massiveMoveBtnBottom');

    function updateSelectionUI() {
        const checked = document.querySelectorAll('.colis-massive-move-checkbox:checked');
        const count = checked.length;
        
        if (countDisplay) {
            countDisplay.textContent = count + ' colis sélectionné(s)';
        }
        
        // Activer/désactiver les boutons de déplacement massif
        const hasSelection = count > 0;
        if (massiveMoveBtn) massiveMoveBtn.disabled = !hasSelection;
        if (massiveMoveBtnBottom) massiveMoveBtnBottom.disabled = !hasSelection;
        
        // Mettre à jour la checkbox "Tout sélectionner" dans le header
        if (selectAllHeader) {
            const totalCheckboxes = document.querySelectorAll('.colis-massive-move-checkbox').length;
            selectAllHeader.checked = count > 0 && count === totalCheckboxes;
            selectAllHeader.indeterminate = count > 0 && count < totalCheckboxes;
        }
        // Même chose pour la checkbox dans la barre d'actions
        if (selectAllBar) {
            const totalCheckboxes = document.querySelectorAll('.colis-massive-move-checkbox').length;
            selectAllBar.checked = count > 0 && count === totalCheckboxes;
        }
    }

    // Écouter les changements sur chaque checkbox
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateSelectionUI);
    });

    // "Tout sélectionner" depuis le header (checkbox dans TH)
    if (selectAllHeader) {
        selectAllHeader.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateSelectionUI();
        });
    }

    // "Tout sélectionner" depuis la barre d'actions
    if (selectAllBar) {
        selectAllBar.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateSelectionUI();
        });
    }

    // Initialiser l'interface
    updateSelectionUI();

    // Initialisation DataTables si nécessaire
    if (typeof window.initializeDataTable === 'function') {
        const firstColumnSelector = document.querySelector('#colisTableOnPaletteDetail thead th');
        const hasCheckboxes = firstColumnSelector && firstColumnSelector.querySelector('input[type="checkbox"]');
        
        // Utiliser scrollX pour éviter le scroll horizontal sur la page entière
        const hasCheckboxesFlag = hasCheckboxes ? [0, 9] : [8];
        
        window.initializeDataTable('#colisTableOnPaletteDetail', [[1, "asc"]], 
            [{ "orderable": false, "targets": hasCheckboxesFlag }],
            25, // Pagination à 25 lignes
            { 
                scrollX: true,
                responsive: false,
                ordering: true,
                paging: true
            }
        );
    }
});
</script>

<?php
// Le code de nettoyage des variables de session reste inchangé et à sa place en fin de fichier.
// Nettoyage des variables de session d'erreur si elles n'ont pas été utilisées
if (isset($_SESSION['show_move_colis_modal_for_colis_id'])) unset($_SESSION['show_move_colis_modal_for_colis_id']);
if (isset($_SESSION['move_colis_error_destination_code_value'])) unset($_SESSION['move_colis_error_destination_code_value']);
if (isset($_SESSION['open_palette_error_max_colis']) && (!isset($palette) || $_SESSION['open_palette_error_max_colis'] != $palette->id) ) unset($_SESSION['open_palette_error_max_colis']);
if (isset($_SESSION['edit_max_colis_error']) && (!isset($palette) || $_SESSION['edit_max_colis_error'] != $palette->id) ) unset($_SESSION['edit_max_colis_error']);
?>