<?php
// src/Models/PackingList.php

namespace App\Models;

use App\Services\ReferenceGenerator;
use PDO;
use PDOException;
use DateTime;
use App\Lib\Auth;

class PackingList
{
    public int $id;
    public string $reference;
    public string $ship_date;
    public string $status;
    public ?int $created_by_user_id;
    public ?int $closed_by_user_id;
    public ?int $site_id = null;
    public string $created_at;
    public string $updated_at;
    public ?Site $site = null;
    public ?string $reception_status = null; // Statut de la réception France (rempli par getAll())

    /**
     * Garde d'appartenance par site. Cloisonne l'accès aux non-administrateurs
     * rattachés à un site. Les administrateurs et les comptes sans site_id ont
     * un accès global.
     */
    public function isAccessibleBy(\App\Models\User $user): bool
    {
        if ($user->site_id === null || $user->hasRole('Administrateur')) {
            return true;
        }
        return $this->site_id !== null && (int)$this->site_id === (int)$user->site_id;
    }

    // ... TOUTES LES AUTRES MÉTHODES (findById, create, etc.) RESTENT INCHANGÉES ...
    // ... Seules les méthodes de recherche sont ajoutées/modifiées à la fin.

    public static function create(string $shipDateString, ?int $siteId, ?int $userId = null): PackingList|false
    {
        $pdo = getPDO();
        try {
            $pdo->beginTransaction();
            $temporaryReference = 'TEMP_PL_' . time() . '_' . uniqid();

            $sqlInsert = "INSERT INTO packing_lists (reference, ship_date, status, created_by_user_id, site_id, created_at, updated_at) 
                          VALUES (:reference, :ship_date, :status, :created_by_user_id, :site_id, NOW(), NOW())";
            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->bindValue(':reference', $temporaryReference);
            $stmtInsert->bindValue(':ship_date', $shipDateString);
            $stmtInsert->bindValue(':status', 'open');
            $stmtInsert->bindValue(':created_by_user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmtInsert->bindValue(':site_id', $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            
            if (!$stmtInsert->execute()) {
                $pdo->rollBack(); return false;
            }

            $lastId = (int)$pdo->lastInsertId();
            if ($lastId === 0) { $pdo->rollBack(); return false; }

            $siteCodePlaceholder = [];
            if ($siteId !== null) {
                $site = Site::findById($siteId);
                if ($site) $siteCodePlaceholder = ['{SITE_CODE}' => $site->code];
            }

            $shipDateTime = new \DateTime($shipDateString);

            // Reessai sur violation d'unicite (SQLSTATE 23000) : on regenere une nouvelle reference
            // en consommant un nouveau compteur, jusqu'a 3 tentatives.
            $sqlUpdate = "UPDATE packing_lists SET reference = :reference WHERE id = :id";
            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $maxRetries = 3;
            $attempt = 0;
            while (true) {
                $attempt++;
                $finalReference = ReferenceGenerator::generate('packing_list_reference_pattern', $lastId, $siteId, $siteCodePlaceholder, $shipDateTime);
                $stmtUpdate->bindParam(':reference', $finalReference, PDO::PARAM_STR);
                $stmtUpdate->bindParam(':id', $lastId, PDO::PARAM_INT);
                try {
                    if (!$stmtUpdate->execute()) {
                        $pdo->rollBack();
                        return false;
                    }
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $attempt < $maxRetries) {
                        error_log("PackingList::create - collision unicite reference (tentative {$attempt}), nouveau tirage.");
                        continue;
                    }
                    $pdo->rollBack();
                    throw $e;
                }
            }

            $pdo->commit();
            return self::findById($lastId);

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Erreur PackingList::create: " . $e->getMessage());
            return false;
        }
    }

