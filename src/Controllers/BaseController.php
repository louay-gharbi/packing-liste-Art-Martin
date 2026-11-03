<?php
// src/Controllers/BaseController.php

namespace App\Controllers;

use App\Lib\Auth;     // Nécessaire pour la méthode authorize()
use App\Lib\Request;  // Bien que non injecté via constructeur, certains enfants pourraient l'utiliser
use App\Lib\Response; // Pour le type hinting du retour de json() et redirect()

/**
 * Classe BaseController
 * Contrôleur de base dont les autres contrôleurs hériteront.
 * Fournit des méthodes utilitaires communes, comme le rendu des vues et l'autorisation.
 */
abstract class BaseController
{
    /**
     * @var string Le chemin vers le fichier de layout principal à utiliser par défaut pour les vues.
     *               Peut être surchargé par les contrôleurs enfants si un layout différent est nécessaire.
     *               Exemple: 'layouts/admin_app.php' pour le back-office, 'layouts/public_app.php' pour le front-office.
     */
    protected string $layout = 'layouts/admin_app.php';

    /**
     * Constructeur de BaseController.
     * Les contrôleurs enfants peuvent appeler parent::__construct() si nécessaire.
     */
    public function __construct()
    {
        // Ce constructeur peut être utilisé pour des initialisations communes
        // à tous les contrôleurs qui en héritent (ex: charger un service de configuration global).
        // Pour l'instant, il reste simple.
    }

    /**
     * Vérifie si l'utilisateur connecté a la permission requise.
     * Si l'utilisateur n'est pas autorisé (pas connecté ou n'a pas la permission),
     * un message flash d'erreur est défini, et l'utilisateur est redirigé.
     * Cette méthode termine l'exécution du script après avoir envoyé la redirection.
     *
     * @param string $permissionName Le nom de la permission à vérifier (ex: 'user_manage', 'packinglist_create').
     * @param string|null $redirectUrl L'URL vers laquelle rediriger en cas d'accès non autorisé.
     *                                 Si null, redirige vers la page d'accueil (BASE_URL . '/').
     * @param string|null $flashMessage Le message d'erreur spécifique à afficher.
     *                                  Si null, un message par défaut est utilisé.
     * @return void
     */
    protected function authorize(string $permissionName, ?string $redirectUrl = null, ?string $flashMessage = null): void
    {
        if (!Auth::can($permissionName)) {
            $defaultMessage = "Accès non autorisé. Vous n'avez pas la permission nécessaire ({$permissionName}).";
            $this->flash(FLASH_ERROR, $flashMessage ?? $defaultMessage);
            
            // Déterminer l'URL de redirection effective
            // Si $redirectUrl est null, on utilise la racine du site.
            $effectiveRedirectUrl = $redirectUrl ?? (defined('BASE_URL') ? BASE_URL . '/' : '/');

            // Utiliser la méthode redirect de ce BaseController pour créer une réponse de redirection
            $response = $this->redirect($effectiveRedirectUrl);
            $response->send(); // Envoyer la réponse de redirection
            exit; // Arrêter l'exécution du script immédiatement après la redirection
        }
    }

