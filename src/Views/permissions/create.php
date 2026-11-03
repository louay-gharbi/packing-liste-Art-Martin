<?php
// src/Views/permissions/create.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request, $permission_data (pour pré-remplissage), $errors (optionnel), $db_error (optionnel)
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<form action="<?= htmlspecialchars($baseUrl) ?>/permissions/store" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="form-group mb-3">
        <label for="name" class="form-label">Nom de la Permission (Code) :</label>
        <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" required value="<?= htmlspecialchars($permission_data['name'] ?? $request->post('name', '')) ?>" placeholder="ex: user_create, packinglist_scan">
        <small class="form-text text-muted">Uniquement lettres minuscules, chiffres et underscores (_).</small>
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
    </div>

    <div class="form-group mb-3">
        <label for="description" class="form-label">Description :</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($permission_data['description'] ?? $request->post('description', '')) ?></textarea>
        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div><?php endif; ?>
    </div>

    <div class="form-group mt-4">
        <button type="submit" class="btn btn-primary">Créer la permission</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/permissions" class="btn btn-secondary">Annuler</a>
    </div>
</form>