    public static function findById(int $id): ?PackingList
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM packing_lists WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur PackingList::findById pour ID {$id}: " . $e->getMessage());
            return null;
        }
    }
    
    public static function getAll(array $filters = [], string $orderBy = 'pl.ship_date DESC, pl.id DESC'): array
    {
        $packingLists = [];
        // CORRECTION : Sélection explicite des colonnes avec des alias pour éviter toute ambiguïté
        $sql = "SELECT 
                    pl.*, 
                    s.name as site_name,
                    s.code as site_code,
                    (SELECT r.status FROM receptions r WHERE r.packing_list_id = pl.id) as reception_status
                FROM packing_lists pl
                LEFT JOIN sites s ON pl.site_id = s.id";
        
        $params = [];
        $whereClauses = [];

        // Filtre par site_id si l'utilisateur est lié à un site et n'est pas admin global
        $currentUser = Auth::user();
        if ($currentUser && $currentUser->site_id !== null && !$currentUser->hasRole('Administrateur')) {
            $whereClauses[] = "pl.site_id = :user_site_id";
            $params[':user_site_id'] = $currentUser->site_id;
        }
        
        // Ajouter d'autres filtres depuis le tableau $filters
        if (isset($filters['site_id']) && !empty($filters['site_id'])) {
            $whereClauses[] = "pl.site_id = :filter_site_id";
            $params[':filter_site_id'] = (int)$filters['site_id'];
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $whereClauses[] = "pl.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        // Valider la clause ORDER BY pour éviter les injections SQL et les erreurs de colonne.
        // On s'assure que le tri se fait sur des colonnes préfixées et valides.
        $safeOrderBy = [];
        $allowedSorts = ['pl.ship_date', 'pl.id', 'pl.reference', 'pl.status', 's.name'];
        $sortClauses = explode(',', $orderBy);
        foreach ($sortClauses as $clause) {
            $parts = preg_split('/\s+/', trim($clause));
            $column = strtolower(trim($parts[0]));
            $direction = (isset($parts[1]) && strtolower($parts[1]) === 'asc') ? 'ASC' : 'DESC';
            if (in_array($column, $allowedSorts)) {
                $safeOrderBy[] = "{$column} {$direction}";
            }
        }
        
        if (!empty($safeOrderBy)) {
            $sql .= " ORDER BY " . implode(', ', $safeOrderBy);
        } else {
            // Fallback si la clause de tri est invalide
            $sql .= " ORDER BY pl.ship_date DESC, pl.id DESC";
        }

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $plData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($plData as $data) {
                $packingLists[] = self::hydrate($data);
            }
        } catch (PDOException $e) { 
            error_log("Erreur PackingList::getAll: " . $e->getMessage() . " | SQL: " . $sql);
        }
        return $packingLists;
    }

    public static function updateShipDate(int $id, string $newShipDateString): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("UPDATE packing_lists SET ship_date = :ship_date WHERE id = :id AND status = 'open'");
            $stmt->bindParam(':ship_date', $newShipDateString);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur PackingList::updateShipDate pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    public static function close(int $id, ?int $userId = null): bool
    {
        $pdo = getPDO();
        try {
            $pdo->beginTransaction();

            $pl = self::findById($id);
            if (!$pl || $pl->status !== 'open') { $pdo->rollBack(); return false; }

            $stmtPl = $pdo->prepare("UPDATE packing_lists SET status = 'closed', closed_by_user_id = :user_id, updated_at = NOW() WHERE id = :id");
            $stmtPl->bindParam(':user_id', $userId, $userId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtPl->bindParam(':id', $id, PDO::PARAM_INT);
            if (!$stmtPl->execute() || $stmtPl->rowCount() === 0) { $pdo->rollBack(); return false; }

            $palettes = Palette::findByPackingListId($id);
            $nowFormatted = (new DateTime())->format('Y-m-d H:i:s');

            foreach ($palettes as $palette) {
                if (($palette->status === 'generated' || $palette->status === 'open') && $palette->getColisCount() === 0) {
                    if (!Palette::deleteById($palette->id)) { $pdo->rollBack(); return false; }
                } elseif ($palette->status === 'open' && $palette->getColisCount() > 0) {
                    $updateData = ['status' => 'closed', 'close_date_time' => $nowFormatted, 'closed_by_user_id' => $userId];
                     if (!Palette::updateFields($palette->id, $updateData)) { $pdo->rollBack(); return false; }
                }
            }

            $pdo->commit();
            return true;

        } catch (\Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Erreur PackingList::close pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    public static function delete(int $id): bool
    {
        $pdo = getPDO();
        try {
            $pdo->beginTransaction();
            $pl = self::findById($id);
            if (!$pl || $pl->status === 'closed') { $pdo->rollBack(); return false; }

            $palettes = Palette::findByPackingListId($id);
            foreach ($palettes as $palette) {
                if (($palette->status === 'open' && $palette->getColisCount() > 0) || $palette->status === 'closed') { $pdo->rollBack(); return false; }
            }

            foreach ($palettes as $palette) {
                if (!Palette::deleteById($palette->id)) { $pdo->rollBack(); return false; }
            }

            $stmtPl = $pdo->prepare("DELETE FROM packing_lists WHERE id = :id");
            $stmtPl->bindParam(':id', $id, PDO::PARAM_INT);
            if (!$stmtPl->execute() || $stmtPl->rowCount() === 0) { $pdo->rollBack(); return false; }

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
             if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Erreur PackingList::delete pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    private static function hydrate(array $data): PackingList
    {
        $pl = new self();
        $pl->id = (int)$data['id'];
        $pl->reference = $data['reference'];
        $pl->ship_date = $data['ship_date'];
        $pl->status = $data['status'];
        $pl->created_by_user_id = isset($data['created_by_user_id']) ? (int)$data['created_by_user_id'] : null;
        $pl->closed_by_user_id = isset($data['closed_by_user_id']) ? (int)$data['closed_by_user_id'] : null;
        $pl->site_id = isset($data['site_id']) ? (int)$data['site_id'] : null;
        $pl->created_at = $data['created_at'];
        $pl->updated_at = $data['updated_at'];
        $pl->reception_status = $data['reception_status'] ?? null;

        if (isset($data['site_code']) && isset($data['site_name'])) {
            $pl->site = new Site();
            $pl->site->id = $pl->site_id;
            $pl->site->code = $data['site_code'];
            $pl->site->name = $data['site_name'];
        }
        return $pl;
    }

    public function loadSite(): void
    {
        if ($this->site_id !== null && $this->site === null) {
            $this->site = Site::findById($this->site_id);
        }
    }

    public static function reopen(int $id): bool
    {
        $pl = self::findById($id);
        if (!$pl || $pl->status !== 'closed') return false;
        // Une PL en réception (ou réceptionnée) en France ne doit plus changer de contenu.
        if (Reception::findByPackingListId($id) !== null) return false;
        $sql = "UPDATE packing_lists SET status = 'open', closed_by_user_id = NULL, updated_at = NOW() WHERE id = :id AND status = 'closed'";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur PDO PackingList::reopen pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    public function getPaletteCount(): int
    {
        if (empty($this->id)) return 0;
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM palettes WHERE packing_list_id = :packing_list_id");
            $stmt->bindParam(':packing_list_id', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erreur PackingList::getPaletteCount pour PL ID {$this->id}: " . $e->getMessage());
            return 0;
        }
    }

    public static function countByStatusAndSite(string $status, ?int $siteId = null): int
    {
        $sql = "SELECT COUNT(*) FROM packing_lists WHERE status = :status";
        $params = [':status' => $status];
        if ($siteId !== null) {
            $sql .= " AND site_id = :site_id";
            $params[':site_id'] = $siteId;
        }
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erreur PackingList::countByStatusAndSite: " . $e->getMessage());
            return 0;
        }
    }

    public static function countClosedTodayBySite(?int $siteId = null): int
    {
        $sql = "SELECT COUNT(*) FROM packing_lists WHERE status = 'closed' AND DATE(updated_at) = CURDATE()";
        $params = [];
        if ($siteId !== null) {
            $sql .= " AND site_id = :site_id";
            $params[':site_id'] = $siteId;
        }
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erreur PackingList::countClosedTodayBySite: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Récupère une PackingList par sa référence exacte.
     * @param string $reference
     * @return PackingList|null
     */
    public static function findByReference(string $reference): ?PackingList
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM packing_lists WHERE reference = :reference");
            $stmt->bindParam(':reference', $reference, PDO::PARAM_STR);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur PackingList::findByReference pour la référence {$reference}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Recherche de Packing Lists via un index FULLTEXT sur la référence.
     * @param string $fulltextQuery Terme de recherche formaté pour `IN BOOLEAN MODE`.
     * @param int $limit
     * @return array
     */
    public static function search(string $fulltextQuery, int $limit = 5): array
    {
        $sql = "SELECT * FROM packing_lists
                WHERE MATCH(reference) AGAINST(? IN BOOLEAN MODE)
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
            error_log('Erreur PackingList::search (FULLTEXT) - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherche de Packing Lists par préfixe de référence en utilisant LIKE.
     * @param string $prefix
     * @param int $limit
     * @return array
     */
    public static function searchByPrefix(string $prefix, int $limit = 10): array
    {
        $sql = "SELECT * FROM packing_lists WHERE reference LIKE ? LIMIT ?";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $prefix . '%', PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map([self::class, 'hydrate'], $rows);
        } catch (\PDOException $e) {
            error_log('Erreur PackingList::searchByPrefix - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calcule la somme totale des quantités de pièces pour tous les colis
     * (directs et enfants) appartenant à cette Packing List.
     *
     * @return float
     */
    public function getTotalScannedQuantity(): float
    {
        if (empty($this->id)) {
            return 0.0;
        }

        $sql = "
            SELECT SUM(c.quantity) 
            FROM colis c
            WHERE c.quantity IS NOT NULL AND (
                -- Colis directement sur une palette de la PL
                c.palette_id IN (SELECT id FROM palettes WHERE packing_list_id = :packing_list_id_1)
                OR 
                -- Colis enfant dont le parent est sur une palette de la PL
                c.parent_colis_id IN (
                    SELECT id FROM colis WHERE palette_id IN (
                        SELECT id FROM palettes WHERE packing_list_id = :packing_list_id_2
                    )
                )
            )
        ";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':packing_list_id_1', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':packing_list_id_2', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            return (float)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("Erreur PackingList::getTotalScannedQuantity pour PL ID {$this->id}: " . $e->getMessage());
            return 0;
        }
    }
}