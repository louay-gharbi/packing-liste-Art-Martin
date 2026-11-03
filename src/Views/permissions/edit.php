<?php
// src/Views/permissions/edit.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $permission (objet Permission), $request, $errors (optionnel), $db_error (optionnel)
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<?php if (isset($permission) && $permission instanceof App\Models\Permission): ?>
    <form action="<?= htmlspecialchars($baseUrl) ?>/permissions/update/<?= $permission->id ?>" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
        <div class="form-group mb-3">
            <label for="name" class="form-label">Nom de la Permission (Code) :</label>
            <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" required value="<?= htmlspecialchars($request->post('name', $permission->name)) ?>" placeholder="ex: user_create">
            <small class="form-text text-muted">Uniquement lettres minuscules, chiffres et underscores (_).</small>
            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
        </div>

        <div class="form-group mb-3">
            <label for="description" class="form-label">Description :</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($request->post('description', $permission->description ?? '')) ?></textarea>
        </div>

        <div class="form-group mt-4">
            <button type="submit" class="btn btn-primary">Mettre à jour la permission</button>
            <a href="<?= htmlspecialchars($baseUrl) ?>/permissions" class="btn btn-secondary">Annuler</a>
        </div>
    </form>
<?php else: ?>
    <div class="alert alert-warning">Permission non trouvée ou erreur lors du chargement.</div>
    <a href="<?= htmlspecialchars($baseUrl) ?>/permissions" class="btn btn-secondary">Retour à la liste</a>
<?php endif; ?>