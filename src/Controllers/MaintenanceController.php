<?php
// src/Controllers/MaintenanceController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Maintenance;
use App\Lib\Request;
use App\Lib\Response;
use DateTime;

/**
 * Activation / désactivation du mode maintenance (rôle Administrateur uniquement).
 */
class MaintenanceController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        if (!Auth::hasRole(Maintenance::ADMIN_ROLE)) {
            $this->flash(FLASH_ERROR, 'Accès réservé aux administrateurs.');
            $this->redirect('/')->send();
            exit;
        }
    }

    public function index(Request $request): Response
    {
        return $this->view('maintenance/index.php', [
            'pageTitle' => 'Mode maintenance',
            'status' => Maintenance::status(),
        ]);
    }

    /** Aperçu de la page vue par les utilisateurs bloqués. */
    public function preview(Request $request): Response
    {
        $status = Maintenance::status();
        return Response::html('errors/maintenance.php', [
            'baseUrl' => BASE_URL,
            'appName' => APP_NAME,
            'message' => ($status['message'] ?? '') !== '' ? $status['message'] : "L'application est en maintenance.",
            'until' => $status['until'] ?? null,
            'isLoggedIn' => true,
            'preview' => true,
        ]);
    }

    public function enable(Request $request): Response
    {
        $message = trim((string)$request->post('message', ''));
        $untilInput = trim((string)$request->post('until', '')); // format datetime-local : 2026-11-03T18:30
        $until = null;
        if ($untilInput !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $untilInput);
            if (!$dt) {
                $this->flash(FLASH_ERROR, 'Date de fin prévue invalide.');
                return $this->redirect('/maintenance');
            }
            $until = $dt->format('Y-m-d H:i');
        }
        if (mb_strlen($message) > Maintenance::MAX_MESSAGE_LENGTH) {
            $this->flash(FLASH_ERROR, 'Message trop long (' . Maintenance::MAX_MESSAGE_LENGTH . ' caractères max).');
            return $this->redirect('/maintenance');
        }

        if (Maintenance::enable($message, $until, Auth::user()->username)) {
            error_log("Maintenance ACTIVÉE par " . Auth::user()->username);
            $this->flash(FLASH_SUCCESS, 'Mode maintenance activé : les autres utilisateurs sont bloqués.');
        } else {
            $this->flash(FLASH_ERROR, 'Impossible d\'activer la maintenance (dossier storage/ non accessible en écriture).');
        }
        return $this->redirect('/maintenance');
    }

    public function disable(Request $request): Response
    {
        if (Maintenance::disable()) {
            error_log("Maintenance DÉSACTIVÉE par " . Auth::user()->username);
            $this->flash(FLASH_SUCCESS, 'Mode maintenance désactivé : l\'application est de nouveau ouverte à tous.');
        } else {
            $this->flash(FLASH_ERROR, 'Impossible de désactiver la maintenance (fichier storage/maintenance.json non supprimable).');
        }
        return $this->redirect('/maintenance');
    }
}
