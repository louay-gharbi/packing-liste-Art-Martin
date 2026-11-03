<?php
// src/Models/Reception.php

namespace App\Models;

use PDO;
use PDOException;
use DateTime;
use Throwable;

/**
 * Réception France d'une Packing List expédiée (fermée) depuis la Tunisie.
 * Règles métier : cf. plan_reception_france.md, section 2 (R1 à R9).
 */
class Reception
{
    public int $id;
    public int $packing_list_id;
    public ?int $site_id = null;
    public string $status; // 'in_progress' | 'closed'
    public string $started_at;
    public ?int $started_by_user_id = null;
    public ?string $closed_at = null;
    public ?int $closed_by_user_id = null;
    public ?string $comment = null;
    public ?int $discrepancy_count = null;
    public string $created_at;
    public string $updated_at;

    public ?string $startedByName = null;
    public ?string $closedByName = null;

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    private static function hydrate(array $data): Reception
    {
        $r = new self();
        $r->id = (int)$data['id'];
        $r->packing_list_id = (int)$data['packing_list_id'];
        $r->site_id = isset($data['site_id']) ? (int)$data['site_id'] : null;
        $r->status = $data['status'];
        $r->started_at = $data['started_at'];
        $r->started_by_user_id = isset($data['started_by_user_id']) ? (int)$data['started_by_user_id'] : null;
        $r->closed_at = $data['closed_at'] ?? null;
        $r->closed_by_user_id = isset($data['closed_by_user_id']) ? (int)$data['closed_by_user_id'] : null;
        $r->comment = $data['comment'] ?? null;
        $r->discrepancy_count = isset($data['discrepancy_count']) ? (int)$data['discrepancy_count'] : null;
        $r->created_at = $data['created_at'];
        $r->updated_at = $data['updated_at'];
        $r->startedByName = $data['started_by_name'] ?? null;
        $r->closedByName = $data['closed_by_name'] ?? null;
        return $r;
    }

    private const SELECT_WITH_USERS = "SELECT r.*, us.username AS started_by_name, uc.username AS closed_by_name
                                       FROM receptions r
                                       LEFT JOIN users us ON us.id = r.started_by_user_id
                                       LEFT JOIN users uc ON uc.id = r.closed_by_user_id";

