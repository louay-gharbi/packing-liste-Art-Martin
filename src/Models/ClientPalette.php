<?php
// src/Models/ClientPalette.php

namespace App\Models;

use PDO;
use PDOException;

/**
 * Palette d'expédition client (France) : colis reçus d'une même destination (client + code postal) et d'une même
 * semaine d'expédition Tunisie, rassemblés par scan. Chaque colis garde la trace de sa palette Tunisie et de sa PL d'origine.
 */
class ClientPalette
{
    public int $id;
    public string $code;
    public string $client_name;
    public string $postal_code = '';
    public int $ship_year;
    public int $ship_week;
    public int $number;
    public string $status; // 'open' | 'closed'
    public string $created_at;
    public ?int $created_by_user_id = null;
    public ?string $closed_at = null;
    public ?int $closed_by_user_id = null;

    public ?string $createdByName = null;
    public ?string $closedByName = null;
    public int $nbColis = 0;

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function weekParam(): string
    {
        return sprintf('%d-W%02d', $this->ship_year, $this->ship_week);
    }

    /** Destination (client + code postal), même clé que le colisage client. */
    public function destinationKey(): string
    {
        return Reception::destinationKey($this->client_name, $this->postal_code);
    }

    private static function hydrate(array $data): ClientPalette
    {
        $p = new self();
        $p->id = (int)$data['id'];
        $p->code = $data['code'];
        $p->client_name = $data['client_name'];
        $p->postal_code = (string)($data['postal_code'] ?? '');
        $p->ship_year = (int)$data['ship_year'];
        $p->ship_week = (int)$data['ship_week'];
        $p->number = (int)$data['number'];
        $p->status = $data['status'];
        $p->created_at = $data['created_at'];
        $p->created_by_user_id = isset($data['created_by_user_id']) ? (int)$data['created_by_user_id'] : null;
        $p->closed_at = $data['closed_at'] ?? null;
        $p->closed_by_user_id = isset($data['closed_by_user_id']) ? (int)$data['closed_by_user_id'] : null;
        $p->createdByName = $data['created_by_name'] ?? null;
        $p->closedByName = $data['closed_by_name'] ?? null;
        $p->nbColis = (int)($data['nb_colis'] ?? 0);
        return $p;
    }

    private const SELECT_FULL = "SELECT cp.*, uc.username AS created_by_name, ucl.username AS closed_by_name,
                                        (SELECT COUNT(*) FROM client_palette_colis cpc WHERE cpc.client_palette_id = cp.id) AS nb_colis
                                 FROM client_palettes cp
                                 LEFT JOIN users uc ON uc.id = cp.created_by_user_id
                                 LEFT JOIN users ucl ON ucl.id = cp.closed_by_user_id";

