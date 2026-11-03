<?php
// src/Lib/Response.php

namespace App\Lib;

/**
 * Classe Response
 * Gère la construction et l'envoi des réponses HTTP.
 */
class Response
{
    protected string $content = '';
    protected int $statusCode = 200;
    protected array $headers = [];

    /**
     * Constructeur de la réponse.
     *
     * @param string $content Le contenu de la réponse (HTML, JSON string, etc.).
     * @param int $statusCode Le code de statut HTTP.
     * @param array $headers Les en-têtes HTTP supplémentaires.
     */
    public function __construct(string $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->setContent($content);
        $this->setStatusCode($statusCode);
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    /**
     * Définit le contenu de la réponse.
     *
     * @param string $content
     * @return $this
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Récupère le contenu de la réponse.
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Définit le code de statut HTTP.
     *
     * @param int $statusCode
     * @return $this
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Récupère le code de statut HTTP.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Ajoute ou modifie un en-tête HTTP.
     *
     * @param string $name Le nom de l'en-tête.
     * @param string $value La valeur de l'en-tête.
     * @return $this
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[strtolower(trim($name))] = trim($value);
        return $this;
    }

    /**
     * Récupère un en-tête HTTP.
     *
     * @param string $name Le nom de l'en-tête.
     * @return string|null La valeur de l'en-tête ou null si non défini.
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? null;
    }

    /**
     * Récupère tous les en-têtes.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Envoie les en-têtes et le contenu de la réponse au client.
     * Ne doit être appelée qu'une seule fois.
     */

    public function send(): void
    {
        if (headers_sent($file, $line)) {
        error_log("Response::send() - Attention : Les en-têtes ont déjà été envoyés par {$file} à la ligne {$line}.");
        // Dans ce cas, on ne peut qu'afficher le contenu, les appels à http_response_code et header() échoueront.
        echo $this->content;
        return;
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
    echo $this->content;
    }

    /**
     * Crée une réponse JSON.
     *
     * @param mixed $data Les données à encoder en JSON.
     * @param int $statusCode Le code de statut HTTP.
     * @param array $headers Les en-têtes HTTP supplémentaires.
     * @param int $options Les options pour json_encode (ex: JSON_PRETTY_PRINT).
     * @return self
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = [], int $options = 0): self
    {
        try {
            $jsonContent = json_encode($data, JSON_THROW_ON_ERROR | $options);
        } catch (\JsonException $e) {
            // Gérer l'erreur de l'encodage JSON, par exemple en renvoyant une erreur 500
            $errorData = ['error' => 'Erreur interne du serveur lors de la génération du JSON.', 'details' => $e->getMessage()];
            $jsonContent = json_encode($errorData, JSON_THROW_ON_ERROR | $options); // Essayer d'encoder l'erreur
            $statusCode = 500;
        }

        $defaultHeaders = ['Content-Type' => 'application/json; charset=utf-8'];
        $finalHeaders = array_merge($defaultHeaders, $headers);

        return new self($jsonContent, $statusCode, $finalHeaders);
    }

    /**
     * Crée une réponse de redirection.
     *
     * @param string $url L'URL vers laquelle rediriger.
     * @param int $statusCode Le code de statut HTTP pour la redirection (301, 302, etc.).
     * @param array $headers Les en-têtes HTTP supplémentaires.
     * @return self
     */
    public static function redirect(string $url, int $statusCode = 302, array $headers = []): self
    {
        $defaultHeaders = ['Location' => $url];
        $finalHeaders = array_merge($defaultHeaders, $headers);
        // Le contenu est généralement vide pour une redirection, mais on peut mettre un message
        $content = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\" /><meta http-equiv=\"refresh\" content=\"0;url={$url}\" /><title>Redirection</title></head><body><p>Redirection vers <a href=\"{$url}\">{$url}</a>.</p></body></html>";

        return new self($content, $statusCode, $finalHeaders);
    }

    /**
     * Crée une réponse HTML à partir d'un fichier de vue.
     *
     * @param string $viewPath Chemin vers le fichier de vue (relatif à VIEWS_PATH).
     * @param array $data Données à extraire et rendre disponibles dans la vue.
     * @param int $statusCode Le code de statut HTTP.
     * @param array $headers Les en-têtes HTTP supplémentaires.
     * @return self
     * @throws \Exception Si le fichier de vue n'est pas trouvé.
     */
    public static function html(string $viewPath, array $data = [], int $statusCode = 200, array $headers = []): self
    {
        $fullViewPath = VIEWS_PATH . '/' . ltrim($viewPath, '/');

        if (!file_exists($fullViewPath)) {
            // En production, vous pourriez vouloir logger cette erreur et retourner une réponse 500 générique.
            throw new \Exception("Fichier de vue non trouvé : {$fullViewPath}");
        }

        // Extraire les données pour les rendre accessibles dans la vue
        // comme des variables locales (ex: $data['title'] devient $title).
        extract($data);

        // Démarrer la mise en tampon de sortie
        ob_start();
        try {
            // Inclure le fichier de vue. Les variables extraites y seront disponibles.
            include $fullViewPath;
            // Récupérer le contenu du tampon
            $content = ob_get_contents();
        } catch (\Throwable $e) {
            ob_end_clean(); // Nettoyer le tampon en cas d'erreur dans la vue
            throw $e; // Relancer l'exception
        }
        // Arrêter et nettoyer le tampon de sortie
        ob_end_clean();

        $defaultHeaders = ['Content-Type' => 'text/html; charset=utf-8'];
        $finalHeaders = array_merge($defaultHeaders, $headers);

        return new self($content, $statusCode, $finalHeaders);
    }
}