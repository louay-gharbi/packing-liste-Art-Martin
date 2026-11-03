<?php
// config/config.php

/**
 * Fichier de configuration principal de l'application.
 * Définit les constantes et paramètres globaux.
 */

// Définir l'environnement d'exécution.
// Par defaut : 'development' (Laragon local). En prod, definir APP_ENV=production
// dans l environnement (php-fpm pool, Apache SetEnv, .env, etc.).
define('APP_ENV', getenv('APP_ENV') ?: 'development');

// Gestion des erreurs selon l'environnement
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Définir le fuseau horaire par défaut
date_default_timezone_set('Europe/Paris');

// --- URLs et Chemins (Approche Dynamique pour APP_URL et BASE_URL) ---

// 1. Déterminer le protocole (http ou https)
$protocol = 'http://'; // Par défaut
if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)) {
    $protocol = 'https://';
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    // Pour les serveurs derrière un reverse proxy / load balancer
    $protocol = 'https://';
} elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $protocol = 'https://';
}

// 2. Déterminer le nom d'hôte (host)
// Utilise HTTP_HOST s'il est défini (le plus courant), sinon SERVER_NAME.
// 'localhost' comme fallback très basique si rien n'est défini (ne devrait pas arriver dans un contexte web).
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'pdttu-060');

// 3. Déterminer le chemin de base de l'application
// SCRIPT_NAME est le chemin du script en cours d'exécution (public/index.php) par rapport à la racine du document du serveur.
// Par exemple: /packinglist/public/index.php ou /index.php si à la racine du domaine.
// dirname() nous donne le dossier parent de ce script.
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptPath);

// Normaliser le basePath :
// - Remplacer les anti-slashs (Windows) par des slashs.
$basePath = str_replace('\\', '/', $basePath);
// - Supprimer les slashs de fin.
$basePath = rtrim($basePath, '/');
// - Si basePath est vide après rtrim (cas où index.php est à la racine du domaine), 
//   ou s'il est juste '/', alors le chemin de base est vide (pas de sous-dossier).
if ($basePath === '' || $basePath === '/') {
    $basePath = ''; 
}
// Exemple: si SCRIPT_NAME est '/packinglist/public/index.php', basePath devient '/packinglist/public'
// Exemple: si SCRIPT_NAME est '/index.php', basePath devient ''

// 4. Définir APP_URL et BASE_URL
// APP_URL est l'URL complète vers le dossier public (ou la racine si configuré ainsi).
define('APP_URL', $protocol . $host . $basePath);
// BASE_URL est la même chose, généralement utilisée sans le slash final (rtrim est déjà fait sur $basePath).
define('BASE_URL', APP_URL); // rtrim(APP_URL, '/') n'est plus nécessaire si $basePath est déjà rtrimé.

// --- Chemins Système (basés sur l'emplacement physique des fichiers) ---
// Ceux-ci ne changent pas car ils sont relatifs à l'emplacement de ce fichier config.php.
define('ROOT_PATH', dirname(__DIR__));      // Chemin racine du projet (ex: C:/xampp/htdocs/packinglist)
define('CONFIG_PATH', ROOT_PATH . '/config');
define('SRC_PATH', ROOT_PATH . '/src');
define('PUBLIC_PATH', ROOT_PATH . '/public'); // Ex: C:/xampp/htdocs/packinglist/public
define('VIEWS_PATH', SRC_PATH . '/Views');
define('LOG_PATH', ROOT_PATH . '/logs');

// --- Fin URLs et Chemins ---


// Paramètres de session
define('SESSION_NAME', 'packing_list_session');
define('SESSION_LIFETIME', 0);
define('SESSION_SECURE', ($protocol === 'https://')); // Basé sur le protocole détecté
define('SESSION_HTTP_ONLY', true);

// Paramètres spécifiques à l'application
define('APP_NAME', 'PackingList Pro');
define('APP_VERSION', '1.0.0');

// Gestion des messages Flash
define('FLASH_SUCCESS', 'flash_success');
define('FLASH_ERROR', 'flash_error');
define('FLASH_INFO', 'flash_info');
define('FLASH_WARNING', 'flash_warning');

// Assurer l'existence du dossier de logs
if (!is_dir(LOG_PATH)) {
    // Tenter de créer le dossier avec des permissions par défaut sécurisées.
    // La réussite dépend des permissions du processus PHP/serveur web.
    if (!@mkdir(LOG_PATH, 0755, true) && !is_dir(LOG_PATH)) { 
        // Si mkdir échoue ET que le dossier n'existe toujours pas (pour éviter les warnings si course condition)
        // On ne peut pas faire grand-chose d'autre ici qu'un error_log si on n'a pas les droits.
        // L'application continuera sans dossier de logs dédié si cette création échoue.
        error_log("Attention: Impossible de créer le dossier de logs à l'emplacement : " . LOG_PATH);
    }
}

// Pour débogage, vous pouvez ajouter ceci temporairement pour voir les valeurs générées :
// error_log("CONFIG - APP_URL: " . APP_URL);
//error_log("CONFIG - BASE_URL: " . BASE_URL);
// error_log("CONFIG - ROOT_PATH: " . ROOT_PATH);
// error_log("CONFIG - PUBLIC_PATH: " . PUBLIC_PATH);
// Paramètres spécifiques à l'application (peuvent aussi venir de la base de données plus tard)