    public static function findById(int $id): ?ClientPalette
    {
        try {
            $stmt = getPDO()->prepare(self::SELECT_FULL . " WHERE cp.id = :id");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::findById ID {$id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Palettes d'une semaine (et d'une destination client + code postal si précisée), triées par client, code postal, numéro.
     * @return ClientPalette[]
     */
    public static function getForWeek(int $year, int $week, ?string $client = null, ?string $postalCode = null): array
    {
        $sql = self::SELECT_FULL . " WHERE cp.ship_year = :y AND cp.ship_week = :w";
        $params = [':y' => $year, ':w' => $week];
        if ($client !== null) {
            $sql .= " AND cp.client_name = :client AND cp.postal_code = :cp";
            $params[':client'] = $client;
            $params[':cp'] = (string)$postalCode;
        }
        try {
            $stmt = getPDO()->prepare($sql . " ORDER BY cp.client_name ASC, cp.postal_code ASC, cp.number ASC");
            $stmt->execute($params);
            return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::getForWeek {$year}-W{$week}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Colis déjà posés sur une palette client de la semaine.
     * @return array [colis_id => ['code' => code palette client, 'id' => id palette client]]
     */
    public static function getPlacedMap(int $year, int $week): array
    {
        try {
            $stmt = getPDO()->prepare("SELECT cpc.colis_id, cp.code, cp.id FROM client_palette_colis cpc
                                       JOIN client_palettes cp ON cp.id = cpc.client_palette_id
                                       WHERE cp.ship_year = :y AND cp.ship_week = :w");
            $stmt->execute([':y' => $year, ':w' => $week]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['colis_id']] = ['code' => $row['code'], 'id' => (int)$row['id']];
            }
            return $map;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::getPlacedMap {$year}-W{$week}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Partie client du code : majuscules sans accents ni séparateurs, 20 caractères max.
     */
    public static function clientSlug(string $client): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $client) ?: $client;
        $slug = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($ascii)), 0, 20);
        return $slug !== '' ? $slug : 'CLIENT';
    }

    /**
     * Crée la palette suivante du client pour la semaine et l'adresse (code postal). Numérotation par préfixe de code
     * (client + semaine), donc continue entre les adresses d'un même client et sans collision entre deux clients
     * au code abrégé identique.
     */
    public static function create(string $client, string $postalCode, int $year, int $week, ?int $userId): ?ClientPalette
    {
        $pdo = getPDO();
        $prefix = sprintf('PALC-%s-%d-S%02d-', self::clientSlug($client), $year, $week);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $stmt = $pdo->prepare("SELECT COALESCE(MAX(number), 0) FROM client_palettes WHERE code LIKE :prefix");
                $stmt->execute([':prefix' => $prefix . '%']);
                $number = (int)$stmt->fetchColumn() + 1;

                $stmt = $pdo->prepare("INSERT INTO client_palettes (code, client_name, postal_code, ship_year, ship_week, number, status, created_at, created_by_user_id)
                                       VALUES (:code, :client, :cp, :y, :w, :n, 'open', NOW(), :uid)");
                $stmt->bindValue(':code', $prefix . sprintf('%02d', $number));
                $stmt->bindValue(':client', $client);
                $stmt->bindValue(':cp', $postalCode);
                $stmt->bindValue(':y', $year, PDO::PARAM_INT);
                $stmt->bindValue(':w', $week, PDO::PARAM_INT);
                $stmt->bindValue(':n', $number, PDO::PARAM_INT);
                $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();
                return self::findById((int)$pdo->lastInsertId());
            } catch (PDOException $e) {
                // Création simultanée du même numéro : on recalcule.
                if ($e->getCode() !== '23000') {
                    error_log("Erreur ClientPalette::create {$client} {$year}-W{$week}: " . $e->getMessage());
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Pose un colis sur la palette.
     * @return array ['status' => added|duplicate|elsewhere|error, 'other_code' => code de l'autre palette client]
     */
    public static function addColis(ClientPalette $palette, int $colisId, ?int $userId): array
    {
        $pdo = getPDO();
        try {
            $stmt = $pdo->prepare("INSERT INTO client_palette_colis (client_palette_id, colis_id, scanned_at, scanned_by_user_id)
                                   VALUES (:pid, :cid, NOW(), :uid)");
            $stmt->bindValue(':pid', $palette->id, PDO::PARAM_INT);
            $stmt->bindValue(':cid', $colisId, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            return ['status' => 'added', 'other_code' => null];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $stmt = $pdo->prepare("SELECT cp.id, cp.code FROM client_palette_colis cpc JOIN client_palettes cp ON cp.id = cpc.client_palette_id
                                       WHERE cpc.colis_id = :cid");
                $stmt->execute([':cid' => $colisId]);
                $other = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($other && (int)$other['id'] === $palette->id) {
                    return ['status' => 'duplicate', 'other_code' => null];
                }
                return ['status' => 'elsewhere', 'other_code' => $other['code'] ?? '?'];
            }
            error_log("Erreur ClientPalette::addColis P{$palette->id} C{$colisId}: " . $e->getMessage());
            return ['status' => 'error', 'other_code' => null];
        }
    }

    public static function removeLine(int $paletteId, int $lineId): bool
    {
        try {
            $stmt = getPDO()->prepare("DELETE FROM client_palette_colis WHERE id = :id AND client_palette_id = :pid");
            $stmt->execute([':id' => $lineId, ':pid' => $paletteId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::removeLine P{$paletteId} L{$lineId}: " . $e->getMessage());
            return false;
        }
    }

    public static function setStatus(int $id, string $status, ?int $userId): bool
    {
        $from = $status === 'closed' ? 'open' : 'closed';
        try {
            $stmt = getPDO()->prepare("UPDATE client_palettes SET status = :status,
                                              closed_at = IF(:status2 = 'closed', NOW(), NULL),
                                              closed_by_user_id = IF(:status3 = 'closed', :uid, NULL)
                                       WHERE id = :id AND status = :from");
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':status2', $status);
            $stmt->bindValue(':status3', $status);
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':from', $from);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::setStatus ID {$id} {$status}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime une palette ouverte et vide.
     */
    public static function deleteIfEmpty(int $id): bool
    {
        try {
            $stmt = getPDO()->prepare("DELETE FROM client_palettes WHERE id = :id AND status = 'open'
                                       AND NOT EXISTS (SELECT 1 FROM client_palette_colis WHERE client_palette_id = :id2)");
            $stmt->execute([':id' => $id, ':id2' => $id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::deleteIfEmpty ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Colis de la palette avec leur emplacement Tunisie (palette + PL d'origine).
     * @param string $order 'scan' (dernier scanné en premier) | 'order' (commande, ligne, colis) pour les exports
     */
    public static function getLines(int $paletteId, string $order = 'scan'): array
    {
        $orderBy = $order === 'order'
            ? "c.order_number ASC, c.line_number ASC, c.package_number ASC"
            : "cpc.scanned_at DESC, cpc.id DESC";
        try {
            $stmt = getPDO()->prepare("SELECT cpc.id AS line_id, cpc.scanned_at, u.username AS scanned_by_name,
                                              c.id AS colis_id, c.package_number, par.package_number AS groupage_number, c.of_number,
                                              c.order_number, c.line_number, c.internal_reference, c.article_reference, c.label, c.sku,
                                              c.quantity, c.unit, c.postal_code,
                                              p.code AS palette_code, pl.reference AS pl_reference
                                       FROM client_palette_colis cpc
                                       JOIN colis c ON c.id = cpc.colis_id
                                       LEFT JOIN colis par ON par.id = c.parent_colis_id
                                       LEFT JOIN palettes p ON p.id = COALESCE(c.palette_id, par.palette_id)
                                       LEFT JOIN packing_lists pl ON pl.id = p.packing_list_id
                                       LEFT JOIN users u ON u.id = cpc.scanned_by_user_id
                                       WHERE cpc.client_palette_id = :pid
                                       ORDER BY {$orderBy}");
            $stmt->execute([':pid' => $paletteId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($lines as &$l) {
                $l['quantity'] = $l['quantity'] !== null ? (float)$l['quantity'] : null;
            }
            unset($l);
            return $lines;
        } catch (PDOException $e) {
            error_log("Erreur ClientPalette::getLines P{$paletteId}: " . $e->getMessage());
            return [];
        }
    }
}
