<?php
// src/Models/User.php

namespace App\Models;

use PDO; // Nécessaire pour le type hinting de $pdo
use PDOException; // Pour la gestion des erreurs PDO
use App\Models\Site;

/**
 * Modèle User
 * Gère les interactions avec la table 'users'.
 */
class User
{
    // La connexion PDO sera injectée ou obtenue via une méthode globale
    // Pour l'instant, nous utilisons la fonction globale getPDO() définie dans database.php
    // Une meilleure approche à long terme pourrait être l'injection de dépendances.

    public int $id;
    public string $username;
    public string $password_hash;
    public string $email;
    public ?string $first_name;
    public ?string $last_name;
    public bool $is_active;
    public ?int $site_id = null;
    public string $created_at;
    public string $updated_at;
    public ?Site $site = null;
    
    // Rôles de l'utilisateur (pourront être chargés séparément)
    public array $roles = [];

    /**
     * Récupère un utilisateur par son ID.
     *
     * @param int $id L'ID de l'utilisateur.
     * @return User|null L'objet User ou null si non trouvé.
     */
    public static function findById(int $id): ?User
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Utiliser fetchObject pour mapper directement les colonnes aux propriétés de la classe User
            // Spécifier le nom de la classe avec PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE
            // ou utiliser PDO::FETCH_INTO et passer un objet existant (moins pratique pour un static find)
            // Le plus simple est fetch(PDO::FETCH_ASSOC) puis hydrater manuellement ou avec un constructeur.
            // Optons pour fetch(PDO::FETCH_ASSOC) et une méthode d'hydratation pour plus de contrôle.

            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userData) {
                return self::hydrate($userData);
            }
            return null;
        } catch (PDOException $e) {
            error_log("Erreur User::findById: " . $e->getMessage());
            return null; // En cas d'erreur, retourner null ou gérer autrement
        }
    }

    /**
     * Récupère un utilisateur par son nom d'utilisateur (username).
     * Utile pour la connexion.
     *
     * @param string $username Le nom d'utilisateur.
     * @return User|null L'objet User ou null si non trouvé.
     */
    public static function findByUsername(string $username): ?User
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->execute();
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userData) {
                return self::hydrate($userData);
            }
            return null;
        } catch (PDOException $e) {
            error_log("Erreur User::findByUsername: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère un utilisateur par son email.
     *
     * @param string $email L'email de l'utilisateur.
     * @return User|null L'objet User ou null si non trouvé.
     */
    public static function findByEmail(string $email): ?User
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($userData) {
                return self::hydrate($userData);
            }
            return null;
        } catch (PDOException $e) {
            error_log("Erreur User::findByEmail: " . $e->getMessage());
            return null;
        }
    }

    public static function getAll(): array
    {
        $users = [];
        try {
            $pdo = getPDO();
            $stmt = $pdo->query("SELECT * FROM users ORDER BY username ASC");
            
            $usersData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($usersData as $userData) {
                $users[] = self::hydrate($userData);
            }
            return $users;
        } catch (PDOException $e) {
            error_log("Erreur User::getAll: " . $e->getMessage());
            return []; // Retourner un tableau vide en cas d'erreur
        }
    }

    /**
     * Crée un nouvel utilisateur en base de données.
     *
     * @param array $data Données de l'utilisateur (username, password, email, first_name, last_name, is_active).
     *                    'password' doit être le mot de passe en clair, il sera hashé.
     * @return User|false L'objet User créé ou false en cas d'échec.
     */
    public static function create(array $data): User|false
    {
        if (empty($data['username']) || empty($data['password']) || empty($data['email'])) {
            error_log("User::create - Données manquantes pour la création (username, password, email).");
            return false;
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        if ($hashedPassword === false) {
            error_log("User::create - Échec du hachage du mot de passe.");
            return false;
        }

         try {
            $pdo = getPDO();
            
            $sql = "INSERT INTO users (username, password_hash, email, first_name, last_name, is_active, site_id, created_at, updated_at) 
                    VALUES (:username, :password_hash, :email, :first_name, :last_name, :is_active, :site_id, NOW(), NOW())";
            $stmt = $pdo->prepare($sql);

            $stmt->bindValue(':username', $data['username']);
            $stmt->bindValue(':password_hash', $hashedPassword);
            $stmt->bindValue(':email', $data['email']);
            $stmt->bindValue(':first_name', $data['first_name'] ?? null);
            $stmt->bindValue(':last_name', $data['last_name'] ?? null);
            $stmt->bindValue(':is_active', $data['is_active'] ?? true, PDO::PARAM_BOOL);
            $stmt->bindValue(':site_id', $data['site_id'] ?? null, isset($data['site_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL); // Gérer site_id
            
            if ($stmt->execute()) {
                $lastId = $pdo->lastInsertId();
                return self::findById((int)$lastId);
            }
            return false;
        } catch (PDOException $e) {
            error_log("Erreur User::create: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Met à jour un utilisateur existant.
     * Le mot de passe n'est mis à jour que s'il est fourni dans $data.
     *
     * @param int $id L'ID de l'utilisateur à mettre à jour.
     * @param array $data Les données à mettre à jour.
     * @return bool True si la mise à jour a réussi, false sinon.
     */
    public static function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false; // Rien à mettre à jour
        }

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['username'])) {
            $fields[] = "username = :username";
            $params[':username'] = $data['username'];
        }
        if (isset($data['email'])) {
            $fields[] = "email = :email";
            $params[':email'] = $data['email'];
        }
        if (isset($data['first_name'])) {
            $fields[] = "first_name = :first_name";
            $params[':first_name'] = $data['first_name'];
        }
        if (isset($data['last_name'])) {
            $fields[] = "last_name = :last_name";
            $params[':last_name'] = $data['last_name'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = :is_active";
            $params[':is_active'] = (bool)$data['is_active'];
        }
         if (array_key_exists('site_id', $data)) { // Permet de mettre à NULL
            $fields[] = "site_id = :site_id";
            $params[':site_id'] = $data['site_id'] ? (int)$data['site_id'] : null;
        }
        if (!empty($data['password'])) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            if ($hashedPassword === false) {
                error_log("User::update - Échec du hachage du nouveau mot de passe pour l'utilisateur ID {$id}.");
                return false;
            }
            $fields[] = "password_hash = :password_hash";
            $params[':password_hash'] = $hashedPassword;
        }

        if (empty($fields)) {
            return true; // Rien à mettre à jour (ou seulement l'ID, ce qui n'a pas de sens ici)
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
/*
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare($sql);
            
            // Binder les valeurs
            foreach ($params as $key => $value) {
                if ($key === ':is_active') {
                    $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
                } elseif ($key === ':id') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            return $stmt->execute();
        } */
       
       try {
            $pdo = getPDO(); $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $type = PDO::PARAM_STR;
                if ($key === ':id' || ($key === ':site_id' && $value !== null)) $type = PDO::PARAM_INT;
                elseif ($key === ':is_active') $type = PDO::PARAM_BOOL;
                elseif ($key === ':site_id' && $value === null) $type = PDO::PARAM_NULL;
                $stmt->bindValue($key, $value, $type);
            }
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur User::update pour l'utilisateur ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime un utilisateur (suppression logique ou physique).
     * Pour l'instant, suppression physique.
     *
     * @param int $id L'ID de l'utilisateur à supprimer.
     * @return bool True si la suppression a réussi, false sinon.
     */
    public static function delete(int $id): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $deleted = $stmt->execute();
            if ($deleted) self::deleteAvatar($id);
            return $deleted;
        } catch (PDOException $e) {
            error_log("Erreur User::delete pour l'utilisateur ID {$id}: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Valide la politique de mot de passe (création/édition).
     * Retourne null si le mot de passe est conforme, ou le message d'erreur sinon.
     *
     * Politique : >= 12 caracteres, au moins une minuscule, une majuscule, un chiffre.
     */
    public static function validatePasswordPolicy(string $password): ?string
    {
        if (strlen($password) < 12) {
            return "Le mot de passe doit contenir au moins 12 caractères.";
        }
        if (!preg_match('/[a-z]/', $password)) {
            return "Le mot de passe doit contenir au moins une lettre minuscule.";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return "Le mot de passe doit contenir au moins une lettre majuscule.";
        }
        if (!preg_match('/\d/', $password)) {
            return "Le mot de passe doit contenir au moins un chiffre.";
        }
        return null;
    }

    /**
     * Met a jour uniquement le hash du mot de passe (utilise pour le rehash transparent).
     * Ne touche pas a updated_at pour ne pas perturber les audits d'edition.
     */
    public static function updatePasswordHash(int $id, string $hash): bool
    {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
            $stmt->bindValue(':h', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erreur User::updatePasswordHash pour ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    // Photo de profil : un fichier par utilisateur (storage/avatars/<id>.<ext>), hors du dossier public.
    public const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
    public const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function avatarDir(): string
    {
        return ROOT_PATH . '/storage/avatars';
    }

    /** Chemin du fichier photo de l'utilisateur, ou null s'il n'en a pas. */
    public static function avatarFile(int $id): ?string
    {
        foreach (self::AVATAR_TYPES as $ext) {
            $file = self::avatarDir() . "/{$id}.{$ext}";
            if (is_file($file)) return $file;
        }
        return null;
    }

    /** URL de la photo (avec version pour éviter le cache après un changement), ou null. */
    public static function avatarUrl(int $id): ?string
    {
        $file = self::avatarFile($id);
        return $file ? BASE_URL . "/profile/photo/{$id}?v=" . filemtime($file) : null;
    }

    /**
     * Enregistre la photo envoyée (entrée de $_FILES). Retourne null si OK, sinon le message d'erreur.
     * Le type est vérifié sur le contenu du fichier, pas sur son nom.
     */
    public static function saveAvatar(int $id, array $upload): ?string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Aucun fichier sélectionné.';
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $upload['size'] > self::AVATAR_MAX_BYTES) {
            return 'Image trop lourde (2 Mo maximum).';
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) return 'Échec de l\'envoi du fichier.';

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $size = @getimagesize($upload['tmp_name']);
        if (!isset(self::AVATAR_TYPES[$mime]) || !$size || $size[0] < 1 || $size[0] > 5000 || $size[1] > 5000) {
            return 'Format non accepté : JPG, PNG ou WEBP uniquement.';
        }

        $dir = self::avatarDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            error_log("User::saveAvatar - impossible de créer {$dir}");
            return 'Erreur serveur : dossier des photos inaccessible.';
        }
        self::deleteAvatar($id);
        if (!move_uploaded_file($upload['tmp_name'], "{$dir}/{$id}." . self::AVATAR_TYPES[$mime])) {
            error_log("User::saveAvatar - échec move_uploaded_file pour l'utilisateur {$id}");
            return 'Erreur lors de l\'enregistrement de la photo.';
        }
        return null;
    }

    public static function deleteAvatar(int $id): void
    {
        while ($file = self::avatarFile($id)) {
            if (!@unlink($file)) break;
        }
    }

    /**
     * Méthode d'hydratation pour créer un objet User à partir d'un tableau de données.
     *
     * @param array $data Les données de l'utilisateur.
     * @return User L'objet User hydraté.
     */
    private static function hydrate(array $data): User
    {
        $user = new self();
        $user->id = (int)$data['id'];
        $user->username = $data['username'];
        $user->password_hash = $data['password_hash'];
        $user->email = $data['email'];
        $user->first_name = $data['first_name'] ?? null;
        $user->last_name = $data['last_name'] ?? null;
        $user->is_active = (bool)$data['is_active'];
        $user->site_id = isset($data['site_id']) ? (int)$data['site_id'] : null;
        $user->created_at = $data['created_at'];
        $user->updated_at = $data['updated_at'];
        // Les rôles pourraient être chargés ici via une autre méthode
        // $user->loadRoles();
        return $user;
    }

    /**
     * Charge l'objet Site associé à cet utilisateur.
     */
    public function loadSite(): void
    {
        if ($this->site_id !== null && $this->site === null) {
            $this->site = Site::findById($this->site_id);
        }
    }

    /**
     * Charge les rôles de cet utilisateur et les stocke dans la propriété $roles.
     * (Exemple, nécessitera le modèle Role)
     */
    public function loadRoles(): void
    {
        if (empty($this->id)) return;
        $this->roles = []; // Réinitialiser
        try {
        $pdo = getPDO();
        $sql = "SELECT r.* 
                FROM roles r
                INNER JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = :user_id ORDER BY r.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':user_id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        $rolesData = $stmt->fetchAll(PDO::FETCH_ASSOC); // Récupère des tableaux associatifs
        foreach($rolesData as $data){            
            $this->roles[] = Role::hydrate($data); // Hydrater en objet Role
            }
        } catch (PDOException $e) {
        error_log("Erreur User::loadRoles pour l'utilisateur ID {$this->id}: " . $e->getMessage());
        }
    }

    /**
     * Vérifie si l'utilisateur a un rôle spécifique.
     * @param string $roleName Le nom du rôle à vérifier.
     * @return bool
     */
    public function hasRole(string $roleName): bool
    {
        if (empty($this->roles)) {
            $this->loadRoles(); // Charger les rôles si ce n'est pas déjà fait
        }
        foreach ($this->roles as $role) {
           
            if ($role->name === $roleName) {
                return true;
            }
        }
        return false;
    }

     /**
     * Assigne un rôle à cet utilisateur.
     * @param int $roleId
     * @return bool
     */
    public function assignRole(int $roleId): bool
    {
        if (empty($this->id)) return false;
        try {
            $pdo = getPDO();
             // Vérifier si l'assignation existe déjà
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = :user_id AND role_id = :role_id");
            $checkStmt->execute([':user_id' => $this->id, ':role_id' => $roleId]);
            if ($checkStmt->fetchColumn() > 0) {
                return true; // Déjà assigné
            }

            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)");
            $stmt->bindParam(':user_id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
            $success = $stmt->execute();
            if ($success) $this->loadRoles(); // Recharger
            return $success;
        } catch (PDOException $e) {
            error_log("Erreur User::assignRole pour Utilisateur ID {$this->id}, Role ID {$roleId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retire un rôle de cet utilisateur.
     * @param int $roleId
     * @return bool
     */
    public function revokeRole(int $roleId): bool
    {
        if (empty($this->id)) return false;
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id");
            $stmt->bindParam(':user_id', $this->id, PDO::PARAM_INT);
            $stmt->bindParam(':role_id', $roleId, PDO::PARAM_INT);
            $success = $stmt->execute();
            if ($success) $this->loadRoles(); // Recharger
            return $success;
        } catch (PDOException $e) {
            error_log("Erreur User::revokeRole pour Utilisateur ID {$this->id}, Role ID {$roleId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Synchronise les rôles pour cet utilisateur.
     * @param array $roleIds Tableau d'IDs de rôles à associer.
     */
    public function syncRoles(array $roleIds): void
    {
        if (empty($this->id)) return;

        $this->loadRoles(); // S'assurer que les rôles actuels sont chargés
        $currentRoleIds = array_map(fn($r) => $r->id, $this->roles);

        $toRevoke = array_diff($currentRoleIds, $roleIds);
        foreach ($toRevoke as $rid) {
            $this->revokeRole($rid);
        }

        $toAssign = array_diff($roleIds, $currentRoleIds);
        foreach ($toAssign as $rid) {
            $this->assignRole($rid);
        }
        $this->loadRoles(); // Recharger pour refléter les changements
    }


    /**
     * Vérifie si l'utilisateur a une permission spécifique (via ses rôles).
     * @param string $permissionName Le nom de la permission.
     * @return bool
     */
    public function hasPermission(string $permissionName): bool
    {
        if (empty($this->roles)) {
            $this->loadRoles();
        }
        foreach ($this->roles as $role) {
            // S'assurer que les permissions du rôle sont chargées avant de vérifier
            // La méthode Role::hasPermission s'en charge déjà.
            if ($role->hasPermission($permissionName)) {
                return true;
            }
        }
        return false;
    }
}