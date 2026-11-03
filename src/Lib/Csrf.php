<?php
// src/Lib/Csrf.php

namespace App\Lib;

/**
 * Protection CSRF — jeton synchronisé en session.
 *
 * Le jeton vit le temps de la session (ne pas régénérer à chaque requête,
 * sous peine d'invalider les onglets déjà ouverts).
 */
class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /**
     * Garantit l'existence d'un jeton CSRF en session et le retourne.
     */
    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Retourne un champ caché HTML prêt à insérer dans un formulaire POST.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /**
     * Vérifie le jeton d'une requête POST. Retourne true si valide.
     * Compare via hash_equals (temps constant) pour éviter les attaques de timing.
     *
     * Accepte le jeton soit en POST (_csrf_token) soit en en-tête X-CSRF-Token.
     */
    public static function validate(Request $request): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        $sent = $request->post('_csrf_token', '');
        if ($sent === '' || $sent === null) {
            $sent = $request->server('HTTP_X_CSRF_TOKEN', '');
        }
        return is_string($sent) && hash_equals($_SESSION[self::SESSION_KEY], $sent);
    }
}
