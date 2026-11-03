<?php
// src/Lib/Request.php

namespace App\Lib;

/**
 * Classe Request
 * Gère l'accès aux données de la requête HTTP (GET, POST, SERVER, etc.).
 */
class Request
{
    private array $getParams;
    private array $postParams;
    private array $serverParams;
    /** Corps brut lu une seule fois au constructeur (php://input non-rejouable en sortie SAPI). */
    private string $rawBody = '';
    /** Payload JSON decode si Content-Type=application/json (sinon tableau vide). */
    private array $jsonPayload = [];
    private string $method;
    private string $uri;
    private string $path;

    /**
     * Constructeur.
     * Initialise les propriétés de la requête à partir des superglobales PHP.
     *
     * IMPORTANT : php://input n est pas garanti rejouable selon le SAPI et la
     * configuration. On le lit UNE SEULE FOIS ici et on met en cache, pour que
     * tout consommateur en aval (middleware CSRF puis controleur) puisse y
     * acceder sans dependre de l ordre d execution.
     */
    public function __construct()
    {
        $this->getParams = $_GET;
        $this->postParams = $_POST;
        $this->serverParams = $_SERVER;
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Déterminer le chemin sans les paramètres GET
        $this->path = parse_url($this->uri, PHP_URL_PATH) ?: '/';

        // Lecture systematique du corps brut (sauf GET ou similaire ou il est vide).
        // file_get_contents retourne false en cas d echec, normalise en chaine vide.
        $raw = @file_get_contents('php://input');
        $this->rawBody = is_string($raw) ? $raw : '';

        // Decoder en JSON seulement si Content-Type le signale, sinon laisser
        // jsonPayload = [] (acces par defaut sans erreur).
        if ($this->rawBody !== '' && str_contains((string) $this->server('CONTENT_TYPE', ''), 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->jsonPayload = $decoded;
            }
        }
    }

    /**
     * Retourne le corps brut de la requete (cache, lu une seule fois au constructeur).
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Récupère la méthode HTTP de la requête (GET, POST, PUT, DELETE, etc.).
     *
     * @return string La méthode HTTP.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Récupère l'URI complète de la requête.
     *
     * @return string L'URI.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Récupère le chemin de la requête (URI sans les paramètres GET).
     *
     * @return string Le chemin.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Récupère une valeur des paramètres GET.
     *
     * @param string $key La clé du paramètre.
     * @param mixed|null $default La valeur par défaut si la clé n'existe pas.
     * @return mixed La valeur du paramètre ou la valeur par défaut.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getParams[$key] ?? $default;
    }

    /**
     * Récupère tous les paramètres GET.
     *
     * @return array Les paramètres GET.
     */
    public function allGet(): array
    {
        return $this->getParams;
    }

    /**
     * Récupère une valeur des paramètres POST.
     *
     * @param string $key La clé du paramètre.
     * @param mixed|null $default La valeur par défaut si la clé n'existe pas.
     * @return mixed La valeur du paramètre ou la valeur par défaut.
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->postParams[$key] ?? $default;
    }

    /**
     * Récupère tous les paramètres POST.
     *
     * @return array Les paramètres POST.
     */
    public function allPost(): array
    {
        return $this->postParams;
    }

    /**
     * Récupère une valeur des paramètres du serveur ($_SERVER).
     *
     * @param string $key La clé du paramètre.
     * @param mixed|null $default La valeur par défaut si la clé n'existe pas.
     * @return mixed La valeur du paramètre ou la valeur par défaut.
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->serverParams[$key] ?? $default;
    }

    /**
     * Récupère tous les paramètres du serveur.
     *
     * @return array Les paramètres du serveur.
     */
    public function allServer(): array
    {
        return $this->serverParams;
    }

    /**
     * Récupère une valeur du payload JSON (si la requête est de type application/json).
     *
     * @param string $key La clé du paramètre dans le JSON.
     * @param mixed|null $default La valeur par défaut si la clé n'existe pas ou si le payload n'est pas JSON.
     * @return mixed La valeur du paramètre ou la valeur par défaut.
     */
    public function json(string $key, mixed $default = null): mixed
    {
        return $this->jsonPayload[$key] ?? $default;
    }

    /**
     * Récupère l'ensemble du payload JSON décodé.
     *
     * @return array|null Le payload JSON sous forme de tableau associatif, ou null.
     */
    public function allJson(): ?array
    {
        return !empty($this->jsonPayload) ? $this->jsonPayload : null;
    }

    /**
     * Vérifie si un paramètre POST existe.
     * @param string $key
     * @return bool
     */
    public function hasPost(string $key): bool
    {
        return isset($this->postParams[$key]);
    }

    /**
     * Vérifie si un paramètre GET existe.
     * @param string $key
     * @return bool
     */
    public function hasGet(string $key): bool
    {
        return isset($this->getParams[$key]);
    }
}