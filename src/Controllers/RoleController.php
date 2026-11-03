<?php
// src/Controllers/RoleController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Role;
use App\Models\Permission; // Nécessaire pour la vue d'édition des rôles

class RoleController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        // Vérifier la permission méta pour gérer les rôles
        $this->authorize('role_manage', '/', 'Accès non autorisé à la gestion des rôles.');
    }

    /**
     * Affiche la liste de tous les rôles.
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $roles = Role::getAll();
        $data = [
            'pageTitle' => 'Gestion des Rôles',
            'roles' => $roles
        ];
        return $this->view('roles/index.php', $data);
    }

    /**
     * Affiche le formulaire de création d'un nouveau rôle.
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $data = [
            'pageTitle' => 'Créer un Rôle',
            'request' => $request,
            'role_data' => [] // Pour les valeurs par défaut du formulaire
        ];
        return $this->view('roles/create.php', $data);
    }

    /**
     * Enregistre un nouveau rôle en base de données.
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $name = trim($request->post('name', ''));
        $description = trim($request->post('description', ''));

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Le nom du rôle est requis.';
        } elseif (Role::findByName($name)) {
            $errors['name'] = 'Ce nom de rôle existe déjà.';
        }
        // Ajouter d'autres validations si nécessaire

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('roles/create.php', [
                'pageTitle' => 'Créer un Rôle',
                'errors' => $errors,
                'role_data' => ['name' => $name, 'description' => $description],
                'request' => $request
            ]);
        }

        $newRole = Role::create($name, $description);

        if ($newRole) {
            $this->flash(FLASH_SUCCESS, 'Rôle "' . htmlspecialchars($newRole->name) . '" créé avec succès.');
            return $this->redirect('/roles');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la création du rôle.');
            return $this->view('roles/create.php', [
                'pageTitle' => 'Créer un Rôle',
                'role_data' => ['name' => $name, 'description' => $description],
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue.'
            ]);
        }
    }

  /**
     * Affiche le formulaire d'édition d'un rôle et de ses permissions.
     * @param Request $request
     * @param int $id L'ID du rôle à modifier.
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $role = Role::findById($id);
        if (!$role) {
            $this->flash(FLASH_ERROR, 'Rôle non trouvé.');
            return $this->redirect('/roles');
        }

        $role->loadPermissions(); // Charger les permissions actuelles du rôle
        $allPermissions = Permission::getAll(); // Charger toutes les permissions disponibles

        // Créer un tableau des IDs des permissions actuellement assignées au rôle
        $assignedPermissionIds = array_map(fn($p) => $p->id, $role->permissions);

        $data = [
            'pageTitle' => 'Modifier le Rôle: ' . htmlspecialchars($role->name),
            'role' => $role,
            'allPermissions' => $allPermissions,
            'assignedPermissionIds' => $assignedPermissionIds,
            'request' => $request
        ];
        return $this->view('roles/edit.php', $data);
    }

    /**
     * Met à jour un rôle existant et ses permissions.
     * @param Request $request
     * @param int $id L'ID du rôle à mettre à jour.
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        $role = Role::findById($id);
        if (!$role) {
            $this->flash(FLASH_ERROR, 'Rôle non trouvé.');
            return $this->redirect('/roles');
        }

        $name = trim($request->post('name', ''));
        $description = trim($request->post('description', ''));
        $permissionIds = array_map('intval', $request->post('permissions', [])); // Tableau d'IDs de permissions cochées

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Le nom du rôle est requis.';
        } elseif ($name !== $role->name && Role::findByName($name)) {
            $errors['name'] = 'Ce nom de rôle existe déjà.';
        }

        if (!empty($errors)) {
            $role->loadPermissions();
            $allPermissions = Permission::getAll();
            $assignedPermissionIds = array_map(fn($p) => $p->id, $role->permissions);
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('roles/edit.php', [
                'pageTitle' => 'Modifier le Rôle: ' . htmlspecialchars($role->name),
                'role' => $role, // Renvoyer l'objet rôle original
                'allPermissions' => $allPermissions,
                'assignedPermissionIds' => $permissionIds, // Utiliser les IDs soumis en cas d'erreur pour pré-cocher
                'errors' => $errors,
                'request' => $request
            ]);
        }

        // Mettre à jour les informations du rôle
        $roleUpdated = Role::update($id, $name, $description);

        // Synchroniser les permissions
        // La méthode syncPermissions s'occupe de supprimer les anciennes et d'ajouter les nouvelles.
        $role->syncPermissions($permissionIds); // Appel sur l'instance $role pour utiliser son ID

        if ($roleUpdated) { // On pourrait aussi vérifier si la synchro des permissions a eu des erreurs
            $this->flash(FLASH_SUCCESS, 'Rôle "' . htmlspecialchars($name) . '" mis à jour avec succès.');
            return $this->redirect('/roles');
        } else {
            // Si Role::update retourne false mais que la synchro a pu se faire, on peut considérer un succès partiel
            // ou simplement afficher une erreur générale si Role::update a échoué.
            $this->flash(FLASH_ERROR, 'Erreur lors de la mise à jour du rôle.');
            // Recharger les données pour la vue
            $role->loadPermissions();
            $allPermissions = Permission::getAll();
            $assignedPermissionIds = array_map(fn($p) => $p->id, $role->permissions);
            return $this->view('roles/edit.php', [
                'pageTitle' => 'Modifier le Rôle: ' . htmlspecialchars($role->name),
                'role' => $role,
                'allPermissions' => $allPermissions,
                'assignedPermissionIds' => $permissionIds,
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue lors de la mise à jour.'
            ]);
        }
    }

    /**
     * Supprime un rôle.
     * @param Request $request
     * @param int $id L'ID du rôle à supprimer.
     * @return Response
     */
    public function delete(Request $request, int $id): Response
    {
        $role = Role::findById($id);
        if (!$role) {
            $this->flash(FLASH_ERROR, 'Rôle non trouvé.');
            return $this->redirect('/roles');
        }

        // Empêcher la suppression des rôles de base si nécessaire
        $protectedRoles = ['Administrateur', 'Superviseur', 'Opérateur de Scan']; // À définir dans config peut-être
        if (in_array($role->name, $protectedRoles)) {
            $this->flash(FLASH_ERROR, 'Le rôle "' . htmlspecialchars($role->name) . '" est protégé et ne peut pas être supprimé.');
            return $this->redirect('/roles');
        }

        if (Role::delete($id)) {
            $this->flash(FLASH_SUCCESS, 'Rôle "' . htmlspecialchars($role->name) . '" supprimé avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la suppression du rôle.');
        }
        return $this->redirect('/roles');
    }
}