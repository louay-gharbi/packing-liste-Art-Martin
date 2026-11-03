<?php
// src/Models/Palette.php

namespace App\Models;

use App\Services\ReferenceGenerator;
use PDO;
use PDOException;
use DateTime;
use Throwable;

class Palette
{
    public int $id;
    public int $packing_list_id;
    public string $code;
    public ?string $qr_code_path = null;
    public string $status;
    public ?int $max_colis;
    public ?string $open_date_time;
    public ?string $close_date_time;
    public ?int $created_by_user_id;
    public ?int $opened_by_user_id;
    public ?int $closed_by_user_id;
    public string $created_at;
    public string $updated_at;

    public ?string $packingListReference = null;
    public ?string $siteName = null;
    public ?int $colisCount = null;

    /**
     * Garde d'appartenance par site, déléguée à la Packing List parente.
     */
    public function isAccessibleBy(\App\Models\User $user): bool
    {
        if ($user->site_id === null || $user->hasRole('Administrateur')) {
            return true;
        }
        $pl = PackingList::findById($this->packing_list_id);
        return $pl !== null && $pl->isAccessibleBy($user);
    }

    // ... TOUTES LES AUTRES MÉTHODES (findById, create, etc.) RESTENT INCHANGÉES ...
    // ... Seules les méthodes de recherche sont ajoutées/modifiées à la fin.

