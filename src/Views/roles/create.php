<?php
// src/Views/roles/create.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request, $role_data (pour pré-remplissage), $errors (optionnel), $db_error (optionnel)
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<form action="<?= htmlspecialchars($baseUrl) ?>/roles/store" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="form-group mb-3">
        <label for="name" class="form-label">Nom du Rôle :</label>
        <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" id="name" name="name" required value="<?= htmlspecialchars($role_data['name'] ?? $request->post('name', '')) ?>">
        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
    </div>

    <div class="form-group mb-3">
        <label for="description" class="form-label">Description :</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($role_data['description'] ?? $request->post('description', '')) ?></textarea>
        <?php if (isset($errors['description'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div><?php endif; ?>
    </div>

    <div class="form-group mt-4">
        <button type="submit" class="btn btn-primary">Créer le rôle</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/roles" class="btn btn-secondary">Annuler</a>
    </div>
</form>