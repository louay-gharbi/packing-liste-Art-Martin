<?php
// src/Models/Role.php

namespace App\Models;

use PDO;
use PDOException;

class Role
{
    public int $id;
    public string $name;
    public ?string $description;
    public string $created_at;
    public string $updated_at;

    public array $permissions = []; // Permissions associées à ce rôle

    /**
     * Récupère un rôle par son ID.
     * @param int $id
     * @return Role|null
     */
    public static function findById(int $id): ?Role
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($data) {
                $role = self::hydrate($data);
                // $role->loadPermissions(); // Charger les permissions associées si besoin immédiatement
                return $role;
            }
            return null;
        } catch (PDOException $e) {
            error_log("Erreur Role::findById: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Récupère un rôle par son nom.
     * @param string $name
     * @return Role|null
     */
    public static function findByName(string $name): ?Role
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE name = :name");
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            return $data ? self::hydrate($data) : null;
        } catch (PDOException $e) {
            error_log("Erreur Role::findByName: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère tous les rôles.
     * @return array Un tableau d'objets Role.
     */
    public static function getAll(): array
    {
        $roles = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT * FROM roles ORDER BY name ASC");
            $rolesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rolesData as $data) {
                $roles[] = self::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erreur Role::getAll: " . $e->getMessage());
        }
        return $roles;
    }

    /**
     * Crée un nouveau rôle.
     * @param string $name
     * @param string|null $description
     * @return Role|false
     */
    public static function create(string $name, ?string $description = null): Role|false
    {
        try {
            $pdo = getPDO();
            $sql = "INSERT INTO roles (name, description) VALUES (:name, :description)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $description);
            if ($stmt->execute()) {
                $lastId = $pdo->lastInsertId();
                return self::findById((int)$lastId);
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erreur Role::create: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Met à jour un rôle.
     * @param int $id
     * @param string $name
     * @param string|null $description
     * @return bool
     */
    public static function update(int $id, string $name, ?string $description = null): bool
    {
        try {
            $pdo = getPDO();
            $sql = "UPDATE roles SET name = :name, description = :description WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur Role::update pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime un rôle.
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            // Les suppressions en cascade dans user_roles et role_permissions devraient gérer les liaisons
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur Role::delete pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

   public static function hydrate(array $data): Role 
{
    $role = new self(); 
    $role->id = (int)$data['id'];
    $role->name = $data['name'];
    $role->description = $data['description'] ?? null;
    $role->created_at = $data['created_at'] ?? date('Y-m-d H:i:s');
    $role->updated_at = $data['updated_at'] ?? date('Y-m-d H:i:s');
    return $role;
}

    /**
     * Charge les permissions associées à ce rôle.
     */
    public function loadPermissions(): void
    {
        if (empty($this->id)) return;
        $this->permissions = [];
        try {
            $pdo = getPDO();
            $sql = "SELECT p.* 
                    FROM permissions p
                    INNER JOIN role_permissions rp ON p.id = rp.permission_id
                    WHERE rp.role_id = :role_id ORDER BY p.name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':role_id', $this->id, PDO::PARAM_INT);
            $stmt->execute();
            $permissionsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($permissionsData as $data){
                $this->permissions[] = Permission::hydrate($data);
            }
        } catch (PDOException $e) {
            error_log("Erreur Role::loadPermissions pour Rôle ID {$this->id}: " . $e->getMessage());
        }
    }

    /**
     * Vérifie si ce rôle a une permission spécifique.
     * @param string $permissionName Le nom de la permission.
     * @return bool
     */
    public function hasPermission(string $permissionName): bool
    {
        if (empty($this->permissions)) { // Charger les permissions si ce n'est pas déjà fait
            $this->loadPermissions();
        }
        foreach ($this->permissions as $permission) {
            if ($permission->name === $permissionName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Assigne une permission à ce rôle.
     * @param int $permissionId
     * @return bool
     */
    public function assignPermission(int $permissionId): bool
    {
        if (empty($this->id)) return false;
        try {
            $pdo = getPDO();
            // Vérifier si l'assignation existe déjà pour éviter les doublons (la clé primaire s'en charge aussi)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id");
            $checkStmt->execute([':role_id' => $this->id, ':permission_id' => $permissionId]);
            if ($checkStmt->fetchColumn() > 0) {
                return true; // Déjà assigné
            }

            $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");
            $stmt->bindParam(':role_id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':permission_id', $permissionId, PDO::PARAM_INT);
            $success = $stmt->execute();
            if ($success) $this->loadPermissions(); // Recharger les permissions
            return $success;
        } catch (PDOException $e) {
            error_log("Erreur Role::assignPermission pour Rôle ID {$this->id}, Permission ID {$permissionId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retire une permission de ce rôle.
     * @param int $permissionId
     * @return bool
     */
    public function revokePermission(int $permissionId): bool
    {
        if (empty($this->id)) return false;
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id");
            $stmt->bindParam(':role_id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':permission_id', $permissionId, PDO::PARAM_INT);
            $success = $stmt->execute();
            if ($success) $this->loadPermissions(); // Recharger
            return $success;
        } catch (PDOException $e) {
            error_log("Erreur Role::revokePermission pour Rôle ID {$this->id}, Permission ID {$permissionId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Synchronise les permissions pour ce rôle.
     * Retire les permissions non présentes dans $permissionIds et ajoute les nouvelles.
     * @param array $permissionIds Tableau d'IDs de permissions à associer.
     */
    public function syncPermissions(array $permissionIds): void
    {
        if (empty($this->id)) return;
        
        $this->loadPermissions(); // S'assurer que les permissions actuelles sont chargées
        $currentPermissionIds = array_map(fn($p) => $p->id, $this->permissions);

        // Permissions à retirer
        $toRevoke = array_diff($currentPermissionIds, $permissionIds);
        foreach ($toRevoke as $pid) {
            $this->revokePermission($pid);
        }

        // Permissions à ajouter
        $toAssign = array_diff($permissionIds, $currentPermissionIds);
        foreach ($toAssign as $pid) {
            $this->assignPermission($pid);
        }
        $this->loadPermissions(); // Recharger pour refléter les changements
    }
}