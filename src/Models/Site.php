<?php
// src/Models/Site.php

namespace App\Models;

use PDO;
use PDOException;

class Site
{
    public int $id;
    public string $code;
    public string $name;
    public ?string $address;
    public bool $is_active;
    public string $created_at;
    public string $updated_at;

    /**
     * Crée un nouveau site en base de données.
     * @param array $data Doit contenir 'code', 'name'. Optionnel: 'address', 'is_active'.
     * @return Site|false L'objet Site créé ou false en cas d'échec.
     */
    public static function create(array $data): Site|false
    {
        if (empty($data['code']) || empty($data['name'])) {
            error_log("Site::create - Le code et le nom du site sont requis.");
            return false;
        }

        $sql = "INSERT INTO sites (code, name, address, is_active, created_at, updated_at) 
                VALUES (:code, :name, :address, :is_active, NOW(), NOW())";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':code', trim(strtoupper($data['code'])));
            $stmt->bindValue(':name', trim($data['name']));
            $stmt->bindValue(':address', isset($data['address']) ? trim($data['address']) : null, 
                            (isset($data['address']) && $data['address'] !== null) ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':is_active', $data['is_active'] ?? true, PDO::PARAM_BOOL);

            if ($stmt->execute()) {
                $lastId = $pdo->lastInsertId();
                if ($lastId) {
                    return self::findById((int)$lastId);
                }
                error_log("Site::create - lastInsertId est revenu 0 ou false après exécution réussie.");
                return false;
            }
            error_log("Site::create - L'exécution de la requête a échoué. Erreur: " . implode(";", $stmt->errorInfo()));
            return false;
        } catch (PDOException $e) {
            error_log("Erreur PDO Site::create: " . $e->getMessage() . " (Code SQLState: " . $e->getCode() . ")");
            // Si c'est une violation d'unicité sur le code, on peut le savoir avec $e->getCode() == 23000
            // et potentiellement retourner un message d'erreur plus spécifique ou une exception personnalisée.
            return false;
        }
    }

    /**
     * Met à jour un site existant.
     * @param int $id ID du site à mettre à jour.
     * @param array $data Données à mettre à jour (peut contenir 'code', 'name', 'address', 'is_active').
     * @return bool True si succès ou si aucune donnée modifiable n'a été fournie, false en cas d'échec de la requête.
     */
    public static function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return true; // Aucune modification effective demandée, considéré comme un succès de non-action
        }

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['code'])) { $fields[] = "code = :code"; $params[':code'] = trim(strtoupper($data['code'])); }
        if (isset($data['name'])) { $fields[] = "name = :name"; $params[':name'] = trim($data['name']); }
        // Gérer la mise à jour de l'adresse, y compris la possibilité de la mettre à NULL
        if (array_key_exists('address', $data)) { 
            $fields[] = "address = :address"; 
            $params[':address'] = ($data['address'] !== null && $data['address'] !== '') ? trim($data['address']) : null;
        }
        if (isset($data['is_active'])) { $fields[] = "is_active = :is_active"; $params[':is_active'] = (bool)$data['is_active']; }

        if (empty($fields)) {
            return true; // Aucun champ modifiable fourni dans $data (seul updated_at serait mis à jour)
        }
        
        $fields[] = "updated_at = NOW()"; // Toujours mettre à jour la date de modification

        $sql = "UPDATE sites SET " . implode(', ', $fields) . " WHERE id = :id";

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            
            // Binder les paramètres dynamiquement
            foreach ($params as $placeholder => $value) {
                $type = PDO::PARAM_STR; // Type par défaut
                if ($placeholder === ':id' || ($placeholder === ':site_id' && $value !== null) ) { // Ajuster si vous avez site_id ici
                    $type = PDO::PARAM_INT;
                } elseif ($placeholder === ':is_active') {
                    $type = PDO::PARAM_BOOL;
                } elseif ($placeholder === ':address' && $value === null) {
                    $type = PDO::PARAM_NULL;
                }
                $stmt->bindValue($placeholder, $value, $type);
            }
            
            return $stmt->execute();
            // rowCount() peut être > 0 si des lignes ont été affectées, ou 0 si les valeurs étaient identiques.
            // execute() retournant true est généralement suffisant pour indiquer le succès de la requête.
        } catch (PDOException $e) {
            error_log("Erreur PDO Site::update pour ID {$id}: " . $e->getMessage() . " (Code SQLState: " . $e->getCode() . ")");
            return false;
        }
    }

    /**
     * Désactive ou réactive un site.
     * @param int $id ID du site.
     * @param bool $isActive Nouveau statut (true pour activer, false pour désactiver).
     * @return bool
     */
    public static function setActiveStatus(int $id, bool $isActive): bool
    {
        $sql = "UPDATE sites SET is_active = :is_active, updated_at = NOW() WHERE id = :id";
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':is_active', $isActive, PDO::PARAM_BOOL);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0; // True si le statut a réellement changé
        } catch (PDOException $e) {
            error_log("Erreur PDO Site::setActiveStatus pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime un site (Hard Delete - à utiliser avec extrême précaution, préférer setActiveStatus).
     * @param int $id ID du site à supprimer.
     * @return bool True si succès, false sinon.
     */
    public static function forceDelete(int $id): bool
    {
        // Rappel: Les contraintes FK avec ON DELETE SET NULL sur packing_lists.site_id
        // et users.site_id rendront ces enregistrements "orphelins" de site.
        error_log("Site::forceDelete - Tentative de suppression physique du site ID {$id}. A UTILISER AVEC PRÉCAUTION.");
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM sites WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur PDO Site::forceDelete pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    // --- Méthodes de recherche et hydratation (comme fournies précédemment) ---
    public static function findById(int $id): ?Site {
        try { $pdo = getPDO(); $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id"); $stmt->bindParam(':id', $id, PDO::PARAM_INT); $stmt->execute(); $data = $stmt->fetch(PDO::FETCH_ASSOC); return $data ? self::hydrate($data) : null; } catch (PDOException $e) { error_log("Erreur Site::findById ID {$id}: " . $e->getMessage()); return null; }
    }
    public static function findByCode(string $code): ?Site {
        try { $pdo = getPDO(); $stmt = $pdo->prepare("SELECT * FROM sites WHERE code = :code"); $stmt->bindParam(':code', $code, PDO::PARAM_STR); $stmt->execute(); $data = $stmt->fetch(PDO::FETCH_ASSOC); return $data ? self::hydrate($data) : null; } catch (PDOException $e) { error_log("Erreur Site::findByCode code {$code}: " . $e->getMessage()); return null; }
    }
    public static function getAllActive(): array {
        $sites = []; try { $pdo = getPDO(); $stmt = $pdo->query("SELECT * FROM sites WHERE is_active = TRUE ORDER BY name ASC"); $sitesData = $stmt->fetchAll(PDO::FETCH_ASSOC); foreach ($sitesData as $data) { $sites[] = self::hydrate($data); } } catch (PDOException $e) { error_log("Erreur Site::getAllActive: " . $e->getMessage()); } return $sites;
    }
    public static function getAll(): array {
        $sites = []; try { $pdo = getPDO(); $stmt = $pdo->query("SELECT * FROM sites ORDER BY name ASC"); $sitesData = $stmt->fetchAll(PDO::FETCH_ASSOC); foreach ($sitesData as $data) { $sites[] = self::hydrate($data); } } catch (PDOException $e) { error_log("Erreur Site::getAll: " . $e->getMessage()); } return $sites;
    }
    private static function hydrate(array $data): Site {
        $site = new self(); $site->id = (int)$data['id']; $site->code = $data['code']; $site->name = $data['name'];
        $site->address = $data['address'] ?? null; $site->is_active = (bool)$data['is_active'];
        $site->created_at = $data['created_at']; $site->updated_at = $data['updated_at']; return $site;
    }
}