     /**
     * Rend une vue HTML en l'injectant dans le layout défini par $this->layout.
     * Cette version est modifiée pour capturer les variables définies dans la vue (comme $pageScripts).
     *
     * @param string $viewPath Chemin vers le fichier de la vue de contenu (relatif à VIEWS_PATH).
     * @param array $data Données à extraire et à rendre disponibles dans la vue et le layout.
     * @param int $statusCode Code de statut HTTP pour la réponse (par défaut 200).
     * @return Response Une instance de Response contenant la page HTML complète.
     * @throws \Exception Si le fichier de vue ou le fichier de layout n'est pas trouvé.
     * @throws \LogicException Si $this->layout est null.
     */
    protected function view(string $viewPath, array $data = [], int $statusCode = 200): Response
    {
        $defaultViewData = [
            'appName' => defined('APP_NAME') ? APP_NAME : 'Application',
            'baseUrl' => defined('BASE_URL') ? BASE_URL : '',
            'pageTitle' => $data['pageTitle'] ?? (defined('APP_NAME') ? APP_NAME : 'Page'),
        ];
        $viewData = array_merge($defaultViewData, $data);

        // 1. Rendre la vue et CAPTURER son contenu ET ses variables
        $viewRenderResult = $this->renderViewAndGetVars($viewPath, $viewData);
        $content = $viewRenderResult['content'];
        $viewDefinedVars = $viewRenderResult['vars']; // On récupère les variables comme $pageScripts

        // 2. Préparer les données pour le layout, en fusionnant tout.
        // Les variables définies dans la vue (comme $pageScripts) écraseront les données initiales si elles ont le même nom.
        $layoutData = array_merge($viewData, $viewDefinedVars, ['content' => $content]);

        if ($this->layout === null || $this->layout === '') {
            error_log("ERREUR BaseController::view() - La propriété \$this->layout n'est pas définie ou est vide.");
            throw new \LogicException("La propriété \$this->layout doit être définie dans le contrôleur pour rendre une vue avec layout.");
        }
        
        // 3. Rendre le layout principal avec toutes les données fusionnées
        $fullPageContent = $this->renderPartial($this->layout, $layoutData);

        // 4. Créer et retourner l'objet Response
        return new Response($fullPageContent, $statusCode, ['Content-Type' => 'text/html; charset=utf-8']);
    }

