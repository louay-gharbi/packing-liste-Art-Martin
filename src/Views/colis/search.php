<?php
// src/Views/colis/search.php
// Variables: pageTitle, request, results, total, page, totalPages, filters, availableSites
use App\Lib\Auth;
?>
<div class="container-fluid">
    <form method="get" class="mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label" for="site_id">Site</label>
                <select name="site_id" id="site_id" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach($availableSites as $s): ?>
                        <option value="<?= $s->id ?>" <?= ($filters['site_id']??'')==$s->id?'selected':'' ?>><?= htmlspecialchars($s->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label" for="package_number">Colis/QR</label>
                <input type="text" list="pn_datalist" name="package_number" id="quickSearchInput" class="form-control" value="<?= htmlspecialchars($filters['package_number']??'') ?>">
                <datalist id="pn_datalist"></datalist>
            </div>
            <div class="col-md">
                <label class="form-label" for="q">Recherche libre</label>
                <input type="text" name="q" id="q" class="form-control" value="<?= htmlspecialchars($filters['q']??'') ?>" placeholder="Numéro colis, SKU, OF…">
                <small class="form-text text-muted">Vous pouvez saisir plusieurs termes séparés par des espaces.</small>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-primary" type="submit">Rechercher</button>
            </div>
        </div>
    </form>

    <?php if(!empty($filters)): ?>
    <div class="mb-2">
        <?php foreach($filters as $k=>$v): ?>
            <a href="?<?= http_build_query(array_diff_key($filters, [$k=>true])) ?>" class="badge bg-secondary text-decoration-none me-1">
                <?= htmlspecialchars($k) ?>: <?= htmlspecialchars($v) ?> <span class="ms-1">&times;</span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm table-hover" id="colisSearchResultsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Parent</th>
                    <th>OF</th>
                    <th>Commande</th>
                    <th>SKU</th>
                    <th>Qté</th>
                    <th>Scan</th>
                    <th>Palette</th>
                    <th data-orderable="false">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($results as $c): ?>
                <tr class="<?= $c->parent_colis_id ? 'child-row' : 'parent-row' ?>">
                    <td><?= htmlspecialchars($c->package_number) ?></td>
                    <td>
                        <?php if(!$c->parent_colis_id && $c->palette_status==='grouped'): ?>
                            <span class="badge bg-info">G</span>
                        <?php elseif($c->parent_package_number): ?>
                            <?= htmlspecialchars($c->parent_package_number) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c->of_number ?? '') ?></td>
                    <td><?= htmlspecialchars($c->order_number ?? '') ?></td>
                    <td><?= htmlspecialchars($c->sku ?? '') ?></td>
                    <td><?= htmlspecialchars($c->quantity ?? '') ?></td>
                    <td><?= htmlspecialchars((new DateTime($c->scan_date_time))->format('d/m/Y')) ?></td>
                    <td><?= htmlspecialchars($c->palette_code ?? '') ?></td>
                    <td>
                        <a href="<?= $baseUrl ?>/palettes/show/<?= $c->palette_id ?>" class="btn btn-sm btn-outline-secondary" title="Palette"><i class="fas fa-pallet"></i></a>
                        <a href="<?= $baseUrl ?>/packing-lists/show/<?= $c->packing_list_id ?>" class="btn btn-sm btn-outline-secondary" title="PL"><i class="fas fa-list"></i></a>
                        <?php if(Auth::can('colis_move') && $c->palette_status === 'open'): ?>
                            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#moveColisModal_<?= $c->id ?>" title="Déplacer"><i class="fas fa-truck-moving"></i></button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#commentModal_<?= $c->id ?>" title="Commentaire"><i class="fas fa-edit"></i></button>
                        <?php if(Auth::can('colis_delete')): ?>
                        <form method="post" action="<?= $baseUrl ?>/colis/delete/<?= $c->id ?>" class="d-inline">
                            <?= \App\Lib\Csrf::field() ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer ce colis ?')"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

    <!-- Modales déplacement et commentaire -->
    <?php foreach($results as $c): ?>
        <?php if(Auth::can('colis_move') && $c->palette_status === 'open'): ?>
            <?php
                $modalId = 'moveColisModal_' . $c->id;
                $formAction = $baseUrl . '/colis/move/' . $c->id;
                $packageNumber = $c->package_number;
                $sourcePaletteCode = $c->palette_code;
                $redirectUrl = '/colis/search?' . http_build_query($filters);
                include dirname(__DIR__) . '/partials/_move_colis_modal.php';
            ?>
        <?php endif; ?>
        <div class="modal fade" id="commentModal_<?= $c->id ?>" tabindex="-1" aria-labelledby="commentModalLabel_<?= $c->id ?>" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form action="<?= htmlspecialchars($baseUrl) ?>/colis/update-comment/<?= $c->id ?>" method="post">
                <?= \App\Lib\Csrf::field() ?>
                <input type="hidden" name="redirect_url" value="/colis/search?<?= http_build_query($filters) ?>">
                <div class="modal-header">
                  <h5 class="modal-title" id="commentModalLabel_<?= $c->id ?>">Commentaire pour colis <?= htmlspecialchars($c->package_number) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <textarea name="comment" class="form-control" rows="3"><?= htmlspecialchars($c->comment ?? '') ?></textarea>
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
</div>
