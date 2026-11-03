<?php
// src/Controllers/PaletteController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Palette;
use App\Models\PackingList;
use App\Models\Site; // Pour le filtre par site
use App\Models\User; // Pour récupérer les noms des utilisateurs
use App\Models\Colis;
use App\Services\QrCodeService;


class PaletteController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
       
    }

    /**
     * Affiche le formulaire de création d'une palette pour une Packing List donnée.
     * Ou, plus simplement, génère directement la palette.
     * Le cahier des charges indique: "Depuis la vue détaillée d'une Packing List open".
     * Donc, cette action pourrait être juste la génération et la redirection.
     * Pour l'instant, on va dire que c'est l'action qui génère la palette.
     *
     * @param Request $request
     * @param int $packingListId L'ID de la Packing List parente.
     * @return Response
     */
    public function storeForPackingList(Request $request, int $packingListId): Response
    {
        $this->authorize('palette_create');
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

        $userId = Auth::id();
        $newPalette = Palette::create($packingListId, $userId);

        if ($newPalette) {
            $this->flash(FLASH_SUCCESS, "Palette " . htmlspecialchars($newPalette->code) . " générée avec succès pour la PL " . htmlspecialchars($packingList->reference) . ".");
            // Idéalement, on pourrait générer et afficher le QR code ici ou rediriger vers une page qui le fait.
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la génération de la palette. Vérifiez les logs et la configuration des patrons.");
        }
        return $this->redirect('/packing-lists/show/' . $packingListId);
    }
    /**
 * Affiche la liste de toutes les palettes (optionnel).
 * @param Request $request
 * @return Response
 */
