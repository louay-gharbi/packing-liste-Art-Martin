<?php
// src/Controllers/HomeController.php

namespace App\Controllers;

use App\Lib\Request;
use App\Lib\Response; 
use App\Lib\Auth;

/**
 * Contrôleur pour la page d'accueil et autres pages statiques/simples.
 * Hérite de BaseController.
 */
class HomeController extends BaseController // Faire hériter de BaseController
{
    /**
     * Affiche la page d'accueil (tableau de bord temporaire).
     * @param Request $request L'objet Request.
     * @return Response La réponse HTTP.
     */
     public function index(Request $request): Response
    {
        Auth::protect(); // PROTÉGER CETTE ROUTE

        // L'utilisateur est connecté si on arrive ici
        $user = Auth::user(); // Récupérer l'utilisateur connecté

        $data = [
            'pageTitle' => "Tableau de bord - " . APP_NAME,
            'welcomeMessage' => "Bienvenue sur votre tableau de bord, " . htmlspecialchars($user->username) . " !",
            'user' => $user // Passer l'objet utilisateur à la vue si nécessaire
        ];
        
        return $this->view('dashboard/index.php', $data);
    }

    /**
     * Affiche la page de connexion (temporaire).
     * @param Request $request L'objet Request.
     * @return Response La réponse HTTP.
     */
    public function loginPage(Request $request): Response
    {
        $data = [
            'pageTitle' => "Connexion - " . APP_NAME
        ];
        return $this->view('auth/login.php', $data);
    }

    /**
     * Page de test pour la connexion à la base de données.
     * @param Request $request L'objet Request.
     * @return Response La réponse HTTP.
     */
    public function testDb(Request $request): Response
    {
        $content = "<h1>Test de Connexion à la Base de Données (via HomeController)</h1>";
        try {
            $pdo = getPDO(); // Utilise la fonction de config/database.php
            if ($pdo) {
                $content .= "<p style='color: green;'>Connexion à la base de données établie avec succès !</p>";
                $stmt = $pdo->query("SELECT VERSION() as mysql_version");
                $version = $stmt->fetchColumn();
                $content .= "<p>Version MySQL: " . htmlspecialchars($version) . "</p>";
            }
        } catch (\PDOException $e) {
            $content .= "<p style='color: red;'>Erreur de connexion à la base de données : " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        // Retourner une réponse simple pour ce test, ou utiliser une vue si plus complexe.
        return new Response($content);
    }

    /**
     * Exemple de page avec un paramètre dans l'URL.
     * @param Request $request L'objet Request.
     * @param string $name Le paramètre 'name' de l'URL.
     * @return Response La réponse HTTP.
     */
    public function greet(Request $request, string $name): Response
    {
        $messageGET = $request->get('message');
        $content = "<h1>Bonjour, " . htmlspecialchars($name) . "! (via HomeController)</h1>";
        $content .= "<p>Paramètre 'name' reçu : " . htmlspecialchars($name) . "</p>";
        if ($messageGET) {
            $content .= "<p>Message GET : " . htmlspecialchars($messageGET) . "</p>";
        }
        return new Response($content);
    }

    /**
     * Exemple de redirection.
     * @param Request $request
     * @return Response
     */
    public function redirectToHome(Request $request): Response
    {
        $this->flash(FLASH_SUCCESS, 'Vous avez été redirigé vers l\'accueil!');
        return $this->redirect('/'); // Redirige vers la racine du site
    }

    /**
     * Exemple de réponse JSON.
     * @param Request $request
     * @return Response
     */
    public function jsonData(Request $request): Response
    {
        $data = [
            'message' => 'Ceci est une réponse JSON',
            'timestamp' => time(),
            'items' => ['item1', 'item2', 'item3']
        ];
        return $this->json($data);
    }
}