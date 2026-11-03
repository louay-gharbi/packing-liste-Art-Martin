<?php
// src/Controllers/ColisController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Colis;
use App\Models\Palette;
use App\Models\PackingList; // Pour vérifier le statut de la PL parente
use DateTime;
use App\Models\Site;

class ColisController extends BaseController
{

    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        // La permission 'colis_scan' sera vérifiée dans les méthodes spécifiques
    }

    /**
     * Affiche l'interface de scan des colis.
     * @param Request $request
     * @return Response
     */
    public function scanInterface(Request $request): Response
    {
        $this->authorize('colis_scan'); // Ou une permission plus générale pour accéder à l'interface

        $activePalette = null;
        $activePaletteId = $_SESSION['active_scan_palette_id'] ?? null;
        $paletteCodeFromQuery = trim($request->get('palette_code', ''));

        // Si un code palette est passé en query (ex: depuis le détail d'une palette)
        if (!empty($paletteCodeFromQuery) && !$activePaletteId) {
            $palette = Palette::findByCode($paletteCodeFromQuery); // Méthode à ajouter dans Palette.php
            if ($palette && $palette->status !== 'closed') {
                // Si la palette est 'generated', on la redirige vers sa page de détail pour l'ouvrir
                if ($palette->status === 'generated') {
                    $this->flash(FLASH_INFO, "La palette ".htmlspecialchars($palette->code)." doit d'abord être ouverte. Veuillez définir le max colis.");
                    return $this->redirect('/palettes/show/' . $palette->id);
                }
                // Si elle est 'open', on la définit comme active
                if ($palette->status === 'open') {
                    $_SESSION['active_scan_palette_id'] = $palette->id;
                    $activePaletteId = $palette->id;
                    $this->flash(FLASH_SUCCESS, "Palette ".htmlspecialchars($palette->code)." activée pour le scan.");
                }
            } elseif ($palette && $palette->status === 'closed') {
                $this->flash(FLASH_ERROR, "La palette ".htmlspecialchars($paletteCodeFromQuery)." est fermée et ne peut pas être utilisée pour le scan.");
            } else {
                $this->flash(FLASH_ERROR, "Palette ".htmlspecialchars($paletteCodeFromQuery)." non trouvée ou inaccessible.");
            }
        }
        
        if ($activePaletteId) {
            $activePalette = Palette::findById((int)$activePaletteId);
            if (!$activePalette || $activePalette->status !== 'open') {
                // La palette a peut-être été fermée ou supprimée entre-temps
                unset($_SESSION['active_scan_palette_id']);
                $activePalette = null;
                if ($activePalette && $activePalette->status !== 'open') { // Vérifie si $activePalette est non-null avant d'accéder à status
                    $this->flash(FLASH_WARNING, "La palette précédemment active n'est plus ouverte pour le scan.");
                }
            }
        }

        $colisScannedOnActivePalette = [];
        if ($activePalette) {
            $colisScannedOnActivePalette = Colis::findByPaletteId($activePalette->id, 'scan_date_time DESC', 5); // Get 5 last
        }


        $data = [
            'pageTitle' => 'Scan des Colis',
            'request' => $request,
            'activePalette' => $activePalette, // L'objet Palette s'il y en a un d'actif
            'colisScannedRecently' => $colisScannedOnActivePalette,
            'palette_code_input' => $request->post('palette_code_input', $activePalette ? $activePalette->code : '')
        ];
        return $this->view('colis/scan.php', $data);
    }

    /**
     * Traite la tentative d'activation d'une palette pour le scan.
     * @param Request $request
     * @return Response
     */
    public function processPaletteForScan(Request $request): Response
    {
        $this->authorize('colis_scan'); // Ou 'palette_open' si on considère que c'est une action d'ouverture

        $paletteCode = trim($request->post('palette_code_input', ''));
        unset($_SESSION['active_scan_palette_id']); // Réinitialiser la palette active

        if (empty($paletteCode)) {
            $this->flash(FLASH_ERROR, "Veuillez entrer ou scanner un code palette.");
            return $this->redirect('/colis/scan');
        }

        $palette = Palette::findByCode($paletteCode); // Méthode à ajouter dans Palette.php

        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette \"".htmlspecialchars($paletteCode)."\" non trouvée.");
            return $this->redirect('/colis/scan');
        }
        if ($r = $this->denyIfNotAccessible($palette, '/colis/scan')) return $r;

        $packingList = PackingList::findById($palette->packing_list_id);
        if (!$packingList || $packingList->status === 'closed') {
             $this->flash(FLASH_ERROR, "La Packing List parente de la palette \"".htmlspecialchars($paletteCode)."\" est fermée ou introuvable.");
             return $this->redirect('/colis/scan');
        }


        if ($palette->status === 'closed') {
            $this->flash(FLASH_ERROR, "La palette \"".htmlspecialchars($paletteCode)."\" est fermée. Aucun scan possible.");
            return $this->redirect('/colis/scan');
        }

        if ($palette->status === 'generated') {
            // Rediriger vers la page de détail de la palette pour la procédure d'ouverture (saisie max_colis)
            $this->flash(FLASH_INFO, "La palette \"".htmlspecialchars($paletteCode)."\" doit être ouverte. Veuillez définir le nombre maximal de colis.");
            // On pourrait stocker le code palette pour le pré-remplir sur la page show
            // $_SESSION['palette_to_open_code'] = $paletteCode;
            return $this->redirect('/palettes/show/' . $palette->id);
        }

        if ($palette->status === 'open') {
            $_SESSION['active_scan_palette_id'] = $palette->id;
            $this->flash(FLASH_SUCCESS, "Palette \"".htmlspecialchars($paletteCode)."\" activée pour le scan.");
        } else {
            // Cas non prévu
            $this->flash(FLASH_ERROR, "Statut inattendu pour la palette \"".htmlspecialchars($paletteCode)."\".");
        }
        
        return $this->redirect('/colis/scan');
    }


    /**
     * Traite le scan d'un colis et l'enregistre.
     * Le payload du QR code contient uniquement les valeurs séparées par
     * des points-virgules dans un ordre prédéfini. Pour compatibilité,
     * l'ancien format "cle|valeur;..." reste accepté. Le tableau obtenu
     * est ensuite converti en JSON pour stockage dans `payload_json` et
     * les champs pertinents sont extraits pour les colonnes dédiées.
     */
    public function processColisScan(Request $request): Response
    {
        $this->authorize('colis_scan');
        
        $activePaletteId = $_SESSION['active_scan_palette_id'] ?? null;
        if (!$activePaletteId) {
            $this->flash(FLASH_ERROR, "Aucune palette active. Activez une palette d'abord.");
            // Gérer réponse AJAX si nécessaire
            return $this->redirect('/colis/scan');
        }

        $activePalette = Palette::findById((int)$activePaletteId);
        if (!$activePalette || $activePalette->status !== 'open') {
            unset($_SESSION['active_scan_palette_id']);
            $this->flash(FLASH_ERROR, "La palette active n'est plus valide ou ouverte.");
            return $this->redirect('/colis/scan');
        }

        $colisPayloadString = trim($request->post('colis_qr_payload', ''));
        if (empty($colisPayloadString)) {
            $this->flash(FLASH_ERROR, "Payload du QR code colis vide.");
            return $this->redirect('/colis/scan');
        }

        // Parsing centralise (gere ancien format "cle|valeur" et nouveau positionnel).
        $parsedPayload = Colis::parseQrPayload($colisPayloadString);

        if (empty($parsedPayload)) {
            $this->flash(FLASH_ERROR, "Format du payload QR colis invalide ou vide après parsing.");
            $_SESSION['scan_error_payload'] = $colisPayloadString;
            $_SESSION['scan_error_trigger_red_screen'] = true;
            return $this->redirect('/colis/scan');
        }

        $activeParentId = $_SESSION['active_parent_colis_id'] ?? null;

        // 2. Convertir le tableau parsé en une chaîne JSON valide pour stockage
        $payloadJsonToStore = json_encode($parsedPayload);
        if ($payloadJsonToStore === false) { // Gérer l'erreur d'encodage JSON (peu probable avec des chaînes simples)
            $this->flash(FLASH_ERROR, "Erreur lors de la conversion du payload en JSON pour stockage.");
            $_SESSION['scan_error_payload'] = $colisPayloadString;
            $_SESSION['scan_error_trigger_red_screen'] = true;
            return $this->redirect('/colis/scan');
        }

        // 3. Extraire les champs spécifiques du tableau $parsedPayload
        $packageNumber = $parsedPayload['package_number'] ?? null;
        $clientName = $parsedPayload['client_name'] ?? null;
        $ofNumber = $parsedPayload['of_number'] ?? null;
        $orderNumber = $parsedPayload['order_number'] ?? null;
        $sku = $parsedPayload['sku'] ?? null;
        $label = $parsedPayload['label'] ?? null;
        $quantity = isset($parsedPayload['qty']) ? (float)$parsedPayload['qty'] : (isset($parsedPayload['quantity']) ? (float)$parsedPayload['quantity'] : null); // Gérer alias 'qty'

        // Nouveaux champs
        $internalReference = $parsedPayload['ref_interne'] ?? null;
        $articleReference = $parsedPayload['ref_article'] ?? null;
        $unit = $parsedPayload['unit'] ?? null;
        $postalCode = $parsedPayload['code_postal'] ?? null;
        $lineNumber = $parsedPayload['ligne'] ?? null;


        // --- Validation des données du colis ---
        $errors = [];
        if (empty($packageNumber)) {
            $errors[] = "Le 'package_number' est manquant dans le payload du colis.";
        }
        if (!$activeParentId && $activePalette->max_colis !== null && $activePalette->getColisCount() >= $activePalette->max_colis) {
            $errors[] = "Capacité maximale de la palette ({$activePalette->max_colis}) atteinte.";
        }
        if (!empty($packageNumber)) {
            $currentParentNumber = null;
            if ($activeParentId) {
                $currentParent = Colis::findById((int)$activeParentId);
                $currentParentNumber = $currentParent ? $currentParent->package_number : null;
            }

            if ($packageNumber !== $currentParentNumber && Colis::packageNumberExists($packageNumber)) {
                $errors[] = "Le numéro de colis \"".htmlspecialchars($packageNumber)."\" a déjà été scanné.";
            }
        }
        if ($quantity !== null && $quantity <= 0 && $quantity !== 0) { // Permettre quantité 0 si c'est un cas d'usage (ex: échantillon, informationnel)
            $errors[] = "La quantité dans le colis doit être un nombre positif (ou 0 si applicable).";
        }


        if (!empty($errors)) {
            $errorMessage = "Erreur(s) lors du scan du colis : <ul><li>" . implode("</li><li>", $errors) . "</li></ul>";
            $this->flash(FLASH_ERROR, $errorMessage);
            $_SESSION['scan_error_payload'] = $colisPayloadString; // Payload brut original
            $_SESSION['scan_error_trigger_red_screen'] = true;
            return $this->redirect('/colis/scan');
        }

        $isParentPayload = count($parsedPayload) === 1 && isset($parsedPayload['package_number']);

        if ($isParentPayload) {
            if ($activeParentId) {
                $currentParent = Colis::findById((int)$activeParentId);
                if ($currentParent && $currentParent->package_number === $packageNumber) {
                    unset($_SESSION['active_parent_colis_id']);
                    $this->flash(FLASH_INFO, "Groupage terminé pour le colis parent ".htmlspecialchars($packageNumber).".");
                    return $this->redirect('/colis/scan');
                }
            }

            $colisData = [
                'palette_id' => $activePalette->id,
                'package_number' => $packageNumber,
                'payload_json' => $payloadJsonToStore,
                'scan_date_time' => (new \DateTime())->format('Y-m-d H:i:s'),
                'scanned_by_user_id' => Auth::id(),
                'type' => 'parent',
                'parent_colis_id' => null
            ];
            $newColis = Colis::create($colisData);
            if ($newColis) {
                $_SESSION['active_parent_colis_id'] = $newColis->id;
                $this->flash(FLASH_SUCCESS, "Colis parent \"".htmlspecialchars($packageNumber)."\" enregistré. Scannez les colis enfants.");
            } else {
                $this->flash(FLASH_ERROR, "Erreur lors de l'enregistrement du colis parent.");
                $_SESSION['scan_error_payload'] = $colisPayloadString;
                $_SESSION['scan_error_trigger_red_screen'] = true;
            }
            return $this->redirect('/colis/scan');
        }

        $colisData = [
            'palette_id' => $activeParentId ? null : $activePalette->id,
            'package_number' => $packageNumber,
            'payload_json' => $payloadJsonToStore,
            'scan_date_time' => (new \DateTime())->format('Y-m-d H:i:s'),
            'scanned_by_user_id' => Auth::id(),
            'type' => 'product',
            'parent_colis_id' => $activeParentId,
            // Champs parsés
            'client_name' => $clientName,
            'of_number' => $ofNumber,
            'order_number' => $orderNumber,
            'sku' => $sku,
            'label' => $label,
            'quantity' => $quantity,
            // Nouveaux champs parsés
            'internal_reference' => $internalReference,
            'article_reference' => $articleReference,
            'unit' => $unit,
            'postal_code' => $postalCode,
            'line_number' => $lineNumber
        ];

        $newColis = Colis::create($colisData);

        if ($newColis) {
            $msg = "Colis \"".htmlspecialchars($packageNumber)."\" scanné";
            if ($activeParentId) {
                $msg .= " et rattaché au parent";
            } else {
                $msg .= " et ajouté à la palette ".htmlspecialchars($activePalette->code);
            }
            $this->flash(FLASH_SUCCESS, $msg . '.');
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de l'enregistrement du colis \"".htmlspecialchars($packageNumber)."\". Vérifiez les logs pour plus de détails.");
            $_SESSION['scan_error_payload'] = $colisPayloadString;
            $_SESSION['scan_error_trigger_red_screen'] = true;
        }
        
        return $this->redirect('/colis/scan');
    }

    /**
     * Action pour "Dé-scanner" (supprimer) un colis d'une palette.
     * @param Request $request
     * @param int $colisId ID du colis à supprimer
     * @return Response
     */
    public function deleteColis(Request $request, int $colisId): Response
    {
        $this->authorize('colis_delete');

        $colis = Colis::findById($colisId);
        if (!$colis) {
            $this->flash(FLASH_ERROR, "Colis non trouvé.");
            return $this->redirect($request->post('redirect_url', '/colis/scan'));
        }
        if ($r = $this->denyIfNotAccessible($colis, '/colis/scan')) return $r;

        $parent = null;
        $paletteId = $colis->palette_id;
        if ($paletteId === null && $colis->parent_colis_id !== null) {
            $parent = Colis::findById($colis->parent_colis_id);
            $paletteId = $parent ? $parent->palette_id : null;
        }

        $palette = Palette::findById($paletteId);
        if (!$palette) {
            // Ne devrait pas arriver si l'intégrité des données est maintenue
            $this->flash(FLASH_ERROR, "Palette parente du colis non trouvée.");
            return $this->redirect($request->post('redirect_url', '/colis/scan'));
        }

        if ($palette->status !== 'open') {
            $this->flash(FLASH_ERROR, "Les colis ne peuvent être supprimés que d'une palette ouverte.");
            return $this->redirect('/palettes/show/' . $palette->id);
        }

        $packageNumbers = [];
        if ($colis->parent_colis_id !== null) {
            $parent = $parent ?: Colis::findById($colis->parent_colis_id);
            if ($parent) {
                $children = Colis::findChildren($parent->id);
                foreach ($children as $child) {
                    $packageNumbers[] = $child->package_number;
                }
                $packageNumbers[] = $parent->package_number;
                $deleteId = $parent->id;
            } else {
                $deleteId = $colisId;
                $packageNumbers[] = $colis->package_number;
            }
        } else {
            $deleteId = $colisId;
            $packageNumbers[] = $colis->package_number;
        }

        if (Colis::deleteById($deleteId)) {
            $pnList = implode(', ', array_map('htmlspecialchars', $packageNumbers));
            $this->flash(FLASH_SUCCESS, "Colis \"".$pnList."\" dé-scanné(s) avec succès de la palette ".htmlspecialchars($palette->code).".");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de la suppression du colis.");
        }

        // Rediriger vers la page de la palette ou la page de scan
        $redirectUrl = $request->post('palette_id_redirect') 
                       ? '/palettes/show/' . $request->post('palette_id_redirect')
                       : '/colis/scan';
        return $this->redirect($redirectUrl);
    }


    /**
     * Désactive la palette de scan active en session.
     * @param Request $request
     * @return Response
     */
    public function deactivatePaletteScan(Request $request): Response
    {
        unset($_SESSION['active_scan_palette_id']);
        $this->flash(FLASH_INFO, "Aucune palette n'est plus active pour le scan.");
        return $this->redirect('/colis/scan');
    }

      /**
     * Affiche le formulaire/modale pour déplacer un colis.
     * Pourrait être appelé depuis la liste des colis sur une palette.
     *
     * @param Request $request
     * @param int $colisId L'ID du colis à déplacer.
     * @return Response
     */
    public function showMoveForm(Request $request, int $colisId): Response
    {
        $this->authorize('colis_move');

        $colis = Colis::findById($colisId);
        if ($colis && $colis->parent_colis_id !== null) {
            $parent = Colis::findById($colis->parent_colis_id);
            if ($parent) {
                $colis = $parent;
                $colisId = $parent->id;
            }
        }

        if (!$colis) {
            $this->flash(FLASH_ERROR, "Colis non trouvé.");
            return $this->redirect($request->server('HTTP_REFERER', '/colis/scan'));
        }

        $sourcePalette = Palette::findById($colis->palette_id);
        if (!$sourcePalette || $sourcePalette->status !== 'open') {
            $this->flash(FLASH_ERROR, "Le colis ne peut être déplacé que depuis une palette ouverte.");
            return $this->redirect('/palettes/show/' . $colis->palette_id);
        }

        // Optionnel: Lister les palettes 'open' vers lesquelles on peut déplacer.
        // Pour l'instant, l'utilisateur saisira le code de la palette destination.
        // $availablePalettes = Palette::findAllOpen(); // Méthode à créer si besoin

        $data = [
            'pageTitle' => 'Déplacer le Colis: ' . htmlspecialchars($colis->package_number),
            'colis' => $colis,
            'sourcePalette' => $sourcePalette,
            'request' => $request,
            // 'availablePalettes' => $availablePalettes
        ];
        // Nous utiliserons une modale sur la page palettes/show.php pour cela au lieu d'une vue dédiée.
        // Si vue dédiée: return $this->view('colis/move_form.php', $data);
        // Pour une modale, on redirige vers la page de la palette source et on passe un indicateur pour ouvrir la modale.
        $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
        return $this->redirect('/palettes/show/' . $sourcePalette->id);
    }


    /**
     * Traite le déplacement d'un colis vers une autre palette.
     *
     * @param Request $request
     * @param int $colisId L'ID du colis à déplacer.
     * @return Response
     */
    public function processMove(Request $request, int $colisId): Response
    {
        $this->authorize('colis_move');

        $colis = Colis::findById($colisId);
        if ($colis && $colis->parent_colis_id !== null) {
            $parent = Colis::findById($colis->parent_colis_id);
            if ($parent) {
                $colis = $parent;
                $colisId = $parent->id;
            }
        }

        if (!$colis) {
            $this->flash(FLASH_ERROR, "Colis à déplacer non trouvé.");
            return $this->redirect($request->post('source_palette_redirect_url', '/colis/scan'));
        }
        if ($r = $this->denyIfNotAccessible($colis, '/colis/scan')) return $r;

        $sourcePaletteId = $colis->palette_id;
        $sourcePalette = Palette::findById($sourcePaletteId);
        if (!$sourcePalette || $sourcePalette->status !== 'open') {
            $this->flash(FLASH_ERROR, "La palette source du colis n'est pas ouverte ou n'existe plus.");
            return $this->redirect($request->post('source_palette_redirect_url', '/palettes/show/' . $sourcePaletteId));
        }

        $destinationPaletteCode = trim($request->post('destination_palette_code', ''));
        if (empty($destinationPaletteCode)) {
            $this->flash(FLASH_ERROR, "Le code de la palette de destination est requis.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId; // Pour réafficher la modale
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }

        $destinationPalette = Palette::findByCode($destinationPaletteCode);
        if (!$destinationPalette) {
            $this->flash(FLASH_ERROR, "Palette de destination \"".htmlspecialchars($destinationPaletteCode)."\" non trouvée.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }
        // Cloisonnement par site : destination doit aussi être accessible.
        if ($r = $this->denyIfNotAccessible($destinationPalette, '/palettes/show/' . $sourcePaletteId)) return $r;

        // Validations supplémentaires
        if ($destinationPalette->id === $sourcePaletteId) {
            $this->flash(FLASH_ERROR, "La palette de destination ne peut pas être la même que la palette source.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }

        // Regle metier critique : interdire le deplacement entre PL differentes.
        if ((int)$destinationPalette->packing_list_id !== (int)$sourcePalette->packing_list_id) {
            $this->flash(FLASH_ERROR, "Le deplacement est interdit : la palette de destination \"" . htmlspecialchars($destinationPaletteCode) . "\" appartient a une autre Packing List que la palette source.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }

        if ($destinationPalette->status !== 'open') {
            $this->flash(FLASH_ERROR, "La palette de destination \"".htmlspecialchars($destinationPaletteCode)."\" n'est pas ouverte.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }
        
        // Vérifier la capacité de la palette destination
        if ($destinationPalette->max_colis !== null && $destinationPalette->getColisCount() >= $destinationPalette->max_colis) {
            $this->flash(FLASH_ERROR, "La palette de destination \"".htmlspecialchars($destinationPaletteCode)."\" a atteint sa capacité maximale ({$destinationPalette->max_colis} colis).");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }

        // Tout est OK, procéder au déplacement
        if (Colis::moveToPalette($colisId, $destinationPalette->id)) {
            $this->flash(FLASH_SUCCESS, "Colis \"".htmlspecialchars($colis->package_number)."\" déplacé avec succès vers la palette \"".htmlspecialchars($destinationPalette->code)."\".");
            // Rediriger vers la palette source pour voir le colis disparaître
            // ou vers la palette destination pour voir le colis apparaître.
            return $this->redirect('/palettes/show/' . $destinationPalette->id);
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors du déplacement du colis.");
            $_SESSION['show_move_colis_modal_for_colis_id'] = $colisId;
            $_SESSION['move_colis_error_destination_code'] = $destinationPaletteCode;
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }
    }


    /**
     * Version AJAX du processColisScan : retourne JSON sans rechargement de page.
     */
    public function processScanAjax(Request $request): Response
    {
        $this->authorize('colis_scan');
        
        $activePaletteId = $_SESSION['active_scan_palette_id'] ?? null;
        if (!$activePaletteId) {
            return Response::json(['error' => 'Aucune palette active.'], 400);
        }

        $activePalette = Palette::findById((int)$activePaletteId);
        if (!$activePalette || $activePalette->status !== 'open') {
            unset($_SESSION['active_scan_palette_id']);
            return Response::json(['error' => 'La palette active n\'est plus valide.'], 400);
        }

        $colisPayloadString = trim($request->post('colis_qr_payload', ''));
        if (empty($colisPayloadString)) {
            return Response::json(['error' => 'Payload du QR code vide.'], 400);
        }

        // Parsing centralise (cf. Colis::parseQrPayload).
        $parsedPayload = Colis::parseQrPayload($colisPayloadString);

        if (empty($parsedPayload)) {
            return Response::json(['error' => 'Format du payload invalide.'], 400);
        }

        $packageNumber = $parsedPayload['package_number'] ?? null;
        if (empty($packageNumber)) {
            return Response::json(['error' => 'package_number manquant.'], 400);
        }

        $activeParentId = $_SESSION['active_parent_colis_id'] ?? null;

        // Verification doublon : on autorise le re-scan du numero de parent en mode groupage
        // (signal de fin de groupage), sinon on rejette tout doublon.
        $currentParentNumber = null;
        if ($activeParentId) {
            $currentParent = Colis::findById((int)$activeParentId);
            $currentParentNumber = $currentParent ? $currentParent->package_number : null;
        }
        if ($packageNumber !== $currentParentNumber && Colis::packageNumberExists($packageNumber)) {
            return Response::json(['error' => 'Ce colis a déjà été scanné.'], 409);
        }

        // Capacité
        if (!$activeParentId && $activePalette->max_colis !== null && $activePalette->getColisCount() >= $activePalette->max_colis) {
            return Response::json(['error' => 'Capacité maximale de la palette atteinte.'], 400);
        }

        $payloadJsonToStore = json_encode($parsedPayload);
        if ($payloadJsonToStore === false) {
            return Response::json(['error' => 'Erreur d\'encodage du payload.'], 500);
        }

        // Détection colis parent (scénario groupage)
        $isParentPayload = count($parsedPayload) === 1 && isset($parsedPayload['package_number']);

        if ($isParentPayload) {
            if ($activeParentId) {
                $currentParent = Colis::findById((int)$activeParentId);
                if ($currentParent && $currentParent->package_number === $packageNumber) {
                    unset($_SESSION['active_parent_colis_id']);
                    return Response::json([
                        'success' => true,
                        'message' => 'Groupage terminé.',
                        'is_grouping' => false,
                        'colis_count' => $activePalette->getColisCount(),
                        'max_colis' => $activePalette->max_colis
                    ]);
                }
            }

            $colisData = [
                'palette_id' => $activePalette->id,
                'package_number' => $packageNumber,
                'payload_json' => $payloadJsonToStore,
                'scan_date_time' => (new \DateTime())->format('Y-m-d H:i:s'),
                'scanned_by_user_id' => Auth::id(),
                'type' => 'parent',
                'parent_colis_id' => null
            ];
            $newColis = Colis::create($colisData);
            if ($newColis) {
                $_SESSION['active_parent_colis_id'] = $newColis->id;
                $activePalette->colisCount = null; // Invalidate cache to get updated count
                return Response::json([
                    'success' => true,
                    'message' => 'Colis parent enregistré. Scannez les colis enfants.',
                    'is_grouping' => true,
                    'parent_package_number' => $packageNumber,
                    'child_count' => 0,
                    'colis_count' => $activePalette->getColisCount(),
                    'max_colis' => $activePalette->max_colis
                ]);
            }
            return Response::json(['error' => 'Erreur lors de l\'enregistrement.'], 500);
        }

        // Colis standard (product)
        $colisData = [
            'palette_id' => $activeParentId ? null : $activePalette->id,
            'package_number' => $packageNumber,
            'payload_json' => $payloadJsonToStore,
            'scan_date_time' => (new \DateTime())->format('Y-m-d H:i:s'),
            'scanned_by_user_id' => Auth::id(),
            'type' => 'product',
            'parent_colis_id' => $activeParentId,
            'client_name' => $parsedPayload['client_name'] ?? null,
            'of_number' => $parsedPayload['of_number'] ?? null,
            'order_number' => $parsedPayload['order_number'] ?? null,
            'sku' => $parsedPayload['sku'] ?? null,
            'label' => $parsedPayload['label'] ?? null,
            'quantity' => isset($parsedPayload['qty']) ? (float)$parsedPayload['qty'] : null,
            'internal_reference' => $parsedPayload['ref_interne'] ?? null,
            'article_reference' => $parsedPayload['ref_article'] ?? null,
            'unit' => $parsedPayload['unit'] ?? null,
            'postal_code' => $parsedPayload['code_postal'] ?? null,
            'line_number' => $parsedPayload['ligne'] ?? null
        ];

        $newColis = Colis::create($colisData);
        if (!$newColis) {
            return Response::json(['error' => 'Erreur lors de l\'enregistrement du colis.'], 500);
        }

        $activePalette->colisCount = null; // Invalidate cache to get updated count

        // Récupérer le compteur de colis enfants si groupage
        $childCount = 0;
        if ($activeParentId) {
            $childCount = count(Colis::findChildren((int)$activeParentId));
        }

        $response = [
            'success' => true,
            'message' => 'Colis scanné avec succès.',
            'last_scan' => [
                'package_number' => $packageNumber,
                'of_number' => $parsedPayload['of_number'] ?? '',
                'time' => (new \DateTime())->format('H:i:s')
            ],
            'colis_count' => $activePalette->getColisCount(),
            'max_colis' => $activePalette->max_colis,
            'is_grouping' => $activeParentId ? true : false,
            'parent_package_number' => $activeParentId ? ($_SESSION['active_parent_colis_id'] ? (Colis::findById((int)$_SESSION['active_parent_colis_id'])?->package_number ?? '') : '') : null,
            'child_count' => $childCount
        ];

        return Response::json($response);
    }

    /**
     * Version AJAX de l'activation de palette.
     */
    public function processPaletteScanAjax(Request $request): Response
    {
        $this->authorize('colis_scan');

        $paletteCode = trim($request->post('palette_code_input', ''));
        unset($_SESSION['active_scan_palette_id']);

        if (empty($paletteCode)) {
            return Response::json(['error' => 'Code palette vide.'], 400);
        }

        $palette = Palette::findByCode($paletteCode);
        if (!$palette) {
            return Response::json(['error' => 'Palette non trouvée.'], 404);
        }
        $currentUser = Auth::user();
        if ($currentUser && !$palette->isAccessibleBy($currentUser)) {
            return Response::json(['error' => 'Palette hors de votre périmètre.'], 403);
        }

        $packingList = PackingList::findById($palette->packing_list_id);
        if (!$packingList || $packingList->status === 'closed') {
            return Response::json(['error' => 'La Packing List parente est fermée.'], 400);
        }

        if ($palette->status === 'closed') {
            return Response::json(['error' => 'Palette fermée. Aucun scan possible.'], 400);
        }

        if ($palette->status === 'generated') {
            return Response::json([
                'success' => true,
                'message' => 'Palette à ouvrir',
                'redirect' => rtrim(BASE_URL, '/') . '/palettes/show/' . $palette->id
            ]);
        }

        if ($palette->status === 'open') {
            $_SESSION['active_scan_palette_id'] = $palette->id;
            return Response::json([
                'success' => true,
                'message' => 'Palette activée',
                'redirect' => rtrim(BASE_URL, '/') . '/colis/scan'
            ]);
        }

        return Response::json(['error' => 'Statut inattendu.'], 400);
    }

    /**
     * Traite le déplacement massif de plusieurs colis vers une palette de destination.
     * Reçoit un tableau d'IDs de colis (checkbox) + un code palette destination.
     *
     * @param Request $request
     * @return Response
     */
    public function processMassiveMove(Request $request): Response
    {
        $this->authorize('colis_move');

        $colisIds = $request->post('colis_ids', []);
        $destinationPaletteCode = trim($request->post('destination_palette_code', ''));
        $sourcePaletteId = (int)$request->post('source_palette_id', 0);
        $redirectUrl = $request->post('redirect_url', '/palettes');

        if (empty($colisIds) || !is_array($colisIds)) {
            $this->flash(FLASH_ERROR, "Aucun colis sélectionné pour le déplacement massif.");
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }

        // Nettoyer les IDs (supprimer les vides, ne garder que les entiers)
        $colisIds = array_filter($colisIds, function($id) {
            return is_numeric($id) && (int)$id > 0;
        });
        $colisIds = array_map('intval', $colisIds);

        if (empty($colisIds)) {
            $this->flash(FLASH_ERROR, "Les IDs de colis fournis sont invalides.");
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }

        if (empty($destinationPaletteCode)) {
            $this->flash(FLASH_ERROR, "Le code de la palette de destination est requis.");
            $_SESSION['show_massive_move_modal'] = true;
            $_SESSION['massive_move_colis_ids'] = $colisIds;
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }

        // 1) Resoudre et valider la palette de DESTINATION en premier : on a besoin
        //    de son packing_list_id pour la garde inter-PL par-colis qui suit.
        $destinationPalette = Palette::findByCode($destinationPaletteCode);
        if (!$destinationPalette) {
            $this->flash(FLASH_ERROR, "Palette de destination \"" . htmlspecialchars($destinationPaletteCode) . "\" non trouvée.");
            $_SESSION['show_massive_move_modal'] = true;
            $_SESSION['massive_move_colis_ids'] = $colisIds;
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }
        if ($r = $this->denyIfNotAccessible($destinationPalette, $sourcePaletteId ? '/palettes/show/' . $sourcePaletteId : $redirectUrl)) return $r;

        if ($destinationPalette->status !== 'open') {
            $this->flash(FLASH_ERROR, "La palette de destination \"" . htmlspecialchars($destinationPaletteCode) . "\" n'est pas ouverte.");
            $_SESSION['show_massive_move_modal'] = true;
            $_SESSION['massive_move_colis_ids'] = $colisIds;
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }
        $destPlId = (int) $destinationPalette->packing_list_id;

        // 2) Boucle unique sur les colis selectionnes : valide
        //    - le cloisonnement par site (Phase 3),
        //    - l'invariant inter-PL (chaque colis doit appartenir a la meme PL
        //      que la destination). La PL source est DERIVEE DU COLIS lui-meme,
        //    jamais du POST source_palette_id (qui pouvait etre vide ou forge).
        $currentUser = Auth::user();
        foreach ($colisIds as $cid) {
            $c = Colis::findById($cid);
            if (!$c || ($currentUser && !$c->isAccessibleBy($currentUser))) {
                $this->flash(FLASH_ERROR, "Un ou plusieurs colis sélectionnés ne vous sont pas accessibles.");
                return $this->redirect($sourcePaletteId ? '/palettes/show/' . $sourcePaletteId : $redirectUrl);
            }
            // Resoudre la palette effective du colis (parent si colis enfant).
            $cPaletteId = $c->palette_id;
            if ($cPaletteId === null && $c->parent_colis_id !== null) {
                $cParent = Colis::findById($c->parent_colis_id);
                $cPaletteId = $cParent?->palette_id;
            }
            $cPalette = $cPaletteId !== null ? Palette::findById((int) $cPaletteId) : null;
            if (!$cPalette || (int) $cPalette->packing_list_id !== $destPlId) {
                $this->flash(FLASH_ERROR, "Déplacement refusé : un ou plusieurs colis sélectionnés "
                    . "n'appartiennent pas à la même Packing List que la palette de destination.");
                $_SESSION['show_massive_move_modal'] = true;
                $_SESSION['massive_move_colis_ids'] = $colisIds;
                return $this->redirect($sourcePaletteId ? '/palettes/show/' . $sourcePaletteId : $redirectUrl);
            }
        }

        // Vérification de capacité de la palette destination
        $nbColis = count($colisIds);
        $currentCount = $destinationPalette->getColisCount();
        $maxColis = $destinationPalette->max_colis;
        if ($maxColis !== null && ($currentCount + $nbColis) > $maxColis) {
            $remaining = $maxColis - $currentCount;
            $this->flash(FLASH_ERROR, "Capacité insuffisante sur la palette destination. Il reste {$remaining} places mais {$nbColis} colis sélectionnés.");
            $_SESSION['show_massive_move_modal'] = true;
            $_SESSION['massive_move_colis_ids'] = $colisIds;
            if ($sourcePaletteId) {
                return $this->redirect('/palettes/show/' . $sourcePaletteId);
            }
            return $this->redirect($redirectUrl);
        }

        // Procéder au déplacement massif
        $result = Colis::moveMultipleToPalette($colisIds, $destinationPalette->id);

        if ($result['success'] && empty($result['errors'])) {
            $this->flash(FLASH_SUCCESS, "{$result['moved_count']} colis déplacés avec succès vers la palette \"" . htmlspecialchars($destinationPalette->code) . "\".");
        } elseif ($result['moved_count'] > 0) {
            $errorSummary = implode('<br>', array_map('htmlspecialchars', $result['errors']));
            $this->flash(FLASH_WARNING, "{$result['moved_count']} colis déplacés partiellement.<br>Erreurs:<br>{$errorSummary}");
        } else {
            $errorSummary = implode('<br>', array_map('htmlspecialchars', $result['errors']));
            $this->flash(FLASH_ERROR, "Échec du déplacement massif.<br>Erreurs:<br>{$errorSummary}");
        }

        // Rediriger vers la palette source ou destination
        $redirectTarget = $request->post('redirect_after', 'source');
        if ($redirectTarget === 'destination' && $destinationPalette) {
            return $this->redirect('/palettes/show/' . $destinationPalette->id);
        }
        if ($sourcePaletteId) {
            return $this->redirect('/palettes/show/' . $sourcePaletteId);
        }
        return $this->redirect($redirectUrl);
    }

    /**
     * Met à jour le commentaire pour un colis donné.
     */
    public function updateComment(Request $request, int $colisId): Response
    {
        $this->authorize('colis_update_comment');

        $colis = Colis::findById($colisId);
        if (!$colis) {
            $this->flash(FLASH_ERROR, "Colis non trouvé.");
            return $this->redirect($request->post('redirect_url', '/colis/scan'));
        }
        if ($r = $this->denyIfNotAccessible($colis, '/colis/scan')) return $r;

        $comment = trim($request->post('comment', ''));
        if (Colis::updateComment($colisId, $comment)) {
            $this->flash(FLASH_SUCCESS, "Commentaire enregistré");
        } else {
            $this->flash(FLASH_ERROR, "Erreur lors de l'enregistrement du commentaire");
        }

        $redirect = $request->post('redirect_url', '/colis/scan');
        return $this->redirect($redirect);
    }



}