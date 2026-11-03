<?php
// src/Models/Colis.php

namespace App\Models;

use PDO;
use PDOException;
use DateTime; // Pour formater les dates de scan

class Colis
{
    public int $id;
    public ?int $palette_id;
    public ?int $packing_list_id = null;
    public string $package_number;
    public string $payload_json;   // Stockera un JSON valide
    public string $scan_date_time;
    public ?int $scanned_by_user_id;
    public string $type = 'product';
    public ?int $parent_colis_id = null;
    public ?string $packing_list_reference = null;
    public ?string $site_name = null;
    public ?string $site_code = null;
    public ?string $palette_status = null; // statut de la palette jointe

    
    // Champs parsés du payload (peuvent être null)
    public ?string $client_name;
    public ?string $of_number;
    public ?string $order_number;
    public ?string $sku;
    public ?string $label;
    public ?float $quantity;

    // NOUVEAUX CHAMPS PARSÉS
    public ?string $internal_reference;
    public ?string $article_reference;
    public ?string $unit;
    public ?string $postal_code;
    public ?string $line_number;
    public ?string $comment = null;

    public string $created_at;
    public string $updated_at;
    public ?string $palette_code = null; // Propriété pour jointure (si utilisée)
    public ?string $parent_package_number = null; // N° colis parent si applicable
    /**
     * Liste des colis enfants rattachés à ce colis.
     *
     * @var Colis[]
     */
    public array $children = [];

    /**
     * Garde d'appartenance par site. Résout la palette directe ou, pour un
     * colis enfant, la palette du colis parent. Refuse par défaut si le colis
     * n'est rattaché à rien (ne devrait pas arriver en pratique).
     */
    public function isAccessibleBy(\App\Models\User $user): bool
    {
        if ($user->site_id === null || $user->hasRole('Administrateur')) {
            return true;
        }
        $paletteId = $this->palette_id;
        if ($paletteId === null && $this->parent_colis_id !== null) {
            $parent = self::findById($this->parent_colis_id);
            $paletteId = $parent?->palette_id;
        }
        if ($paletteId === null) {
            return false;
        }
        $palette = Palette::findById((int)$paletteId);
        return $palette !== null && $palette->isAccessibleBy($user);
    }

    // ... TOUTES LES AUTRES MÉTHODES (findById, create, etc.) RESTENT INCHANGÉES ...
    // ... Seule la méthode search est modifiée.
    
    /**
     * Récupère tous les colis associés à une palette spécifique.
     *
     * @param int $paletteId L'ID de la palette.
     * @param string $orderBy Clause ORDER BY (ex: 'scan_date_time DESC')
     * @param int|null $limit Nombre maximal de résultats
     * @return array Un tableau d'objets Colis.
     */
    public static function findByPaletteId(int $paletteId, string $orderBy = 'scan_date_time ASC', ?int $limit = null): array
    {
        $colisList = [];
        try {
            $pdo = getPDO();
            $sql = "SELECT c.*, cp.package_number AS parent_package_number FROM colis c "
                . "LEFT JOIN colis cp ON c.parent_colis_id = cp.id "
                . "WHERE c.palette_id = :pid1 "
                . "OR c.parent_colis_id IN (SELECT id FROM colis WHERE palette_id = :pid2) "
                . "ORDER BY " . $orderBy; // Attention si $orderBy vient de l'utilisateur
            if ($limit !== null && $limit > 0) {
                $sql .= " LIMIT " . (int)$limit;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':pid1', $paletteId, PDO::PARAM_INT);
            $stmt->bindValue(':pid2', $paletteId, PDO::PARAM_INT);
            $stmt->execute();
            $colisData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($colisData as $data) {
                $colisList[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erreur Colis::findByPaletteId pour Palette ID {$paletteId}: " . $e->getMessage());
        }
        return $colisList;
    }

    /**
     * Récupère un colis par son ID.
     * @param int $id
     * @return Colis|null
     */
    public static function findById(int $id): ?Colis
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM colis WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur Colis::findById pour ID {$id}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Vérifie si un package_number existe déjà en base.
     * @param string $packageNumber
     * @return bool
     */
    public static function packageNumberExists(string $packageNumber): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM colis WHERE package_number = :package_number");
            $stmt->bindParam(':package_number', $packageNumber, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Erreur Colis::packageNumberExists pour package_number {$packageNumber}: " . $e->getMessage());
            return true; // En cas d'erreur, considérer qu'il existe pour éviter les doublons
        }
    }

   /**
     * Crée un nouveau colis en base de données après un scan.
     *
     * @param array $data Données du colis à insérer. Doit inclure:
     *                    palette_id, package_number, payload_json (chaîne JSON valide), 
     *                    scan_date_time, scanned_by_user_id,
     *                    et les champs parsés optionnels (client_name, of_number, etc. y compris les nouveaux)
     * @return Colis|false L'objet Colis créé ou false en cas d'échec.
     */
    public static function create(array $data): Colis|false
    {
        $requiredFields = ['package_number', 'payload_json', 'scan_date_time']; // palette_id peut être null pour un colis enfant
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                error_log("Colis::create - Champ requis manquant: {$field} dans les données: " . print_r($data, true));
                return false;
            }
        }
        // S'assurer que payload_json est bien une chaîne (le contrôleur devrait s'en charger)
        if (!is_string($data['payload_json'])) {
             error_log("Colis::create - payload_json n'est pas une chaîne.");
             return false;
        }


