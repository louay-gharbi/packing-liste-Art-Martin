<?php
// src/Views/users/index.php
// Variables attendues: $pageTitle, $users, $baseUrl, $appName
// NOUVEAU: $availableSites (pour le filtre), $currentFilterSiteId
use App\Lib\Auth;
use App\Models\User as UserModel; // Pour le type hinting
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-6">
        <?php if (Auth::can('user_manage')): // Ou la permission spécifique pour créer ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/users/create" class="btn btn-success">
                <i class="fas fa-user-plus"></i> Créer un Utilisateur
            </a>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <?php 
        // Afficher le filtre de site uniquement si l'utilisateur est admin et qu'il y a des sites disponibles
        $currentUserForFilter = Auth::user();
        if ($currentUserForFilter && $currentUserForFilter->hasRole('Administrateur') && !empty($availableSites) && count($availableSites) > 1): 
        ?>
            <form method="get" action="<?= htmlspecialchars($baseUrl) ?>/users" class="row g-2 justify-content-md-end">
                <div class="col-auto">
                    <label for="filter_site_id" class="col-form-label col-form-label-sm">Filtrer par Site :</label>
                </div>
                <div class="col-auto">
                    <select name="filter_site_id" id="filter_site_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tous les Sites</option>
                        <?php foreach ($availableSites as $site): ?>
                            <option value="<?= $site->id ?>" <?= (isset($currentFilterSiteId) && $currentFilterSiteId == $site->id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i></button>
                    <?php if (isset($currentFilterSiteId) && $currentFilterSiteId): ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/users" class="btn btn-sm btn-outline-secondary ms-1" title="Réinitialiser le filtre"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($currentUserForFilter && $currentUserForFilter->site_id !== null && count($availableSites) === 1): ?>
            <div class="text-md-end">
                <span class="badge bg-light text-dark p-2">Affichage pour le site : <?= htmlspecialchars($availableSites[0]->name) ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>


<?php if (isset($users) && !empty($users)): ?>
    <div class="table-responsive">
        <table id="usersTable" class="table table-striped table-hover datatable" style="width:100%">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom d'utilisateur</th>
                    <th>Email</th>
                    <th>Prénom</th>
                    <th>Nom</th>
                    <th>Site</th> <!-- NOUVELLE COLONNE -->
                    <th>Rôles</th>
                    <th>Actif</th>
                    <th data-orderable="false" style="min-width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php if (!$user instanceof UserModel) { continue; } ?>
                    <tr>
                        <td><?= htmlspecialchars($user->id) ?></td>
                        <td><?= htmlspecialchars($user->username) ?></td>
                        <td><?= htmlspecialchars($user->email) ?></td>
                        <td><?= htmlspecialchars($user->first_name ?? '') ?></td>
                        <td><?= htmlspecialchars($user->last_name ?? '') ?></td>
                        <td>
                            <?php // $user->site est chargé par $user->loadSite() dans le contrôleur ou par la jointure dans User::getAll()
                                if ($user->site) {
                                    echo htmlspecialchars($user->site->name) . " <small class='text-muted'>(" . htmlspecialchars($user->site->code) . ")</small>";
                                } elseif ($user->site_id) { // Fallback si l'objet $user->site n'est pas chargé mais site_id existe
                                    echo "<span class='text-muted'>Site ID: " . htmlspecialchars($user->site_id) . " (non chargé)</span>";
                                } else {
                                    echo '<span class="text-mutedfst-italic">Global</span>';
                                }
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!empty($user->roles)) {
                                echo htmlspecialchars(implode(', ', array_map(fn($role) => $role->name, $user->roles)));
                            } else {
                                echo '<span class="text-muted">N/A</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $user->is_active ? 'success' : 'secondary' ?>">
                                <?= $user->is_active ? 'Oui' : 'Non' ?>
                            </span>
                        </td>
                        <td>
                            <?php if (Auth::can('user_manage')): // Ou user_update ?>
                                <a href="<?= htmlspecialchars($baseUrl) ?>/users/edit/<?= $user->id ?>" class="btn btn-sm btn-primary" title="Modifier"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if (Auth::can('user_manage') && Auth::id() !== $user->id): // Ou user_delete ?>
                            <form action="<?= htmlspecialchars($baseUrl) ?>/users/delete/<?= $user->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
            <?= \App\Lib\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-danger ms-1" title="Supprimer"><i class="fas fa-trash-alt"></i></button>
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
            Aucun utilisateur trouvé pour le site sélectionné. <a href="<?= htmlspecialchars($baseUrl) ?>/users">Voir tous les sites</a>.
        <?php else: ?>
            Aucun utilisateur trouvé.
        <?php endif; ?>
    </div>
<?php endif; ?>