     /**
     * Rend un fichier de vue et retourne à la fois son contenu HTML et les variables qui y ont été définies.
     *
     * @param string $filePath Chemin vers le fichier de vue (relatif à VIEWS_PATH).
     * @param array $data Données à rendre disponibles dans la vue.
     * @return array ['content' => string, 'vars' => array]
     * @throws \Exception
     */
    private function renderViewAndGetVars(string $filePath, array $data = []): array
    {
        $fullViewPath = rtrim(VIEWS_PATH, '/\\') . '/' . ltrim($filePath, '/\\');

        if (!file_exists($fullViewPath)) {
            error_log("ERREUR BaseController::renderViewAndGetVars() - Fichier de vue non trouvé : {$fullViewPath}");
            throw new \Exception("Fichier de vue non trouvé : {$fullViewPath}");
        }

        // Isoler l'exécution pour capturer les variables
        $renderer = function($path, $data) {
            extract($data);
            ob_start();
            try {
                include $path;
                $content = ob_get_clean();
                // Retourne le contenu ET les variables définies dans cette portée
                return ['content' => $content, 'vars' => get_defined_vars()];
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        $result = $renderer($fullViewPath, $data);

        // Nettoyer le tableau de variables pour ne garder que celles définies dans le fichier de vue,
        // pas les variables de la fonction $renderer elle-même.
        unset($result['vars']['path'], $result['vars']['data']);

        return $result;
    }

    /**
     * Rend un fichier de vue (partiel ou layout) sans l'injecter dans un autre layout.
     * Les données du tableau $data sont extraites en variables locales accessibles dans le fichier inclus.
     *
     * @param string $filePath Chemin vers le fichier de vue (relatif à VIEWS_PATH).
     * @param array $data Données à rendre disponibles dans la vue.
     * @return string Le contenu HTML rendu du fichier.
     * @throws \InvalidArgumentException Si le chemin du fichier est invalide.
     * @throws \Exception Si le fichier de vue n'est pas trouvé ou si une erreur survient pendant l'inclusion.
     */
    protected function renderPartial(string $filePath, array $data = []): string
    {
        if (empty($filePath)) {
             error_log("ERREUR BaseController::renderPartial() - Chemin de fichier vide fourni.");
             throw new \InvalidArgumentException("Le chemin du fichier de vue (partiel/layout) ne peut pas être vide.");
        }

        // Construire le chemin complet vers le fichier de vue
        $fullViewPath = rtrim(VIEWS_PATH, '/\\') . '/' . ltrim($filePath, '/\\');

        if (!file_exists($fullViewPath)) {
            error_log("ERREUR BaseController::renderPartial() - Fichier de vue non trouvé : {$fullViewPath}");
            throw new \Exception("Fichier de vue non trouvé : {$fullViewPath}");
        }

        // Extraire les variables du tableau $data pour les rendre accessibles dans la portée de la vue incluse.
        // Par exemple, si $data = ['title' => 'Mon Titre'], alors la variable $title sera disponible dans la vue.
        extract($data);

        // Démarrer la mise en tampon de la sortie pour capturer le contenu de la vue incluse.
        ob_start();
        try {
            include $fullViewPath; // Inclure le fichier de vue
            return ob_get_clean(); // Retourner le contenu capturé et nettoyer le tampon
        } catch (\Throwable $e) { // Attraper toutes les erreurs/exceptions (PHP 7+)
            ob_end_clean(); // S'assurer que le tampon est nettoyé même en cas d'erreur
            error_log("Erreur lors du rendu du partiel {$fullViewPath}: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            throw $e; // Relancer l'exception pour une gestion d'erreur globale si nécessaire
        }
    }

    /**
     * Crée et retourne une réponse JSON.
     *
     * @param mixed $data Les données à encoder en JSON.
     * @param int $statusCode Le code de statut HTTP (par défaut 200).
     * @param array $headers Les en-têtes HTTP supplémentaires.
     * @param int $options Les options pour json_encode().
     * @return Response L'objet Response configuré pour du JSON.
     */
    protected function json(mixed $data, int $statusCode = 200, array $headers = [], int $options = 0): Response
    {
        return Response::json($data, $statusCode, $headers, $options);
    }

    /**
     * Crée et retourne une réponse de redirection.
     * Gère la construction d'URL relative à BASE_URL si l'URL fournie est relative.
     *
     * @param string $url L'URL de redirection. Peut être absolue ou relative au site.
     * @param int $statusCode Le code de statut HTTP (par défaut 302 Found).
     * @param array $headers Les en-têtes HTTP supplémentaires.
     * @return Response L'objet Response configuré pour la redirection.
     */
    protected function redirect(string $url, int $statusCode = 302, array $headers = []): Response
    {
        $finalUrl = $url;
        // Si l'URL n'est pas absolue (ne commence pas par http/https)
        if (!preg_match('/^https?:\/\//i', $url)) {
            if (defined('BASE_URL')) {
                // Si elle commence par un '/', la joindre à BASE_URL (sans le / final de BASE_URL)
                // Sinon, la considérer comme relative à BASE_URL et ajouter un '/'
                $finalUrl = rtrim(BASE_URL, '/') . (str_starts_with($url, '/') ? $url : '/' . $url);
            } else {
                // Fallback si BASE_URL n'est pas défini (ne devrait pas arriver)
                $finalUrl = (str_starts_with($url, '/') ? '' : '/') . $url;
            }
        }
        return Response::redirect($finalUrl, $statusCode, $headers);
    }

    /**
     * Enregistre un message "flash" en session qui sera affiché une seule fois.
     *
     * @param string $type La clé du type de message (ex: FLASH_SUCCESS, FLASH_ERROR - définies dans config.php).
     * @param string $message Le message à stocker.
     * @return void
     */
    protected function flash(string $type, string $message): void
    {
        // S'assurer que la session est démarrée avant d'y écrire.
        // Normalement, index.php démarre la session globalement.
        if (session_status() === PHP_SESSION_NONE) {
            // Cette condition est une sécurité, mais peut indiquer un problème si la session n'est pas déjà active.
            // En général, il est préférable de démarrer la session une seule fois au début du script (ex: index.php).
            error_log("BaseController::flash() - Attention: La session a été démarrée tardivement.");
            session_start();
        }
        $_SESSION[$type] = $message;
    }

    /**
     * Garde d'accès objet : si l'utilisateur n'a pas le droit d'accéder à
     * $obj (cloisonnement par site), retourne une Response de redirection
     * avec flash. Sinon retourne null.
     *
     * À appeler juste après findById() (et après authorize()) :
     *   if ($r = $this->denyIfNotAccessible($obj, '/palettes')) return $r;
     */
    protected function denyIfNotAccessible(object $obj, string $redirectUrl = '/'): ?Response
    {
        $user = Auth::user();
        if ($user && method_exists($obj, 'isAccessibleBy') && !$obj->isAccessibleBy($user)) {
            $this->flash(FLASH_ERROR, "Accès non autorisé à cet élément.");
            return $this->redirect($redirectUrl);
        }
        return null;
    }
}