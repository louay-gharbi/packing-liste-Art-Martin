<?php
// src/Views/permissions/index.php
// Variables attendues: $pageTitle, $permissions, $baseUrl, $appName
?>

<div class="action-bar mb-3">
    <a href="<?= htmlspecialchars($baseUrl) ?>/permissions/create" class="btn btn-success">Créer une nouvelle permission</a>
</div>

<?php if (!empty($permissions)): ?>
    <div class="table-responsive">
        <table id=permissionsTable class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom (Code)</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($permissions as $permission): ?>
                    <tr>
                        <td><?= htmlspecialchars($permission->id) ?></td>
                        <td><code><?= htmlspecialchars($permission->name) ?></code></td>
                        <td><?= nl2br(htmlspecialchars($permission->description ?? '')) ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/permissions/edit/<?= $permission->id ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> Modifier
                            </a>
                            <form action="<?= htmlspecialchars($baseUrl) ?>/permissions/delete/<?= $permission->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette permission ? Elle sera retirée de tous les rôles.');">
            <?= \App\Lib\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash-alt"></i> Supprimer
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">Aucune permission trouvée.</div>
<?php endif; ?>