<?php
// src/Views/partials/_admin_sidebar.php
$baseUrl = $baseUrl ?? BASE_URL;
$appName = $appName ?? APP_NAME;

$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$appBasePath = parse_url(BASE_URL, PHP_URL_PATH);
$activePath = (strpos($currentPath, $appBasePath) === 0) ? substr($currentPath, strlen($appBasePath)) : $currentPath;
$activePath = '/' . trim($activePath, '/');

function isActiveLink(string $linkPath, string $currentActivePath, bool $exactMatch = false): string {
    $linkPath = '/' . trim($linkPath, '/');
    if ($exactMatch) return ($linkPath === $currentActivePath) ? 'active' : '';
    return (str_starts_with($currentActivePath, $linkPath) && $linkPath !== '/') || ($linkPath === '/' && $currentActivePath === '/') ? 'active' : '';
}
?>
<aside class="admin-sidebar">
    <div class="sidebar-header">
        <a href="<?= htmlspecialchars($baseUrl) ?>/" class="app-logo"><?= htmlspecialchars($appName) ?></a>
    </div>
    <nav class="sidebar-nav">
        <ul>
            <?php if (App\Lib\Auth::isLoggedIn()): ?>
                <?php if (App\Lib\Auth::can('dashboard_view')): ?>
                <li>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/" class="<?= isActiveLink('/', $activePath, true) ?>">
                        <i class="fas fa-tachometer-alt"></i> <span>Tableau de bord</span>
                    </a>
                </li>
                <?php endif; ?>

                <?php // Section Administration - visible si au moins une permission de gestion est présente ?>
                <?php if (
                    App\Lib\Auth::can('user_manage') || App\Lib\Auth::can('role_manage') ||
                    App\Lib\Auth::can('permission_manage') || App\Lib\Auth::can('site_manage')
                ): ?>
                    <li class="nav-section-title">Administration</li>
                    <?php if (App\Lib\Auth::can('user_manage')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/users" class="<?= isActiveLink('/users', $activePath) ?>"><i class="fas fa-users"></i> <span>Utilisateurs</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('role_manage')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/roles" class="<?= isActiveLink('/roles', $activePath) ?>"><i class="fas fa-user-tag"></i> <span>Rôles</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('permission_manage')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/permissions" class="<?= isActiveLink('/permissions', $activePath) ?>"><i class="fas fa-shield-alt"></i> <span>Permissions</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('site_manage')): // NOUVEAU LIEN ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/sites" class="<?= isActiveLink('/sites', $activePath) ?>"><i class="fas fa-industry"></i> <span>Gestion des Sites</span></a></li>
                    <?php endif; ?>
            <?php endif; ?>
                    
                <?php // Section Gestion Colisage - visible si au moins une permission de cette section est présente ?>
                <?php if (
                    App\Lib\Auth::can('packinglist_read_all') || App\Lib\Auth::can('palette_read_all') ||
                    App\Lib\Auth::can('colis_scan') ||
                    App\Lib\Auth::can('theoretical_view_deviations') || App\Lib\Auth::can('order_blc_manage')
                ): ?>
                    <li class="nav-section-title">Gestion Colisage</li>
                    <?php if (App\Lib\Auth::can('packinglist_read_all')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="<?= isActiveLink('/packing-lists', $activePath) ?>"><i class="fas fa-list-alt"></i> <span>Packing Lists</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('palette_read_all')): // Perm pour la liste globale des palettes ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/palettes" class="<?= isActiveLink('/palettes', $activePath) ?>"><i class="fas fa-pallet"></i> <span>Toutes les Palettes</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('colis_scan')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan" class="<?= isActiveLink('/colis/scan', $activePath) ?>"><i class="fas fa-qrcode"></i> <span>Scan Colis</span></a></li>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/search" class="<?= isActiveLink('/search', $activePath) ?>"><i class="fas fa-search"></i> <span>Recherche</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('theoretical_view_deviations')): // ou theoretical_import_csv pour le menu ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/theoretical-exports" class="<?= isActiveLink('/theoretical-exports', $activePath) ?>"><i class="fas fa-file-import"></i> <span>Import/Suivi Théorique</span></a></li>
                    <?php endif; ?>
                    <?php if (App\Lib\Auth::can('order_blc_manage')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/commandes-blc" class="<?= isActiveLink('/commandes-blc', $activePath) ?>"><i class="fas fa-file-invoice"></i> <span>BLC AMF commandes</span></a></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (App\Lib\Auth::can('reception_view') || App\Lib\Auth::can('reception_scan')): ?>
                    <li class="nav-section-title">Réception</li>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/receptions" class="<?= isActiveLink('/receptions', $activePath) ?>"><i class="fas fa-truck-loading"></i> <span>Réceptions</span></a></li>
                    <?php if (App\Lib\Auth::can('reception_view')): ?>
                        <li><a href="<?= htmlspecialchars($baseUrl) ?>/receptions/client-packing" class="<?= isActiveLink('/receptions/client-packing', $activePath) ?>"><i class="fas fa-boxes"></i> <span>Colisage client</span></a></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php // Paramètres et maintenance en bas de la liste ?>
                <?php $canMaintenance = App\Lib\Auth::hasRole(App\Lib\Maintenance::ADMIN_ROLE); ?>
                <?php if (App\Lib\Auth::can('settings_manage') || $canMaintenance): ?>
                    <li class="nav-section-title">Système</li>
                    <?php if (App\Lib\Auth::can('settings_manage')): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/settings" class="<?= isActiveLink('/settings', $activePath) ?>"><i class="fas fa-cogs"></i> <span>Paramètres</span></a></li>
                    <?php endif; ?>
                    <?php if ($canMaintenance): ?>
                    <li><a href="<?= htmlspecialchars($baseUrl) ?>/maintenance" class="<?= isActiveLink('/maintenance', $activePath) ?>"><i class="fas fa-tools"></i> <span>Maintenance</span>
                        <?php if (App\Lib\Maintenance::isActive()): ?><span class="badge bg-warning text-dark ms-auto">ON</span><?php endif; ?></a></li>
                    <?php endif; ?>
                <?php endif; ?>

            <?php endif; // Fin de isLoggedIn ?>
        </ul>
    </nav>
    <?php // Connecté : profil et déconnexion sont dans le menu en haut à droite ?>
    <?php if (!App\Lib\Auth::isLoggedIn()): ?>
    <div class="sidebar-footer">
        <a href="<?= htmlspecialchars($baseUrl) ?>/login" title="Se connecter">
            <i class="fas fa-sign-in-alt"></i> <span>Connexion</span>
        </a>
    </div>
    <?php endif; ?>
</aside>