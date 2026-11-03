<?php
// src/Views/users/create.php
// Variables attendues du contrôleur :
// $pageTitle : string
// $baseUrl : string
// $appName : string
// $request : App\Lib\Request (pour $request->post() en cas d'erreur et de pré-remplissage)
// $allRoles : array (d'objets App\Models\Role)
// $allSites : array (d'objets App\Models\Site)
// $user_data : array (pour les valeurs par défaut/précédemment saisies, ex: $user_data['username'], $user_data['site_id'])
// $errors : array (optionnel, pour les messages d'erreur par champ)
// $db_error : string (optionnel, pour une erreur globale de BDD)

use App\Lib\Auth; // Pour des vérifications de permission si besoin dans la vue (généralement pas ici)
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<form action="<?= htmlspecialchars($baseUrl) ?>/users/store" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="username" class="form-label">Nom d'utilisateur : <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" 
                   id="username" name="username" required 
                   value="<?= htmlspecialchars($user_data['username'] ?? $request->post('username', '')) ?>">
            <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['username']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
            <label for="email" class="form-label">Email : <span class="text-danger">*</span></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                   id="email" name="email" required 
                   value="<?= htmlspecialchars($user_data['email'] ?? $request->post('email', '')) ?>">
            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="password" class="form-label">Mot de passe : <span class="text-danger">*</span></label>
            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                   id="password" name="password" required>
            <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
            <label for="confirm_password" class="form-label">Confirmer le mot de passe : <span class="text-danger">*</span></label>
            <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                   id="confirm_password" name="confirm_password" required>
            <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="first_name" class="form-label">Prénom :</label>
            <input type="text" class="form-control" id="first_name" name="first_name" 
                   value="<?= htmlspecialchars($user_data['first_name'] ?? $request->post('first_name', '')) ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="last_name" class="form-label">Nom :</label>
            <input type="text" class="form-control" id="last_name" name="last_name" 
                   value="<?= htmlspecialchars($user_data['last_name'] ?? $request->post('last_name', '')) ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="site_id" class="form-label">Site de rattachement :</label>
            <select class="form-select <?= isset($errors['site_id']) ? 'is-invalid' : '' ?>" id="site_id" name="site_id">
                <option value="">-- Aucun site (Global) --</option>
                <?php if (isset($allSites) && !empty($allSites)): ?>
                    <?php foreach ($allSites as $site): ?>
                        <option value="<?= $site->id ?>" 
                                <?= (isset($user_data['site_id']) && $user_data['site_id'] == $site->id) ? 'selected' : (($request->post('site_id') == $site->id) ? 'selected' : '') ?>>
                            <?= htmlspecialchars($site->name) ?> (<?= htmlspecialchars($site->code) ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['site_id'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['site_id']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6 mb-3 align-self-center">
            <div class="form-check mt-3">
                <?php 
                    // Gérer la valeur cochée : 
                    // 1. Valeur postée (si le formulaire a été soumis avec erreurs)
                    // 2. Valeur de $user_data (si c'est un pré-remplissage pour la création, souvent true par défaut)
                    // 3. Par défaut à true si rien d'autre n'est défini
                    $isActiveChecked = true; // Valeur par défaut pour la création
                    if ($request->getMethod() === 'POST' && $request->hasPost('username')) { // username est un champ toujours présent
                        $isActiveChecked = $request->post('is_active') === 'on';
                    } elseif (isset($user_data['is_active'])) {
                        $isActiveChecked = (bool)$user_data['is_active'];
                    }
                ?>
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= $isActiveChecked ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">
                    Compte Actif
                </label>
            </div>
        </div>
    </div>

    <div class="form-group mb-3">
        <label class="form-label">Rôles :</label>
        <?php if (isset($allRoles) && !empty($allRoles)): ?>
            <div class="border p-2 rounded bg-light" style="max-height: 150px; overflow-y: auto;">
            <?php foreach ($allRoles as $role): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="roles[]" id="role_<?= $role->id ?>" value="<?= $role->id ?>"
                        <?= (in_array($role->id, $user_data['role_ids'] ?? $request->post('roles', []))) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="role_<?= $role->id ?>"><?= htmlspecialchars($role->name) ?></label>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">Aucun rôle disponible.</p>
        <?php endif; ?>
    </div>

    <div class="form-group mt-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Créer l'utilisateur</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/users" class="btn btn-secondary">Annuler</a>
    </div>
</form>