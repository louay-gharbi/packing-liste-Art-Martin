<?php
// src/Controllers/DashboardController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\PackingList;
use App\Models\Palette;
use App\Models\Colis;
use App\Models\Site; 
use App\Models\User;

class DashboardController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    public function index(Request $request): Response
    {
        $this->authorize('dashboard_view'); 

        $currentUser = Auth::user();
        if (!$currentUser) { 
            $this->flash(FLASH_ERROR, "Session invalide ou utilisateur non trouvé.");
            return $this->redirect('/login');
        }

        $data = [
            'pageTitle' => 'Tableau de Bord',
            'request' => $request,
            'currentUser' => $currentUser,
            'activeScanPalette' => null,
            'stats' => [],
            'pendingPackingLists' => [],
            'currentUserSiteName' => 'Global (Tous Sites)', 
            'availableSitesForFilter' => [],
            'currentDashboardSiteFilter' => null,
            'dailyScansStats' => [],
            'recentActivity' => [],
        ];

        // --- 1. WIDGET OPÉRATEUR DE SCAN ---
        if (Auth::can('dashboard_view_operator_widget')) {
            $activePaletteId = $_SESSION['active_scan_palette_id'] ?? null;
            if ($activePaletteId) {
                $palette = Palette::findById((int)$activePaletteId);
                if ($palette && $palette->status === 'open') {
                    $plForActivePalette = PackingList::findById($palette->packing_list_id);
                    if ($plForActivePalette) { 
                        $palette->packingListReference = $plForActivePalette->reference;
                        $plForActivePalette->loadSite();
                        if ($plForActivePalette->site) {
                            $palette->siteName = $plForActivePalette->site->name;
                        }
                    }
                    $data['activeScanPalette'] = $palette;
                } else {
                    unset($_SESSION['active_scan_palette_id']); 
                }
            }
        }

        // --- 2. GESTION DES PERMISSIONS ET DU CONTEXTE DE SITE ---
        $canViewAllKPIs = Auth::can('dashboard_view_kpis_all_sites');
        $canViewOwnKPIs = Auth::can('dashboard_view_kpis_own_site');
        $canViewAllTasks = Auth::can('dashboard_view_pending_tasks_all_sites');
        $canViewOwnTasks = Auth::can('dashboard_view_pending_tasks_own_site');

        $userSiteId = $currentUser->site_id;
        $siteIdForStatsContext = null; 

        if ($canViewAllKPIs || $canViewAllTasks) {
            $data['availableSitesForFilter'] = Site::getAllActive();
            $filterSiteIdFromRequest = $request->get('dashboard_site_filter');
            
            if ($filterSiteIdFromRequest !== null && $filterSiteIdFromRequest !== '') {
                $siteIdForStatsContext = (int)$filterSiteIdFromRequest;
                $siteObj = Site::findById($siteIdForStatsContext);
                $data['currentUserSiteName'] = $siteObj ? $siteObj->name : "Site ID {$siteIdForStatsContext}";
            } else {
                $data['currentUserSiteName'] = 'Global (Tous Sites)';
                $siteIdForStatsContext = null;
            }
        } elseif (($canViewOwnKPIs || $canViewOwnTasks) && $userSiteId !== null) {
            $siteIdForStatsContext = $userSiteId;
            $siteObj = Site::findById($siteIdForStatsContext); 
            if ($siteObj) {
                $data['currentUserSiteName'] = $siteObj->name;
            } else {
                $data['currentUserSiteName'] = "Site ID {$userSiteId} (invalide)";
                $siteIdForStatsContext = -1;
            }
        } else {
            $siteIdForStatsContext = -1;
        }

        $data['currentDashboardSiteFilter'] = $siteIdForStatsContext;

        // --- 3. CHARGEMENT DES KPIs ---
        if ($siteIdForStatsContext !== -1 && ($canViewAllKPIs || ($canViewOwnKPIs && $userSiteId !== null))) { 
            $data['stats']['packingListsOpen'] = PackingList::countByStatusAndSite('open', $siteIdForStatsContext);
            $data['stats']['packingListsClosedToday'] = PackingList::countClosedTodayBySite($siteIdForStatsContext);
            $data['stats']['palettesGenerated'] = Palette::countByStatusAndSite('generated', $siteIdForStatsContext);
            $data['stats']['palettesOpen'] = Palette::countByStatusAndSite('open', $siteIdForStatsContext);
            $data['stats']['colisScannedToday'] = Colis::countScannedTodayBySite($siteIdForStatsContext);
            
            // Statistiques quotidiennes (graphique)
            $data['dailyScansStats'] = Colis::getDailyScansStats($siteIdForStatsContext);
            
            // Activité récente
            $data['recentActivity'] = Colis::getRecentActivity(8, $siteIdForStatsContext);
        }

        // --- 4. CHARGEMENT DES TÂCHES (PL en attente) ---
        if ($siteIdForStatsContext !== -1 && ($canViewAllTasks || ($canViewOwnTasks && $userSiteId !== null))) {
            $filtersPL = ['status' => 'open'];
            if ($siteIdForStatsContext !== null) {
                $filtersPL['site_id'] = $siteIdForStatsContext;
            }
            $data['pendingPackingLists'] = PackingList::getAll($filtersPL, 'pl.ship_date ASC, pl.id ASC LIMIT 8');
        }
        
        return $this->view('dashboard/index.php', $data);
    }
}