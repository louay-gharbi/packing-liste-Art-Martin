<?php
// config/database.php

/**
 * Fichier de configuration pour la connexion à la base de données.
 */

// En production, on refuse explicitement les fallbacks dangereux (root / mot de passe vide).
// Hors production, on conserve les valeurs par defaut pour ne pas casser l'environnement
// de developpement local (Laragon par defaut).
$isProd = (defined('APP_ENV') && APP_ENV === 'production');

$dbUserEnv = getenv('DB_USER');
$dbPasswordEnv = getenv('DB_PASSWORD');

if ($isProd) {
    if ($dbUserEnv === false || $dbUserEnv === '' || $dbPasswordEnv === false) {
        // Echec explicite : ne JAMAIS tomber sur root / vide en production.
        throw new \RuntimeException(
            "Configuration BDD invalide en production : les variables d'environnement DB_USER et DB_PASSWORD doivent etre definies."
        );
    }
}

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'packing_list_db');
define('DB_USER', $dbUserEnv !== false && $dbUserEnv !== '' ? $dbUserEnv : 'root');
define('DB_PASSWORD', $dbPasswordEnv !== false ? $dbPasswordEnv : '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

/**
 * Data Source Name (DSN) pour PDO.
 * Construit à partir des constantes ci-dessus.
 */
define('DB_DSN', "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET);

/**
 * Options pour la connexion PDO.
 */
define('PDO_OPTIONS', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lancer des exceptions en cas d'erreur SQL
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupérer les résultats sous forme de tableau associatif par défaut
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Désactiver l'émulation des requêtes préparées pour une vraie préparation
]);

/**
 * Fonction pour obtenir une instance de connexion PDO.
 * Utilise un pattern Singleton basique pour ne créer qu'une seule instance.
 *
 * @return PDO L'instance de la connexion PDO.
 * @throws PDOException Si la connexion échoue.
 */
function getPDO(): PDO
{
    // Variable statique pour stocker l'instance de PDO
    static $pdoInstance = null;

    // Si l'instance n'a pas encore été créée
    if ($pdoInstance === null) {
        try {
            // Créer une nouvelle instance de PDO
            $pdoInstance = new PDO(DB_DSN, DB_USER, DB_PASSWORD, PDO_OPTIONS);
        } catch (PDOException $e) {
            // Logger en clair cote serveur puis relancer l'exception : le try/catch global
            // de index.php produira la page 500 coherente (et masquera les details en prod).
            error_log("Erreur de connexion a la base de donnees : " . $e->getMessage());
            throw $e;
        }
    }

    // Retourner l'instance de PDO
    return $pdoInstance;
}