    public static function create(int $packingListId, ?int $userId = null): Palette|false
    {
        $pdo = getPDO();
        try {
            $pdo->beginTransaction();

          $initialStatus = 'generated';
            $temporaryCode = 'TEMP_PAL_' . time() . '_' . random_int(1000, 9999) . '_' . uniqid();
            $sqlInsert = "INSERT INTO palettes (packing_list_id, code, status, created_by_user_id, created_at, updated_at) 
                          VALUES (:packing_list_id, :code, :status, :created_by_user_id, NOW(), NOW())";
            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->bindParam(':packing_list_id', $packingListId, PDO::PARAM_INT);
            $stmtInsert->bindValue(':code', $temporaryCode);
            $stmtInsert->bindValue(':status', $initialStatus);
            $stmtInsert->bindValue(':created_by_user_id', $userId, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            if (!$stmtInsert->execute()) { $pdo->rollBack(); error_log("Palette::create - Échec insertion initiale."); return false; }
            $lastId = (int)$pdo->lastInsertId();
            if ($lastId === 0) { $pdo->rollBack(); error_log("Palette::create - lastInsertId est 0."); return false; }

            $parentPackingList = PackingList::findById($packingListId);
            $siteIdForGenerator = null;
            $siteCodePlaceholder = [];

            if ($parentPackingList && $parentPackingList->site_id !== null) {
                $siteIdForGenerator = $parentPackingList->site_id;
                $site = Site::findById($parentPackingList->site_id);
                if ($site) {
                    $siteCodePlaceholder = ['{SITE_CODE}' => $site->code];
                } else {
                    error_log("Palette::create [ID:{$lastId}] - ATTENTION: Site ID {$parentPackingList->site_id} de la PL parente non trouvé.");
                }
            } else if ($parentPackingList && $parentPackingList->site_id === null) {
                error_log("Palette::create [ID:{$lastId}] - INFO: La PL parente n'est rattachée à aucun site (site_id est NULL).");
            } else {
                error_log("Palette::create [ID:{$lastId}] - ERREUR CRITIQUE: Packing List parente ID {$packingListId} non trouvée pour la création de la palette.");
                $pdo->rollBack();
                return false;
            }

            // Reessai sur violation d'unicite (SQLSTATE 23000) jusqu'a 3 tentatives.
            $sqlUpdate = "UPDATE palettes SET code = :code, qr_code_path = :qr_code_path, updated_at = NOW() WHERE id = :id";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $maxRetries = 3;
            $attempt = 0;
            $finalCode = '';
            while (true) {
                $attempt++;
                $finalCode = ReferenceGenerator::generate('palette_code_pattern', $lastId, $siteIdForGenerator, $siteCodePlaceholder, new DateTime());
                try {
                    // Tentative d'update sans QR d'abord pour valider l'unicite.
                    $stmtUpdate->bindParam(':code', $finalCode, PDO::PARAM_STR);
                    $stmtUpdate->bindValue(':qr_code_path', null, PDO::PARAM_NULL);
                    $stmtUpdate->bindParam(':id', $lastId, PDO::PARAM_INT);
                    if (!$stmtUpdate->execute()) {
                        $pdo->rollBack();
                        error_log("Palette::create [ID:{$lastId}] - Échec UPDATE (errorInfo): " . implode(";", $stmtUpdate->errorInfo()));
                        return false;
                    }
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $attempt < $maxRetries) {
                        error_log("Palette::create - collision unicite code (tentative {$attempt}), nouveau tirage.");
                        continue;
                    }
                    $pdo->rollBack();
                    throw $e;
                }
            }

            // Le QR code n'est plus stocké : il est généré à la volée (PaletteController::qrCode).
            $pdo->commit();
            return self::findById($lastId);

        } catch (PDOException | Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Erreur Palette::create: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    private static function hydrate(array $data): Palette
    {
        $palette = new self();
        $palette->id = (int)$data['id'];
        $palette->packing_list_id = (int)$data['packing_list_id'];
        $palette->code = $data['code'];
        $palette->qr_code_path = $data['qr_code_path'] ?? null;
        $palette->status = $data['status'];
        $palette->max_colis = isset($data['max_colis']) ? (int)$data['max_colis'] : null;
        $palette->open_date_time = $data['open_date_time'] ?? null;
        $palette->close_date_time = $data['close_date_time'] ?? null;
        $palette->created_by_user_id = isset($data['created_by_user_id']) ? (int)$data['created_by_user_id'] : null;
        $palette->opened_by_user_id = isset($data['opened_by_user_id']) ? (int)$data['opened_by_user_id'] : null;
        $palette->closed_by_user_id = isset($data['closed_by_user_id']) ? (int)$data['closed_by_user_id'] : null;
        $palette->created_at = $data['created_at'];
        $palette->updated_at = $data['updated_at'];
        return $palette;
    }

    public static function open(int $id, int $maxColis, ?int $userId = null): bool {
        $palette = self::findById($id);
        if (!$palette || $palette->status !== 'generated') { return false; }
        if ($maxColis <= 0) { return false; }
        $sql = "UPDATE palettes SET status = 'open', max_colis = :max_colis, open_date_time = :odt, opened_by_user_id = :uid, updated_at = NOW() WHERE id = :id AND status = 'generated'";
        try {
            $pdo = getPDO(); $stmt = $pdo->prepare($sql);
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $stmt->bindParam(':max_colis', $maxColis, PDO::PARAM_INT); $stmt->bindParam(':odt', $now);
            $stmt->bindParam(':uid', $userId, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL); $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute(); return $stmt->rowCount() > 0;
        } catch (PDOException $e) { error_log("Erreur Palette::open ID {$id}: " . $e->getMessage()); return false; }
    }

    public static function updateMaxColis(int $id, int $newMaxColis): bool {
        $palette = self::findById($id);
        if (!$palette || $palette->status !== 'open') { return false; }
        if ($newMaxColis <= 0) { return false; }
        if ($palette->getColisCount() > $newMaxColis) { return false; }
        $sql = "UPDATE palettes SET max_colis = :max_colis, updated_at = NOW() WHERE id = :id AND status = 'open'";
        try {
            $pdo = getPDO(); $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':max_colis', $newMaxColis, PDO::PARAM_INT); $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute(); return $stmt->rowCount() > 0;
        } catch (PDOException $e) { error_log("Erreur Palette::updateMaxColis ID {$id}: " . $e->getMessage()); return false; }
    }

    public static function findById(int $id): ?Palette {
        try { $pdo = getPDO(); $stmt = $pdo->prepare("SELECT * FROM palettes WHERE id = :id"); $stmt->bindParam(':id', $id, PDO::PARAM_INT); $stmt->execute(); $data = $stmt->fetch(PDO::FETCH_ASSOC); return $data ? self::hydrate($data) : null; } catch (PDOException $e) { error_log("Erreur Palette::findById ID {$id}: " . $e->getMessage()); return null; }
    }
    
    public static function findByCode(string $code): ?Palette {
        try { $pdo = getPDO(); $stmt = $pdo->prepare("SELECT * FROM palettes WHERE code = :code"); $stmt->bindParam(':code', $code, PDO::PARAM_STR); $stmt->execute(); $data = $stmt->fetch(PDO::FETCH_ASSOC); return $data ? self::hydrate($data) : null; } catch (PDOException $e) { error_log("Erreur Palette::findByCode code {$code}: " . $e->getMessage()); return null; }
    }

    public static function findByPackingListId(int $packingListId): array {
        $palettes = []; try { $pdo = getPDO(); $stmt = $pdo->prepare("SELECT * FROM palettes WHERE packing_list_id = :plid ORDER BY created_at ASC"); $stmt->bindParam(':plid', $packingListId, PDO::PARAM_INT); $stmt->execute(); $data = $stmt->fetchAll(PDO::FETCH_ASSOC); foreach ($data as $row) $palettes[] = self::hydrate($row); } catch (PDOException $e) { error_log("Erreur Palette::findByPackingListId PL ID {$packingListId}: " . $e->getMessage()); } return $palettes;
    }

    public function getColisCount(): int
    {
        if (empty($this->id)) return 0;
        if ($this->colisCount !== null) return $this->colisCount;
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM colis WHERE palette_id = :pid AND parent_colis_id IS NULL");
            $stmt->bindValue(':pid', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            $this->colisCount = (int)$stmt->fetchColumn();
            return $this->colisCount;
        } catch (PDOException $e) {
            error_log("Erreur Palette::getColisCount pour Palette ID {$this->id}: " . $e->getMessage());
            return 0;
        }
    }

    public static function deleteById(int $id): bool {
        try { $pdo = getPDO(); $stmt = $pdo->prepare("DELETE FROM palettes WHERE id = :id"); $stmt->bindParam(':id', $id, PDO::PARAM_INT); return $stmt->execute(); } catch (PDOException $e) { error_log("Erreur Palette::deleteById ID {$id}: " . $e->getMessage()); return false; }
    }

    public static function updateFields(int $id, array $dataToUpdate): bool {
        if (empty($dataToUpdate)) return true;
        $allowedFields = ['status', 'max_colis', 'open_date_time', 'close_date_time', 'opened_by_user_id', 'closed_by_user_id', 'qr_code_path'];
        $fields = []; $params = [':id' => $id];
        foreach ($dataToUpdate as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $fields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
        }
        if (empty($fields)) return true;
        $fields[] = "updated_at = NOW()";
        $sql = "UPDATE palettes SET " . implode(', ', $fields) . " WHERE id = :id";
        try {
            $pdo = getPDO(); $stmt = $pdo->prepare($sql);
            foreach ($params as $placeholder => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue($placeholder, $value, $type);
            }
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) { error_log("Erreur Palette::updateFields ID {$id}: " . $e->getMessage()); return false; }
    }

    public static function reopen(int $id): bool
    {
        $palette = self::findById($id);
        if (!$palette || $palette->status !== 'closed') return false;
        $packingList = PackingList::findById($palette->packing_list_id);
        if (!$packingList || $packingList->status !== 'open') return false;
        $sql = "UPDATE palettes SET status = 'open', close_date_time = NULL, closed_by_user_id = NULL, updated_at = NOW() WHERE id = :id AND status = 'closed'";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur PDO Palette::reopen pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }
    
    public static function countByStatusAndSite(string $status, ?int $siteId = null): int {
        $sql = "SELECT COUNT(p.id) FROM palettes p";
        $params = [':status' => $status];
        $whereClauses = ["p.status = :status"];
        if ($siteId !== null) {
            $sql .= " INNER JOIN packing_lists pl ON p.packing_list_id = pl.id";
            $whereClauses[] = "pl.site_id = :site_id";
            $params[':site_id'] = $siteId;
        }
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
        try { $pdo = getPDO(); $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn(); }
        catch (PDOException $e) { error_log("Err Palette::countByStatusAndSite: ".$e->getMessage()); return 0; }
    }

    /**
     * Recherche de palettes via un index FULLTEXT sur le code.
     * @param string $fulltextQuery Terme de recherche formaté pour `IN BOOLEAN MODE`.
     * @param int $limit
     * @return array
     */
    public static function search(string $fulltextQuery, int $limit = 5): array
    {
        $sql = "SELECT * FROM palettes 
                WHERE MATCH(code) AGAINST(? IN BOOLEAN MODE)
                LIMIT ?";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $fulltextQuery, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map([self::class, 'hydrate'], $rows);
        } catch (PDOException $e) {
            error_log('Erreur Palette::search (FULLTEXT) - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherche de palettes par préfixe de code en utilisant LIKE.
     * @param string $prefix
     * @param int $limit
     * @return array
     */
    public static function searchByPrefix(string $prefix, int $limit = 10): array
    {
        $sql = "SELECT * FROM palettes WHERE code LIKE ? LIMIT ?";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $prefix . '%', PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map([self::class, 'hydrate'], $rows);
        } catch (\PDOException $e) {
            error_log('Erreur Palette::searchByPrefix - ' . $e->getMessage());
            return [];
        }
    }
   
    /**
     * Récupère les quantités totales de pièces pour un ensemble de palettes en une seule requête.
     *
     * @param array $paletteIds
     * @return array Tableau associatif [palette_id => total_quantity]
     */
    public static function getQuantitiesForMultiplePalettes(array $paletteIds): array
    {
        if (empty($paletteIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($paletteIds), '?'));

        $sql = "
            SELECT 
                p.id as palette_id, 
                COALESCE(SUM(c.quantity), 0) as total_quantity
            FROM palettes p
            LEFT JOIN colis c ON c.quantity IS NOT NULL AND (
                c.palette_id = p.id OR c.parent_colis_id IN (SELECT id FROM colis WHERE palette_id = p.id)
            )
            WHERE p.id IN ({$placeholders})
            GROUP BY p.id
        ";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paletteIds);
            // Retourne un tableau associatif directement formaté [palette_id => total_quantity]
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
	    return array_map('floatval', $results);
        } catch (\PDOException $e) {
            error_log('Erreur Palette::getQuantitiesForMultiplePalettes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère un récapitulatif des quantités scannées par SKU pour cette palette.
     *
     * @return array
     */
    /**
     * Retourne la quantité totale scannée sur cette palette.
     */
    public function getTotalScannedQuantity(): int
    {
        if (empty($this->id)) return 0;
        $sql = "SELECT COALESCE(SUM(c.quantity), 0) as total FROM colis c WHERE c.quantity IS NOT NULL AND (c.palette_id = :pid1 OR c.parent_colis_id IN (SELECT id FROM colis WHERE palette_id = :pid2))";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':pid1', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':pid2', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("Erreur Palette::getTotalScannedQuantity: " . $e->getMessage());
            return 0;
        }
    }

    public function getScannedQuantitiesBySku(): array
    {
        if (empty($this->id)) {
            return [];
        }

        $sql = "
            SELECT 
                article_reference as sku, 
                label, 
                SUM(quantity) as total_quantity
            FROM colis
            WHERE 
                article_reference IS NOT NULL AND quantity IS NOT NULL AND (
                    palette_id = :palette_id_1 
                    OR parent_colis_id IN (SELECT id FROM colis WHERE palette_id = :palette_id_2)
                )
            GROUP BY article_reference, label
            ORDER BY article_reference ASC
        ";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':palette_id_1', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':palette_id_2', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Erreur Palette::getScannedQuantitiesBySku pour Palette ID {$this->id}: " . $e->getMessage());
            return [];
        }
    }
}