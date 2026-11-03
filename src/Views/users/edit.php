<?php
// src/Views/users/edit.php
// Variables attendues du contrôleur :
// $pageTitle : string
// $baseUrl : string
// $appName : string
// $user : App\Models\User (l'objet utilisateur à éditer)
// $request : App\Lib\Request
// $allRoles : array (d'objets App\Models\Role)
// $assignedRoleIds : array (IDs des rôles actuellement assignés à l'utilisateur)
// $allSites : array (d'objets App\Models\Site)
// $errors : array (optionnel)
// $db_error : string (optionnel)

use App\Lib\Auth;
use App\Models\User as UserModel;

// Sécurité : Vérifier si $user est bien un objet valide
if (!isset($user) || !$user instanceof UserModel) {
    echo "<div class='alert alert-danger'>Erreur: Les données de l'utilisateur n'ont pas pu être chargées.</div>";
    if (isset($baseUrl)) {
        echo "<a href='" . htmlspecialchars($baseUrl) . "/users' class='btn btn-secondary mt-2'>Retour à la liste</a>";
    }
    return; // Arrêter le rendu si $user n'est pas valide
}
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>

<form action="<?= htmlspecialchars($baseUrl) ?>/users/update/<?= $user->id ?>" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="username" class="form-label">Nom d'utilisateur : <span class="text-danger">*</span></label>
            <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" 
                   id="username" name="username" required 
                   value="<?= htmlspecialchars($request->post('username', $user->username)) ?>">
            <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['username']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
            <label for="email" class="form-label">Email : <span class="text-danger">*</span></label>
            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                   id="email" name="email" required 
                   value="<?= htmlspecialchars($request->post('email', $user->email)) ?>">
            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="password" class="form-label">Nouveau mot de passe :</label>
            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                   id="password" name="password">
            <small class="form-text text-muted">Laissez vide pour conserver le mot de passe actuel.</small>
            <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
            <label for="confirm_password" class="form-label">Confirmer le nouveau mot de passe :</label>
            <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                   id="confirm_password" name="confirm_password">
            <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="first_name" class="form-label">Prénom :</label>
            <input type="text" class="form-control" id="first_name" name="first_name" 
                   value="<?= htmlspecialchars($request->post('first_name', $user->first_name ?? '')) ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label for="last_name" class="form-label">Nom :</label>
            <input type="text" class="form-control" id="last_name" name="last_name" 
                   value="<?= htmlspecialchars($request->post('last_name', $user->last_name ?? '')) ?>">
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6 mb-3">
            <label for="site_id_edit" class="form-label">Site de rattachement :</label>
            <select class="form-select <?= isset($errors['site_id']) ? 'is-invalid' : '' ?>" id="site_id_edit" name="site_id">
                <option value="">-- Aucun site (Global) --</option>
                <?php if (isset($allSites) && !empty($allSites)): ?>
                    <?php foreach ($allSites as $site): ?>
                        <?php
                            // Déterminer la valeur sélectionnée :
                            // 1. Valeur postée (si le formulaire a été soumis avec erreurs)
                            // 2. Valeur actuelle de l'utilisateur ($user->site_id)
                            $selectedSiteId = $request->post('site_id', $user->site_id);
                        ?>
                        <option value="<?= $site->id ?>" <?= ($selectedSiteId == $site->id) ? 'selected' : '' ?>>
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
                    // 1. Valeur postée (si le formulaire a été soumis avec erreurs et que 'is_active' était un champ soumis)
                    // 2. Valeur actuelle de l'utilisateur ($user->is_active)
                    $isActiveChecked = $user->is_active; // Valeur par défaut issue de l'objet user
                    if ($request->getMethod() === 'POST' && $request->hasPost('username')) { // Vérifier si le formulaire a été soumis (username est un champ toujours présent)
                        // Pour une checkbox, si elle n'est pas cochée, elle n'est pas envoyée dans POST.
                        // Donc, on vérifie si 'is_active' est présent dans POST pour coché, sinon c'est non coché.
                        $isActiveChecked = $request->post('is_active') === 'on';
                    }
                ?>
                <input class="form-check-input" type="checkbox" id="is_active_edit" name="is_active" <?= $isActiveChecked ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active_edit">
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
                    <input class="form-check-input" type="checkbox" name="roles[]" id="role_edit_<?= $role->id ?>" value="<?= $role->id ?>"
                        <?= (in_array($role->id, $request->post('roles', $assignedRoleIds ?? []))) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="role_edit_<?= $role->id ?>"><?= htmlspecialchars($role->name) ?></label>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">Aucun rôle disponible.</p>
        <?php endif; ?>
    </div>

    <div class="form-group mt-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mettre à jour l'utilisateur</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/users" class="btn btn-secondary">Annuler</a>
    </div>
</form>