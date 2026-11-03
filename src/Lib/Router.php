<?php
// src/Lib/Router.php

namespace App\Lib;

use Closure; // Pour utiliser les fonctions anonymes (closures) comme handlers

/**
 * Classe Router
 * Gère la définition des routes et la distribution des requêtes aux contrôleurs appropriés.
 */
class Router
{
    /**
     * @var array Stocke toutes les routes enregistrées.
     * Chaque route est un tableau associatif contenant le chemin, la méthode, et le handler.
     */
    protected array $routes = [];

    /**
     * @var string Le préfixe de namespace pour les contrôleurs.
     * Utilisé pour construire le nom complet de la classe du contrôleur.
     */
    protected string $controllerNamespace = 'App\\Controllers\\';

    /**
     * @var string Le basePath de l'application, si elle est dans un sous-dossier.
     * Par exemple, '/packinglist/public'. Doit être configuré pour un routage correct.
     */
    private string $basePath = '';


    /**
     * Constructeur du Router.
     *
     * @param string $basePath Le chemin de base de l'application (ex: /packinglist/public)
     *                         Cela est nécessaire si l'application n'est pas à la racine du domaine.
     *                         Si APP_URL est 'http://localhost/packinglist/public', alors $basePath devrait être '/packinglist/public'.
     *                         Si l'application est à la racine (ex: http://localhost/), $basePath peut être une chaîne vide ''.
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Ajoute une route GET.
     *
     * @param string $path Le chemin de la route (ex: '/users/{id}').
     * @param string|array|Closure $handler Le handler de la route.
     *                                      Peut être 'ControllerName@methodName',
     *                                      [Controller::class, 'methodName'],
     *                                      ou une Closure.
     * @return $this
     */
    public function get(string $path, string|array|Closure $handler): self
    {
        $this->addRoute('GET', $path, $handler);
        return $this;
    }

    /**
     * Ajoute une route POST.
     *
     * @param string $path Le chemin de la route.
     * @param string|array|Closure $handler Le handler de la route.
     * @return $this
     */
    public function post(string $path, string|array|Closure $handler): self
    {
        $this->addRoute('POST', $path, $handler);
        return $this;
    }

    // Vous pouvez ajouter des méthodes pour PUT, DELETE, PATCH, etc. si nécessaire
    // public function put(...) { $this->addRoute('PUT', ...); }
    // public function delete(...) { $this->addRoute('DELETE', ...); }

    /**
     * Méthode interne pour ajouter une route à la collection.
     *
     * @param string $method La méthode HTTP (GET, POST, etc.).
     * @param string $path Le chemin de la route.
     * @param string|array|Closure $handler Le handler.
     */
    protected function addRoute(string $method, string $path, string|array|Closure $handler): void
    {
        // Normaliser le chemin: s'assurer qu'il commence par un / et ne se termine pas par un / (sauf pour la racine)
        $path = '/' . trim($path, '/');
        if ($path === '') $path = '/'; // Pour la route racine

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }

    /**
     * Traite la requête actuelle et appelle le handler de la route correspondante.
     *
     * @param Request $request L'objet Request contenant les informations de la requête.
     * @return mixed Le résultat de l'exécution du handler.
     * @throws \Exception Si aucune route ne correspond ou si le handler est invalide.
     */
    public function dispatch(Request $request): mixed
    {
        $requestMethod = $request->getMethod();
        $requestPath = $request->getPath(); // Utilise le chemin déjà nettoyé par Request

        // Ajuster requestPath si l'application est dans un sous-dossier
        // Le Request->getPath() retourne le chemin de l'URI complète.
        // Nous devons le comparer au chemin relatif à la racine de l'application.
        if (!empty($this->basePath) && strpos($requestPath, $this->basePath) === 0) {
            $routePathToMatch = substr($requestPath, strlen($this->basePath));
            if (empty($routePathToMatch)) { // Si après suppression du basePath on a une chaine vide, c'est la racine
                $routePathToMatch = '/';
            }
        } else {
            $routePathToMatch = $requestPath;
        }
        // S'assurer qu'il commence par un /
        if (!str_starts_with($routePathToMatch, '/')) {
            $routePathToMatch = '/' . $routePathToMatch;
        }


        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            // Convertir le chemin de la route en une expression régulière
            // Gère les paramètres de route comme /users/{id}
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_.-]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $routePathToMatch, $matches)) {
                // Supprimer les correspondances numériques pour ne garder que les paramètres nommés
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $this->callHandler($route['handler'], $params, $request);
            }
        }

        // Si aucune route n'est trouvée
        http_response_code(404);
        // Vous pouvez choisir de lancer une exception, d'afficher une vue 404, etc.
        // Pour l'instant, un simple message et on s'assure que la vue 404 est appelée depuis index.php
        throw new \Exception("404 - Page non trouvée pour la route '{$routePathToMatch}' (Méthode: {$requestMethod})");
    }

    /**
     * Appelle le handler de la route.
     *
     * @param string|array|Closure $handler Le handler.
     * @param array $params Les paramètres extraits de l'URI.
     * @param Request $request L'objet Request.
     * @return mixed Le résultat de l'appel du handler.
     * @throws \Exception Si le handler est invalide ou si le contrôleur/méthode n'existe pas.
     */
    protected function callHandler(string|array|Closure $handler, array $params, Request $request): mixed
    {
        if ($handler instanceof Closure) {
            // Passer l'objet Request et les paramètres à la Closure
            return call_user_func_array($handler, array_merge([$request], $params));
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controllerName, $methodName] = explode('@', $handler);
            $controllerClass = $this->controllerNamespace . $controllerName;
        } elseif (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            $controllerClass = $handler[0]; // Doit être le FQCN (Fully Qualified Class Name)
            $methodName = $handler[1];
            // S'assurer que le namespace est bien App\Controllers si ce n'est pas un FQCN
            if (!str_starts_with($controllerClass, 'App\\Controllers\\') && !class_exists($controllerClass)) {
                 $controllerClass = $this->controllerNamespace . $controllerClass;
            }
        } else {
            throw new \Exception("Handler de route invalide.");
        }

        if (!class_exists($controllerClass)) {
            throw new \Exception("Contrôleur '{$controllerClass}' non trouvé.");
        }

        $controllerInstance = new $controllerClass();

        if (!method_exists($controllerInstance, $methodName)) {
            throw new \Exception("Méthode '{$methodName}' non trouvée dans le contrôleur '{$controllerClass}'.");
        }

        // Passer l'objet Request en premier, suivi des paramètres de route
        $argsToPass = array_merge([$request], array_values($params)); // array_values pour réindexer $params
        return call_user_func_array([$controllerInstance, $methodName], $argsToPass);
    }
}