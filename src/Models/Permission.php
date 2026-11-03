<?php
// src/Models/Permission.php

namespace App\Models;

use PDO;
use PDOException;

class Permission
{
    public int $id;
    public string $name;
    public ?string $description;
    public string $created_at;
    public string $updated_at;

    /**
     * Récupère une permission par son ID.
     * @param int $id
     * @return Permission|null
     */
    public static function findById(int $id): ?Permission
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM permissions WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur Permission::findById: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Récupère toutes les permissions.
     * @return array Un tableau d'objets Permission.
     */
    public static function getAll(): array
    {
        $permissions = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT * FROM permissions ORDER BY name ASC");
            $permissionsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($permissionsData as $data) {
                $permissions[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erreur Permission::getAll: " . $e->getMessage());
        }
        return $permissions;
    }

    /**
     * Crée une nouvelle permission.
     * @param string $name
     * @param string|null $description
     * @return Permission|false
     */
    public static function create(string $name, ?string $description = null): Permission|false
    {
        try {
            $pdo = getPDO();
            $sql = "INSERT INTO permissions (name, description) VALUES (:name, :description)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $description);
            if ($stmt->execute()) {
                $lastId = $pdo->lastInsertId();
                return self::findById((int)$lastId);
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erreur Permission::create: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Met à jour une permission.
     * @param int $id
     * @param string $name
     * @param string|null $description
     * @return bool
     */
    public static function update(int $id, string $name, ?string $description = null): bool
    {
        try {
            $pdo = getPDO();
            $sql = "UPDATE permissions SET name = :name, description = :description WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur Permission::update pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime une permission.
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM permissions WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            return $stmt->execute(); // Supprime aussi les entrées dans role_permissions grâce à ON DELETE CASCADE
        } catch (PDOException $e) {
            error_log("Erreur Permission::delete pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }


    public static function hydrate(array $data): Permission
    {
        $permission = new self();
        $permission->id = (int)$data['id'];
        $permission->name = $data['name'];
        $permission->description = $data['description'] ?? null;
        $permission->created_at = $data['created_at'];
        $permission->updated_at = $data['updated_at'];
        return $permission;
    }
}