public function indexAll(Request $request): Response
    {
        $this->authorize('palette_read_all');

        $filters = [];
        $whereClauses = ["1=1"]; // Toujours vrai pour commencer
        $params = [];

        // Filtre par Site (pour Admin)
        $currentUser = Auth::user();
        $availableSitesForFilter = Site::getAllActive();
        $filterSiteId = $request->get('filter_site_id');

        if ($currentUser->site_id !== null && !$currentUser->hasRole('Administrateur')) {
            // Forcer le filtre sur le site de l'utilisateur non-admin
            $whereClauses[] = "pl.site_id = :user_site_id";
            $params[':user_site_id'] = $currentUser->site_id;
            $currentFilterSiteId = $currentUser->site_id;
            // Limiter les sites disponibles dans le dropdown pour cet utilisateur
            $siteOfUser = Site::findById($currentUser->site_id);
            $availableSitesForFilter = $siteOfUser ? [$siteOfUser] : [];
        } elseif ($filterSiteId !== null && $filterSiteId !== '') {
            $whereClauses[] = "pl.site_id = :filter_site_id";
            $params[':filter_site_id'] = (int)$filterSiteId;
            $currentFilterSiteId = (int)$filterSiteId;
        } else {
            $currentFilterSiteId = null;
        }

        // Filtre par Statut de Palette
        $filterStatus = $request->get('filter_palette_status');
        if ($filterStatus !== null && $filterStatus !== '') {
            $whereClauses[] = "p.status = :filter_palette_status";
            $params[':filter_palette_status'] = $filterStatus;
        }

        // Filtre par Référence de Packing List (recherche partielle)
        $filterPlReference = trim($request->get('filter_pl_reference', ''));
        if (!empty($filterPlReference)) {
            $whereClauses[] = "pl.reference LIKE :filter_pl_reference";
            $params[':filter_pl_reference'] = '%' . $filterPlReference . '%';
        }


        $allPalettesData = [];
        try {
            $pdo = getPDO();
            $sql = "SELECT p.id, p.code, p.status, p.max_colis, 
                           p.open_date_time, p.close_date_time, p.created_at AS palette_created_at,
                           p.qr_code_path, p.packing_list_id,
                           pl.reference as packing_list_reference, pl.site_id,
                           s.name as site_name, s.code as site_code,
                           COUNT(c.id) as colis_count,
                           u_created.username as created_by_username,
                           u_opened.username as opened_by_username,
                           u_closed.username as closed_by_username
                    FROM palettes p 
                    LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id 
                    LEFT JOIN sites s ON pl.site_id = s.id
                    LEFT JOIN colis c ON p.id = c.palette_id AND c.parent_colis_id IS NULL
                    LEFT JOIN users u_created ON p.created_by_user_id = u_created.id
                    LEFT JOIN users u_opened ON p.opened_by_user_id = u_opened.id
                    LEFT JOIN users u_closed ON p.closed_by_user_id = u_closed.id
                    WHERE " . implode(" AND ", $whereClauses) . "
                    GROUP BY p.id, p.code, p.status, p.max_colis, p.open_date_time, p.close_date_time, 
                             p.created_at, p.qr_code_path, p.packing_list_id,
                             pl.reference, pl.site_id, s.name, s.code,
                             u_created.username, u_opened.username, u_closed.username
                    ORDER BY p.created_at DESC";
            
            error_log("PaletteController::indexAll - SQL: " . preg_replace('/\s+/', ' ', $sql) . " PARAMS: " . print_r($params, true));
            
            $stmt = $pdo->prepare($sql); // Utiliser prepare() car on a des paramètres
            $stmt->execute($params);
            $allPalettesData = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            error_log("PaletteController::indexAll - Palettes récupérées: " . count($allPalettesData));

        } catch (\PDOException $e) {
            error_log("Erreur PDO PaletteController::indexAll: " . $e->getMessage());
            $this->flash(FLASH_ERROR, "Erreur lors de la récupération de la liste des palettes.");
        }
        
        $data = [
            'pageTitle' => 'Liste de Toutes les Palettes',
            'palettesWithDetails' => $allPalettesData,
            'availableSites' => $availableSitesForFilter,
            'currentFilterSiteId' => $currentFilterSiteId,
            'currentFilterStatus' => $filterStatus,
            'currentFilterPlReference' => $filterPlReference,
            'request' => $request // Pour pré-remplir les filtres
        ];
        return $this->view('palettes/index_all.php', $data);
    }
    
    public function show(Request $request, int $id): Response
    {
         $this->authorize('palette_read_details');
        $palette = Palette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            return $this->redirect('/packing-lists');
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;

        $packingList = PackingList::findById($palette->packing_list_id);
        $colis = Colis::findByPaletteId($id);
        $groupedColisOrdered = $this->groupColisForDisplay($colis);

        // --- LOGIQUE MISE À JOUR ---
        // 1. Calculer le total de pièces pour la palette en utilisant la méthode du modèle
        $totalScannedQuantityPalette = $palette->getTotalScannedQuantity();
        
        // 2. Récupérer le récapitulatif par SKU
        $recapBySku = $palette->getScannedQuantitiesBySku();
        // --- FIN LOGIQUE MISE À JOUR ---

        $data = [
            'pageTitle' => 'Détail Palette: ' . htmlspecialchars($palette->code),
            'palette' => $palette,
            'packingList' => $packingList,
            'colisGrouped' => $groupedColisOrdered,
            'totalScannedQuantityPalette' => $totalScannedQuantityPalette, // Passer le total à la vue
            'recapBySku' => $recapBySku,
            'request' => $request
        ];
        return $this->view('palettes/show.php', $data);
    }


    /**
     * Gère l'ouverture d'une palette (premier scan) et la saisie de max_colis.
     * Ceci est une action POST typiquement appelée après la saisie de max_colis.
     *
     * @param Request $request
     * @param int $id L'ID de la palette.
     * @return Response
     */
    public function processOpening(Request $request, int $id): Response
    {
        $this->authorize('palette_open');
        $palette = Palette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            return $this->redirect($request->server('HTTP_REFERER', '/palettes/show/' . $id));
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;

        if ($palette->status !== 'generated') {
            $this->flash(FLASH_INFO, "Cette palette n'est pas en statut 'générée' ou est déjà ouverte.");
            return $this->redirect('/palettes/show/' . $id);
        }
        
        // Vérifier que la PL parente est 'open'
        $packingList = PackingList::findById($palette->packing_list_id);
        if (!$packingList || $packingList->status !== 'open') {
            $this->flash(FLASH_ERROR, "La Packing List parente doit être ouverte pour ouvrir cette palette.");
            return $this->redirect('/palettes/show/' . $id);
        }

        $maxColis = (int)$request->post('max_colis');
        if ($maxColis <= 0) {
            $this->flash(FLASH_ERROR, "Le nombre maximal de colis doit être un entier positif.");
            // Renvoyer à la vue show avec une erreur pour réafficher le formulaire de saisie max_colis
            $_SESSION['open_palette_error_max_colis'] = $id; // Marqueur pour la vue show
            return $this->redirect('/palettes/show/' . $id);
        }

        $userId = Auth::id();
        if (Palette::open($id, $maxColis, $userId)) {
            $this->flash(FLASH_SUCCESS, "Palette " . htmlspecialchars($palette->code) . " ouverte avec succès. Max colis : {$maxColis}.");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de l'ouverture de la palette.");
        }
        return $this->redirect('/palettes/show/' . $id);
    }


    /**
     * Met à jour le max_colis d'une palette ouverte.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function updateMaxColis(Request $request, int $id): Response
    {
        $this->authorize('palette_update_max_colis');
        $palette = Palette::findById($id);
        if (!$palette) { /* ... erreur ... */ $this->flash(FLASH_ERROR, "Palette non trouvée."); return $this->redirect($request->server('HTTP_REFERER', '/palettes/show/' . $id));}
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;
        if ($palette->status !== 'open') { /* ... erreur ... */ $this->flash(FLASH_ERROR, "Le max_colis ne peut être modifié que pour une palette ouverte."); return $this->redirect('/palettes/show/' . $id); }

        $newMaxColis = (int)$request->post('max_colis_edit'); // Nom différent pour éviter conflit avec le form d'ouverture
        if ($newMaxColis <= 0) {
            $this->flash(FLASH_ERROR, "Le nouveau nombre maximal de colis doit être un entier positif.");
            $_SESSION['edit_max_colis_error'] = $id; // Marqueur pour la vue show
            return $this->redirect('/palettes/show/' . $id);
        }
        
        // La validation du nombre de colis scannés est dans Palette::updateMaxColis()
        if (Palette::updateMaxColis($id, $newMaxColis)) {
            $this->flash(FLASH_SUCCESS, "Max colis mis à jour à {$newMaxColis} pour la palette " . htmlspecialchars($palette->code) . ".");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la mise à jour du max colis. Vérifiez que le nouveau max n'est pas inférieur au nombre de colis déjà scannés.");
            $_SESSION['edit_max_colis_error'] = $id;
        }
        return $this->redirect('/palettes/show/' . $id);
    }

    /**
     * Réorganise une liste de colis en une hiérarchie parent/enfants, robuste et performante.
     * La méthode retourne une liste des colis de premier niveau (parents ou colis seuls).
     * Chaque colis parent possède une nouvelle propriété ->children qui est un tableau de ses colis enfants.
     * Cette approche gère nativement le cas où un enfant apparaîtrait avant son parent dans la liste d'origine.
     *
     * @param array $colisList Liste de tous les objets Colis de la palette.
     * @return array Tableau des objets Colis de premier niveau.
     */
    private function groupColisForDisplay(array $colisList): array
    {
        // Si la liste est vide, on retourne immédiatement un tableau vide.
        if (empty($colisList)) {
            return [];
        }

        $colisById = [];
        // Étape 1: Indexer tous les colis par leur ID pour un accès rapide.
        // On en profite pour initialiser la propriété 'children' sur chaque objet colis.
        foreach ($colisList as $colis) {
            $colis->children = [];
            $colisById[$colis->id] = $colis;
        }

        $rootColis = [];
        // Étape 2: Parcourir la liste indexée pour construire la hiérarchie.
        foreach ($colisById as $id => $colis) {
            // Si le colis a un parent ET que ce parent existe dans notre liste indexée...
            if ($colis->parent_colis_id !== null && isset($colisById[$colis->parent_colis_id])) {
                // ... on l'ajoute au tableau "children" de son parent.
                $colisById[$colis->parent_colis_id]->children[] = $colis;
            } else {
                // ... sinon, c'est un colis de premier niveau (un parent ou un colis seul/orphelin).
                // On l'ajoute à la liste des colis "racines" qui sera retournée.
                $rootColis[] = $colis;
            }
        }

        // Trier les enfants de chaque colis parent par date de scan croissante
        foreach ($colisById as $colis) {
            if (!empty($colis->children)) {
                usort($colis->children, function ($a, $b) {
                    return strtotime($a->scan_date_time) <=> strtotime($b->scan_date_time);
                });
            }
        }

        // Trier les colis racines : les parents d'abord, puis les colis sans enfants
        usort($rootColis, function ($a, $b) {
            $aIsParent = !empty($a->children);
            $bIsParent = !empty($b->children);
            if ($aIsParent && !$bIsParent) {
                return -1;
            }
            if (!$aIsParent && $bIsParent) {
                return 1;
            }
            return strtotime($a->scan_date_time) <=> strtotime($b->scan_date_time);
        });

        return $rootColis;
    }


    /**
     * Ferme une palette.
     * @param Request $request
     * @param int $id
     * @return Response
     */
   public function closePalette(Request $request, int $id): Response
    {
        $this->authorize('palette_close');

        $palette = Palette::findById($id);
        if (!$palette || $palette->status !== 'open') {
            $this->flash(FLASH_ERROR, "La palette ne peut être fermée que si elle est ouverte.");
            return $this->redirect('/palettes/show/' . $id);
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;

        $updateData = [
            'status' => 'closed',
            'close_date_time' => (new \DateTime())->format('Y-m-d H:i:s'),
            'closed_by_user_id' => Auth::id()
        ];
        
        if (Palette::updateFields($id, $updateData)) {
            $this->flash(FLASH_SUCCESS, "Palette " . htmlspecialchars($palette->code) . " fermée avec succès.");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la fermeture de la palette.");
        }
        return $this->redirect('/palettes/show/' . $id);
    }
    /**
     * Supprime une palette (sous conditions).
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function delete(Request $request, int $id): Response
    {
        $this->authorize('palette_delete');
        $palette = Palette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            return $this->redirect($request->server('HTTP_REFERER', '/packing-lists')); // Rediriger vers la liste des PL si la palette n'existe plus
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;

        $packingListId = $palette->packing_list_id; // Pour la redirection

        // Conditions de suppression du CdC:
        // - 'generated' (implicitement vide) : OK
        // - 'open' ET vide : OK
        $canDelete = false;
        if ($palette->status === 'generated') {
            $canDelete = true;
        } elseif ($palette->status === 'open' && $palette->getColisCount() === 0) {
            $canDelete = true;
        }

        if (!$canDelete) {
            $this->flash(FLASH_ERROR, "Cette palette ne peut pas être supprimée (elle doit être 'générée' ou 'ouverte et vide').");
            return $this->redirect('/palettes/show/' . $id);
        }

        // Contrainte BDD : colis.palette_id est ON DELETE RESTRICT
        // Donc getColisCount() doit être 0 pour que la suppression réussisse.
        if ($palette->getColisCount() > 0) {
             $this->flash(FLASH_ERROR, "Impossible de supprimer la palette car elle contient des colis. Videz la palette d'abord.");
             return $this->redirect('/palettes/show/' . $id);
        }

        if (Palette::deleteById($id)) {
            $this->flash(FLASH_SUCCESS, "Palette " . htmlspecialchars($palette->code) . " supprimée avec succès.");
            return $this->redirect('/packing-lists/show/' . $packingListId); // Rediriger vers la PL parente
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la suppression de la palette.");
            return $this->redirect('/palettes/show/' . $id);
        }
    }

    /**
     * QR code de la palette avec son code écrit dessous, généré à la volée en SVG (rien n'est stocké). ?download=1 pour télécharger.
     */
    public function qrCode(Request $request, int $id): Response
    {
        $palette = Palette::findById($id);
        $currentUser = Auth::user();
        if (!$palette || ($currentUser && !$palette->isAccessibleBy($currentUser))) {
            return new Response('QR code introuvable.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $headers = ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400'];
        if ($request->get('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="qr_palette_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $palette->code) . '.svg"';
        }
        // ?label=0 : QR seul (l'impression groupée gère elle-même l'affichage du code).
        $label = $request->get('label', '1') === '0' ? null : $palette->code;
        return new Response(QrCodeService::svg($palette->code, 300, $label), 200, $headers);
    }

    /**
     * Page d'impression directe du QR code d'une palette (le navigateur ouvre l'impression au chargement).
     */
    /**
     * PDF du détail palette (Dompdf, A4 paysage) : chaque groupage suivi de ses colis fils.
     */
    public function exportPdf(Request $request, int $id): void
    {
        $this->authorize('palette_read_details');
        $palette = Palette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            $this->redirect('/packing-lists')->send();
            exit;
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) { $r->send(); exit; }

        $logoPath = ROOT_PATH . '/logo.jpg';
        $html = $this->renderPartial('palettes/export_pdf.php', [
            'palette' => $palette,
            'packingList' => PackingList::findById($palette->packing_list_id),
            'colisGrouped' => $this->groupColisForDisplay(Colis::findByPaletteId($id)),
            'nbColis' => $palette->getColisCount(),
            'appName' => APP_NAME,
            'currentDate' => (new \DateTime())->format('d/m/Y H:i'),
            'logoBase64' => is_readable($logoPath) ? base64_encode(file_get_contents($logoPath)) : null,
        ]);

        $options = new \Dompdf\Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->getCanvas()->page_text(780, 570, "{PAGE_NUM} / {PAGE_COUNT}", null, 7, [0.4, 0.4, 0.4]);

        if (ob_get_level()) ob_end_clean();
        $dompdf->stream('Palette_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $palette->code) . '_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
        exit;
    }

    public function qrCodePrint(Request $request, int $id): Response
    {
        $palette = Palette::findById($id);
        $currentUser = Auth::user();
        if (!$palette || ($currentUser && !$palette->isAccessibleBy($currentUser))) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            return $this->redirect('/palettes');
        }
        $html = $this->renderPartial('palettes/print_qrcode_single.php', [
            'palette' => $palette,
            'qrDataUri' => QrCodeService::svgDataUri($palette->code, 300, $palette->code),
            'backUrl' => BASE_URL . '/palettes/show/' . $palette->id,
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Affiche une page avec les QR codes des palettes sélectionnées pour impression.
     * @param Request $request
     * @return Response
     */
    public function printQrCodes(Request $request): Response
    {
        // Idéalement, vérifier une permission comme 'palette_print_qr'
        $this->authorize('palette_read_details'); 

        $selectedPaletteIdsString = $request->post('selected_palettes_for_print', '');
        $packingListId = (int)$request->post('packing_list_id_for_print'); // Pour le contexte/retour

        $labelWidthMm = (int)$request->post('label_width_mm', 100);
        $labelHeightMm = (int)$request->post('label_height_mm', 50);
        $qrSizeMm = (int)$request->post('qr_size_mm', 40); // Taille du QR code sur l'étiquette
        $palettesPerRow = (int)$request->post('palettes_per_row', 2);
        $printWithLabelText = $request->post('print_with_label_text') === 'on';


        if (empty($selectedPaletteIdsString)) {
            $this->flash(FLASH_ERROR, "Aucune palette sélectionnée pour l'impression.");
            return $this->redirect($packingListId ? '/packing-lists/show/' . $packingListId : '/packing-lists');
        }

        $selectedPaletteIds = explode(',', $selectedPaletteIdsString);
        $palettesToPrint = [];
        $currentUser = Auth::user();
        foreach ($selectedPaletteIds as $paletteId) {
            $palette = Palette::findById((int)$paletteId);
            if (!$palette) { continue; }
            // Cloisonnement par site: ne jamais imprimer le QR d'une palette hors périmètre.
            if ($currentUser && !$palette->isAccessibleBy($currentUser)) { continue; }
            $palettesToPrint[] = $palette;
        }

        if (empty($palettesToPrint)) {
            $this->flash(FLASH_ERROR, "Aucune palette valide trouvée pour l'impression.");
             return $this->redirect($packingListId ? '/packing-lists/show/' . $packingListId : '/packing-lists');
        }
        
        // Changer le layout pour une page d'impression épurée
        $this->layout = 'layouts/print_layout.php'; // Vue de layout spécifique pour l'impression

        $data = [
            'pageTitle' => 'Impression QR Codes Palettes',
            'palettesToPrint' => $palettesToPrint,
            'labelWidthMm' => $labelWidthMm,
            'labelHeightMm' => $labelHeightMm,
            'qrSizeMm' => $qrSizeMm,
            'palettesPerRow' => $palettesPerRow,
            'printWithLabelText' => $printWithLabelText,
            'packingListIdForReturn' => $packingListId // Pour un bouton de retour
        ];
        return $this->view('palettes/print_qrcodes.php', $data);
    }

     /**
     * Réouvre une palette.
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function reopenPalette(Request $request, int $id): Response
    {
        $this->authorize('palette_reopen');

        $palette = Palette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette non trouvée.");
            return $this->redirect($request->server('HTTP_REFERER', '/packing-lists'));
        }
        if ($r = $this->denyIfNotAccessible($palette, '/packing-lists')) return $r;

        if (Palette::reopen($id)) {
            $this->flash(FLASH_SUCCESS, "Palette \"" . htmlspecialchars($palette->code) . "\" réouverte avec succès.");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la réouverture de la palette \"" . htmlspecialchars($palette->code) . "\". Vérifiez qu'elle était bien fermée et que sa Packing List est ouverte.");
        }
        return $this->redirect('/palettes/show/' . $id);
    }
}