<?php
// src/Views/sites/index.php
// Variables attendues du contrôleur :
// $pageTitle : string
// $sites : array (d'objets App\Models\Site)
// $baseUrl : string
// $appName : string
// $request : App\Lib\Request

use App\Lib\Auth;
use App\Models\Site as SiteModel; // Pour le type hinting
?>

<div class="action-bar mb-3">
    <?php if (Auth::can('site_manage')): // Ou une permission plus spécifique comme 'site_create' ?>
        <a href="<?= htmlspecialchars($baseUrl) ?>/sites/create" class="btn btn-success">
            <i class="fas fa-plus-circle"></i> Créer un Nouveau Site
        </a>
    <?php endif; ?>
</div>

<?php if (isset($sites) && !empty($sites)): ?>
    <div class="table-responsive">
        <table id="sitesTable" class="table table-striped table-hover datatable" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Nom du Site</th>
                    <th>Adresse</th>
                    <th class="text-center">Statut</th>
                    <th>Créé le</th>
                    <th>Modifié le</th>
                    <th data-orderable="false" style="min-width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $site): ?>
                    <?php if (!$site instanceof SiteModel) { continue; } ?>
                    <tr class="<?= !$site->is_active ? 'table-secondary text-muted' : '' ?>">
                        <td><?= htmlspecialchars($site->id) ?></td>
                        <td><strong><?= htmlspecialchars($site->code) ?></strong></td>
                        <td><?= htmlspecialchars($site->name) ?></td>
                        <td><?= nl2br(htmlspecialchars($site->address ?? 'N/A')) ?></td>
                        <td class="text-center">
                            <?php if ($site->is_active): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td data-order="<?= htmlspecialchars($site->created_at) ?>"><?= htmlspecialchars((new \DateTime($site->created_at))->format('d/m/Y H:i')) ?></td>
                        <td data-order="<?= htmlspecialchars($site->updated_at) ?>"><?= htmlspecialchars((new \DateTime($site->updated_at))->format('d/m/Y H:i')) ?></td>
                        <td>
                            <?php if (Auth::can('site_manage')): // Ou 'site_update' ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/sites/edit/<?= $site->id ?>" class="btn btn-sm btn-primary" title="Modifier le site">
                                    <i class="fas fa-edit"></i> Modifier
                                </a>
                            <?php endif; ?>

                            <?php if (Auth::can('site_manage')): // Ou 'site_toggle_activity' ?>
                                <?php if ($site->is_active): ?>
                                    <form action="<?= htmlspecialchars($baseUrl) ?>/sites/deactivate/<?= $site->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir désactiver ce site ?');">
            <?= \App\Lib\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-warning ms-1" title="Désactiver le site">
                                            <i class="fas fa-toggle-off"></i> Désactiver
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form action="<?= htmlspecialchars($baseUrl) ?>/sites/reactivate/<?= $site->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir réactiver ce site ?');">
            <?= \App\Lib\Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-success ms-1" title="Réactiver le site">
                                            <i class="fas fa-toggle-on"></i> Réactiver
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php /* Bouton de suppression physique (à utiliser avec EXTRÊME prudence, peut-être caché par défaut)
                            <?php if (Auth::can('site_force_delete')): // Une permission très spécifique ?>
                                <form action="<?= htmlspecialchars($baseUrl) ?>/sites/force-delete/<?= $site->id ?>" method="post" style="display:inline;" onsubmit="return confirm('ATTENTION : Suppression PHYSIQUE ! Êtes-vous absolument sûr ? Toutes les données liées (PL, utilisateurs) qui ont ON DELETE SET NULL deviendront orphelines de site.');">
            <?= \App\Lib\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="Suppression Physique"><i class="fas fa-skull-crossbones"></i></button>
                                </form>
                            <?php endif; ?>
                            */ ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">Aucun site n'a été configuré pour le moment.</div>
<?php endif; ?>

