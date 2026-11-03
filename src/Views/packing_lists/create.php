<?php
// src/Views/packing_lists/create.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request
// NOUVEAU: $availableSites (sites pour lesquels l'user peut créer), $pl_data (peut contenir 'site_id' par défaut)
// $errors (optionnel), $db_error (optionnel)

use App\Lib\Auth;
use App\Models\Site as SiteModel; // Pour le type hinting

?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/store" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="ship_date" class="form-label">Date d'expédition : <span class="text-danger">*</span></label>
            <input type="date" class="form-control <?= isset($errors['ship_date']) ? 'is-invalid' : '' ?>" 
                   id="ship_date" name="ship_date" required 
                   value="<?= htmlspecialchars($request->post('ship_date', $pl_data['ship_date'] ?? date('Y-m-d'))) ?>">
            <?php if (isset($errors['ship_date'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['ship_date']) ?></div><?php endif; ?>
        </div>

        <div class="col-md-6 mb-3">
            <label for="site_id_pl_create" class="form-label">Site pour la Packing List : <span class="text-danger">*</span></label>
            <select class="form-select <?= isset($errors['site_id']) ? 'is-invalid' : '' ?>" 
                    id="site_id_pl_create" name="site_id" required
                    <?php // Désactiver si un seul site est disponible et pré-sélectionné
                        if (isset($availableSites) && count($availableSites) === 1 && isset($pl_data['site_id']) && $pl_data['site_id'] === $availableSites[0]->id) {
                            // echo 'disabled'; // Attention: les champs disabled ne sont pas envoyés par POST.
                            // Il faudrait un champ hidden si on le désactive, ou le laisser modifiable pour un admin.
                            // Pour un utilisateur non-admin lié à un site, le contrôleur forcera son site_id de toute façon.
                        }
                    ?>>
                <?php if (isset($availableSites) && count($availableSites) > 1): // Si plusieurs sites, proposer un choix ?>
                    <option value="">-- Sélectionner un Site --</option>
                <?php endif; ?>

                <?php if (isset($availableSites) && !empty($availableSites)): ?>
                    <?php foreach ($availableSites as $site): ?>
                        <?php if (!$site instanceof SiteModel) continue; ?>
                        <?php
                            $selected = false;
                            if (isset($pl_data['site_id']) && $pl_data['site_id'] == $site->id) {
                                $selected = true;
                            } elseif ($request->post('site_id') == $site->id) {
                                $selected = true;
                            }
                        ?>
                        <option value="<?= $site->id ?>" <?= $selected ? 'selected' : '' ?>>
                            <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                        </option>
                    <?php endforeach; ?>
                <?php elseif(isset($availableSites) && empty($availableSites)): ?>
                     <option value="">Aucun site actif disponible</option>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['site_id'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['site_id']) ?></div><?php endif; ?>
            <?php if (isset($availableSites) && count($availableSites) === 1 && isset($pl_data['site_id']) && $pl_data['site_id'] === $availableSites[0]->id): ?>
                <small class="form-text text-muted">Site assigné automatiquement.</small>
                <!-- Si désactivé, il faut un champ hidden pour envoyer la valeur :
                <input type="hidden" name="site_id" value="<?= $pl_data['site_id'] ?>">
                -->
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Vous pouvez ajouter d'autres champs ici si nécessaire pour la création d'une PL -->
    <!-- Par exemple, un champ pour un client, une destination, des notes, etc. -->
    <!-- 
    <div class="form-group mb-3">
        <label for="pl_notes" class="form-label">Notes :</label>
        <textarea class="form-control" id="pl_notes" name="notes" rows="3"><?= htmlspecialchars($request->post('notes', $pl_data['notes'] ?? '')) ?></textarea>
    </div>
    -->

    <div class="form-group mt-4">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Créer la Packing List
        </button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-secondary">Annuler</a>
    </div>
</form>