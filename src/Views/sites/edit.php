<?php
// src/Views/sites/edit.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request
// $site : App\Models\Site (l'objet site à éditer)
// $errors : array (optionnel)
// $db_error : string (optionnel)

use App\Lib\Auth;
use App\Models\Site as SiteModel;

if (!isset($site) || !$site instanceof SiteModel) {
    echo "<div class='alert alert-danger'>Erreur: Les données du site à modifier n'ont pas pu être chargées.</div>";
    if (isset($baseUrl)) { echo "<a href='" . htmlspecialchars($baseUrl) . "/sites' class='btn btn-secondary mt-2'>Retour à la liste</a>"; }
    return;
}
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h5>
    </div>
    <div class="card-body">
        <form action="<?= htmlspecialchars($baseUrl) ?>/sites/update/<?= $site->id ?>" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="site_code_edit" class="form-label">Code Site : <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($errors['code']) ? 'is-invalid' : '' ?>" 
                           id="site_code_edit" name="code" maxlength="20" required 
                           value="<?= htmlspecialchars($request->post('code', $site->code)) ?>"
                           oninput="this.value = this.value.toUpperCase()">
                    <small class="form-text text-muted">Identifiant unique. Sera converti en majuscules.</small>
                    <?php if (isset($errors['code'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['code']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="site_name_edit" class="form-label">Nom du Site : <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                           id="site_name_edit" name="name" maxlength="100" required 
                           value="<?= htmlspecialchars($request->post('name', $site->name)) ?>">
                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="site_address_edit" class="form-label">Adresse :</label>
                <textarea class="form-control" id="site_address_edit" name="address" rows="3"><?= htmlspecialchars($request->post('address', $site->address ?? '')) ?></textarea>
            </div>
            
            <div class="form-check mb-3">
                <?php
                    $isActiveCurrent = $site->is_active;
                    if ($request->getMethod() === 'POST' && $request->hasPost('code')) { // Si le formulaire a été soumis
                        $isActiveCurrent = $request->post('is_active') === 'on';
                    }
                ?>
                <input class="form-check-input" type="checkbox" id="site_is_active_edit" name="is_active" <?= $isActiveCurrent ? 'checked' : '' ?>>
                <label class="form-check-label" for="site_is_active_edit">
                    Site Actif
                </label>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mettre à jour le Site</button>
                <a href="<?= htmlspecialchars($baseUrl) ?>/sites" class="btn btn-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>