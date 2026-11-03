<?php
// public/index.php

// A METTRE TOUT EN HAUT DU FICHIER (avant TOUTE sortie)

// 1. Charger l'autoloader de Composer
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 2. Charger la configuration (aucune sortie HTML ne doit être émise ici).
require_once dirname(__DIR__) . '/config/config.php';

// 3. Configurer puis démarrer la session AVANT toute sortie HTML.
// Les paramètres de cookie (HttpOnly, Secure, SameSite) doivent être appliqués
// avant session_start, sinon les flags ne seront pas portés par le Set-Cookie.
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => SESSION_SECURE,
        'httponly' => SESSION_HTTP_ONLY,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 4. Charger la configuration BDD.
require_once CONFIG_PATH . '/database.php';

use App\Controllers\DashboardController;
use App\Lib\Csrf;
use App\Lib\Request;
use App\Lib\Response;
use App\Lib\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\RoleController;
use App\Controllers\PermissionController;
use App\Controllers\SettingController;
use App\Controllers\PackingListController;
use App\Controllers\PaletteController;
use App\Controllers\ColisController;
use App\Controllers\TheoreticalExportController;
use App\Controllers\SearchController;
use App\Controllers\SiteController;

// error_log("DEBUG 6: Use statements processed");

// 3. Initialiser l'objet Request
$request = new Request();
// error_log("DEBUG 7: Request object created");

// 4. Initialiser le Router
$appUrlPath = parse_url(APP_URL, PHP_URL_PATH);
$router = new Router($appUrlPath ?: '');
// error_log("DEBUG 8: Router object created");

// 5. Définir les routes
// NOUVELLE ROUTE D'ACCUEIL POINTANT VERS LE DASHBOARDCONTROLLER
$router->get('/', [DashboardController::class, 'index']); 

// --- ROUTES POUR LA GESTION DES CONNEXTIONS ---
$router->get('/login', [AuthController::class, 'showLoginForm']); // Afficher le formulaire
$router->post('/login', [AuthController::class, 'login']);        // Traiter la soumission du formulaire
$router->get('/logout', [AuthController::class, 'logout']);        // Déconnexion

// --- ROUTES POUR LA GESTION DES USERS ---
$router->get('/users', [UserController::class, 'index']);          // Lister les utilisateurs
$router->get('/users/create', [UserController::class, 'create']);  // Afficher le formulaire de création
$router->post('/users/store', [UserController::class, 'store']);   // Enregistrer le nouvel utilisateur
$router->get('/users/edit/{id}', [UserController::class, 'edit']);    // Afficher le formulaire d'édition {id} est un paramètre
$router->post('/users/update/{id}', [UserController::class, 'update']);// Traiter la mise à jour de l'utilisateur {id}
$router->post('/users/delete/{id}', [UserController::class, 'delete']); // Supprimer l'utilisateur {id} (méthode POST)

// --- ROUTES POUR LA GESTION DES ROLES ---
$router->get('/roles', [RoleController::class, 'index']);
$router->get('/roles/create', [RoleController::class, 'create']);
$router->post('/roles/store', [RoleController::class, 'store']);
$router->get('/roles/edit/{id}', [RoleController::class, 'edit']);
$router->post('/roles/update/{id}', [RoleController::class, 'update']);
$router->post('/roles/delete/{id}', [RoleController::class, 'delete']); // Utiliser POST pour la suppression

// --- ROUTES POUR LA GESTION DES PERMISSIONS ---
$router->get('/permissions', [PermissionController::class, 'index']);
$router->get('/permissions/create', [PermissionController::class, 'create']);
$router->post('/permissions/store', [PermissionController::class, 'store']);
$router->get('/permissions/edit/{id}', [PermissionController::class, 'edit']);
$router->post('/permissions/update/{id}', [PermissionController::class, 'update']);
$router->post('/permissions/delete/{id}', [PermissionController::class, 'delete']);

// --- ROUTE POUR LES PARAMÈTRES ---
$router->get('/settings', [SettingController::class, 'index']);   // Afficher le formulaire d'édition
$router->post('/settings/update', [SettingController::class, 'update']); // Traiter la mise à jour

