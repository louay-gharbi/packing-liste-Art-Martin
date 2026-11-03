<?php
// src/Views/roles/edit.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $role (objet Role), $request, $allPermissions, $assignedPermissionIds, $errors (optionnel), $db_error (optionnel)
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<?php if (isset($role) && $role instanceof App\Models\Role): ?>
    <form action="<?= htmlspecialchars($baseUrl) ?>/roles/update/<?= $role->id ?>" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
        <div class="form-group mb-3">
            <label for="name" class="form-label">Nom du Rôle :</label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" required value="<?= htmlspecialchars($request->post('name', $role->name)) ?>">
            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
        </div>

        <div class="form-group mb-3">
            <label for="description" class="form-label">Description :</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($request->post('description', $role->description ?? '')) ?></textarea>
        </div>

        <div class="form-group mb-3">
            <label class="form-label">Permissions :</label>
            <?php if (!empty($allPermissions)): ?>
                <div class="border p-3 rounded" style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($allPermissions as $permission): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="permissions[]" id="perm_<?= $permission->id ?>" value="<?= $permission->id ?>"
                            <?= (in_array($permission->id, $request->post('permissions', $assignedPermissionIds ?? []))) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="perm_<?= $permission->id ?>">
                            <?= htmlspecialchars($permission->name) ?>
                            <?php if(!empty($permission->description)): ?>
                                <small class="text-muted">(<?= htmlspecialchars($permission->description) ?>)</small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">Aucune permission disponible. Veuillez d'abord <a href="<?= htmlspecialchars($baseUrl) ?>/permissions/create">créer des permissions</a>.</p>
            <?php endif; ?>
        </div>

        <div class="form-group mt-4">
            <button type="submit" class="btn btn-primary">Mettre à jour le rôle</button>
            <a href="<?= htmlspecialchars($baseUrl) ?>/roles" class="btn btn-secondary">Annuler</a>
        </div>
    </form>
<?php else: ?>
    <div class="alert alert-warning">Rôle non trouvé ou erreur lors du chargement.</div>
    <a href="<?= htmlspecialchars($baseUrl) ?>/roles" class="btn btn-secondary">Retour à la liste</a>
<?php endif; ?>