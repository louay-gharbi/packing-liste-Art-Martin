<?php
// src/Views/roles/index.php
// Variables attendues: $pageTitle, $roles, $baseUrl, $appName
?>

<div class="action-bar">
    <a href="<?= htmlspecialchars($baseUrl) ?>/roles/create" class="btn btn-success">Créer un nouveau rôle</a>
</div>

<?php if (!empty($roles)): ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom du Rôle</th>
                    <th>Description</th>
                    <th style="width: 250px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td><?= htmlspecialchars($role->id) ?></td>
                        <td><?= htmlspecialchars($role->name) ?></td>
                        <td><?= nl2br(htmlspecialchars($role->description ?? '')) // nl2br pour les sauts de ligne dans la description ?></td>
                        <td>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/roles/edit/<?= $role->id ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> Modifier/Permissions
                            </a>
                            <?php 
                                // Empêcher la suppression des rôles de base, à définir selon vos besoins
                                $protectedRoles = ['Administrateur', 'Superviseur', 'Opérateur de Scan']; 
                            ?>
                            <?php if (!in_array($role->name, $protectedRoles)): ?>
                                <form action="<?= htmlspecialchars($baseUrl) ?>/roles/delete/<?= $role->id ?>" method="post" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce rôle ? Cela retirera aussi toutes ses permissions assignées.');">
            <?= \App\Lib\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash-alt"></i> Supprimer
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-info">Aucun rôle trouvé.</div>
<?php endif; ?>