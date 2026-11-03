<?php
// src/Views/profile/index.php
// Variables attendues: $user (App\Models\User, rôles et site chargés), $errors, $request, $baseUrl
$errors = $errors ?? [];
$isInfoPost = $request->getMethod() === 'POST' && $request->hasPost('email');
$val = fn(string $field, ?string $current) => htmlspecialchars($isInfoPost ? (string)$request->post($field, '') : (string)($current ?? ''));
$fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
$initials = mb_strtoupper(mb_substr($user->first_name ?: $user->username, 0, 1) . mb_substr($user->last_name ?? '', 0, 1));
$avatarUrl = \App\Models\User::avatarUrl($user->id);
?>
<style>
    .profile-avatar { width: 96px; height: 96px; border-radius: 50%; background: #0d6efd; color: #fff; flex-shrink: 0;
                      display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 600; }
    img.profile-avatar { object-fit: cover; border: 2px solid #dee2e6; background: #fff; }
</style>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <?php if ($avatarUrl): ?>
                        <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Photo de profil" class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-bold fs-5"><?= htmlspecialchars($fullName !== '' ? $fullName : $user->username) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($user->username) ?></div>
                    </div>
                </div>
                <form action="<?= htmlspecialchars($baseUrl) ?>/profile/photo" method="post" enctype="multipart/form-data" class="mb-2">
                    <?= \App\Lib\Csrf::field() ?>
                    <label for="photo" class="form-label small mb-1">Photo de profil <span class="text-muted">(JPG, PNG ou WEBP, 2 Mo max)</span></label>
                    <div class="input-group input-group-sm">
                        <input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Envoyer</button>
                    </div>
                </form>
                <?php if ($avatarUrl): ?>
                    <form action="<?= htmlspecialchars($baseUrl) ?>/profile/photo/delete" method="post" class="mb-3" onsubmit="return confirm('Supprimer votre photo de profil ?');">
                        <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i> Supprimer la photo</button>
                    </form>
                <?php else: ?>
                    <div class="mb-3"></div>
                <?php endif; ?>
                <dl class="row small mb-0">
                    <dt class="col-5">Site</dt>
                    <dd class="col-7"><?= $user->site ? htmlspecialchars($user->site->name) . ' (' . htmlspecialchars($user->site->code) . ')' : 'Aucun (global)' ?></dd>
                    <dt class="col-5">Rôle(s)</dt>
                    <dd class="col-7">
                        <?php foreach ($user->roles as $role): ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($role->name) ?></span>
                        <?php endforeach; ?>
                        <?php if (!$user->roles): ?><span class="text-muted">Aucun</span><?php endif; ?>
                    </dd>
                    <dt class="col-5">Compte créé le</dt>
                    <dd class="col-7"><?= (new DateTime($user->created_at))->format('d/m/Y') ?></dd>
                </dl>
                <p class="text-muted small mt-3 mb-0"><i class="fas fa-info-circle me-1"></i>Le site et les rôles sont gérés par un administrateur.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-user me-1"></i> Mes informations</div>
            <div class="card-body">
                <form action="<?= htmlspecialchars($baseUrl) ?>/profile/update" method="post">
                    <?= \App\Lib\Csrf::field() ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">Prénom</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" maxlength="100" value="<?= $val('first_name', $user->first_name) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Nom</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100" value="<?= $val('last_name', $user->last_name) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" id="email" name="email" required value="<?= $val('email', $user->email) ?>">
                            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nom d'utilisateur</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user->username) ?>" disabled>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-key me-1"></i> Changer mon mot de passe</div>
            <div class="card-body">
                <form action="<?= htmlspecialchars($baseUrl) ?>/profile/password" method="post" autocomplete="off">
                    <?= \App\Lib\Csrf::field() ?>
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Mot de passe actuel <span class="text-danger">*</span></label>
                        <input type="password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" id="current_password" name="current_password" required autocomplete="current-password">
                        <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['current_password']) ?></div><?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Nouveau mot de passe <span class="text-danger">*</span></label>
                            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" id="password" name="password" required autocomplete="new-password">
                            <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
                            <small class="form-text text-muted">12 caractères minimum, avec une minuscule, une majuscule et un chiffre.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label">Confirmer le nouveau mot de passe <span class="text-danger">*</span></label>
                            <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            <?php if (isset($errors['confirm_password'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Changer le mot de passe</button>
                </form>
            </div>
        </div>
    </div>
</div>
