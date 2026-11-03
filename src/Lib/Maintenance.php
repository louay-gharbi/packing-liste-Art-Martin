<?php
// src/Lib/Maintenance.php

namespace App\Lib;

/**
 * Mode maintenance : tant qu'il est actif, seuls les administrateurs utilisent l'application.
 * L'état est un fichier (storage/maintenance.json) et non une table, pour pouvoir bloquer
 * l'application même pendant une intervention sur la base de données.
 */
class Maintenance
{
    public const ADMIN_ROLE = 'Administrateur';
    public const MAX_MESSAGE_LENGTH = 500;

    // Routes toujours accessibles : sans elles, un administrateur déconnecté ne pourrait plus revenir.
    private const ALWAYS_ALLOWED = ['/login', '/logout'];

    public static function file(): string
    {
        return ROOT_PATH . '/storage/maintenance.json';
    }

    /**
     * @return array|null ['message', 'until' (Y-m-d H:i ou null), 'started_at', 'started_by'] ou null si inactif
     */
    public static function status(): ?array
    {
        $file = self::file();
        if (!is_file($file)) return null;
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function isActive(): bool
    {
        return self::status() !== null;
    }

    public static function enable(string $message, ?string $until, string $startedBy): bool
    {
        $dir = dirname(self::file());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            error_log("Maintenance::enable - impossible de créer {$dir}");
            return false;
        }
        $data = [
            'message' => mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH),
            'until' => $until,
            'started_at' => date('Y-m-d H:i:s'),
            'started_by' => $startedBy,
        ];
        // Écriture dans un fichier temporaire puis renommage : jamais de fichier à moitié écrit.
        $tmp = self::file() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, self::file())) {
            error_log("Maintenance::enable - écriture impossible de " . self::file());
            return false;
        }
        return true;
    }

    public static function disable(): bool
    {
        return !is_file(self::file()) || @unlink(self::file());
    }

    /** L'utilisateur connecté peut-il utiliser l'application pendant la maintenance ? */
    public static function canBypass(): bool
    {
        return Auth::isLoggedIn() && Auth::hasRole(self::ADMIN_ROLE);
    }

    /**
     * Réponse de blocage si la maintenance est active et que la requête n'est pas autorisée, sinon null.
     * $path est le chemin relatif à l'application (ex. "/packing-lists").
     */
    public static function blockingResponse(Request $request, string $path): ?Response
    {
        $status = self::status();
        if ($status === null || in_array($path, self::ALWAYS_ALLOWED, true) || self::canBypass()) {
            return null;
        }

        $headers = [];
        if (!empty($status['until']) && ($ts = strtotime($status['until'])) && $ts > time()) {
            $headers['Retry-After'] = (string)($ts - time());
        }
        $message = $status['message'] !== '' ? $status['message'] : "L'application est en maintenance.";

        $accept = (string)$request->server('HTTP_ACCEPT', '');
        $xrw = (string)$request->server('HTTP_X_REQUESTED_WITH', '');
        if (str_contains($accept, 'application/json') || strcasecmp($xrw, 'XMLHttpRequest') === 0) {
            return Response::json(['error' => $message, 'maintenance' => true], 503, $headers);
        }
        return Response::html('errors/maintenance.php', [
            'baseUrl' => BASE_URL,
            'appName' => APP_NAME,
            'message' => $message,
            'until' => $status['until'] ?? null,
            'isLoggedIn' => Auth::isLoggedIn(),
        ], 503, $headers);
    }
}
