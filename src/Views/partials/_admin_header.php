<?php
// src/Views/partials/_admin_header.php
// S'assurer que les constantes sont disponibles
if (session_status() === PHP_SESSION_NONE) { session_start(); } // Pour Auth et messages flash
// require_once dirname(dirname(dirname(__DIR__))) . '/config/config.php'; // Si besoin des constantes APP_NAME, BASE_URL
// $baseUrl = $baseUrl ?? BASE_URL; // Assurer que $baseUrl est défini
// $appName = $appName ?? APP_NAME; // Assurer que $appName est défini
?>
<header class="app-header" style="background-color: #343a40; color: white; padding: 10px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
    <div>
        <strong><?= htmlspecialchars($appName ?? 'Admin Panel') ?></strong>
        <nav style="display: inline-block; margin-left: 20px;">
            <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/" style="color: white; text-decoration: none; margin-right: 15px;">Tableau de bord</a>
            <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/users" style="color: white; text-decoration: none; margin-right: 15px;">Utilisateurs</a>
            <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/roles" style="color: white; text-decoration: none; margin-right: 15px;">Rôles</a>
            <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/permissions" style="color: white; text-decoration: none; margin-right: 15px;">Permissions</a>
            <!-- Ajouter d'autres liens d'administration ici -->
        </nav>
    </div>
    <div class="user-info" style="font-size: 0.9em;">
        <?php if (App\Lib\Auth::isLoggedIn()): ?>
            Connecté : <strong><?= htmlspecialchars(App\Lib\Auth::user()->username ?? '') ?></strong>
            | <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/logout" style="color: #ffc107; text-decoration: none;">Déconnexion</a>
        <?php else: ?>
            <a href="<?= htmlspecialchars($baseUrl ?? '/') ?>/login" style="color: white; text-decoration: none;">Se connecter</a>
        <?php endif; ?>
    </div>
</header>