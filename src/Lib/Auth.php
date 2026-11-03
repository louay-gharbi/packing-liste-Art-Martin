<?php
// src/Lib/Auth.php

namespace App\Lib;

use App\Models\User; // Nécessaire pour la méthode user() et hasPermission() via l'objet User

/**
 * Classe Auth
 * Gère l'authentification des utilisateurs, les sessions et la protection des routes.
 */
class Auth
{
    private const USER_SESSION_KEY = 'user_id'; // Clé pour stocker l'ID utilisateur en session

    /**
     * Tente de connecter un utilisateur.
     *
     * @param string $username Le nom d'utilisateur.
     * @param string $password Le mot de passe en clair.
     * @return bool True si la connexion réussit, false sinon.
     */
    public static function login(string $username, string $password): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = User::findByUsername($username);

        if ($user && $user->is_active && password_verify($password, $user->password_hash)) {
            $_SESSION[self::USER_SESSION_KEY] = $user->id;
            // Optionnel: stocker d'autres infos pour accès rapide, mais attention à la désynchronisation
            // $_SESSION['username'] = $user->username;
            // Si vous stockez l'objet user en session, assurez-vous qu'il est sérialisable
            // et qu'il est rafraîchi si les données en BDD changent.
            // $_SESSION['user_object'] = $user;

            session_regenerate_id(true);

            // Rehash transparent si le coût/algorithme par défaut a évolué.
            // Echec silencieux (log) : ne doit pas bloquer la connexion.
            if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if ($newHash !== false) {
                    User::updatePasswordHash($user->id, $newHash);
                }
            }

            return true;
        }
        return false;
    }

    /**
     * Déconnecte l'utilisateur actuel.
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Vérifie si un utilisateur est actuellement connecté.
     *
     * @return bool True si connecté, false sinon.
     */
    public static function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION[self::USER_SESSION_KEY]);
    }

    /**
     * Récupère l'objet User de l'utilisateur actuellement connecté.
     * Retourne null si non connecté ou si l'utilisateur n'est plus valide.
     *
     * @return User|null L'objet User ou null.
     */
    public static function user(): ?User
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        // Pour éviter des requêtes BDD répétitives sur le même chargement de page,
        // on peut mettre en cache l'objet utilisateur dans une variable statique de la classe Auth.
        static $currentUser = null;
        static $currentUserId = null;

        if ($currentUser !== null && $currentUserId === $_SESSION[self::USER_SESSION_KEY]) {
            return $currentUser;
        }

        $user = User::findById($_SESSION[self::USER_SESSION_KEY]);
        
        if ($user) {
            $currentUser = $user;
            $currentUserId = $user->id;
        } else {
            // L'ID en session ne correspond plus à un utilisateur valide (ex: supprimé entre-temps)
            self::logout(); // Déconnecter pour nettoyer la session
            $currentUser = null;
            $currentUserId = null;
        }
        return $currentUser;
    }

    /**
     * Récupère l'ID de l'utilisateur actuellement connecté.
     *
     * @return int|null L'ID de l'utilisateur ou null si non connecté.
     */
    public static function id(): ?int
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        return $_SESSION[self::USER_SESSION_KEY];
    }

    /**
     * Middleware de protection : s'assure que l'utilisateur est connecté.
     * Si non connecté, enregistre l'URL actuelle pour redirection après login et redirige vers la page de connexion.
     *
     * @param string $loginUrl L'URL de la page de connexion.
     * @return void
     */
    public static function protect(string $loginUrl = ''): void
    {
        if (!self::isLoggedIn()) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Stocker l'URL demandée pour rediriger l'utilisateur après la connexion (optionnel)
            if (isset($_SERVER['REQUEST_URI'])) {
                 $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            }

            $effectiveLoginUrl = $loginUrl;
            if (empty($effectiveLoginUrl)) {
                $effectiveLoginUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/login';
            } elseif (!preg_match('/^https?:\/\//i', $effectiveLoginUrl)) {
                 $effectiveLoginUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . (str_starts_with($effectiveLoginUrl, '/') ? $effectiveLoginUrl : '/' . $effectiveLoginUrl);
            }
            
            header("Location: " . $effectiveLoginUrl);
            exit;
        }
    }

    /**
     * Vérifie si l'utilisateur connecté a une permission spécifique.
     * Cette méthode délègue la vérification à la méthode hasPermission() de l'objet User.
     *
     * @param string $permissionName Le nom de la permission à vérifier (ex: 'user_manage', 'packinglist_create').
     * @return bool True si l'utilisateur a la permission, false sinon (y compris si non connecté).
     */
    public static function can(string $permissionName): bool
    {
        $user = self::user(); // Récupère l'objet User de l'utilisateur connecté
        if ($user instanceof User) { // Vérifier si $user est bien une instance de User
            return $user->hasPermission($permissionName); // Déléguer à la méthode de l'objet User
        }
        return false; // Non connecté ou utilisateur invalide n'a aucune permission
    }

    /**
     * Vérifie si l'utilisateur connecté a un rôle spécifique.
     * (Méthode de convenance, délègue à l'objet User)
     * @param string $roleName
     * @return bool
     */
    public static function hasRole(string $roleName): bool
    {
        $user = self::user();
        if ($user instanceof User) {
            return $user->hasRole($roleName);
        }
        return false;
    }
}