<?php
// src/Controllers/TheoreticalExportController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\TheoreticalExport;
use App\Models\Site;
use App\Models\Colis;
use App\Models\PackingList;

class TheoreticalExportController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    public function index(Request $request): Response
    {
        $this->authorize('theoretical_view_deviations');
        $data = ['pageTitle' => 'Module Exports Théoriques'];
        return $this->view('theoretical_exports/index.php', $data);
    }

    public function showImportForm(Request $request): Response
    {
        $this->authorize('theoretical_import_csv');

        $currentUser = Auth::user();
        $filters = [];
        // L'utilisateur ne peut voir que les PL ouvertes de son site (ou de tous les sites s'il est admin)
        if ($currentUser->site_id !== null && !$currentUser->hasRole('Administrateur')) {
            $filters['site_id'] = $currentUser->site_id;
        }
        $filters['status'] = 'open';
        
        $availablePackingLists = PackingList::getAll($filters, 'pl.ship_date ASC');

        $data = [
            'pageTitle' => 'Importer un Fichier Théorique d\'Expédition',
            'availablePackingLists' => $availablePackingLists,
            'request' => $request
        ];
        return $this->view('theoretical_exports/import.php', $data);
    }

    public function processImport(Request $request): Response
    {
        $this->authorize('theoretical_import_csv');

        $packingListId = $request->post('packing_list_id', '');
        $packingListId = ($packingListId === '') ? null : (int)$packingListId;
        $uploadedFile = $_FILES['theoretical_file'] ?? null;
        $currentUser = Auth::user();
        $errors = [];

        // Validation de la Packing List
        if (empty($packingListId)) {
            $errors['packing_list_id'] = 'Veuillez sélectionner une Packing List.';
        } else {
            $packingList = PackingList::findById($packingListId);
            if (!$packingList || $packingList->status !== 'open') {
                $errors['packing_list_id'] = 'La Packing List sélectionnée est invalide ou n\'est plus ouverte.';
            } elseif ($currentUser && !$packingList->isAccessibleBy($currentUser)) {
                $errors['packing_list_id'] = "Vous n'êtes pas autorisé à importer un théorique pour cette Packing List.";
            }
        }

        // Validation du fichier (extension + MIME reel via finfo).
        if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK || $uploadedFile['size'] === 0) {
            $errors['file'] = 'Veuillez sélectionner un fichier CSV valide. Erreur: ' . ($uploadedFile['error'] ?? 'Fichier manquant');
        } elseif (strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors['file'] = 'Le fichier doit être au format CSV.';
        } else {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($uploadedFile['tmp_name']);
            // Les CSV remontent souvent en text/plain — tolerer.
            $allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
            if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
                $errors['file'] = "Le contenu du fichier ne correspond pas a un CSV (type detecte : {$mime}).";
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) { $this->flash(FLASH_ERROR, $error); }
            return $this->redirect('/theoretical-exports/import');
        }

        // Si la validation passe, on a un objet $packingList valide
        $now = new \DateTime();
        $shipDate = new \DateTime($packingList->ship_date);
        $importYear = (int)$shipDate->format('o');
        $importWeek = (int)$shipDate->format('W');

        // Plafond : refuser au-dela de 50 000 lignes utiles pour eviter l'epuisement memoire.
        $maxLines = 50000;

        // Pre-lecture du fichier pour nettoyer un eventuel BOM UTF-8.
        $fileHandle = fopen($uploadedFile['tmp_name'], 'r');
        if ($fileHandle === false) {
            $this->flash(FLASH_ERROR, "Impossible d'ouvrir le fichier importe.");
            return $this->redirect('/theoretical-exports/import');
        }
        // Sauter le BOM UTF-8 s'il existe.
        $bom = fread($fileHandle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fileHandle);
        }

        $rowsToInsert = [];
        $lineErrors = [];
        $lineNumber = 1;
        $aborted = false;
        fgetcsv($fileHandle, 0, ';'); // Ignorer l'en-tete

        while (($rowData = fgetcsv($fileHandle, 0, ';')) !== false) {
            $lineNumber++;
            if (count($rowsToInsert) >= $maxLines) {
                $lineErrors[] = "Limite de {$maxLines} lignes atteinte. Import interrompu.";
                $aborted = true;
                break;
            }
            if (count($rowData) < 4) { $lineErrors[] = "Ligne {$lineNumber}: Nombre de colonnes insuffisant."; continue; }

            $ofNumber = trim($rowData[0]);
            $orderNumber = trim($rowData[1]);
            $article_reference = trim($rowData[2]);
            $expectedQuantity = (int)trim($rowData[3]);

            if (empty($ofNumber) || empty($orderNumber) || empty($article_reference) || $expectedQuantity <= 0) {
                $lineErrors[] = "Ligne {$lineNumber}: Données invalides ou quantité non positive.";
                continue;
            }

            $rowsToInsert[] = [
                ':site_id' => $packingList->site_id,
                ':packing_list_id' => $packingList->id,
                ':import_year' => $importYear,
                ':import_week' => $importWeek,
                ':of_number' => $ofNumber,
                ':order_number' => $orderNumber,
                ':article_reference' => $article_reference,
                ':expected_quantity' => $expectedQuantity,
                ':imported_at' => $now->format('Y-m-d H:i:s'),
                ':imported_by_user_id' => $currentUser->id
            ];
        }
        fclose($fileHandle);

        if ($aborted) {
            $this->flash(FLASH_ERROR, "Import interrompu : limite de {$maxLines} lignes atteinte. L'ancien theorique n'a PAS ete remplace.");
            return $this->redirect('/theoretical-exports/import');
        }

        // Transaction atomique : purge + insertion. Si l'insertion echoue, on garde l'ancien theorique.
        $pdo = getPDO();
        $insertedCount = 0;
        try {
            $pdo->beginTransaction();
            TheoreticalExport::deleteByPackingListId($packingList->id);
            $insertedCount = !empty($rowsToInsert) ? TheoreticalExport::insertBatch($rowsToInsert) : 0;
            $pdo->commit();
            error_log("Theorique PL ID {$packingList->id} : purge + {$insertedCount} lignes inserees (transaction atomique).");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("TheoreticalExportController::processImport - rollback : " . $e->getMessage());
            $this->flash(FLASH_ERROR, "Erreur lors de l'import : aucune modification appliquee. L'ancien theorique est preserve.");
            return $this->redirect('/theoretical-exports/import');
        }

        if ($insertedCount > 0) {
            $this->flash(FLASH_SUCCESS, "{$insertedCount} lignes théoriques importées avec succès pour la PL " . htmlspecialchars($packingList->reference) . ".");
        } else {
            $this->flash(FLASH_WARNING, "Aucune nouvelle ligne n'a été importée. Vérifiez le contenu du fichier.");
        }
        if (!empty($lineErrors)) {
            $this->flash(FLASH_ERROR, "Erreurs dans le CSV (lignes ignorées): <br>" . implode("<br>", array_slice($lineErrors, 0, 10)));
        }

        return $this->redirect('/theoretical-exports/deviations?packing_list_id=' . $packingList->id);
    }

    public function showDeviations(Request $request): Response
    {
        $this->authorize('theoretical_view_deviations');
        
        $packingListIdFromQuery = $request->get('packing_list_id');
        if (empty($packingListIdFromQuery) || !ctype_digit($packingListIdFromQuery)) {
            $this->flash(FLASH_INFO, "Veuillez sélectionner une Packing List pour voir ses écarts.");
            // On pourrait afficher une page de sélection de PL ici
            return $this->redirect('/packing-lists');
        }

        $packingList = PackingList::findById((int)$packingListIdFromQuery);
        if (!$packingList) {
            $this->flash(FLASH_ERROR, "Packing List non trouvée.");
            return $this->redirect('/packing-lists');
        }

        $packingList->loadSite();

        // Cloisonnement par site uniformisé via isAccessibleBy().
        if ($r = $this->denyIfNotAccessible($packingList, '/packing-lists')) return $r;

        $theoreticalData = TheoreticalExport::getByPackingListId($packingList->id);
        $scannedData = Colis::getScannedQuantitiesByPackingList($packingList->id);

        $comparisonData = [];
        $theoreticalMap = [];
        foreach ($theoreticalData as $thExport) {
            $key = $thExport->of_number . '|' . $thExport->order_number . '|' . $thExport->article_reference;
            $theoreticalMap[$key] = $thExport->expected_quantity;
        }

        $scannedMap = [];
        foreach ($scannedData as $scan) {
            $key = $scan['of_number'] . '|' . $scan['order_number'] . '|' . $scan['article_reference'];
            $scannedMap[$key] = (int)$scan['total_scanned_quantity'];
        }

        $combinedKeys = array_unique(array_merge(array_keys($theoreticalMap), array_keys($scannedMap)));
        sort($combinedKeys);

        foreach ($combinedKeys as $key) {
            list($of, $order, $article_reference_val) = explode('|', $key);
            $expected = $theoreticalMap[$key] ?? 0;
            $scanned = $scannedMap[$key] ?? 0;
            $comparisonData[] = [
                'of_number' => $of, 'order_number' => $order, 'article_reference' => $article_reference_val,
                'expected_quantity' => $expected, 'scanned_quantity' => $scanned,
                'deviation' => $scanned - $expected,
            ];
        }

        $data = [
            'pageTitle' => 'Écarts pour PL: ' . htmlspecialchars($packingList->reference),
            'comparisonData' => $comparisonData,
            'packingList' => $packingList,
            'request' => $request
        ];
        return $this->view('theoretical_exports/deviations_follow_up.php', $data);
    }
}