// --- ROUTES POUR LES PACKING LISTS ---
$router->get('/packing-lists', [PackingListController::class, 'index']);
$router->get('/packing-lists/create', [PackingListController::class, 'create']);
$router->post('/packing-lists/store', [PackingListController::class, 'store']);
$router->get('/packing-lists/show/{id}', [PackingListController::class, 'show']); // {id} doit être un entier
$router->get('/packing-lists/edit/{id}', [PackingListController::class, 'edit']);
$router->post('/packing-lists/update/{id}', [PackingListController::class, 'update']); // Mise à jour date exp.
$router->post('/packing-lists/close/{id}', [PackingListController::class, 'closePl']); // Action de fermeture
$router->post('/packing-lists/delete/{id}', [PackingListController::class, 'deletePl']); // Action de suppression
$router->post('/packing-lists/{packingListId}/palettes/generate-multiple', [PackingListController::class, 'generateMultiplePalettes']);
$router->post('/packing-lists/reopen/{id}', [PackingListController::class, 'reopenPl']);
$router->get('/packing-lists/{packingListId}/export-colis/xlsx', [PackingListController::class, 'exportColisToXlsx']);
$router->get('/packing-lists/{packingListId}/export-colis/pdf', [PackingListController::class, 'exportColisToPdf']);
$router->get('/packing-lists/{id}/export-synthesis/xlsx', [PackingListController::class, 'exportSynthesisToXlsx']);

// --- ROUTES POUR LES PALETTES ---
// Création d'une palette pour une PL spécifique (POST car action de création)
$router->post('/packing-lists/{packingListId}/palettes/store', [PaletteController::class, 'storeForPackingList']);
$router->get('/palettes/show/{id}', [PaletteController::class, 'show']);
$router->post('/palettes/open/{id}', [PaletteController::class, 'processOpening']); // Saisie de max_colis après premier "scan"
$router->post('/palettes/update-max-colis/{id}', [PaletteController::class, 'updateMaxColis']);
$router->post('/palettes/close/{id}', [PaletteController::class, 'closePalette']);
$router->post('/palettes/delete/{id}', [PaletteController::class, 'delete']);
$router->post('/palettes/print-qrcodes', [PaletteController::class, 'printQrCodes']);
$router->post('/palettes/reopen/{id}', [PaletteController::class, 'reopenPalette']);

// Optionnel: une route pour lister toutes les palettes (pas explicitement demandée pour l'instant)
$router->get('/palettes', [PaletteController::class, 'indexAll']); 

// --- ROUTES POUR LE SCAN DE COLIS ---
$router->get('/colis/scan', [ColisController::class, 'scanInterface']);
$router->post('/colis/process-palette-scan', [ColisController::class, 'processPaletteForScan']); // Activer une palette
$router->post('/colis/process-palette-scan-ajax', [ColisController::class, 'processPaletteScanAjax']); // Version AJAX activation palette
$router->post('/colis/process-scan', [ColisController::class, 'processColisScan']); // Enregistrer un colis scanné
$router->post('/colis/process-scan-ajax', [ColisController::class, 'processScanAjax']); // Version AJAX du scan
$router->get('/colis/deactivate-palette-scan', [ColisController::class, 'deactivatePaletteScan']); // Désactiver palette
$router->post('/colis/delete/{colisId}', [ColisController::class, 'deleteColis']); // Dé-scanner un colis (depuis la vue palette/show)

// --- NOUVELLES ROUTES POUR LE DÉPLACEMENT DE COLIS ---
// On n'a pas de route GET pour afficher le formulaire car on utilise une modale.
$router->post('/colis/move/{colisId}', [ColisController::class, 'processMove']); // Traiter le déplacement
$router->post('/colis/massive-move', [ColisController::class, 'processMassiveMove']); // Traiter le déplacement massif
$router->post('/colis/update-comment/{colisId}', [ColisController::class, 'updateComment']);

// --- ROUTES POUR LA RECHERCHE UNIFIÉE ---
$router->get('/search', [SearchController::class, 'index']);
$router->post('/search/ajax', [SearchController::class, 'ajaxSearch']);
$router->get('/search/global', [SearchController::class, 'globalSearch']);

// ---ROUTES POUR LES RECHERCHES SPÉCIFIQUES ---
$router->post('/search/by-of', [SearchController::class, 'ajaxSearchByOf']);
$router->post('/search/by-sku', [SearchController::class, 'ajaxSearchBySku']);

// --- ROUTES POUR L'IMPORT THÉORIQUE ET SUIVI DES ÉCARTS ---
$router->get('/theoretical-exports', [TheoreticalExportController::class, 'index']); // Nouvelle page d'index
$router->get('/theoretical-exports/deviations', [TheoreticalExportController::class, 'showDeviations']);
$router->get('/theoretical-exports/import', [TheoreticalExportController::class, 'showImportForm']);
$router->post('/theoretical-exports/process-import', [TheoreticalExportController::class, 'processImport']);

