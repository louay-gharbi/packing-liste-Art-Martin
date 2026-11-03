<?php
// src/Models/OrderBlc.php

namespace App\Models;

use PDO;
use PDOException;
use DateTime;

/**
 * BLC AMF d'une commande (N° CDE AMTD = colis.order_number) : un BLC par commande, un BLC n'appartient qu'à une commande.
 */
class OrderBlc
{
    public const MAX_LENGTH = 100;

    /**
     * Commandes présentes dans les colis, avec leur BLC éventuel, la plus récemment expédiée en premier.
     * @param array $filters date_from, date_to (Y-m-d, date d'expédition de la PL), q (commande, client, code postal ou BLC),
     *                       blc_status ('' | 'missing' | 'filled')
     */
    public static function getOrders(array $filters = []): array
    {
        $where = ["c.order_number IS NOT NULL", "c.order_number <> ''"];
        $having = [];
        $params = [];
        if (!empty($filters['date_from'])) { $where[] = "pl.ship_date >= :dfrom"; $params[':dfrom'] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $where[] = "pl.ship_date <= :dto";   $params[':dto'] = $filters['date_to']; }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(c.order_number LIKE :q1 OR c.client_name LIKE :q2 OR ob.blc_amf LIKE :q3 OR c.postal_code LIKE :q4)";
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        if (($filters['blc_status'] ?? '') === 'missing') $where[] = "ob.blc_amf IS NULL";
        if (($filters['blc_status'] ?? '') === 'filled')  $where[] = "ob.blc_amf IS NOT NULL";

        $sql = "SELECT c.order_number, MAX(c.client_name) AS client_name,
                       GROUP_CONCAT(DISTINCT NULLIF(TRIM(c.postal_code), '') ORDER BY c.postal_code SEPARATOR ', ') AS postal_codes,
                       COUNT(DISTINCT c.id) AS nb_colis,
                       GROUP_CONCAT(DISTINCT pl.reference ORDER BY pl.ship_date DESC SEPARATOR ', ') AS pl_references,
                       MAX(pl.ship_date) AS last_ship_date,
                       ob.blc_amf, ob.updated_at, u.username AS updated_by_name
                FROM colis c
                LEFT JOIN colis par ON par.id = c.parent_colis_id
                JOIN palettes p ON p.id = COALESCE(c.palette_id, par.palette_id)
                JOIN packing_lists pl ON pl.id = p.packing_list_id
                LEFT JOIN order_blc ob ON ob.order_number = c.order_number
                LEFT JOIN users u ON u.id = ob.updated_by_user_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY c.order_number, ob.blc_amf, ob.updated_at, u.username
                ORDER BY last_ship_date DESC, c.order_number DESC";
        try {
            $stmt = getPDO()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur OrderBlc::getOrders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Semaines d'expédition (ISO) de toutes les PL, la plus récente en premier.
     * @return array [['year' => int, 'week' => int, 'nb_pl' => int], ...]
     */
    public static function getShipWeeks(): array
    {
        try {
            $dates = getPDO()->query("SELECT ship_date FROM packing_lists WHERE ship_date IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Erreur OrderBlc::getShipWeeks: " . $e->getMessage());
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

    public static function orderExists(string $orderNumber): bool
    {
        $stmt = getPDO()->prepare("SELECT 1 FROM colis WHERE order_number = :o LIMIT 1");
        $stmt->execute([':o' => $orderNumber]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Enregistre (ou retire si $blc vide) le BLC d'une commande.
     * @return array ['status' => saved|removed|duplicate|error, 'other_order' => commande qui a déjà ce BLC]
     */
    public static function save(string $orderNumber, string $blc, ?int $userId): array
    {
        $pdo = getPDO();
        try {
            if ($blc === '') {
                $pdo->prepare("DELETE FROM order_blc WHERE order_number = :o")->execute([':o' => $orderNumber]);
                return ['status' => 'removed', 'other_order' => null];
            }
            $stmt = $pdo->prepare("SELECT order_number FROM order_blc WHERE blc_amf = :b AND order_number <> :o");
            $stmt->execute([':b' => $blc, ':o' => $orderNumber]);
            if ($other = $stmt->fetchColumn()) {
                return ['status' => 'duplicate', 'other_order' => $other];
            }
            $stmt = $pdo->prepare("INSERT INTO order_blc (order_number, blc_amf, updated_at, updated_by_user_id)
                                   VALUES (:o, :b, NOW(), :uid)
                                   ON DUPLICATE KEY UPDATE blc_amf = VALUES(blc_amf), updated_at = NOW(), updated_by_user_id = VALUES(updated_by_user_id)");
            $stmt->bindValue(':o', $orderNumber);
            $stmt->bindValue(':b', $blc);
            $stmt->bindValue(':uid', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            return ['status' => 'saved', 'other_order' => null];
        } catch (PDOException $e) {
            // Saisie simultanée du même BLC sur deux commandes : la contrainte UNIQUE tranche.
            if ($e->getCode() === '23000') {
                $stmt = $pdo->prepare("SELECT order_number FROM order_blc WHERE blc_amf = :b");
                $stmt->execute([':b' => $blc]);
                return ['status' => 'duplicate', 'other_order' => $stmt->fetchColumn() ?: '?'];
            }
            error_log("Erreur OrderBlc::save {$orderNumber}: " . $e->getMessage());
            return ['status' => 'error', 'other_order' => null];
        }
    }

    /**
     * BLC des commandes données (pour les exports).
     * @return array [order_number => blc_amf]
     */
    public static function getMap(array $orderNumbers): array
    {
        $orderNumbers = array_values(array_unique(array_filter(array_map('strval', $orderNumbers), 'strlen')));
        if (!$orderNumbers) return [];
        try {
            $in = implode(',', array_fill(0, count($orderNumbers), '?'));
            $stmt = getPDO()->prepare("SELECT order_number, blc_amf FROM order_blc WHERE order_number IN ($in)");
            $stmt->execute($orderNumbers);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            error_log("Erreur OrderBlc::getMap: " . $e->getMessage());
            return [];
        }
    }
}
