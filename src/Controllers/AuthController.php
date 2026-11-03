<?php
// src/Controllers/AuthController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use PDO;
use PDOException;

class AuthController extends BaseController
{
    /** Seuil d'échecs avant blocage. */
    private const LOGIN_ATTEMPTS_THRESHOLD = 5;
    /** Fenêtre glissante en minutes pour compter les échecs. */
    private const LOGIN_ATTEMPTS_WINDOW_MIN = 15;

    /**
     * Affiche le formulaire de connexion.
     * Si l'utilisateur est déjà connecté, le redirige vers la page d'accueil.
     *
     * @param Request $request
     * @return Response
     */
    public function showLoginForm(Request $request): Response
    {
    if (Auth::isLoggedIn()) {
        return $this->redirect('/');
    }

    $data = [
        'pageTitle' => 'Connexion - ' . APP_NAME,
        'request' => $request // << AJOUTER L'OBJET REQUEST ICI
    ];
    
    return $this->view('auth/login.php', $data);
    }

    /**
     * Traite la soumission du formulaire de connexion.
     *
     * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        if (Auth::isLoggedIn()) {
            return $this->redirect('/');
        }

        $username = $request->post('username');
        $password = $request->post('password');
        // $rememberMe = $request->post('remember_me') === 'on'; // Si vous implémentez "Se souvenir de moi"

        if (empty($username) || empty($password)) {
            $this->flash(FLASH_ERROR, 'Veuillez saisir votre nom d\'utilisateur et votre mot de passe.');
            return $this->redirect('/login');
        }

        $ip = (string) ($request->server('REMOTE_ADDR', '') ?? '');

        // Throttling : refuser si trop d'échecs récents sur ce couple (username, IP).
        if ($this->countRecentFailedAttempts($username, $ip) >= self::LOGIN_ATTEMPTS_THRESHOLD) {
            // Message volontairement neutre : ne pas révéler l'existence du compte.
            $this->flash(FLASH_ERROR, "Trop de tentatives. Réessayez dans quelques minutes.");
            return $this->redirect('/login');
        }

        if (Auth::login($username, $password)) {
            // Connexion réussie : purger les échecs du couple.
            $this->clearFailedAttempts($username, $ip);
            $this->flash(FLASH_SUCCESS, 'Connexion réussie. Bienvenue ' . htmlspecialchars($username) . '!');
            return $this->redirect('/');
        }

        // Échec : tracer la tentative et renvoyer un message uniforme.
        $this->recordFailedAttempt($username, $ip);
        $this->flash(FLASH_ERROR, 'Nom d\'utilisateur ou mot de passe incorrect, ou compte inactif.');
        return $this->redirect('/login');
    }

    /**
     * Compte les tentatives echouees du couple (username, IP) dans la fenetre glissante.
     */
    private function countRecentFailedAttempts(string $username, string $ip): int
    {
        try {
            $pdo = getPDO();
            $sql = "SELECT COUNT(*) FROM login_attempts
                    WHERE username = :u AND ip_address = :ip
                      AND attempted_at >= (NOW() - INTERVAL :win MINUTE)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':u', $username, PDO::PARAM_STR);
            $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
            $stmt->bindValue(':win', self::LOGIN_ATTEMPTS_WINDOW_MIN, PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Si la table n'existe pas encore (migration non passee), ne pas bloquer la connexion.
            error_log("AuthController::countRecentFailedAttempts - " . $e->getMessage());
            return 0;
        }
    }

    private function recordFailedAttempt(string $username, string $ip): void
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (:u, :ip)");
            $stmt->bindValue(':u', $username, PDO::PARAM_STR);
            $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("AuthController::recordFailedAttempt - " . $e->getMessage());
        }
    }

    private function clearFailedAttempts(string $username, string $ip): void
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = :u AND ip_address = :ip");
            $stmt->bindValue(':u', $username, PDO::PARAM_STR);
            $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("AuthController::clearFailedAttempts - " . $e->getMessage());
        }
    }

    /**
     * Déconnecte l'utilisateur.
     *
     * @param Request $request
     * @return Response
     */
    public function logout(Request $request): Response
    {
        Auth::logout();
        $this->flash(FLASH_INFO, 'Vous avez été déconnecté.');
        return $this->redirect('/login'); // Rediriger vers la page de connexion après la déconnexion
    }
}