// --- ROUTES POUR LA GESTION DES SITES ---
$router->get('/sites', [SiteController::class, 'index']);
$router->get('/sites/create', [SiteController::class, 'create']);
$router->post('/sites/store', [SiteController::class, 'store']);
$router->get('/sites/edit/{id}', [SiteController::class, 'edit']);
$router->post('/sites/update/{id}', [SiteController::class, 'update']);
$router->post('/sites/deactivate/{id}', [SiteController::class, 'deactivateSite']);
$router->post('/sites/reactivate/{id}', [SiteController::class, 'reactivateSite']);
$router->post('/sites/force-delete/{id}', [SiteController::class, 'forceDeleteSite']);


// 6. Vérification CSRF centrale pour toute requête POST.
// Doit s'exécuter AVANT le dispatch — les formulaires (Tâche 1.3) et les fetch AJAX (Tâche 1.4)
// envoient le jeton soit dans le champ _csrf_token, soit dans l'en-tête X-CSRF-Token.
if ($request->getMethod() === 'POST') {
    if (!Csrf::validate($request)) {
        $accept = (string) $request->server('HTTP_ACCEPT', '');
        $xrw    = (string) $request->server('HTTP_X_REQUESTED_WITH', '');
        $ctype  = (string) $request->server('CONTENT_TYPE', '');
        $isAjax = str_contains($accept, 'application/json')
               || strcasecmp($xrw, 'XMLHttpRequest') === 0
               || str_contains($ctype, 'application/json');

        if ($isAjax) {
            // 403 (Forbidden) plutot que 419 non-IANA (Apache transforme en 500).
            // Le champ "reload":true signale au JS qu un reload de page resoudra.
            $resp = Response::json([
                'error' => 'Jeton de sécurité invalide ou expiré. Rechargez la page.',
                'reload' => true,
            ], 403);
        } else {
            if (session_status() === PHP_SESSION_NONE) { session_start(); }
            $_SESSION[FLASH_ERROR] = "Votre session a expiré ou le jeton de sécurité est invalide. Veuillez réessayer.";
            $back = (string) $request->server('HTTP_REFERER', BASE_URL . '/');
            $resp = Response::redirect($back, 303);
        }
        $resp->send();
        exit;
    }
}

// 7. Dispatcher la requête et envoyer la réponse
try {
    // error_log("DEBUG 10: Entering try block for dispatch");
    $response = $router->dispatch($request);
    // error_log("DEBUG 11: Router dispatch completed");

    if ($response instanceof Response) {
        // error_log("DEBUG 12: Response object received, attempting to send");
        $response->send();
        // Si send() réussit et affiche du contenu, le script devrait se terminer ici ou peu après.
    } else {
        error_log("ERREUR CRITIQUE: Le handler de route n'a pas retourné d'objet Response valide.");
        $fallbackData = [
            'errorMessage' => "Erreur interne: le contrôleur n'a pas retourné de réponse valide.",
            'baseUrl' => BASE_URL
        ];
        $fallbackResponse = Response::html('errors/500.php', $fallbackData, 500);
        $fallbackResponse->send();
        exit; // S'assurer que le script s'arrête ici
    }
} catch (\Throwable $e) {
    // error_log("DEBUG CATCH: Erreur attrapée dans le bloc catch principal !");
    $errorCode = ($e instanceof \Exception && (str_contains($e->getMessage(), '404') || $e->getCode() === 404)) ? 404 : 500;
    
    // Loggue systématiquement les erreurs, même 404 pour le moment pour le debug
    error_log("ERREUR ATTRAPÉE DANS INDEX.PHP: " . $e->getMessage() . "\n" . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());

    $errorData = [
        'errorMessage' => ini_get('display_errors') ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : "Une erreur est survenue.",
        'errorTrace' => ini_get('display_errors') ? $e->getTraceAsString() : null,
        'baseUrl' => BASE_URL // Passer BASE_URL comme baseUrl pour les vues d'erreur
    ];

    if ($errorCode === 404) {
        // error_log("DEBUG CATCH: Erreur 404 détectée");
        $errorResponse = Response::html('errors/404.php', $errorData, 404);
    } else {
        // error_log("DEBUG CATCH: Erreur 500 (ou autre) détectée");
        $errorResponse = Response::html('errors/500.php', $errorData, 500);
    }
    $errorResponse->send();
    exit; // S'assurer que le script s'arrête ici après avoir envoyé la page d'erreur
}

// error_log("DEBUG FIN: Fin de index.php (ne devrait pas être atteint si une réponse a été envoyée)");