    public static function findById(int $id): ?Reception
    {
        try {
            $stmt = getPDO()->prepare(self::SELECT_WITH_USERS . " WHERE r.id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur Reception::findById ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    public static function findByPackingListId(int $packingListId): ?Reception
    {
        try {
            $stmt = getPDO()->prepare(self::SELECT_WITH_USERS . " WHERE r.packing_list_id = :plid");
            $stmt->bindValue(':plid', $packingListId, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur Reception::findByPackingListId PL {$packingListId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Démarre la réception d'une PL fermée (R1). Idempotent : si la réception existe déjà, elle est retournée.
     */
    public static function start(int $packingListId, ?int $userId, ?int $siteId): Reception|false
    {
        $existing = self::findByPackingListId($packingListId);
        if ($existing) return $existing;

        $pl = PackingList::findById($packingListId);
        if (!$pl || $pl->status !== 'closed') return false;

        try {
            $stmt = getPDO()->prepare("INSERT INTO receptions (packing_list_id, site_id, status, started_at, started_by_user_id)
                                       VALUES (:plid, :site_id, 'in_progress', NOW(), :uid)");
            $stmt->bindValue(':plid', $packingListId, PDO::PARAM_INT);
            $stmt->bindValue(':site_id', $siteId, $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            // Course entre deux démarrages simultanés : la contrainte UNIQUE garantit une seule réception.
            if ($e->getCode() !== '23000') {
                error_log("Erreur Reception::start PL {$packingListId}: " . $e->getMessage());
                return false;
            }
        }
        return self::findByPackingListId($packingListId) ?? false;
    }

    public static function close(int $id, ?int $userId, ?string $comment, int $discrepancyCount): bool
    {
        try {
            $stmt = getPDO()->prepare("UPDATE receptions SET status = 'closed', closed_at = NOW(), closed_by_user_id = :uid,
                                              comment = :comment, discrepancy_count = :dc
                                       WHERE id = :id AND status = 'in_progress'");
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':comment', $comment, $comment === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':dc', $discrepancyCount, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur Reception::close ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * PL fermées (tous sites de production) avec leur état de réception.
     * Pas de cloisonnement par site : l'accès est contrôlé par les permissions reception_*.
     *
     * @param array $filters reception_status (todo|in_progress|closed), site_id, date_from, date_to (Y-m-d)
     */
    public static function getEligiblePackingLists(array $filters = []): array
    {
        $where = ["pl.status = 'closed'"];
        $params = [];

        $status = $filters['reception_status'] ?? '';
        if ($status === 'todo') {
            $where[] = "r.id IS NULL";
        } elseif ($status === 'in_progress' || $status === 'closed') {
            $where[] = "r.status = :rstatus";
            $params[':rstatus'] = $status;
        }
        if (!empty($filters['site_id'])) {
            $where[] = "pl.site_id = :site_id";
            $params[':site_id'] = (int)$filters['site_id'];
        }
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            if (!empty($filters['date_from'])) { $where[] = "pl.ship_date >= :dfrom"; $params[':dfrom'] = $filters['date_from']; }
            if (!empty($filters['date_to']))   { $where[] = "pl.ship_date <= :dto";   $params[':dto'] = $filters['date_to']; }
        } else {
            // Par défaut : 90 derniers jours + toute réception encore en cours.
            $where[] = "(pl.ship_date >= CURDATE() - INTERVAL 90 DAY OR r.status = 'in_progress')";
        }

        $sql = "SELECT pl.id, pl.reference, pl.ship_date, s.name AS site_name, s.code AS site_code,
                       r.id AS reception_id, r.status AS reception_status, r.closed_at, r.discrepancy_count,
                       (SELECT COUNT(*) FROM palettes p WHERE p.packing_list_id = pl.id AND p.status = 'closed') AS nb_palettes,
                       (SELECT COUNT(*) FROM colis c JOIN palettes p ON p.id = c.palette_id
                         WHERE p.packing_list_id = pl.id AND p.status = 'closed' AND c.parent_colis_id IS NULL) AS nb_colis,
                       (SELECT COUNT(*) FROM reception_palettes rp JOIN palettes p ON p.id = rp.palette_id
                         WHERE rp.reception_id = r.id AND p.packing_list_id = pl.id) AS nb_palettes_recues
                FROM packing_lists pl
                LEFT JOIN sites s ON s.id = pl.site_id
                LEFT JOIN receptions r ON r.packing_list_id = pl.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY (r.status = 'in_progress') DESC, pl.ship_date DESC, pl.id DESC";
        try {
            $stmt = getPDO()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Reception::getEligiblePackingLists: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Scan d'une palette complète (R5) : tous ses colis sont considérés reçus.
     * @return array ['status' => received|duplicate|unexpected, 'reception_palette_id' => int|null]
     */
    public static function scanPalette(Reception $reception, Palette $palette, ?int $userId, bool $auto = false): array
    {
        $pdo = getPDO();
        $existing = $pdo->prepare("SELECT id FROM reception_palettes WHERE reception_id = :rid AND palette_id = :pid");
        $existing->execute([':rid' => $reception->id, ':pid' => $palette->id]);
        $existingId = $existing->fetchColumn();
        if ($existingId) {
            return ['status' => 'duplicate', 'reception_palette_id' => (int)$existingId];
        }

        $unexpected = ($palette->packing_list_id !== $reception->packing_list_id);
        try {
            $stmt = $pdo->prepare("INSERT INTO reception_palettes (reception_id, palette_id, is_unexpected, is_auto, scanned_at, scanned_by_user_id)
                                   VALUES (:rid, :pid, :unexp, :auto, NOW(), :uid)");
            $stmt->bindValue(':rid', $reception->id, PDO::PARAM_INT);
            $stmt->bindValue(':pid', $palette->id, PDO::PARAM_INT);
            $stmt->bindValue(':unexp', $unexpected ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':auto', $auto ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            $newId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $existing->execute([':rid' => $reception->id, ':pid' => $palette->id]);
                return ['status' => 'duplicate', 'reception_palette_id' => (int)$existing->fetchColumn()];
            }
            throw $e;
        }
        return ['status' => $unexpected ? 'unexpected' : 'received', 'reception_palette_id' => $newId];
    }

    /**
     * Code de la palette Tunisie d'un colis (celle de son groupage pour un colis enfant), null si colis inconnu.
     */
    public static function findPaletteCodeOfColis(string $packageNumber): ?string
    {
        try {
            $stmt = getPDO()->prepare("SELECT p.code FROM colis c LEFT JOIN colis par ON par.id = c.parent_colis_id
                                       JOIN palettes p ON p.id = COALESCE(c.palette_id, par.palette_id)
                                       WHERE c.package_number = :pn");
            $stmt->execute([':pn' => $packageNumber]);
            $code = $stmt->fetchColumn();
            return $code !== false ? $code : null;
        } catch (PDOException $e) {
            error_log("Erreur Reception::findPaletteCodeOfColis {$packageNumber}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * État complet de la réception : palettes attendues (avec leurs colis), inattendus,
     * compteurs et écarts (R3, R4, R7). Source unique pour l'écran de scan, le rapport et l'export.
     */
    public static function getState(Reception $reception): array
    {
        $pdo = getPDO();
        $rid = $reception->id;
        $plId = $reception->packing_list_id;

        $stmt = $pdo->prepare("SELECT p.id, p.code, rp.id AS rp_id, rp.is_auto, rp.scanned_at
                               FROM palettes p
                               LEFT JOIN reception_palettes rp ON rp.palette_id = p.id AND rp.reception_id = :rid
                               WHERE p.packing_list_id = :pl AND p.status = 'closed'
                               ORDER BY p.code ASC");
        $stmt->execute([':rid' => $rid, ':pl' => $plId]);
        $paletteRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT c.id, c.palette_id, c.package_number, c.type, c.of_number, c.sku, c.article_reference, c.label, c.quantity
                               FROM colis c JOIN palettes p ON p.id = c.palette_id
                               WHERE p.packing_list_id = :pl AND p.status = 'closed' AND c.parent_colis_id IS NULL
                               ORDER BY c.package_number ASC");
        $stmt->execute([':pl' => $plId]);
        $topColis = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT ch.id, ch.parent_colis_id
                               FROM colis ch JOIN colis par ON par.id = ch.parent_colis_id
                               JOIN palettes p ON p.id = par.palette_id
                               WHERE p.packing_list_id = :pl");
        $stmt->execute([':pl' => $plId]);
        $childToParent = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->prepare("SELECT rc.*, pl.reference AS origin_pl_reference
                               FROM reception_colis rc
                               LEFT JOIN colis c ON c.id = rc.colis_id
                               LEFT JOIN colis par ON par.id = c.parent_colis_id
                               LEFT JOIN palettes p ON p.id = COALESCE(c.palette_id, par.palette_id)
                               LEFT JOIN packing_lists pl ON pl.id = p.packing_list_id
                               WHERE rc.reception_id = :rid ORDER BY rc.scanned_at ASC");
        $stmt->execute([':rid' => $rid]);
        $scannedColis = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT rp.*, p.code, pl.reference AS origin_pl_reference
                               FROM reception_palettes rp JOIN palettes p ON p.id = rp.palette_id
                               JOIN packing_lists pl ON pl.id = p.packing_list_id
                               WHERE rp.reception_id = :rid AND rp.is_unexpected = 1 ORDER BY rp.scanned_at ASC");
        $stmt->execute([':rid' => $rid]);
        $unexpectedPalettes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Colis de premier niveau couverts par un scan (le colis lui-même ou l'un de ses enfants, R4).
        $scanByTopColis = [];
        foreach ($scannedColis as $rc) {
            if ($rc['is_unexpected'] || $rc['colis_id'] === null) continue;
            $cid = (int)$rc['colis_id'];
            $topId = isset($childToParent[$cid]) ? (int)$childToParent[$cid] : $cid;
            $scanByTopColis[$topId] ??= $rc;
        }

        $colisByPalette = [];
        foreach ($topColis as $c) {
            $rc = $scanByTopColis[(int)$c['id']] ?? null;
            $colisByPalette[(int)$c['palette_id']][] = $c + [
                'scanned' => $rc !== null,
                'rc_id'   => $rc ? (int)$rc['id'] : null,
            ];
        }

        $palettes = [];
        $counts = ['palettes_expected' => 0, 'palettes_received' => 0, 'colis_expected' => 0, 'colis_scanned' => 0,
                   'colis_received' => 0, 'unexpected' => 0];
        $missingPalettes = [];
        $missingColis = []; // [palette_code => [colis...]] pour les palettes contrôlées en détail
        foreach ($paletteRows as $p) {
            $colis = $colisByPalette[(int)$p['id']] ?? [];
            $nbScanned = count(array_filter($colis, fn($c) => $c['scanned']));
            $received = $p['rp_id'] !== null;
            $mode = !$received ? 'none' : ($nbScanned > 0 ? 'detailed' : 'global');

            $counts['palettes_expected']++;
            $counts['colis_expected'] += count($colis);
            $counts['colis_scanned'] += $nbScanned;
            if ($received) {
                $counts['palettes_received']++;
                $counts['colis_received'] += $mode === 'detailed' ? $nbScanned : count($colis);
            }

            if (!$received) {
                $missingPalettes[] = ['code' => $p['code'], 'nb_colis' => count($colis), 'colis' => $colis];
            } elseif ($mode === 'detailed') {
                $missing = array_values(array_filter($colis, fn($c) => !$c['scanned']));
                if ($missing) $missingColis[$p['code']] = $missing;
            }

            $palettes[] = [
                'id' => (int)$p['id'], 'code' => $p['code'], 'rp_id' => $p['rp_id'] !== null ? (int)$p['rp_id'] : null,
                'received' => $received, 'is_auto' => (bool)$p['is_auto'], 'scanned_at' => $p['scanned_at'], 'mode' => $mode,
                'nb_colis' => count($colis), 'nb_scanned' => $nbScanned, 'colis' => $colis,
            ];
        }

        $unexpectedColis = array_values(array_filter($scannedColis, fn($rc) => (int)$rc['is_unexpected'] === 1));
        $counts['unexpected'] = count($unexpectedPalettes) + count($unexpectedColis);

        $nbMissingColis = array_sum(array_map('count', $missingColis));
        $discrepancyCount = count($missingPalettes) + $nbMissingColis + $counts['unexpected'];

        return [
            'palettes' => $palettes,
            'unexpected_palettes' => $unexpectedPalettes,
            'unexpected_colis' => $unexpectedColis,
            'counts' => $counts,
            'missing_palettes' => $missingPalettes,
            'missing_colis' => $missingColis,
            'discrepancy_count' => $discrepancyCount,
        ];
    }

    /**
     * Derniers scans (palettes et colis confondus) pour l'écran de scan.
     */
    public static function getRecentScans(int $receptionId, int $limit = 5): array
    {
        try {
            $stmt = getPDO()->prepare("SELECT * FROM (
                    SELECT 'palette' AS kind, p.code AS label, rp.is_unexpected, rp.scanned_at, rp.id
                    FROM reception_palettes rp JOIN palettes p ON p.id = rp.palette_id
                    WHERE rp.reception_id = :rid1 AND rp.is_auto = 0
                    UNION ALL
                    SELECT 'colis' AS kind, rc.package_number AS label, rc.is_unexpected, rc.scanned_at, rc.id
                    FROM reception_colis rc WHERE rc.reception_id = :rid2
                ) t ORDER BY scanned_at DESC, id DESC LIMIT " . (int)$limit);
            $stmt->execute([':rid1' => $receptionId, ':rid2' => $receptionId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Reception::getRecentScans R{$receptionId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Semaines d'expédition (ISO) des PL fermées, la plus récente en premier.
     * @return array [['year' => int, 'week' => int, 'nb_pl' => int], ...]
     */
    public static function getShipWeeks(): array
    {
        try {
            $dates = getPDO()->query("SELECT ship_date FROM packing_lists WHERE status = 'closed'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Erreur Reception::getShipWeeks: " . $e->getMessage());
            return [];
        }
        $weeks = [];
        foreach ($dates as $date) {
            $d = new DateTime($date);
            $key = $d->format('o-\WW');
            $weeks[$key] ??= ['year' => (int)$d->format('o'), 'week' => (int)$d->format('W'), 'nb_pl' => 0];
            $weeks[$key]['nb_pl']++;
        }
        krsort($weeks);
        return array_values($weeks);
    }

    /**
     * Semaines d'expédition (ISO) ayant au moins une réception, la plus récente en premier.
     * @return array [['year' => int, 'week' => int, 'nb_closed' => int], ...]
     */
    public static function getWeeksWithReceptions(): array
    {
        try {
            $rows = getPDO()->query("SELECT pl.ship_date, r.status FROM receptions r
                                     JOIN packing_lists pl ON pl.id = r.packing_list_id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Reception::getWeeksWithReceptions: " . $e->getMessage());
            return [];
        }
        $weeks = [];
        foreach ($rows as $row) {
            $d = new DateTime($row['ship_date']);
            $key = $d->format('o-\WW');
            $weeks[$key] ??= ['year' => (int)$d->format('o'), 'week' => (int)$d->format('W'), 'nb_closed' => 0];
            if ($row['status'] === 'closed') $weeks[$key]['nb_closed']++;
        }
        krsort($weeks);
        return array_values($weeks);
    }

    /**
     * Destination de livraison = client + code postal (un même client peut avoir plusieurs adresses).
     * Sert de clé de regroupement du colisage client et d'identifiant dans les URL (?client=).
     */
    public static function destinationKey(string $clientName, string $postalCode): string
    {
        return $clientName . ' — ' . ($postalCode !== '' ? $postalCode : 'sans CP');
    }

    /**
     * Colisage client d'une semaine d'expédition (ISO) : colis reçus des réceptions CLÔTURÉES de la semaine,
     * tous sites confondus, groupages décomposés en colis enfants, regroupés par destination (client + code postal).
     * Colis reçu = palette reçue en global (tous ses colis) ou colis scanné sur une palette contrôlée en détail.
     * Les colis manquants et les inattendus (rattachés à une autre PL) sont exclus.
     *
     * @return array ['monday', 'sunday', 'packing_lists' => état de réception des PL de la semaine,
     *                'clients' => [destinationKey => [lignes...]]] (triés par client puis code postal)
     */
    public static function getClientPackingForWeek(int $year, int $week): array
    {
        $monday = (new DateTime())->setISODate($year, $week)->setTime(0, 0);
        $sunday = (clone $monday)->modify('+6 days');
        $packingLists = self::getEligiblePackingLists(['date_from' => $monday->format('Y-m-d'), 'date_to' => $sunday->format('Y-m-d')]);

        // Colis de premier niveau reçus, avec leur contexte (PL, palette).
        $received = [];
        foreach ($packingLists as $pl) {
            if ($pl['reception_status'] !== 'closed') continue;
            $reception = self::findById((int)$pl['reception_id']);
            if (!$reception) continue;
            foreach (self::getState($reception)['palettes'] as $p) {
                if ($p['mode'] === 'none') continue;
                foreach ($p['colis'] as $c) {
                    if ($p['mode'] === 'detailed' && !$c['scanned']) continue;
                    $received[(int)$c['id']] = [
                        'pl_reference' => $pl['reference'],
                        'site_code' => $pl['site_code'],
                        'palette_code' => $p['code'],
                    ];
                }
            }
        }

        $clients = [];
        if ($received) {
            $ids = array_keys($received);
            $in = implode(',', array_fill(0, count($ids), '?'));
            try {
                $stmt = getPDO()->prepare("SELECT c.*, par.package_number AS parent_package_number
                                           FROM colis c LEFT JOIN colis par ON par.id = c.parent_colis_id
                                           WHERE c.id IN ($in) OR c.parent_colis_id IN ($in)");
                $stmt->execute(array_merge($ids, $ids));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Erreur Reception::getClientPackingForWeek {$year}-W{$week}: " . $e->getMessage());
                $rows = [];
            }

            $parentsWithChildren = [];
            foreach ($rows as $row) {
                if ($row['parent_colis_id'] !== null) $parentsWithChildren[(int)$row['parent_colis_id']] = true;
            }
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                // Décomposition : un groupage est remplacé par ses enfants.
                if (isset($parentsWithChildren[$id])) continue;
                $topId = $row['parent_colis_id'] !== null ? (int)$row['parent_colis_id'] : $id;
                if (!isset($received[$topId])) continue;
                $clientName = trim((string)($row['client_name'] ?? '')) ?: '(Sans client)';
                $postalCode = trim((string)($row['postal_code'] ?? ''));
                $clients[self::destinationKey($clientName, $postalCode)][] = $received[$topId] + [
                    'client_name' => $clientName,
                    'colis_id' => $id,
                    'package_number' => $row['package_number'],
                    'groupage_number' => $row['parent_package_number'],
                    'of_number' => $row['of_number'],
                    'order_number' => $row['order_number'],
                    'line_number' => $row['line_number'],
                    'internal_reference' => $row['internal_reference'],
                    'article_reference' => $row['article_reference'],
                    'label' => $row['label'],
                    'sku' => $row['sku'],
                    'quantity' => $row['quantity'] !== null ? (float)$row['quantity'] : null,
                    'unit' => $row['unit'],
                    'postal_code' => $postalCode,
                ];
            }
            ksort($clients, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($clients as &$lines) {
                usort($lines, fn($a, $b) => [(string)$a['order_number'], (string)$a['line_number'], $a['package_number']]
                                       <=> [(string)$b['order_number'], (string)$b['line_number'], $b['package_number']]);
            }
            unset($lines);
        }

        return ['monday' => $monday, 'sunday' => $sunday, 'packing_lists' => $packingLists, 'clients' => $clients];
    }
}
