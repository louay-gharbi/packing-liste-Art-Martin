<?php
// src/Controllers/SearchController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Services\SearchService;
use App\Models\Colis;
use App\Models\Palette;
use App\Models\PackingList;

class SearchController extends BaseController
{
    private SearchService $searchService;

    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        // Permission générale de recherche, la même que pour le scan.
        $this->authorize('colis_scan');
        $this->searchService = new SearchService();
    }

    /**
     * Affiche la page de recherche unifiée.
     */
    public function index(Request $request): Response
    {
        $data = [
            'pageTitle' => 'Recherche Globale',
            'request' => $request,
        ];

        return $this->view('search/index.php', $data);
    }

    /**
     * Point d'entrée de l'API pour les recherches AJAX.
     */
    public function ajaxSearch(Request $request): Response
    {
        // Lecture via Request->json() (corps lu une seule fois au constructeur,
        // pas de re-lecture de php://input qui n est pas garanti rejouable).
        $query = trim((string) $request->json('query', ''));

        if (empty($query)) {
            return $this->json([]);
        }

        $results = $this->searchService->performSearch($query);

        return $this->json($results);
    }

    /**
     * Recherche globale instantanée pour la barre de recherche du header.
     * Retourne des résultats groupés : colis, palettes, packing lists.
     */
    public function globalSearch(Request $request): Response
    {
        $query = trim($request->get('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        // Cloisonnement par site : un utilisateur rattache a un site et non-admin
        // ne voit que les colis/palettes/PL de son site.
        $currentUser = Auth::user();
        $isScoped = $currentUser
            && $currentUser->site_id !== null
            && !$currentUser->hasRole('Administrateur');
        $scopedSiteId = $isScoped ? (int) $currentUser->site_id : null;

        $results = [
            'colis' => [],
            'palettes' => [],
            'packing_lists' => []
        ];

        try {
            $pdo = getPDO();
            $likeQuery = '%' . $query . '%';

            // Colis : on joint la PL pour filtrer par site_id si necessaire.
            // Resolution palette : COALESCE(c.palette_id, parent.palette_id) pour
            // que les colis enfants (groupages) heritent de la palette de leur parent.
            $colisSql = "SELECT c.id, c.package_number, c.of_number, c.sku, c.label, c.client_name,
                                p.id AS palette_id, p.code AS palette_code,
                                pl.id AS pl_id, pl.reference AS pl_reference
                         FROM colis c
                         LEFT JOIN colis parent ON c.parent_colis_id = parent.id
                         LEFT JOIN palettes p ON p.id = COALESCE(c.palette_id, parent.palette_id)
                         LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                         WHERE (c.package_number LIKE :q1
                            OR c.of_number LIKE :q2
                            OR c.sku LIKE :q3
                            OR c.order_number LIKE :q4
                            OR c.client_name LIKE :q5)";
            if ($isScoped) {
                $colisSql .= " AND pl.site_id = :sid";
            }
            $colisSql .= " LIMIT 10";
            $stmt = $pdo->prepare($colisSql);
            $stmt->bindValue(':q1', $likeQuery);
            $stmt->bindValue(':q2', $likeQuery);
            $stmt->bindValue(':q3', $likeQuery);
            $stmt->bindValue(':q4', $likeQuery);
            $stmt->bindValue(':q5', $likeQuery);
            if ($isScoped) { $stmt->bindValue(':sid', $scopedSiteId, \PDO::PARAM_INT); }
            $stmt->execute();
            $results['colis'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Palettes (top 3)
            $palSql = "SELECT p.id, p.code, p.status, pl.reference AS pl_reference
                       FROM palettes p
                       LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                       WHERE p.code LIKE :q";
            if ($isScoped) { $palSql .= " AND pl.site_id = :sid"; }
            $palSql .= " LIMIT 3";
            $stmt = $pdo->prepare($palSql);
            $stmt->bindValue(':q', $likeQuery);
            if ($isScoped) { $stmt->bindValue(':sid', $scopedSiteId, \PDO::PARAM_INT); }
            $stmt->execute();
            $results['palettes'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Packing Lists (top 3)
            $plSql = "SELECT pl.id, pl.reference, pl.status, s.name AS site_name
                      FROM packing_lists pl
                      LEFT JOIN sites s ON pl.site_id = s.id
                      WHERE pl.reference LIKE :q";
            if ($isScoped) { $plSql .= " AND pl.site_id = :sid"; }
            $plSql .= " LIMIT 3";
            $stmt = $pdo->prepare($plSql);
            $stmt->bindValue(':q', $likeQuery);
            if ($isScoped) { $stmt->bindValue(':sid', $scopedSiteId, \PDO::PARAM_INT); }
            $stmt->execute();
            $results['packing_lists'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            error_log("SearchController::globalSearch - " . $e->getMessage());
            return $this->json(['error' => 'Erreur base de données'], 500);
        }

        return $this->json($results);
    }

    public function ajaxSearchByOf(Request $request): Response
    {
        try {
            $query = trim((string) $request->json('query', ''));

            if (empty($query)) {
                return $this->json(['error' => 'OF number is required'], 400);
            }

            error_log("SearchController::ajaxSearchByOf - Lancement de la recherche pour l'OF: '{$query}'");
            $summary = Colis::getSummaryByOfNumber($query);
            error_log("SearchController::ajaxSearchByOf - Recherche terminée. Envoi de la réponse JSON.");
            
            return $this->json($summary);

        } catch (\Throwable $e) {
            error_log("--- ERREUR FATALE DANS ajaxSearchByOf ---");
            error_log("Message: " . $e->getMessage());
            error_log("Fichier: " . $e->getFile() . " Ligne: " . $e->getLine());
            error_log("Trace: " . $e->getTraceAsString());
            return $this->json(['error' => 'Erreur interne du serveur.'], 500);
        }
    }

    /**
     * Point d'entrée de l'API pour la recherche par SKU.
     */
    public function ajaxSearchBySku(Request $request): Response
    {
        try {
            $query = trim((string) $request->json('query', ''));

            if (empty($query)) {
                return $this->json(['error' => 'SKU is required'], 400);
            }

            error_log("SearchController::ajaxSearchBySku - Lancement de la recherche pour le SKU: '{$query}'");
            $summary = Colis::getSummaryBySku($query);
            error_log("SearchController::ajaxSearchBySku - Recherche terminée. Envoi de la réponse JSON.");

            return $this->json($summary);

        } catch (\Throwable $e) {
            error_log("--- ERREUR FATALE DANS ajaxSearchBySku ---");
            error_log("Message: " . $e->getMessage());
            error_log("Fichier: " . $e->getFile() . " Ligne: " . $e->getLine());
            error_log("Trace: " . $e->getTraceAsString());
            return $this->json(['error' => 'Erreur interne du serveur.'], 500);
        }
    }
}