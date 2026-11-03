<?php
// src/Models/TheoreticalExport.php

namespace App\Models;

use PDO;
use PDOException;
use DateTime;

class TheoreticalExport
{
    public int $id;
    public ?int $packing_list_id; // NOUVEAU CHAMP
    public int $site_id;
    public int $import_year;
    public int $import_week;
    public string $of_number;
    public string $order_number;
    public string $article_reference;
    public int $expected_quantity;
    public ?DateTime $imported_at;
    public ?int $imported_by_user_id;
    public string $created_at;
    public string $updated_at;

    /**
     * Supprime toutes les entrées théoriques pour une Packing List donnée.
     *
     * @param int $packingListId
     * @return bool
     */
    public static function deleteByPackingListId(int $packingListId): bool
    {
        $sql = "DELETE FROM theoretical_exports WHERE packing_list_id = :packing_list_id";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':packing_list_id', $packingListId, PDO::PARAM_INT);
            $stmt->execute();
            return true; 
        } catch (PDOException $e) {
            error_log("Erreur PDO TheoreticalExport::deleteByPackingListId pour PL ID {$packingListId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insère un lot de données théoriques.
     *
     * @param array $rowsData
     * @return int Le nombre de lignes insérées.
     */
    public static function insertBatch(array $rowsData): int
    {
        if (empty($rowsData)) {
            return 0;
        }
        
        $sql = "INSERT INTO theoretical_exports 
                    (site_id, packing_list_id, import_year, import_week, of_number, order_number, article_reference, expected_quantity, imported_at, imported_by_user_id, created_at, updated_at) 
                VALUES 
                    (:site_id, :packing_list_id, :import_year, :import_week, :of_number, :order_number, :article_reference, :expected_quantity, :imported_at, :imported_by_user_id, NOW(), NOW())";
        
        $pdo = getPDO();
        $insertedCount = 0;
        $stmt = $pdo->prepare($sql);

        foreach ($rowsData as $row) {
            try {
                $stmt->execute($row);
                $insertedCount++;
            } catch (PDOException $e) {
                error_log("TheoreticalExport::insertBatch - Échec d'insertion pour la ligne: " . print_r($row, true) . " Erreur: " . $e->getMessage());
            }
        }
        return $insertedCount;
    }

    /**
     * Récupère les données théoriques pour une Packing List donnée.
     *
     * @param int $packingListId
     * @param array $filters Optionnels sur OF, Commande, SKU.
     * @return array
     */
    // Fichier : src/Models/TheoreticalExport.php

public static function getByPackingListId(int $packingListId, array $filters = []): array
{
    $results = [];
    $sql = "SELECT * FROM theoretical_exports WHERE packing_list_id = :packing_list_id";
    
    $params = [':packing_list_id' => $packingListId];

    if (!empty($filters['of_number'])) { 
        $sql .= " AND of_number = :of_number"; 
        $params[':of_number'] = $filters['of_number']; 
    }
    if (!empty($filters['order_number'])) { 
        $sql .= " AND order_number = :order_number"; 
        $params[':order_number'] = $filters['order_number']; 
    }
    if (!empty($filters['article_reference'])) { 
        $sql .= " AND article_reference = :article_reference"; 
        $params[':article_reference'] = $filters['article_reference']; 
    }
    
    // CORRECTION : La clause ORDER BY ne doit apparaître qu'une seule fois.
    $sql .= " ORDER BY of_number, order_number, article_reference";

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $dataRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($dataRows as $data) {
            $results[] = self::hydrate($data);
        }
    } catch (PDOException $e) {
        error_log("Erreur PDO TheoreticalExport::getByPackingListId: " . $e->getMessage());
    }
    return $results;
}
    
    private static function hydrate(array $data): TheoreticalExport
    {
        $export = new self();
        $export->id = (int)$data['id'];
        $export->packing_list_id = isset($data['packing_list_id']) ? (int)$data['packing_list_id'] : null;
        $export->site_id = (int)$data['site_id'];
        $export->import_year = (int)$data['import_year'];
        $export->import_week = (int)$data['import_week'];
        $export->of_number = $data['of_number'];
        $export->order_number = $data['order_number'];
        $export->article_reference = $data['article_reference'];
        $export->expected_quantity = (int)$data['expected_quantity'];
        try {
            $export->imported_at = isset($data['imported_at']) ? new DateTime($data['imported_at']) : null;
        } catch (\Exception $e) {
            $export->imported_at = null;
        }
        $export->imported_by_user_id = isset($data['imported_by_user_id']) ? (int)$data['imported_by_user_id'] : null;
        $export->created_at = $data['created_at'];
        $export->updated_at = $data['updated_at'];
        return $export;
    }
}