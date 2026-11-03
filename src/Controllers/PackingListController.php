<?php
// src/Controllers/PackingListController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\PackingList;
use App\Models\User; // Pour récupérer l'utilisateur créateur si besoin
use DateTime; // Pour la manipulation des dates
use App\Models\Palette;
use App\Models\Site;
use App\Models\Reception;
use App\Models\OrderBlc;
// Pour PhpSpreadsheet (XLSX)
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
// Pour Dompdf (PDF)
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Models\Colis;
use App\Lib\ExportSanitizer;

class PackingListController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect(); // Protéger toutes les actions de ce contrôleur
        // TODO: Affiner la protection basée sur les permissions spécifiques (ex: packinglist_view, packinglist_create)
        // if (!Auth::user()->hasPermission('packinglist_view')) { /* ... interdire ... */ }
    }

     /**
     * Méthode helper privée pour récupérer les sites disponibles pour l'utilisateur actuel
     * pour la création de Packing List.
     * @return array Tableau d'objets Site.
     */
    private function getAvailableSitesForCurrentUser(): array
    {
        $currentUser = Auth::user();
        $availableSites = [];

        if (!$currentUser) { // Sécurité au cas où Auth::protect() n'aurait pas été appelé ou aurait échoué
            return [];
        }

        if ($currentUser->hasRole('Administrateur') || $currentUser->site_id === null) {
            // L'administrateur ou un utilisateur global (non rattaché à un site) peut voir/choisir parmi tous les sites actifs.
            $availableSites = Site::getAllActiveProduction();
        } elseif ($currentUser->site_id !== null) {
            // L'utilisateur est rattaché à un site spécifique.
            $site = Site::findById($currentUser->site_id);
            if ($site && $site->is_active && !$site->isReceptionSite()) {
                // Il ne peut créer des PL que pour son propre site (s'il est actif).
                $availableSites = [$site];
            }
            // Si son site est inactif ou introuvable, $availableSites restera vide.
            // Le contrôleur appelant devra gérer ce cas (ex: interdire la création).
        }
        return $availableSites;
    }


    /**
     * Affiche la liste de toutes les Packing Lists.
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $this->authorize('packinglist_read_all');

        $currentUser = Auth::user();
        $filters = [];
        $availableSitesForFilter = Site::getAllActiveProduction(); // Sites pour le dropdown de filtre

        // Déterminer le site_id pour le filtre
        $filterSiteId = $request->get('filter_site_id');

        if ($currentUser->site_id !== null && !$currentUser->hasRole('Administrateur')) {
            // Si l'utilisateur est lié à un site et n'est pas admin, forcer le filtre sur son site
            $filters['site_id'] = $currentUser->site_id;
            // On pourrait limiter $availableSitesForFilter à son seul site s'il ne doit pas voir les autres options
            $currentFilterSiteId = $currentUser->site_id;
        } elseif ($filterSiteId !== null && $filterSiteId !== '') {
            // Si un filtre est appliqué par un admin (ou un utilisateur autorisé à voir plusieurs sites)
            $filters['site_id'] = (int)$filterSiteId;
            $currentFilterSiteId = (int)$filterSiteId;
        } else {
            // Aucun filtre de site explicite, ou l'utilisateur peut voir tous les sites
            $currentFilterSiteId = null;
        }
        
        // S'assurer que si $filters['site_id'] est '', null ou 0, il n'est pas appliqué
        if (isset($filters['site_id']) && ($filters['site_id'] === '' || $filters['site_id'] === 0)) {
            unset($filters['site_id']);
            $currentFilterSiteId = null;
        }

        $packingLists = PackingList::getAll($filters, 'pl.ship_date DESC, pl.id DESC');
        // PackingList::getAll a été modifié pour joindre le nom du site, donc pas besoin de loadSite en boucle ici

        $data = [
            'pageTitle' => 'Gestion des Packing Lists',
            'packingLists' => $packingLists,
            'availableSites' => $availableSitesForFilter,
            'currentFilterSiteId' => $currentFilterSiteId
        ];
        return $this->view('packing_lists/index.php', $data);
    }

    /**
     * Enregistre une nouvelle Packing List en base de données.
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $this->authorize('packinglist_create');
        $availableSitesForCreation = $this->getAvailableSitesForCurrentUser();
        if (empty($availableSitesForCreation) && !(Auth::user()->hasRole('Administrateur') && Auth::user()->site_id === null)) {
             $this->flash(FLASH_ERROR, "Aucun site actif ne vous est assigné pour la création de Packing List.");
             return $this->redirect('/packing-lists');
        }

        $defaultSiteId = (count($availableSitesForCreation) === 1) ? $availableSitesForCreation[0]->id : null;
        if (Auth::user()->site_id !== null && !$defaultSiteId) $defaultSiteId = Auth::user()->site_id;


        $data = [
            'pageTitle' => 'Créer une Packing List',
            'request' => $request, // Important pour que la vue puisse utiliser $request->post() si besoin (même si au premier affichage c'est vide)
            'availableSites' => $availableSitesForCreation,
            'pl_data' => [
                'ship_date' => (new \DateTime())->format('Y-m-d'),
                'site_id' => $request->post('site_id', $defaultSiteId) // Pré-remplir avec post ou défaut
            ]
        ];
        return $this->view('packing_lists/create.php', $data);
    }

    public function store(Request $request): Response
    {
        $this->authorize('packinglist_create');
       
        $shipDateString = trim($request->post('ship_date', ''));
        $siteId = $request->post('site_id', '');
        $siteId = ($siteId === '') ? null : (int)$siteId;

        $errors = [];
        if (empty($shipDateString)) { $errors['ship_date'] = 'La date d\'expédition est requise.'; }
        else { $d = \DateTime::createFromFormat('Y-m-d', $shipDateString); if (!$d || $d->format('Y-m-d') !== $shipDateString) { $errors['ship_date'] = 'Le format de la date d\'expédition est invalide (AAAA-MM-JJ attendu).'; }}

        if (empty($siteId)) {
            $errors['site_id'] = 'Le site est requis pour la Packing List.';
        } else {
            $site = Site::findById($siteId);
            if (!$site || !$site->is_active) { $errors['site_id'] = 'Le site sélectionné est invalide ou inactif.';}
            elseif ($site->isReceptionSite()) { $errors['site_id'] = 'Un site de réception ne peut pas porter de Packing List.'; }
        }
        
        $currentUser = Auth::user();
        if ($siteId && $currentUser->site_id !== null && !$currentUser->hasRole('Administrateur') && $currentUser->site_id !== $siteId) {
            $errors['site_id'] = "Vous n'êtes pas autorisé à créer une Packing List pour ce site.";
        }

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs dans le formulaire.');
            $availableSitesForCreation = $this->getAvailableSitesForCurrentUser();
            return $this->view('packing_lists/create.php', [
                'pageTitle' => 'Créer une Packing List',
                'errors' => $errors,
                'pl_data' => ['ship_date' => $shipDateString, 'site_id' => $siteId],
                'availableSites' => $availableSitesForCreation,
                'request' => $request // <<<=== S'ASSURER QUE $request EST PASSÉ ICI
            ]);
        }

        $userId = Auth::id();

        $newPackingList = PackingList::create($shipDateString, $siteId, $userId);

        if ($newPackingList) {
           
            $this->flash(FLASH_SUCCESS, 'Packing List "' . htmlspecialchars($newPackingList->reference) . '" créée avec succès.');
            return $this->redirect('/packing-lists/show/' . $newPackingList->id);
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la création de la Packing List. Vérifiez les logs et la configuration des patrons.');
            $availableSitesForCreation = $this->getAvailableSitesForCurrentUser();
            return $this->view('packing_lists/create.php', [
                'pageTitle' => 'Créer une Packing List',
                'pl_data' => ['ship_date' => $shipDateString, 'site_id' => $siteId],
                'availableSites' => $availableSitesForCreation,
                'request' => $request, // <<<=== S'ASSURER QUE $request EST PASSÉ ICI
                'db_error' => 'Une erreur interne est survenue. La référence n\'a peut-être pas pu être générée.'
            ]);
        }
    }

    public function show(Request $request, int $id): Response
        {
            $this->authorize('packinglist_read_all');
            $packingList = PackingList::findById($id);

            if (!$packingList) {
                $this->flash(FLASH_ERROR, "Packing List non trouvée.");
                return $this->redirect('/packing-lists');
            }
            if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

            $packingList->loadSite();
            $palettes = Palette::findByPackingListId($id);

            // Quantité totale scannée
            $totalScannedQuantityPL = $packingList->getTotalScannedQuantity();

            // Quantités par palette
            $paletteIds = array_map(fn($p) => $p->id, $palettes);
            $quantitiesByPalette = !empty($paletteIds) ? Palette::getQuantitiesForMultiplePalettes($paletteIds) : [];
            
            // NOUVEAU : Récupération de la synthèse agrégée
            $aggregationSummary = Colis::getAggregationSummaryByPackingList($id);

            $data = [
                'pageTitle' => 'Détail Packing List: ' . htmlspecialchars($packingList->reference),
                'packingList' => $packingList,
                'palettes' => $palettes,
                'totalScannedQuantityPL' => $totalScannedQuantityPL, 
                'quantitiesByPalette' => $quantitiesByPalette,
                'aggregationSummary' => $aggregationSummary, // Transmis à la vue
                'reception' => Reception::findByPackingListId($id) // Réception France éventuelle (lecture seule)
            ];
            
            return $this->view('packing_lists/show.php', $data);
        }

    /**
     * Affiche le formulaire d'édition d'une Packing List (pour la date d'expédition).
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $this->authorize('packinglist_update');
        $packingList = PackingList::findById($id);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée.");
            return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

        if ($packingList->status !== 'open') {
            $this->flash(FLASH_ERROR, "Seules les Packing Lists ouvertes peuvent être modifiées.");
            return $this->redirect('/packing-lists/show/' . $id);
        }
        $packingList->loadSite();
        $data = [
            'pageTitle' => 'Modifier Packing List: ' . htmlspecialchars($packingList->reference),
            'packingList' => $packingList,
            'request' => $request
        ];
        return $this->view('packing_lists/edit.php', $data);
    }

    /**
     * Met à jour la date d'expédition d'une Packing List.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        $this->authorize('packinglist_update');
        $packingList = PackingList::findById($id);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée.");
            return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

        if ($packingList->status !== 'open') {
            $this->flash(FLASH_ERROR, "Seules les Packing Lists ouvertes peuvent être modifiées.");
            return $this->redirect('/packing-lists/show/' . $id);
        }

        $shipDateString = trim($request->post('ship_date', ''));
        $errors = [];
        if (empty($shipDateString)) {
            $errors['ship_date'] = 'La date d\'expédition est requise.';
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $shipDateString);
            if (!$d || $d->format('Y-m-d') !== $shipDateString) {
                $errors['ship_date'] = 'Le format de la date d\'expédition est invalide (AAAA-MM-JJ attendu).';
            }
        }

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('packing_lists/edit.php', [
                'pageTitle' => 'Modifier Packing List: ' . htmlspecialchars($packingList->reference),
                'packingList' => $packingList, // Renvoyer l'objet original
                'errors' => $errors,
                'request' => $request // Pour pré-remplir avec la date POST si besoin
            ]);
        }

        if (PackingList::updateShipDate($id, $shipDateString)) {
            $this->flash(FLASH_SUCCESS, 'Date d\'expédition de la Packing List mise à jour.');
            return $this->redirect('/packing-lists/show/' . $id);
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la mise à jour de la date d\'expédition ou aucune modification effectuée.');
            // Recharger les données pour la vue
             return $this->view('packing_lists/edit.php', [
                'pageTitle' => 'Modifier Packing List: ' . htmlspecialchars($packingList->reference),
                'packingList' => PackingList::findById($id), // Recharger pour avoir la valeur actuelle
                'request' => $request, // Pour conserver les données soumises en cas de re-affichage
                'db_error' => 'Erreur de base de données ou aucune modification.'
            ]);
        }
    }

    /**
     * Ferme une Packing List.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function closePl(Request $request, int $id): Response
    {
        // Vérifier la permission 'packinglist_close' si implémentée
        // if (!Auth::user()->hasPermission('packinglist_close')) { ... }
        $this->authorize('packinglist_close');
        $pl = PackingList::findById($id);
        if (!$pl) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée.");
            return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($pl, '/packing-lists')) return $r;
        $userId = Auth::id();
        if (PackingList::close($id, $userId)) {
            $this->flash(FLASH_SUCCESS, 'Packing List fermée avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la fermeture de la Packing List. Elle est peut-être déjà fermée ou des conditions ne sont pas remplies.');
        }
        return $this->redirect('/packing-lists/show/' . $id);
    }

    /**
     * Supprime une Packing List.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function deletePl(Request $request, int $id): Response
    {
        $this->authorize('packinglist_delete');


        $packingList = PackingList::findById($id); // Récupérer pour le message flash

        if (!$packingList) {
             $this->flash(FLASH_ERROR, 'Packing List non trouvée.');
             return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

        if (PackingList::delete($id)) {
            $this->flash(FLASH_SUCCESS, 'Packing List "' . htmlspecialchars($packingList->reference) . '" supprimée avec succès.');
            return $this->redirect('/packing-lists'); // Rediriger vers la liste après suppression
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la suppression de la Packing List. Vérifiez les conditions (statut, palettes associées).');
            return $this->redirect('/packing-lists/show/' . $id);
        }
    }
    
    // Méthode privée pour les données par défaut de la vue (si BaseController ne le fait pas déjà de manière accessible)
    private function getDefaultViewData(): array
    {
        return [
            'appName' => APP_NAME,
            'baseUrl' => BASE_URL,
            'pageTitle' => $this->pageTitle ?? 'Application',
        ];
    }
    // Propriété pour stocker le titre de la page pour le layout temporaire
    private ?string $pageTitle = null;


  /**
     * Génère plusieurs palettes pour une Packing List donnée.
     * @param Request $request
     * @param int $packingListId
     * @return Response
     */
    public function generateMultiplePalettes(Request $request, int $packingListId): Response
    {
        $this->authorize('palette_create'); // Utiliser la même permission que pour créer une seule palette

        $packingList = PackingList::findById($packingListId);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List parente non trouvée.");
            return $this->redirect($request->server('HTTP_REFERER', '/packing-lists'));
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

        if ($packingList->status !== 'open') {
            $this->flash(FLASH_ERROR, "Les palettes ne peuvent être ajoutées qu'à des Packing Lists ouvertes.");
            return $this->redirect('/packing-lists/show/' . $packingListId);
        }

        $numberOfPalettes = (int)$request->post('number_of_palettes_to_generate', 0);

        if ($numberOfPalettes <= 0 || $numberOfPalettes > 50) { // Limiter pour éviter les abus/erreurs
            $this->flash(FLASH_ERROR, "Nombre de palettes à générer invalide (doit être entre 1 et 50).");
            return $this->redirect('/packing-lists/show/' . $packingListId);
        }

        $userId = Auth::id();
        $palettesGeneratedCount = 0;
        $errorsEncountered = 0;
        $generatedPaletteCodes = [];

        for ($i = 0; $i < $numberOfPalettes; $i++) {
            $newPalette = Palette::create($packingListId, $userId);
            if ($newPalette) {
                $palettesGeneratedCount++;
                $generatedPaletteCodes[] = $newPalette->code; // Stocker les codes pour un message
            } else {
                $errorsEncountered++;
                error_log("PackingListController::generateMultiplePalettes - Erreur lors de la génération de la palette " . ($i + 1) . "/{$numberOfPalettes} pour PL ID {$packingListId}");
            }
        }

        if ($palettesGeneratedCount > 0) {
            $this->flash(FLASH_SUCCESS, "{$palettesGeneratedCount} palette(s) générée(s) avec succès : " . implode(', ', $generatedPaletteCodes));
        }
        if ($errorsEncountered > 0) {
            $this->flash(FLASH_ERROR, "{$errorsEncountered} erreur(s) rencontrée(s) lors de la génération de palettes multiples. Vérifiez les logs.");
        }
        if ($palettesGeneratedCount === 0 && $errorsEncountered === 0 && $numberOfPalettes > 0) {
             $this->flash(FLASH_INFO, "Aucune palette n'a été demandée pour la génération.");
        }


        return $this->redirect('/packing-lists/show/' . $packingListId);
    }

      /**
     * Réouvre une Packing List.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function reopenPl(Request $request, int $id): Response
    {
        $this->authorize('packinglist_reopen');

        $pl = PackingList::findById($id); // Pour le message flash
        if (!$pl) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée.");
            return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($pl, '/packing-lists')) return $r;

        if (Reception::findByPackingListId($id) !== null) {
            $this->flash(FLASH_ERROR, 'La Packing List "' . htmlspecialchars($pl->reference) . '" est en cours de réception / réceptionnée : réouverture impossible.');
            return $this->redirect('/packing-lists/show/' . $id);
        }

        if (PackingList::reopen($id)) {
            $this->flash(FLASH_SUCCESS, 'Packing List "' . htmlspecialchars($pl->reference) . '" réouverte avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la réouverture de la Packing List "' . htmlspecialchars($pl->reference) . '". Elle n\'était peut-être pas fermée.');
        }
        return $this->redirect('/packing-lists/show/' . $id);
    }
     /**
     * Exporte la liste des colis d'une Packing List au format XLSX (Excel).
     * @param Request $request
     * @param int $packingListId
     * @return void Action de téléchargement.
     */
    public function exportColisToXlsx(Request $request, int $packingListId): void
    {
        $this->authorize('packinglist_export_colis_xlsx');

        $packingList = PackingList::findById($packingListId);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée pour l'export Excel.");
            $response = $this->redirect($request->server('HTTP_REFERER', '/packing-lists'));
            $response->send();
            exit;
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) { $r->send(); exit; }

        $colisList = Colis::findAllByPackingListId($packingListId, 'p.code ASC, c.package_number ASC');

        // Préparer des informations pour déterminer les parents avec enfants
        $childrenByParent = [];
        foreach ($colisList as $colis) {
            if ($colis->parent_colis_id !== null) {
                $childrenByParent[$colis->parent_colis_id][] = $colis;
            }
        }

        $paletteCodes = [];
        $totalQuantity = 0;
        foreach ($colisList as $colis) {
            $code = $colis->palette_code;
            if (!$code && $colis->palette_id) {
                $paletteObj = Palette::findById($colis->palette_id);
                $code = $paletteObj ? $paletteObj->code : null;
            }
            if ($code) {
                $paletteCodes[] = $code;
            }
            if (is_numeric($colis->quantity)) {
                $totalQuantity += (int)$colis->quantity;
            }
        }
        $parentColisOnly = array_filter($colisList, function ($c) { return $c->parent_colis_id === null; });
        $summaryData = [
            'total_palettes' => count(array_unique($paletteCodes)),
            'total_colis' => count($parentColisOnly),
            'total_quantity' => $totalQuantity,
        ];

        $paletteCodes = [];
        $totalQuantity = 0;
        foreach ($colisList as $colis) {
            $code = $colis->palette_code;
            if (!$code && $colis->palette_id) {
                $paletteObj = Palette::findById($colis->palette_id);
                $code = $paletteObj ? $paletteObj->code : null;
            }
            if ($code) {
                $paletteCodes[] = $code;
            }
            if (is_numeric($colis->quantity)) {
                $totalQuantity += (int)$colis->quantity;
            }
        }
        $parentColisOnly = array_filter($colisList, function ($c) { return $c->parent_colis_id === null; });
        $summaryData = [
            'total_palettes' => count(array_unique($paletteCodes)),
            'total_colis' => count($parentColisOnly),
            'total_quantity' => $totalQuantity,
        ];

        // Trier par code palette puis numéro de colis parent (ou numéro courant)
        usort($colisList, function ($a, $b) {
            $keyA = ($a->palette_code ?? '') . '|' . ($a->parent_package_number ?: $a->package_number) . '|' . $a->package_number;
            $keyB = ($b->palette_code ?? '') . '|' . ($b->parent_package_number ?: $b->package_number) . '|' . $b->package_number;
            return $keyA <=> $keyB;
        });

        if (empty($colisList)) {
            $this->flash(FLASH_INFO, "Aucun colis à exporter pour la Packing List " . htmlspecialchars($packingList->reference) . ".");
            $response = $this->redirect('/packing-lists/show/' . $packingListId);
            $response->send();
            exit;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Colis PL ' . substr(preg_replace('/[^a-zA-Z0-9]/', '_', $packingList->reference), 0, 25)); // Nom d'onglet valide

        // Insérer le logo en haut à gauche si disponible
        $logoPath = ROOT_PATH . '/logo.jpg';
        if (is_readable($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(40);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($sheet);
        }

        // Informations de la Packing List en haut du fichier
        $sheet->setCellValue('C1', 'Packing List:');
        $sheet->setCellValue('D1', $packingList->reference);
        $sheet->getStyle('C1:D1')->getFont()->setBold(true);
        $sheet->setCellValue('C2', "Date d'expédition:");
        $sheet->setCellValue('D2', (new DateTime($packingList->ship_date))->format('d/m/Y'));
        $sheet->getStyle('C2:D2')->getFont()->setBold(true);
        $sheet->mergeCells('D1:F1'); // Fusionner pour le titre
        $sheet->mergeCells('D2:F2'); // Fusionner pour la date

        // En-têtes du tableau des colis (commençant à la ligne 4)
        $headerRow = 4;
        $headers = [
            'Palette',
            'Colis',
            'Numéro lot',
            'Quantité',
            'Unité',
            'N°CDE AMTD',
            'N°Ligne',
            'Réf. interne',
            'BLC AMF',
            'Client',
            'Code postal livraison',
            'Code article',
            'Libellé',
            'Référencement client',
            'Poids brut (Kg)',
            'Commentaire'
        ];
        $sheet->fromArray($headers, NULL, 'A' . $headerRow);
        $sheet->getStyle('A' . $headerRow . ':' . $sheet->getHighestDataColumn() . $headerRow)->getFont()->setBold(true);
        $sheet->getStyle('A' . $headerRow . ':' . $sheet->getHighestDataColumn() . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


        $blcMap = OrderBlc::getMap(array_map(fn($c) => $c->order_number, $colisList));
        $currentRow = $headerRow + 1;
        foreach ($colisList as $colis) {
            $paletteCode = $colis->palette_code ?? null;
            if (!$paletteCode && $colis->palette_id) {
                $paletteObj = Palette::findById($colis->palette_id);
                $paletteCode = $paletteObj ? $paletteObj->code : 'N/A';
            }

            // Si c'est un parent avec enfants, on ignore la ligne
            if ($colis->type === 'parent' && isset($childrenByParent[$colis->id])) {
                continue;
            }

            if ($colis->type === 'parent') {
                // Parent sans enfant : afficher commentaire sur cellules fusionnées
                $sheet->setCellValue('A'.$currentRow, ExportSanitizer::cell($paletteCode ?: 'N/A'));
                $sheet->setCellValue('B'.$currentRow, ExportSanitizer::cell($colis->package_number));
                $sheet->mergeCells("E{$currentRow}:P{$currentRow}");
                $sheet->setCellValue('E'.$currentRow, ExportSanitizer::cell($colis->comment ?? ''));
            } else {
                $row = [
                    ExportSanitizer::cell($paletteCode ?: 'N/A'),
                    ExportSanitizer::cell($colis->parent_package_number ?: $colis->package_number),
                    ExportSanitizer::cell($colis->of_number ?? ''),
                    // Quantite : valeur numerique calculee, pas de sanitization.
                    $colis->quantity ?? '',
                    ExportSanitizer::cell($colis->unit ?? ''),
                    ExportSanitizer::cell($colis->order_number ?? ''),
                    ExportSanitizer::cell($colis->line_number ?? ''),
                    ExportSanitizer::cell($colis->internal_reference ?? ''),
                    ExportSanitizer::cell($blcMap[(string)$colis->order_number] ?? ''),
                    ExportSanitizer::cell($colis->client_name ?? ''),
                    ExportSanitizer::cell($colis->postal_code ?? ''),
                    ExportSanitizer::cell($colis->article_reference ?? ''),
                    ExportSanitizer::cell($colis->label ?? ''),
                    ExportSanitizer::cell($colis->sku ?? ''),
                    '',
                    ExportSanitizer::cell($colis->comment ?? '')
                ];
                $sheet->fromArray($row, NULL, 'A'.$currentRow);
            }
            $currentRow++;
        }

        $lastDataRow = $currentRow - 1;

        // Appliquer un quadrillage sur le tableau des colis
        $sheet->getStyle("A{$headerRow}:P{$lastDataRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Ajouter un récapitulatif en dessous du tableau
        $summaryStart = $lastDataRow + 2;
        $sheet->setCellValue('A'.$summaryStart, 'Récapitulatif');
        $sheet->mergeCells("A{$summaryStart}:B{$summaryStart}");
        $sheet->getStyle('A'.$summaryStart)->getFont()->setBold(true);
        $sheet->setCellValue('A'.($summaryStart + 1), 'Nombre total des palettes');
        $sheet->setCellValue('B'.($summaryStart + 1), $summaryData['total_palettes']);
        $sheet->setCellValue('A'.($summaryStart + 2), 'Nombre total des colis');
        $sheet->setCellValue('B'.($summaryStart + 2), $summaryData['total_colis']);
        $sheet->setCellValue('A'.($summaryStart + 3), 'Quantité totale');
        $sheet->setCellValue('B'.($summaryStart + 3), $summaryData['total_quantity']);
        $sheet->getStyle("A{$summaryStart}:B".($summaryStart+3))
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Auto-ajuster la largeur des colonnes
        foreach (range('A', $sheet->getHighestDataColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "export_colis_PL_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $packingList->reference) . "_" . date('Ymd_His') . ".xlsx";
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        ob_end_clean(); // Nettoyer tout output buffer avant d'envoyer le fichier
        $writer->save('php://output');
        exit;
    }

    /**
     * Exporte la liste des colis d'une Packing List au format PDF.
     * @param Request $request
     * @param int $packingListId
     * @return void Action de téléchargement.
     */
    public function exportColisToPdf(Request $request, int $packingListId): void
    {
        $this->authorize('packinglist_export_colis_pdf');

        $packingList = PackingList::findById($packingListId);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée pour l'export PDF.");
            $response = $this->redirect($request->server('HTTP_REFERER', '/packing-lists'));
            $response->send();
            exit;
        }
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) { $r->send(); exit; }

        $colisList = Colis::findAllByPackingListId($packingListId, 'p.code ASC, c.package_number ASC');

        // Préparer des informations pour déterminer les parents avec enfants
        $childrenByParent = [];
        foreach ($colisList as $colis) {
            if ($colis->parent_colis_id !== null) {
                $childrenByParent[$colis->parent_colis_id][] = $colis;
            }
        }

        // Récapitulatif : mêmes règles que l'export XLSX (exportColisToXlsx).
        $paletteCodes = [];
        $totalQuantity = 0;
        foreach ($colisList as $colis) {
            if ($colis->palette_code) {
                $paletteCodes[] = $colis->palette_code;
            }
            if (is_numeric($colis->quantity)) {
                $totalQuantity += (int)$colis->quantity;
            }
        }
        $summaryData = [
            'total_palettes' => count(array_unique($paletteCodes)),
            'total_colis' => count(array_filter($colisList, fn($c) => $c->parent_colis_id === null)),
            'total_quantity' => $totalQuantity,
        ];

        // Trier par code palette puis numéro de colis parent (ou numéro courant)
        usort($colisList, function ($a, $b) {
            $keyA = ($a->palette_code ?? '') . '|' . ($a->parent_package_number ?: $a->package_number) . '|' . $a->package_number;
            $keyB = ($b->palette_code ?? '') . '|' . ($b->parent_package_number ?: $b->package_number) . '|' . $b->package_number;
            return $keyA <=> $keyB;
        });

        if (empty($colisList)) {
            $this->flash(FLASH_INFO, "Aucun colis à exporter en PDF pour la Packing List " . htmlspecialchars($packingList->reference) . ".");
            $response = $this->redirect('/packing-lists/show/' . $packingListId);
            $response->send();
            exit;
        }

        // Préparer les données pour la vue HTML qui sera convertie en PDF
        $logoBase64 = null;
        $logoPath = ROOT_PATH . '/logo.jpg';
        if (is_readable($logoPath)) {
            $logoBase64 = base64_encode(file_get_contents($logoPath));
        }

        $dataForView = [
            // 'pageTitle' n'est pas directement utilisé par la vue export_colis_table mais bon à avoir pour le contexte
            'pageTitle' => 'Liste des Colis - PL: ' . htmlspecialchars($packingList->reference),
            'packingList' => $packingList,
            'colisList' => $colisList,
            'childrenByParent' => $childrenByParent,
            'summaryData' => $summaryData,
            'blcMap' => OrderBlc::getMap(array_map(fn($c) => $c->order_number, $colisList)),
            'appName' => APP_NAME,
            'currentDate' => (new \DateTime())->format('d/m/Y H:i'),
            // Passer baseUrl et appName si votre vue partielle les utilise, sinon pas nécessaire pour Dompdf
            'baseUrl' => BASE_URL,
            'logoBase64' => $logoBase64
        ];

        // Rendre la vue HTML en une chaîne de caractères
        // Utiliser renderPartial pour obtenir le HTML brut sans le layout complet de l'application admin
        $htmlContent = $this->renderPartial('packing_lists/export_colis_table.php', $dataForView);

        // Configurer Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false); // Mettre à true seulement si vous chargez des images/CSS externes (et maîtrisez les risques)
        $options->set('defaultFont', 'DejaVu Sans'); // Police qui supporte bien UTF-8, souvent incluse

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'landscape'); // Format A4 paysage
        
        // Rendre le PDF
        $dompdf->render();

        $filename = "export_colis_PL_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $packingList->reference) . "_" . date('Ymd_His') . ".pdf";
        
        // Nettoyer tout output buffer avant d'envoyer le PDF
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Envoyer le PDF au navigateur pour téléchargement
        $dompdf->stream($filename, ["Attachment" => true]);
        exit;
    }

public function exportSynthesisToXlsx(Request $request, int $id): void
{
    $this->authorize('packinglist_export_colis_xlsx');
    $packingList = PackingList::findById($id);
    if (!$packingList) {
        $this->flash(FLASH_ERROR, "Packing List non trouvée pour l'export synthèse.");
        $r = $this->redirect($request->server('HTTP_REFERER', '/packing-lists'));
        $r->send();
        exit;
    }
    if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) { $r->send(); exit; }
    $data = Colis::getAggregationSummaryByPackingList($id);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Synthese');

    // --- 1. ENTÊTES ---
    $headers = ['Client', 'CP Livraison', 'N° Commande', 'N° Ligne', 'Code Article', 'Quantité Totale'];
    $sheet->fromArray($headers, NULL, 'A1');
    $sheet->getStyle('A1:F1')->getFont()->setBold(true);
    $sheet->getStyle('A1:F1')->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('E0E0E0');

    // --- 2. REMPLISSAGE DES DONNÉES ---
    $currentRow = 2;
    foreach ($data as $row) {
        if ($row['is_total']) {
            // Sécurité anti-formule (=) et mise en gras du total
            $sheet->setCellValueExplicit('A' . $currentRow, $row['client'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue('F' . $currentRow, (float)$row['quantite_totale']);
            
            $sheet->getStyle("A{$currentRow}:F{$currentRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$currentRow}:F{$currentRow}")->getFill()
                  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setARGB('D9EAF7');
        } else {
            // Champs d'origine non maitrisee : sanitization pour neutraliser les formules.
            $sheet->setCellValueExplicit('A' . $currentRow, ExportSanitizer::cell($row['client']),       \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $currentRow, ExportSanitizer::cell($row['cp_livraison']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $currentRow, ExportSanitizer::cell($row['num_commande']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $currentRow, ExportSanitizer::cell($row['ligne']),        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $currentRow, ExportSanitizer::cell($row['code_article']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $currentRow, (float)$row['quantite_totale']);
        }
        $currentRow++;
    }

    $lastDataRow = $currentRow - 2; // Dernière ligne avant le total général

    // --- 3. LOGIQUE DE FUSION HIÉRARCHIQUE ---
    // On fusionne si la valeur est identique ET si le parent est identique
    $columnsToMerge = ['A', 'B', 'C', 'D'];
    foreach ($columnsToMerge as $colIndex => $colLetter) {
        $startMergeRow = 2;
        for ($i = 2; $i <= $lastDataRow; $i++) {
            $currentVal = $sheet->getCell($colLetter . $i)->getValue();
            $nextVal    = $sheet->getCell($colLetter . ($i + 1))->getValue();
            
            // Vérification de la colonne parente (pour éviter de fusionner deux commandes 10 de deux clients différents)
            $parentMatch = true;
            if ($colIndex > 0) {
                $parentCol = $columnsToMerge[$colIndex - 1];
                $parentMatch = ($sheet->getCell($parentCol . $i)->getValue() === $sheet->getCell($parentCol . ($i + 1))->getValue());
            }

            if ($currentVal !== $nextVal || !$parentMatch || $i === $lastDataRow) {
                if ($i > $startMergeRow) {
                    $sheet->mergeCells($colLetter . $startMergeRow . ':' . $colLetter . $i);
                    $sheet->getStyle($colLetter . $startMergeRow)->getAlignment()
                          ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                          ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }
                $startMergeRow = $i + 1;
            }
        }
    }

    // --- 4. FORMATAGE FINAL ---
    // Format numérique pour la colonne Quantité (F) avec 2 décimales
    $sheet->getStyle('F2:F' . ($currentRow - 1))
          ->getNumberFormat()
          ->setFormatCode('#,##0.00');

    // Bordures
    $sheet->getStyle('A1:F' . ($currentRow - 1))
          ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

    // Auto-ajustement des colonnes
    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Envoi du fichier
    $filename = "Synthese_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $packingList->reference) . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    if (ob_get_length()) ob_end_clean(); // Sécurité pour éviter toute corruption du fichier binaire
    $writer->save('php://output');
    exit;
}
    }
