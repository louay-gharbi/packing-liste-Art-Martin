<?php
// src/Views/packing_lists/index.php
// Variables attendues: $pageTitle, $packingLists, $baseUrl, $appName
// NOUVEAU: $availableSites (pour le filtre), $currentFilterSiteId
use App\Lib\Auth;
use App\Models\PackingList as PLModel;
use App\Models\Site as SiteModel; // Pour le type hinting

?>

<div class="row mb-3 align-items-center">
    <div class="col-md-auto">
        <?php if (Auth::can('packinglist_create')): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/create" class="btn btn-success">
                <i class="fas fa-plus"></i> Créer une Packing List
            </a>
        <?php endif; ?>
    </div>
    <div class="col-md">
        <?php 
        $currentUserForPlFilter = Auth::user();
        // Afficher le filtre de site si l'utilisateur est admin global OU s'il n'est pas lié à un site et qu'il y a des sites à filtrer
        // OU s'il est lié à un site mais qu'il peut voir d'autres sites (logique plus complexe non gérée ici, on suppose admin voit tout)
        $canDisplaySiteFilter = ($currentUserForPlFilter && ($currentUserForPlFilter->site_id === null || $currentUserForPlFilter->hasRole('Administrateur')) && !empty($availableSites) && count($availableSites) > 0);
        ?>
        <?php if ($canDisplaySiteFilter): ?>
            <form method="get" action="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="row gx-2 gy-2 align-items-center justify-content-md-end" id="filterPackingListSiteForm">
                <div class="col-auto">
                    <label for="filter_site_id_pl" class="col-form-label col-form-label-sm visually-hidden">Filtrer par Site :</label>
                </div>
                <div class="col-md-4 col-lg-3">
                    <select name="filter_site_id" id="filter_site_id_pl" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tous les Sites</option>
                        <?php foreach ($availableSites as $site): ?>
                            <?php if (!$site instanceof SiteModel) continue; ?>
                            <option value="<?= $site->id ?>" <?= (isset($currentFilterSiteId) && $currentFilterSiteId == $site->id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Appliquer le filtre"><i class="fas fa-filter"></i></button>
                    <?php if (isset($currentFilterSiteId) && $currentFilterSiteId): ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-sm btn-outline-secondary ms-1" title="Réinitialiser le filtre de site"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($currentUserForPlFilter && $currentUserForPlFilter->site_id !== null && !empty($availableSites) && count($availableSites) === 1 && $availableSites[0] instanceof SiteModel): ?>
            <div class="text-md-end">
                <span class="badge bg-light text-dark p-2">Affichage pour le site : <?= htmlspecialchars($availableSites[0]->name) ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>


<?php if (isset($packingLists) && !empty($packingLists)): ?>
    <div class="table-responsive">
        <table id="packingListsTable" class="table table-striped table-hover datatable" style="width:100%">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Site</th> <!-- NOUVELLE COLONNE -->
                    <th>Date d'expédition</th>
                    <th>Statut</th>
                    <th>Nb Palettes</th>
                    <th>Créée le</th>
                    <th data-orderable="false" style="min-width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packingLists as $pl): ?>
                    <?php if (!$pl instanceof PLModel) { continue; } ?>
                    <tr>
                        <td>
                            <?php if (Auth::can('packinglist_read_one')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $pl->id ?>" title="Voir détails de <?= htmlspecialchars($pl->reference) ?>">
                                    <?= htmlspecialchars($pl->reference) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($pl->reference) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php // $pl->site est chargé par la jointure dans PackingList::getAll() ou par $pl->loadSite()
                                if ($pl->site) {
                                    echo htmlspecialchars($pl->site->name); // Ou $pl->site->code
                                } elseif ($pl->site_id) {
                                    echo "<small class='text-muted'>Site ID: " . htmlspecialchars($pl->site_id) . "</small>";
                                } else {
                                    echo '<small class="text-muted fst-italic">N/D</small>';
                                }
                            ?>
                        </td>
                        <?php $shipDate = new DateTime($pl->ship_date); ?>
                        <td data-order="<?= $shipDate->format('Y-m-d') ?>"><?= htmlspecialchars($shipDate->format('d/m/Y')) ?></td>
                        <td>
                            <?php 
                                $statusClass = 'secondary'; $statusText = htmlspecialchars(ucfirst($pl->status));
                                if ($pl->status === 'open') { $statusClass = 'success'; $statusText = 'Ouverte'; }
                                elseif ($pl->status === 'closed') { $statusClass = 'dark'; $statusText = 'Fermée'; }
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                        <td><?= $pl->getPaletteCount() ?></td>
                        <?php $createdAt = new DateTime($pl->created_at); ?>
                        <td data-order="<?= $createdAt->format('Y-m-d H:i:s') ?>"><?= htmlspecialchars($createdAt->format('d/m/Y H:i')) ?></td>
                        <td>
                            <?php if (Auth::can('packinglist_read_one')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $pl->id ?>" class="btn btn-sm btn-info" title="Voir détails"><i class="fas fa-eye"></i></a>
                            <?php endif; ?>
                            <?php if ($pl->status === 'open' && Auth::can('packinglist_update')): ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/edit/<?= $pl->id ?>" class="btn btn-sm btn-primary ms-1" title="Modifier date"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($pl->status === 'open' && Auth::can('packinglist_close')): ?>
                                <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/close/<?= $pl->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir fermer cette Packing List ?');">
            <?= \App\Lib\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-warning ms-1" title="Fermer la PL"><i class="fas fa-lock"></i></button>
                                </form>
                            <?php elseif($pl->status === 'closed' && Auth::can('packinglist_reopen')): ?>
                                <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/reopen/<?= $pl->id ?>" method="post" style="display:inline;" class="ms-1" onsubmit="return confirm('Êtes-vous sûr de vouloir réouvrir cette Packing List ?');">
            <?= \App\Lib\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-success" title="Réouvrir la PL"><i class="fas fa-undo"></i></button>
                                </form>
                            <?php endif; ?>
                             <?php if (Auth::can('packinglist_delete') && $pl->status === 'open'): ?>
                                <form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/delete/<?= $pl->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette Packing List ?');">
            <?= \App\Lib\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger ms-1" title="Supprimer la PL"><i class="fas fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <?php if (isset($currentFilterSiteId) && $currentFilterSiteId): ?>
            Aucune Packing List trouvée pour le site sélectionné. <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists">Voir tous les sites</a> (si autorisé).
        <?php else: ?>
            Aucune Packing List trouvée.
        <?php endif; ?>
    </div>
<?php endif; ?>