        $sql = "INSERT INTO colis (
                    palette_id, package_number, payload_json, scan_date_time, scanned_by_user_id,
                    type, parent_colis_id,
                    client_name, of_number, order_number, sku, label, quantity,
                    internal_reference, article_reference, unit, postal_code, line_number, comment,
                    created_at, updated_at
                ) VALUES (
                    :palette_id, :package_number, :payload_json, :scan_date_time, :scanned_by_user_id,
                    :type, :parent_colis_id,
                    :client_name, :of_number, :order_number, :sku, :label, :quantity,
                    :internal_reference, :article_reference, :unit, :postal_code, :line_number, :comment,
                    NOW(), NOW()
                )";
        
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);

            if (array_key_exists('palette_id', $data)) {
                $stmt->bindValue(':palette_id', $data['palette_id'], $data['palette_id'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':palette_id', null, PDO::PARAM_NULL);
            }
            $stmt->bindParam(':package_number', $data['package_number']);
            $stmt->bindParam(':payload_json', $data['payload_json']); // Doit être une chaîne JSON valide
            $stmt->bindParam(':scan_date_time', $data['scan_date_time']);
            $stmt->bindValue(':scanned_by_user_id', $data['scanned_by_user_id'] ?? null, PDO::PARAM_INT); // Peut être null
            $stmt->bindValue(':type', $data['type'] ?? 'product');
            $stmt->bindValue(':parent_colis_id', $data['parent_colis_id'] ?? null, PDO::PARAM_INT);
            
            $stmt->bindValue(':client_name', $data['client_name'] ?? null);
            $stmt->bindValue(':of_number', $data['of_number'] ?? null);
            $stmt->bindValue(':order_number', $data['order_number'] ?? null);
            $stmt->bindValue(':sku', $data['sku'] ?? null);
            $stmt->bindValue(':label', $data['label'] ?? null);
            $stmt->bindValue(':quantity', $data['quantity'] ?? null, PDO::PARAM_INT);
            
            // Binding des nouveaux champs
            $stmt->bindValue(':internal_reference', $data['internal_reference'] ?? null);
            $stmt->bindValue(':article_reference', $data['article_reference'] ?? null);
            $stmt->bindValue(':unit', $data['unit'] ?? null);
            $stmt->bindValue(':postal_code', $data['postal_code'] ?? null);
            $stmt->bindValue(':line_number', $data['line_number'] ?? null);
            $stmt->bindValue(':comment', $data['comment'] ?? null);


            if ($stmt->execute()) {
                $lastId = $pdo->lastInsertId();
                if ($lastId) {
                    return self::findById((int)$lastId);
                }
                error_log("Colis::create - lastInsertId est 0 ou false après exécution réussie de l'INSERT.");
                return false;
            }
            error_log("Colis::create - L'exécution de l'INSERT a échoué. Erreur: " . implode(";", $stmt->errorInfo()));
            return false;
        } catch (PDOException $e) {
            error_log("Erreur PDO Colis::create: " . $e->getMessage() . " SQLSTATE: " . $e->getCode() . " Données: " . print_r($data, true));
            return false;
        }
    }
    
    /**
     * Déplace un colis vers une nouvelle palette.
     *
     * @param int $colisId L'ID du colis à déplacer.
     * @param int $newPaletteId L'ID de la nouvelle palette de destination.
     * @return bool True si le déplacement a réussi, false sinon.
     */
    public static function moveToPalette(int $colisId, int $newPaletteId): bool
    {
        try {
            $pdo = getPDO();

            // Resoudre la palette effective du colis : si colis enfant (palette_id NULL,
            // parent_colis_id defini), on remonte au parent pour trouver la palette source.
            $colis = self::findById($colisId);
            if (!$colis) {
                error_log("Colis::moveToPalette - colis {$colisId} introuvable.");
                return false;
            }
            $sourcePaletteId = $colis->palette_id;
            if ($sourcePaletteId === null && $colis->parent_colis_id !== null) {
                $parent = self::findById($colis->parent_colis_id);
                $sourcePaletteId = $parent?->palette_id;
            }

            $sourcePalette = $sourcePaletteId !== null ? Palette::findById((int)$sourcePaletteId) : null;
            $destPalette   = Palette::findById($newPaletteId);

            if (!$sourcePalette || !$destPalette) {
                error_log("Colis::moveToPalette - palette source ou destination introuvable (colis {$colisId} -> palette {$newPaletteId}).");
                return false;
            }

            // INVARIANT METIER : aucun deplacement inter-Packing-List ne doit etre possible.
            // Backstop modele : defense en profondeur, independant du chemin d'appel.
            if ((int)$sourcePalette->packing_list_id !== (int)$destPalette->packing_list_id) {
                error_log("Colis::moveToPalette - REFUS inter-PL : colis {$colisId} "
                    . "(PL {$sourcePalette->packing_list_id}) -> palette {$newPaletteId} "
                    . "(PL {$destPalette->packing_list_id}).");
                return false;
            }

            $stmt = $pdo->prepare("UPDATE colis SET palette_id = :pid, updated_at = NOW() WHERE id = :cid");
            $stmt->bindValue(':pid', $newPaletteId, PDO::PARAM_INT);
            $stmt->bindValue(':cid', $colisId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur Colis::moveToPalette pour colis ID {$colisId} vers palette ID {$newPaletteId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Déplace plusieurs colis (et leurs groupes parent/enfant) vers une nouvelle palette.
     * Utilise une transaction pour garantir l'intégrité.
     *
     * @param array $colisIds Tableau d'IDs des colis à déplacer (IDs parents uniquement, les enfants sont inclus automatiquement)
     * @param int $newPaletteId L'ID de la palette de destination
     * @return array ['success' => bool, 'moved_count' => int, 'errors' => string[]]
     */
    public static function moveMultipleToPalette(array $colisIds, int $newPaletteId): array
    {
        $result = [
            'success' => true,
            'moved_count' => 0,
            'errors' => []
        ];

        if (empty($colisIds)) {
            $result['errors'][] = "Aucun colis sélectionné.";
            $result['success'] = false;
            return $result;
        }

        try {
            $pdo = getPDO();
            $pdo->beginTransaction();

            // Resoudre la PL de destination UNE seule fois pour la boucle.
            $destPalette = Palette::findById($newPaletteId);
            if (!$destPalette) {
                $pdo->rollBack();
                $result['success'] = false;
                $result['errors'][] = "Palette de destination introuvable.";
                return $result;
            }
            $destPlId = (int)$destPalette->packing_list_id;

            $allAffectedIds = [];

            foreach ($colisIds as $colisId) {
                $colisId = (int)$colisId;
                if ($colisId <= 0) continue;

                $colis = self::findById($colisId);
                if (!$colis) {
                    $result['errors'][] = "Colis ID {$colisId} introuvable.";
                    continue;
                }

                // Si c'est un colis enfant, remonter au parent
                if ($colis->parent_colis_id !== null) {
                    $parent = self::findById($colis->parent_colis_id);
                    if ($parent) {
                        $colisId = $parent->id;
                        $colis = $parent;
                    }
                }

                // Éviter les doublons
                if (in_array($colisId, $allAffectedIds)) {
                    continue;
                }

                // INVARIANT METIER : refuser TOUT le lot si le colis traite n'est pas
                // dans la meme PL que la palette de destination. "Tout ou rien".
                // Les enfants suivent le parent ; il suffit donc de verifier le parent.
                $colisSourcePaletteId = $colis->palette_id;
                if ($colisSourcePaletteId === null && $colis->parent_colis_id !== null) {
                    $p = self::findById($colis->parent_colis_id);
                    $colisSourcePaletteId = $p?->palette_id;
                }
                $colisSourcePalette = $colisSourcePaletteId !== null ? Palette::findById((int)$colisSourcePaletteId) : null;
                if (!$colisSourcePalette || (int)$colisSourcePalette->packing_list_id !== $destPlId) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $result['success'] = false;
                    $result['errors'][] = "Deplacement inter-Packing-List refuse : le colis ID {$colisId} "
                        . "n'appartient pas a la meme Packing List que la palette de destination. "
                        . "Aucun colis n'a ete deplace.";
                    return $result;
                }

                // Déplacer le colis parent
                $stmt1 = $pdo->prepare("UPDATE colis SET palette_id = :pid, updated_at = NOW() WHERE id = :cid");
                $stmt1->bindValue(':pid', $newPaletteId, PDO::PARAM_INT);
                $stmt1->bindValue(':cid', $colisId, PDO::PARAM_INT);
                $stmt1->execute();
                $allAffectedIds[] = $colisId;
                $result['moved_count']++;

                // Déplacer les colis enfants
                $children = self::findChildren($colisId);
                foreach ($children as $child) {
                    if (!in_array($child->id, $allAffectedIds)) {
                        $stmt2 = $pdo->prepare("UPDATE colis SET palette_id = :pid, updated_at = NOW() WHERE id = :cid");
                        $stmt2->bindValue(':pid', $newPaletteId, PDO::PARAM_INT);
                        $stmt2->bindValue(':cid', $child->id, PDO::PARAM_INT);
                        $stmt2->execute();
                        $allAffectedIds[] = $child->id;
                        $result['moved_count']++;
                    }
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['success'] = false;
            $result['errors'][] = "Erreur BDD: " . $e->getMessage();
            error_log("Erreur Colis::moveMultipleToPalette: " . $e->getMessage());
        }

        if (!empty($result['errors'])) {
            $result['success'] = false;
        }

        return $result;
    }

    /**
     * Supprime un colis ("dé-scanner").
     * @param int $id L'ID du colis à supprimer.
     * @return bool True si succès, false sinon.
     */
    public static function deleteById(int $id): bool
    {
        try {
            $pdo = getPDO();
            $pdo->beginTransaction();

            $target = self::findById($id);
            if (!$target) {
                $pdo->rollBack();
                return false;
            }

            $parentId = $target->parent_colis_id ?? $id;

            // Supprimer d'abord les éventuels colis enfants
            $stmtChild = $pdo->prepare("DELETE FROM colis WHERE parent_colis_id = :pid");
            $stmtChild->bindParam(':pid', $parentId, PDO::PARAM_INT);
            $stmtChild->execute();

            // Supprimer ensuite le colis parent ou le colis ciblé
            $stmt = $pdo->prepare("DELETE FROM colis WHERE id = :id");
            $stmt->bindParam(':id', $parentId, PDO::PARAM_INT);
            $stmt->execute();

            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Erreur Colis::deleteById pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retourne la liste des colis enfants d'un colis parent.
     *
     * @param int $parentId
     * @return Colis[]
     */
    public static function findChildren(int $parentId): array
    {
        $children = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM colis WHERE parent_colis_id = :pid");
            $stmt->bindParam(':pid', $parentId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $children[] = self::hydrate($row);
            }
        } catch (PDOException $e) {
            error_log("Erreur Colis::findChildren pour parent ID {$parentId}: " . $e->getMessage());
        }
        return $children;
    }

    /**
     * Met à jour le commentaire d'un colis.
     *
     * @param int $colisId
     * @param string|null $comment
     * @return bool
     */
    public static function updateComment(int $colisId, ?string $comment): bool
    {
        $sql = "UPDATE colis SET comment = :comment, updated_at = NOW() WHERE id = :id";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':comment', $comment);
            $stmt->bindValue(':id', $colisId, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log('Erreur Colis::updateComment pour ID ' . $colisId . ': ' . $e->getMessage());
            return false;
        }
    }

     public static function findAllByPackingListId(int $packingListId, string $orderBy = 'p.code ASC, c.package_number ASC'): array
    {
        $colisList = [];
        try {
            $pdo = getPDO();
            $sql = "SELECT c.*, p.code AS palette_code, cp.package_number AS parent_package_number
                    FROM colis c
                    LEFT JOIN colis cp ON c.parent_colis_id = cp.id
                    LEFT JOIN palettes p ON c.palette_id = p.id
                                   OR cp.palette_id = p.id
                    WHERE p.packing_list_id = :packing_list_id
                    ORDER BY " . $orderBy;
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':packing_list_id', $packingListId, PDO::PARAM_INT);
            $stmt->execute();
            
            $colisData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($colisData as $data) {
                $colisList[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erreur Colis::findAllByPackingListId pour PL ID {$packingListId}: " . $e->getMessage());
        }
        return $colisList;
    }

    public static function getScannedQuantitiesBySite(int $siteId, array $filters = []): array
    {
        // Inclut les colis enfants (groupages) via COALESCE(c.palette_id, parent.palette_id)
        // pour rester coherent avec getScannedQuantitiesByPackingList. Sans ca, les enfants
        // (palette_id NULL) etaient totalement exclus du total scanne par site.
        $sql = "SELECT c.of_number, c.order_number, c.sku, SUM(c.quantity) as total_scanned_quantity
                FROM colis c
                LEFT JOIN colis parent ON c.parent_colis_id = parent.id
                INNER JOIN palettes pal ON pal.id = COALESCE(c.palette_id, parent.palette_id)
                INNER JOIN packing_lists pl ON pal.packing_list_id = pl.id
                WHERE pl.site_id = :site_id
                  AND pal.status IN ('open', 'closed')";
        
        $params = [':site_id' => $siteId];

        if (!empty($filters['of_number'])) { $sql .= " AND c.of_number = :of_number"; $params[':of_number'] = $filters['of_number']; }
        if (!empty($filters['order_number'])) { $sql .= " AND c.order_number = :order_number"; $params[':order_number'] = $filters['order_number']; }
        if (!empty($filters['sku'])) { $sql .= " AND c.sku = :sku"; $params[':sku'] = $filters['sku']; }
        $sql .= " GROUP BY c.of_number, c.order_number, c.sku";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Colis::getScannedQuantitiesBySite pour Site ID {$siteId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère les quantités totales scannées par OF/Commande/SKU pour une Packing List donnée.
     * Cette version corrigée prend en compte à la fois les colis directs et les colis enfants (groupés).
     *
     * @param int $packingListId
     * @param array $filters Optionnels sur OF, Commande, SKU.
     * @return array
     */
    public static function getScannedQuantitiesByPackingList(int $packingListId, array $filters = []): array
    {
        // La requête est réécrite pour inclure tous les colis (parents et enfants)
        // liés aux palettes de la Packing List spécifiée.
        $sql = "
            SELECT 
                c.of_number, 
                c.order_number, 
                c.article_reference, 
                SUM(c.quantity) as total_scanned_quantity
            FROM colis c
            WHERE 
                -- Condition 1: Colis directement sur une palette de la PL
                c.palette_id IN (
                    SELECT id FROM palettes 
                    WHERE packing_list_id = :packing_list_id_1 
                    AND status IN ('open', 'closed')
                )
                -- Condition 2: OU colis enfant dont le parent est sur une palette de la PL
                OR c.parent_colis_id IN (
                    SELECT id FROM colis 
                    WHERE palette_id IN (
                        SELECT id FROM palettes 
                        WHERE packing_list_id = :packing_list_id_2 
                        AND status IN ('open', 'closed')
                    )
                )
        ";
        
        $params = [
            ':packing_list_id_1' => $packingListId,
            ':packing_list_id_2' => $packingListId
        ];

        // Construction de la clause WHERE pour les filtres supplémentaires
        $filterClauses = [];
        if (!empty($filters['of_number'])) {
            $filterClauses[] = "c.of_number = :of_number";
            $params[':of_number'] = $filters['of_number'];
        }
        if (!empty($filters['order_number'])) {
            $filterClauses[] = "c.order_number = :order_number";
            $params[':order_number'] = $filters['order_number'];
        }
        if (!empty($filters['article_reference'])) {
            $filterClauses[] = "c.article_reference = :article_reference";
            $params[':article_reference'] = $filters['article_reference'];
        }
        
        // Ajouter les filtres à la requête principale
        if (!empty($filterClauses)) {
            // On doit mettre les filtres dans une parenthèse globale après le WHERE principal
            $sql .= " AND (" . implode(" AND ", $filterClauses) . ")";
        }

        $sql .= " GROUP BY c.of_number, c.order_number, c.article_reference";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Colis::getScannedQuantitiesByPackingList pour PL ID {$packingListId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retourne les statistiques de scans par jour pour les 7 derniers jours.
     * @param int|null $siteId
     * @return array [ ['date' => '2025-05-22', 'count' => 15], ... ]
     */
    public static function getDailyScansStats(?int $siteId = null): array
    {
        $sql = "SELECT DATE(c.scan_date_time) AS date, COUNT(*) AS count FROM colis c ";
        $params = [];
        $whereClauses = ["c.scan_date_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"];

        if ($siteId !== null) {
            $sql .= "INNER JOIN palettes p ON c.palette_id = p.id ";
            $sql .= "INNER JOIN packing_lists pl ON p.packing_list_id = pl.id ";
            $whereClauses[] = "pl.site_id = :site_id";
            $params[':site_id'] = $siteId;
        }

        $sql .= " WHERE " . implode(" AND ", $whereClauses);
        $sql .= " GROUP BY DATE(c.scan_date_time) ORDER BY date ASC";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Remplir les jours vides avec 0
            $stats = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $stats[$date] = 0;
            }
            foreach ($results as $row) {
                if (isset($stats[$row['date']])) {
                    $stats[$row['date']] = (int)$row['count'];
                }
            }
            
            $formatted = [];
            foreach ($stats as $date => $count) {
                $formatted[] = ['date' => $date, 'count' => $count];
            }
            return $formatted;
        } catch (PDOException $e) {
            error_log("Erreur Colis::getDailyScansStats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retourne l'activité récente (derniers colis scannés).
     * @param int $limit
     * @param int|null $siteId
     * @return array
     */
    public static function getRecentActivity(int $limit = 10, ?int $siteId = null): array
    {
        $sql = "SELECT c.package_number, c.scan_date_time, c.of_number, c.sku, c.type,
                       p.code AS palette_code, pl.reference AS pl_reference
                FROM colis c
                LEFT JOIN palettes p ON c.palette_id = p.id
                LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id ";
        
        $params = [];
        $whereClauses = [];
        
        if ($siteId !== null) {
            $sql .= "LEFT JOIN sites s ON pl.site_id = s.id ";
            $whereClauses[] = "s.id = :site_id";
            $params[':site_id'] = $siteId;
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY c.scan_date_time DESC LIMIT " . (int)$limit;

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Colis::getRecentActivity: " . $e->getMessage());
            return [];
        }
    }

    public static function countScannedTodayBySite(?int $siteId = null): int
    {
        $sql = "SELECT COUNT(c.id) FROM colis c ";
        $params = [];
        $whereClauses = ["DATE(c.scan_date_time) = CURDATE()"];

        if ($siteId !== null) {
            $sql .= "INNER JOIN palettes p ON c.palette_id = p.id ";
            $sql .= "INNER JOIN packing_lists pl ON p.packing_list_id = pl.id ";
            $whereClauses[] = "pl.site_id = :site_id";
            $params[':site_id'] = $siteId;
        }
        
        $sql .= " WHERE " . implode(" AND ", $whereClauses);

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Erreur Colis::countScannedTodayBySite (siteId: {$siteId}): " . $e->getMessage());
            return 0;
        }
    }

    private static function hydrate(array $data): Colis
    {
        $colis = new self();
        $colis->id = (int)$data['id'];
        $colis->palette_id = isset($data['palette_id']) ? (int)$data['palette_id'] : null;
        $colis->packing_list_id = isset($data['packing_list_id']) ? (int)$data['packing_list_id'] : null;
        $colis->package_number = $data['package_number'];
        $colis->payload_json = $data['payload_json'];
        $colis->scan_date_time = $data['scan_date_time'];
        $colis->scanned_by_user_id = isset($data['scanned_by_user_id']) ? (int)$data['scanned_by_user_id'] : null;
        $colis->type = $data['type'] ?? 'product';
        $colis->parent_colis_id = isset($data['parent_colis_id']) ? (int)$data['parent_colis_id'] : null;
        $colis->client_name = $data['client_name'] ?? null;
        $colis->of_number = $data['of_number'] ?? null;
        $colis->order_number = $data['order_number'] ?? null;
        $colis->sku = $data['sku'] ?? null;
        $colis->label = $data['label'] ?? null;
	$colis->quantity = isset($data['quantity']) ? (float)$data['quantity'] : null;
        $colis->internal_reference = $data['internal_reference'] ?? null;
        $colis->article_reference = $data['article_reference'] ?? null;
        $colis->unit = $data['unit'] ?? null;
        $colis->postal_code = $data['postal_code'] ?? null;
        $colis->line_number = $data['line_number'] ?? null;
        $colis->comment = $data['comment'] ?? null;
        $colis->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
        $colis->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $colis->palette_code = $data['palette_code'] ?? null;
        $colis->packing_list_reference = $data['packing_list_reference'] ?? null;
        $colis->site_name = $data['site_name'] ?? null;
        $colis->site_code = $data['site_code'] ?? null;
        $colis->palette_status = $data['palette_status'] ?? null;
        $colis->parent_package_number = $data['parent_package_number'] ?? null;

        return $colis;
    }

    /**
     * Parsing du payload QR. Centralise les deux formats supportes :
     *  - ancien : "cle|valeur;cle|valeur;..."
     *  - nouveau : 12 valeurs positionnelles separees par ";"
     *
     * En cas d echec (format inattendu, mauvais nombre de champs), retourne
     * un tableau vide et journalise le payload rejete (sans le contenu sensible
     * complet : tronque a 200 caracteres) pour faciliter le diagnostic terrain.
     */
    public static function parseQrPayload(string $payload): array
    {
        $parsed = [];
        if (strpos($payload, '|') !== false) {
            $pairs = explode(';', $payload);
            foreach ($pairs as $pair) {
                $kv = explode('|', $pair, 2);
                if (count($kv) === 2 && trim($kv[0]) !== '') {
                    $parsed[trim($kv[0])] = trim($kv[1]);
                }
            }
        } else {
            $expected = ['package_number','qty','of_number','ref_interne','client_name','ref_article','label','sku','order_number','unit','code_postal','ligne'];
            $values = array_map('trim', explode(';', $payload));
            if (count($values) === count($expected)) {
                $parsed = array_combine($expected, $values);
            }
        }
        if (empty($parsed)) {
            error_log("Colis::parseQrPayload - format invalide, payload rejete (tronque): " . substr($payload, 0, 200));
        }
        return $parsed;
    }

    public static function findOneByPackageNumberWithJoins(string $pn): ?Colis
    {
        $sql = "SELECT c.*, p.id AS palette_id, pl.id AS packing_list_id,
                       p.code AS palette_code, p.status AS palette_status,
                       pl.reference AS packing_list_reference,
                       s.name AS site_name, s.code AS site_code,
                       cp.package_number AS parent_package_number
                FROM colis c
                LEFT JOIN colis cp ON c.parent_colis_id = cp.id
                LEFT JOIN palettes p ON c.palette_id = p.id OR cp.palette_id = p.id
                LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                LEFT JOIN sites s ON pl.site_id = s.id
                WHERE c.package_number = :pn
                LIMIT 1";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':pn', $pn);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? self::hydrate($row) : null;
        } catch (PDOException $e) {
            error_log('Erreur Colis::findOneByPackageNumberWithJoins - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Recherche de colis via un index FULLTEXT sur plusieurs champs.
     * @param string $fulltextQuery Terme de recherche formaté pour `IN BOOLEAN MODE`.
     * @param int $limit
     * @return array
     */
    public static function search(string $fulltextQuery, int $limit = 10): array
    {
        // CORRECTION : Ajout de l'alias 'c.' devant chaque nom de colonne pour lever l'ambiguïté.
        $indexed_fields = 'c.`package_number`, c.`sku`, c.`of_number`, c.`order_number`, c.`comment`, c.`label`';

        $sql = "SELECT c.*, p.id AS palette_id, pl.id AS packing_list_id,
                       p.code AS palette_code, p.status AS palette_status,
                       pl.reference AS packing_list_reference
                FROM colis c
                LEFT JOIN colis cp ON c.parent_colis_id = cp.id
                LEFT JOIN palettes p ON c.palette_id = p.id OR cp.palette_id = p.id
                LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                WHERE MATCH(" . $indexed_fields . ") AGAINST(? IN BOOLEAN MODE)
                LIMIT ?";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $fulltextQuery, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map([self::class, 'hydrate'], $rows);
        } catch (\PDOException $e) {
            error_log('Erreur Colis::search (FULLTEXT) - ' . $e->getMessage());
            return [];
        }
    }
    public static function findChildrenWithJoins(int $parentId): array
    {
        $sql = "SELECT c.*, p.id AS palette_id, pl.id AS packing_list_id,
                       p.code AS palette_code, p.status AS palette_status,
                       pl.reference AS packing_list_reference,
                       s.name AS site_name, s.code AS site_code,
                       cp.package_number AS parent_package_number
                FROM colis c
                LEFT JOIN colis cp ON c.parent_colis_id = cp.id
                LEFT JOIN palettes p ON c.palette_id = p.id OR cp.palette_id = p.id
                LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
                LEFT JOIN sites s ON pl.site_id = s.id
                WHERE c.parent_colis_id = :pid
                ORDER BY c.scan_date_time";
        $children = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':pid', $parentId, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $children[] = self::hydrate($row);
            }
        } catch (PDOException $e) {
            error_log('Erreur Colis::findChildrenWithJoins - ' . $e->getMessage());
        }
        return $children;
    }
  // DANS src/Models/Colis.php
// Remplacez les méthodes existantes par ce bloc complet

    /**
     * Calcule un récapitulatif complet pour un numéro d'OF donné.
     * Fait appel à la méthode générique getSummaryByField.
     *
     * @param string $ofNumber
     * @return array
     */
    public static function getSummaryByOfNumber(string $ofNumber): array
    {
        return self::getSummaryByField('of_number', $ofNumber);
    }

    /**
     * Calcule un récapitulatif complet pour un SKU donné.
     * Fait appel à la méthode générique getSummaryByField.
     *
     * @param string $sku
     * @return array
     */
    public static function getSummaryBySku(string $sku): array
    {
        return self::getSummaryByField('sku', $sku);
    }

    private static function getSummaryByField(string $fieldName, string $value): array
    {
        $allowedFields = ['of_number', 'sku'];
        if (!in_array($fieldName, $allowedFields)) {
            throw new \InvalidArgumentException("Nom de champ invalide pour le récapitulatif.");
        }

        // Requête unique pour récupérer TOUTES les informations nécessaires, y compris les détails des colis
        $sql = "
            SELECT 
                c.id as colis_id,
                c.package_number,
                c.quantity,
                p.id as palette_id,
                p.code as palette_code,
                pl.id as pl_id,
                pl.reference as pl_reference
            FROM colis c
            LEFT JOIN colis cp ON c.parent_colis_id = cp.id
            LEFT JOIN palettes p ON c.palette_id = p.id OR cp.palette_id = p.id
            LEFT JOIN packing_lists pl ON p.packing_list_id = pl.id
            WHERE c.{$fieldName} = :value AND p.id IS NOT NULL
            ORDER BY pl.reference, p.code, c.package_number
        ";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':value' => $value]);
            $colisDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($colisDetails)) {
                return [ 'reference' => $value, 'colis_count' => 0 ];
            }

            // Traitement des résultats en PHP
            $total_quantity = 0;
            $palettes = [];
            $packing_lists = [];

            foreach ($colisDetails as $colis) {
                $total_quantity += (int)$colis['quantity'];
                if ($colis['palette_id']) {
                    $palettes[$colis['palette_id']] = ['id' => $colis['palette_id'], 'code' => $colis['palette_code']];
                }
                if ($colis['pl_id']) {
                    $packing_lists[$colis['pl_id']] = ['id' => $colis['pl_id'], 'reference' => $colis['pl_reference']];
                }
            }

            return [
                'type' => strtoupper(str_replace('_', ' ', $fieldName)),
                'reference' => $value,
                'total_quantity' => $total_quantity,
                'colis_count' => count($colisDetails),
                'palettes_count' => count($palettes),
                'colis_details' => $colisDetails, // <-- LISTE DÉTAILLÉE AJOUTÉE ICI
                'palettes' => array_values($palettes),
                'packing_lists' => array_values($packing_lists),
            ];

        } catch (\PDOException $e) {
            error_log("Erreur Colis::getSummaryByField pour {$fieldName}={$value}: " . $e->getMessage());
            return ['error' => 'Database error'];
        }
    }

    /**
     * Récupère la synthèse agrégée des produits pour une Packing List
     */
    public static function getAggregationSummaryByPackingList(int $packingListId): array
    {
        $sql = "
            (SELECT 
                COALESCE(NULLIF(c.client_name, ''), 'N/A') AS client,
                COALESCE(NULLIF(c.postal_code, ''), 'N/A') AS cp_livraison,
                COALESCE(NULLIF(c.order_number, ''), 'N/A') AS num_commande,
                COALESCE(NULLIF(c.line_number, ''), 'N/A') AS ligne,
                COALESCE(NULLIF(c.article_reference, ''), 'N/A') AS code_article,
                SUM(c.quantity) AS quantite_totale,
                0 AS is_total
            FROM 
                colis c
            LEFT JOIN 
                colis cp ON c.parent_colis_id = cp.id
            JOIN 
                palettes p ON (c.palette_id = p.id OR cp.palette_id = p.id)
            WHERE 
                p.packing_list_id = :id1
                AND c.type = 'product'
            GROUP BY 
                client, cp_livraison, num_commande, ligne, code_article)

            UNION ALL

            (SELECT 
                'TOTAL GÉNÉRAL' AS client,
                '' AS cp_livraison,
                '' AS num_commande,
                '' AS ligne,
                '' AS code_article,
                SUM(c.quantity) AS quantite_totale,
                1 AS is_total
            FROM 
                colis c
            LEFT JOIN 
                colis cp ON c.parent_colis_id = cp.id
            JOIN 
                palettes p ON (c.palette_id = p.id OR cp.palette_id = p.id)
            WHERE 
                p.packing_list_id = :id2
                AND c.type = 'product')

            ORDER BY 
                is_total ASC, 
                client ASC, 
                cp_livraison ASC, 
                num_commande ASC, 
                -- TRI NUMÉRIQUE DE LA LIGNE :
                -- On convertit en non-signé, les 'N/A' seront traités comme 0 ou triés à la fin
                CAST(NULLIF(ligne, 'N/A') AS UNSIGNED) ASC, 
                ligne ASC, 
                code_article ASC
        ";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id1', $packingListId, PDO::PARAM_INT);
            $stmt->bindValue(':id2', $packingListId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Erreur Colis::getAggregationSummaryByPackingList : " . $e->getMessage());
            return [];
        }
    }
}