<?php
// src/Views/layouts/admin_app.php
$baseUrl = $baseUrl ?? BASE_URL;
$appName = $appName ?? APP_NAME;
$pageTitle = $pageTitle ?? 'Application';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Lib\Csrf::token(), ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($appName) ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- DataTables CSS Core -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- DataTables Buttons CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <!-- DataTables Responsive CSS (si vous l'utilisez) -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">
    
    <!-- Styles globaux -->
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/css/style.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/css/admin_layout.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <?php if (isset($pageStyles) && is_array($pageStyles)): ?>
        <?php foreach ($pageStyles as $style): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/css/<?= htmlspecialchars($style) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <style>
        /* Pour que les boutons DataTables s'alignent bien avec les autres éléments */
        .dt-buttons .btn-secondary { margin-bottom: 0.5rem; }
        
        /* ===== CORRECTION BOUTONS DATATABLES (lisibilité) ===== */
        .dt-buttons .btn-outline-secondary,
        .dt-buttons .btn-secondary {
            color: #333 !important;
            background-color: #f0f0f0 !important;
            border-color: #ced4da !important;
        }
        .dt-buttons .btn-outline-secondary:hover,
        .dt-buttons .btn-secondary:hover {
            color: #000 !important;
            background-color: #e2e6ea !important;
            border-color: #adb5bd !important;
        }
        .dt-buttons .btn-outline-secondary:focus,
        .dt-buttons .btn-secondary:focus {
            color: #333 !important;
            background-color: #d4dae0 !important;
            border-color: #adb5bd !important;
        }
        .dt-buttons .btn-outline-success {
            color: #155724 !important;
            border-color: #c3e6cb !important;
        }
        .dt-buttons .btn-outline-danger {
            color: #721c24 !important;
            border-color: #f5c6cb !important;
        }
        .dt-buttons .btn-outline-warning {
            color: #856404 !important;
            border-color: #ffeeba !important;
        }
        .dt-buttons .btn-outline-info {
            color: #0c5460 !important;
            border-color: #bee5eb !important;
        }
        .dt-buttons .btn {
            font-weight: 500 !important;
            padding: 0.25rem 0.5rem !important;
            font-size: 0.8rem !important;
        }
        .dt-button-collection .dropdown-menu {
            min-width: 220px !important;
        }
        .dt-button-collection .dropdown-menu .dt-button {
            padding: 6px 16px !important;
            color: #333 !important;
            background: white !important;
            border: none !important;
            border-radius: 0 !important;
        }
        .dt-button-collection .dropdown-menu .dt-button:hover {
            background: #f0f4ff !important;
            color: #0d6efd !important;
        }
        
        /* ===== BARRE DE RECHERCHE GLOBALE ===== */
        .global-search-wrapper {
            position: relative;
            max-width: 400px;
            width: 100%;
        }
        .global-search-wrapper .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            z-index: 5;
            pointer-events: none;
        }
        .global-search-input {
            padding-left: 38px !important;
            padding-right: 30px !important;
            border-radius: 20px !important;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        .global-search-input:focus {
            background: white;
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
            width: 100%;
        }
        .global-search-input::placeholder {
            color: #adb5bd;
        }
        .global-search-shortcut {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.65rem;
            color: #adb5bd;
            background: #e9ecef;
            padding: 1px 6px;
            border-radius: 4px;
            pointer-events: none;
            z-index: 5;
        }

        /* Dropdown résultats */
        .global-search-results {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            z-index: 9999;
            max-height: 600px;
            overflow-y: auto;
        }
        .global-search-results.open { display: block; }
        .global-search-results .result-section {
            padding: 8px 12px 4px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            font-weight: 600;
        }
        .global-search-results .result-empty {
            padding: 24px 16px;
            text-align: center;
            color: #adb5bd;
            font-size: 0.9rem;
        }
        .global-search-results .result-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.15s;
            gap: 10px;
            text-decoration: none;
            color: inherit;
            border-left: 3px solid transparent;
        }
        .global-search-results .result-item:hover,
        .global-search-results .result-item.highlighted {
            background: #f0f4ff;
            border-left-color: #0d6efd;
        }
        .global-search-results .result-item .result-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.8rem;
        }
        .result-icon-colis { background: #e8f5e9; color: #2e7d32; }
        .result-icon-palette { background: #e3f2fd; color: #1565c0; }
        .result-icon-pl { background: #fff3e0; color: #e65100; }
        .global-search-results .result-item .result-info {
            flex: 1;
            min-width: 0;
        }
        .global-search-results .result-item .result-title {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .global-search-results .result-item .result-subtitle {
            font-size: 0.75rem;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .global-search-results .result-item .result-badge {
            font-size: 0.65rem;
            padding: 1px 6px;
            border-radius: 10px;
            flex-shrink: 0;
        }
        .global-search-results .result-footer {
            padding: 10px 12px;
            text-align: center;
            border-top: 1px solid #eee;
        }
        .global-search-results .result-footer a {
            font-size: 0.8rem;
            color: #0d6efd;
        }
        .admin-main-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .admin-main-header h1 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        /* ===== FIX SCROLL HORIZONTAL PAGES ===== */
        .admin-page-content {
            overflow-x: hidden;
        }
        .admin-main-content {
            overflow-x: hidden;
            max-width: 100%;
        }
        .table-responsive {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Empêcher les tables de déborder de leur conteneur */
        .card .table-responsive table.dataTable {
            width: auto !important;
            max-width: 100%;
        }
        .card .table-responsive .dataTables_wrapper {
            max-width: 100%;
            overflow-x: auto;
        }
        /* Fix pour les modales */
        .modal-dialog {
            max-width: 500px;
            margin: 1.75rem auto;
        }
        @media (max-width: 768px) {
            .modal-dialog {
                margin: 0.5rem;
                max-width: calc(100vw - 1rem);
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include VIEWS_PATH . '/partials/_admin_sidebar.php'; ?>
        <main class="admin-main-content">
            <header class="admin-main-header">
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                
                <!-- Barre de recherche globale persistante -->
                <?php if (\App\Lib\Auth::isLoggedIn()): ?>
                <div class="global-search-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="form-control global-search-input" 
                           id="globalSearchInput" 
                           placeholder="Rechercher colis, palette, PL..." 
                           autocomplete="off">
                    <span class="global-search-shortcut"><?php if (preg_match('/Mac/i', $_SERVER['HTTP_USER_AGENT'] ?? '')): ?>⌘K<?php else: ?>Ctrl+K<?php endif; ?></span>
                    
                    <!-- Dropdown résultats -->
                    <div class="global-search-results" id="globalSearchResults">
                        <div class="result-empty" id="globalSearchEmpty">
                            <i class="fas fa-search fa-2x mb-2 d-block"></i>
                            Tapez au moins 2 caractères pour rechercher
                        </div>
                        <div id="globalSearchContent"></div>
                    </div>
                </div>
                <?php endif; ?>
            </header>
            <div class="admin-page-content">
                <!-- Messages Flash (gérés par le layout) -->
                <?php
                if (session_status() === PHP_SESSION_NONE) { session_start(); }
                $flashTypes = [
                    ['key' => FLASH_SUCCESS, 'class' => 'flash-success', 'style' => 'background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb;'],
                    ['key' => FLASH_ERROR, 'class' => 'flash-error', 'style' => 'background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'],
                    ['key' => FLASH_INFO, 'class' => 'flash-info', 'style' => 'background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb;'],
                    ['key' => FLASH_WARNING, 'class' => 'flash-warning', 'style' => 'background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba;']
                ];
                foreach ($flashTypes as $flash) {
                    if (isset($_SESSION[$flash['key']])) {
                        echo '<div class="alert alert-'.str_replace('flash-','', $flash['class']).' alert-dismissible fade show" role="alert" style="'.htmlspecialchars($flash['style']).'">'.htmlspecialchars($_SESSION[$flash['key']]).'<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                        unset($_SESSION[$flash['key']]);
                    }
                }
                ?>
                <?= $content ?? 'Contenu non défini.' ?>
            </div>
            <footer class="admin-main-footer"><p>© <?= date('Y') ?> <?= htmlspecialchars($appName) ?>. Tous droits réservés.</p></footer>
        </main>
    </div>

    <!-- 1. jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    
    <!-- 2. Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- 3. DataTables JS Core -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- 4. DataTables Buttons JS et ses dépendances -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script> <!-- Pour l'export Excel (XLSX) -->
    <script type="text/javascript" charset="utf8" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script> <!-- Pour l'export PDF -->
    <script type="text/javascript" charset="utf8" src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script> <!-- Polices pour pdfmake -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script> <!-- Pour les boutons CSV, Excel, PDF -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script> <!-- Pour le bouton Print -->
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script> <!-- Pour la visibilité des colonnes (optionnel) -->

    <!-- 5. DataTables Responsive JS (si vous l'utilisez) -->
    <script type="text/javascript" src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>
    
    <script>const baseUrl = "<?= htmlspecialchars($baseUrl) ?>";</script>
    <!-- 6. Votre script JS personnalisé -->
    <script src="<?= htmlspecialchars($baseUrl) ?>/js/app.js"></script>

    <?php if (isset($pageScripts) && is_array($pageScripts)): ?>
        <?php foreach ($pageScripts as $script): ?>
            <script src="<?= htmlspecialchars($baseUrl) ?>/js/<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>