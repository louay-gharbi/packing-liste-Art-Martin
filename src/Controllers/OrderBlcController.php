<?php
// src/Controllers/OrderBlcController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\OrderBlc;
use DateTime;

/**
 * Saisie des BLC AMF par commande (N° CDE AMTD). Permission : order_blc_manage.
 */
class OrderBlcController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    public function index(Request $request): Response
    {
        $this->authorize('order_blc_manage');
        $weeks = OrderBlc::getShipWeeks();

        // Semaine d'expédition ISO (?week=2026-W42, "all" = toutes) ; par défaut la plus récente.
        $weekParam = (string)$request->get('week', '');
        if ($weekParam === '' && $weeks) {
            $weekParam = sprintf('%d-W%02d', $weeks[0]['year'], $weeks[0]['week']);
        }
        $filters = [
            'week' => 'all',
            'q' => mb_substr(trim((string)$request->get('q', '')), 0, 100),
            'blc_status' => in_array($request->get('blc_status', ''), ['missing', 'filled'], true) ? $request->get('blc_status') : '',
        ];
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', $weekParam, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 53) {
            $monday = (new DateTime())->setISODate((int)$m[1], (int)$m[2]);
            $filters['week'] = sprintf('%d-W%02d', (int)$m[1], (int)$m[2]);
            $filters['date_from'] = $monday->format('Y-m-d');
            $filters['date_to'] = $monday->modify('+6 days')->format('Y-m-d');
        }

        $orders = OrderBlc::getOrders($filters);
        return $this->view('order_blc/index.php', [
            'pageTitle' => 'BLC AMF commandes',
            'orders' => $orders,
            'filters' => $filters,
            'weeks' => $weeks,
            'nbMissing' => count(array_filter($orders, fn($o) => $o['blc_amf'] === null)),
        ]);
    }

    /**
     * Enregistrement AJAX du BLC d'une commande (vide = retrait).
     * 200 enregistré/retiré · 409 BLC déjà utilisé par une autre commande · 400 saisie invalide · 404 commande inconnue.
     */
    public function saveAjax(Request $request): Response
    {
        $this->authorize('order_blc_manage');
        $order = trim((string)$request->post('order_number', ''));
        $blc = trim((string)$request->post('blc_amf', ''));
        if ($order === '' || !OrderBlc::orderExists($order)) {
            return Response::json(['error' => "Commande \"{$order}\" introuvable."], 404);
        }
        if (mb_strlen($blc) > OrderBlc::MAX_LENGTH) {
            return Response::json(['error' => "BLC AMF trop long (" . OrderBlc::MAX_LENGTH . " caractères max)."], 400);
        }

        $result = OrderBlc::save($order, $blc, Auth::id());
        switch ($result['status']) {
            case 'saved':
                return Response::json(['success' => true, 'message' => "Commande {$order} : BLC AMF {$blc} enregistré.", 'blc_amf' => $blc,
                                       'updated' => (new DateTime())->format('d/m/Y H:i') . ' · ' . (Auth::user()->username ?? '')]);
            case 'removed':
                return Response::json(['success' => true, 'message' => "Commande {$order} : BLC AMF retiré.", 'blc_amf' => '', 'updated' => '']);
            case 'duplicate':
                return Response::json(['error' => "Le BLC AMF \"{$blc}\" est déjà attribué à la commande {$result['other_order']}."], 409);
            default:
                return Response::json(['error' => "Erreur lors de l'enregistrement."], 500);
        }
    }
}
