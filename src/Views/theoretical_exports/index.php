<?php
// src/Views/theoretical_exports/index.php
// Variables: $pageTitle, $baseUrl, $appName
use App\Lib\Auth;
?>
<div class="list-group">
    <?php if (Auth::can('theoretical_import_csv')): ?>
        <a href="<?= htmlspecialchars($baseUrl) ?>/theoretical-exports/import" class="list-group-item list-group-item-action">
            <i class="fas fa-file-upload fa-fw me-2"></i>Importer un Fichier Théorique
        </a>
    <?php endif; ?>
    <?php if (Auth::can('theoretical_view_deviations')): ?>
        <a href="<?= htmlspecialchars($baseUrl) ?>/theoretical-exports/deviations" class="list-group-item list-group-item-action">
            <i class="fas fa-balance-scale fa-fw me-2"></i>Suivi des Écarts Théorique vs. Scanné
        </a>
    <?php endif; ?>
    <?php // Ajoutez d'autres liens si ce module s'étoffe ?>
</div>

<?php if (!Auth::can('theoretical_import_csv') && !Auth::can('theoretical_view_deviations')): ?>
    <div class="alert alert-warning">Vous n'avez pas les permissions nécessaires pour accéder aux fonctionnalités de ce module.</div>
<?php endif; ?>