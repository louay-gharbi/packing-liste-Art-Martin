<?php
// src/Views/sites/create.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request
// $site_data : array (pour pré-remplissage en cas d'erreur, ex: $site_data['code'])
// $errors : array (optionnel)
// $db_error : string (optionnel)

use App\Lib\Auth;
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h5>
    </div>
    <div class="card-body">
        <form action="<?= htmlspecialchars($baseUrl) ?>/sites/store" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="site_code" class="form-label">Code Site : <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($errors['code']) ? 'is-invalid' : '' ?>" 
                           id="site_code" name="code" maxlength="20" required 
                           value="<?= htmlspecialchars($site_data['code'] ?? $request->post('code', '')) ?>" 
                           oninput="this.value = this.value.toUpperCase()">
                    <small class="form-text text-muted">Identifiant unique du site (ex: PANTIN, LYON01). Sera converti en majuscules.</small>
                    <?php if (isset($errors['code'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['code']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="site_name" class="form-label">Nom du Site : <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                           id="site_name" name="name" maxlength="100" required 
                           value="<?= htmlspecialchars($site_data['name'] ?? $request->post('name', '')) ?>">
                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="site_address" class="form-label">Adresse :</label>
                <textarea class="form-control" id="site_address" name="address" rows="3"><?= htmlspecialchars($site_data['address'] ?? $request->post('address', '')) ?></textarea>
            </div>
            
            <div class="form-check mb-3">
                <?php
                    $isActive = true; // Actif par défaut à la création
                    if ($request->getMethod() === 'POST' && $request->hasPost('code')) { // Si le formulaire a été soumis
                        $isActive = $request->post('is_active') === 'on';
                    } elseif (isset($site_data['is_active'])) {
                        $isActive = (bool)$site_data['is_active'];
                    }
                ?>
                <input class="form-check-input" type="checkbox" id="site_is_active" name="is_active" <?= $isActive ? 'checked' : '' ?>>
                <label class="form-check-label" for="site_is_active">
                    Site Actif
                </label>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer le Site</button>
                <a href="<?= htmlspecialchars($baseUrl) ?>/sites" class="btn btn-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>