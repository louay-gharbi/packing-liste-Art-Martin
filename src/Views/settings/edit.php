<?php
// src/Views/settings/edit.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $settings (array clé=>valeur), $settingDescriptions (array clé=>description), $request
?>

<form action="<?= htmlspecialchars($baseUrl) ?>/settings/update" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <?php if (empty($settings)): ?>
        <div class="alert alert-warning">Aucun paramètre à configurer pour le moment.</div>
    <?php else: ?>
        <?php foreach ($settings as $key => $value): ?>
            <div class="form-group mb-4">
                <label for="setting_<?= htmlspecialchars($key) ?>" class="form-label fw-bold">
                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) // Transforme packing_list_pattern en Packing List Pattern ?>
                </label>
                
                <?php
                // Déterminer le type de champ en fonction de la clé ou d'un type prédéfini
                $inputType = "text";
                $rows = 1;
                if (str_contains($key, '_pattern')) {
                    $inputType = "textarea";
                    $rows = 3;
                } elseif (is_numeric($value) && !str_contains($key, '_pattern')) {
                    // $inputType = "number"; // Attention avec les grands nombres ou formats spécifiques
                }
                // Vous pouvez ajouter d'autres logiques pour des types de champs spécifiques (checkbox, select, etc.)
                ?>

                <?php if ($inputType === "textarea"): ?>
                    <textarea class="form-control" 
                              id="setting_<?= htmlspecialchars($key) ?>" 
                              name="<?= htmlspecialchars($key) ?>" 
                              rows="<?= $rows ?>"><?= htmlspecialchars($value ?? '') ?></textarea>
                <?php else: ?>
                    <input type="<?= $inputType ?>" 
                           class="form-control" 
                           id="setting_<?= htmlspecialchars($key) ?>" 
                           name="<?= htmlspecialchars($key) ?>" 
                           value="<?= htmlspecialchars($value ?? '') ?>">
                <?php endif; ?>

                <?php if (isset($settingDescriptions[$key])): ?>
                    <small class="form-text text-muted"><?= htmlspecialchars($settingDescriptions[$key]) ?></small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-group mt-4">
            <button type="submit" class="btn btn-primary">Enregistrer les paramètres</button>
            <a href="<?= htmlspecialchars($baseUrl) ?>/" class="btn btn-secondary">Annuler</a>
        </div>
    <?php endif; ?>
</form>