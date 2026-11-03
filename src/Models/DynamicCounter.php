<?php
// src/Models/DynamicCounter.php

namespace App\Models;

use PDO;
use PDOException;

class DynamicCounter
{
    /**
     * Récupère la valeur actuelle d'un compteur et l'incrémente.
     * Si le compteur n'existe pas, il est créé avec la valeur initiale de 1 (après incrémentation).
     * Cette méthode doit être appelée dans une transaction si plusieurs opérations atomiques sont nécessaires.
     *
     * @param string $counterKey La clé unique du compteur (ex: 'pl_annual_2025', 'palette_monthly_202505').
     * @return int La nouvelle valeur du compteur après incrémentation.
     */
    public static function getNextValue(string $counterKey): int
    {
        $pdo = getPDO();
        try {
            // Pour une atomicité, il serait mieux d'utiliser une transaction et FOR UPDATE si la BDD le supporte bien
            // ou une approche d'UPSERT avec incrémentation.
            // Pour MySQL, on peut essayer un INSERT ... ON DUPLICATE KEY UPDATE.

            // Tentative d'INSERT ... ON DUPLICATE KEY UPDATE
            $sql = "INSERT INTO dynamic_counters (counter_key, current_value) VALUES (:counter_key, 1)
                    ON DUPLICATE KEY UPDATE current_value = current_value + 1";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':counter_key', $counterKey, PDO::PARAM_STR);
            $stmt->execute();

            // Récupérer la valeur mise à jour (ou insérée)
            $stmtSelect = $pdo->prepare("SELECT current_value FROM dynamic_counters WHERE counter_key = :counter_key");
            $stmtSelect->bindParam(':counter_key', $counterKey, PDO::PARAM_STR);
            $stmtSelect->execute();
            
            $result = $stmtSelect->fetchColumn();
            if ($result === false) {
                // Ne devrait pas arriver avec la logique ON DUPLICATE KEY UPDATE
                error_log("Erreur critique DynamicCounter::getNextValue - Impossible de récupérer la valeur pour {$counterKey} après l'opération.");
                throw new \RuntimeException("Impossible de récupérer la valeur du compteur {$counterKey}.");
            }
            return (int)$result;

        } catch (PDOException $e) {
            error_log("Erreur DynamicCounter::getNextValue pour la clé {$counterKey}: " . $e->getMessage());
            // Gérer l'erreur, peut-être lever une exception pour que l'appelant sache qu'il y a un problème
            throw new \RuntimeException("Erreur lors de la récupération de la prochaine valeur du compteur {$counterKey}.", 0, $e);
        }
    }

    /**
     * Réinitialise un compteur à une valeur spécifique (ou 0 par défaut).
     * @param string $counterKey La clé du compteur.
     * @param int $value La valeur à laquelle réinitialiser.
     * @return bool
     */
    public static function resetCounter(string $counterKey, int $value = 0): bool
    {
        try {
            $pdo = getPDO();
            $sql = "UPDATE dynamic_counters SET current_value = :current_value WHERE counter_key = :counter_key";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':current_value', $value, PDO::PARAM_INT);
            $stmt->bindParam(':counter_key', $counterKey, PDO::PARAM_STR);
            
            $success = $stmt->execute();
            // Si le compteur n'existait pas, execute() retourne true mais rowCount() sera 0.
            // On peut considérer cela comme un succès si on veut juste s'assurer qu'il est à cette valeur.
            // Si on veut créer s'il n'existe pas :
            if ($stmt->rowCount() === 0) {
                 $insertSql = "INSERT IGNORE INTO dynamic_counters (counter_key, current_value) VALUES (:counter_key, :current_value)";
                 $insertStmt = $pdo->prepare($insertSql);
                 $insertStmt->bindParam(':counter_key', $counterKey, PDO::PARAM_STR);
                 $insertStmt->bindParam(':current_value', $value, PDO::PARAM_INT);
                 $success = $insertStmt->execute();
            }
            return $success;

        } catch (PDOException $e) {
            error_log("Erreur DynamicCounter::resetCounter pour la clé {$counterKey}: " . $e->getMessage());
            return false;
        }
    }
}