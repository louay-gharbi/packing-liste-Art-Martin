<?php
// src/Models/Setting.php

namespace App\Models;

use PDO;
use PDOException;

class Setting
{
    public string $setting_key;
    public ?string $setting_value;
    public ?string $description;
    // Note: la table SQL a un 'id' et 'updated_at', mais nous opérons surtout par 'setting_key'

    /**
     * Récupère la valeur d'un paramètre par sa clé.
     * @param string $key La clé du paramètre.
     * @param mixed $default Valeur par défaut si la clé n'est pas trouvée.
     * @return mixed La valeur du paramètre ou la valeur par défaut.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :setting_key");
            $stmt->bindParam(':setting_key', $key, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['setting_value'] : $default;
        } catch (PDOException $e) {
            error_log("Erreur Setting::get pour la clé {$key}: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Définit la valeur d'un paramètre (crée ou met à jour).
     * @param string $key La clé du paramètre.
     * @param mixed $value La valeur à enregistrer.
     * @param string|null $description Description optionnelle du paramètre.
     * @return bool True si succès, false sinon.
     */
    public static function set(string $key, mixed $value, ?string $description = null): bool
    {
        try {
            $pdo = getPDO();
            // Vérifier si la clé existe pour faire un INSERT ou un UPDATE
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = :setting_key");
            $stmtCheck->bindParam(':setting_key', $key, PDO::PARAM_STR);
            $stmtCheck->execute();
            $exists = $stmtCheck->fetchColumn() > 0;

            if ($exists) {
                $sql = "UPDATE settings SET setting_value = :setting_value";
                if ($description !== null) { // Mettre à jour la description seulement si fournie
                    $sql .= ", description = :description";
                }
                $sql .= " WHERE setting_key = :setting_key";
                $stmt = $pdo->prepare($sql);
            } else {
                $sql = "INSERT INTO settings (setting_key, setting_value, description) VALUES (:setting_key, :setting_value, :description)";
                $stmt = $pdo->prepare($sql);
            }

            $stmt->bindValue(':setting_key', $key, PDO::PARAM_STR);
            $stmt->bindValue(':setting_value', $value); // PDO déterminera le type
            if ($description !== null || !$exists) { // Binder la description si elle est fournie ou si c'est un nouvel enregistrement
                $stmt->bindValue(':description', $description);
            }
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur Setting::set pour la clé {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère tous les paramètres sous forme de tableau associatif clé => valeur.
     * @return array
     */
    public static function getAllAsKeyValue(): array
    {
        $settings = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            error_log("Erreur Setting::getAllAsKeyValue: " . $e->getMessage());
        }
        return $settings;
    }

     /**
     * Récupère tous les paramètres sous forme de tableau d'objets (ou tableaux associatifs avec plus de détails).
     * @return array
     */
    public static function getAllDetails(): array
    {
        $settingsDetails = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT setting_key, setting_value, description FROM settings ORDER BY setting_key ASC");
            $settingsDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur Setting::getAllDetails: " . $e->getMessage());
        }
        return $settingsDetails;
    }
}