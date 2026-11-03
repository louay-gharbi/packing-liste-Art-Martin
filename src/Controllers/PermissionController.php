<?php
// src/Controllers/PermissionController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Permission;

class PermissionController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        // Vérifier la permission méta pour gérer les permissions
        $this->authorize('permission_manage', '/', 'Accès non autorisé à la gestion des permissions.');
    }

    /**
     * Affiche la liste de toutes les permissions.
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $permissions = Permission::getAll();
        $data = [
            'pageTitle' => 'Gestion des Permissions',
            'permissions' => $permissions
        ];
        return $this->view('permissions/index.php', $data);
    }

    /**
     * Affiche le formulaire de création d'une nouvelle permission.
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $data = [
            'pageTitle' => 'Créer une Permission',
            'request' => $request,
            'permission_data' => [] // Pour les valeurs par défaut du formulaire
        ];
        return $this->view('permissions/create.php', $data);
    }

    /**
     * Enregistre une nouvelle permission en base de données.
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $name = trim($request->post('name', ''));
        $description = trim($request->post('description', ''));

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Le nom de la permission (code) est requis.';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) { // Ex: user_create, packinglist_view_all
            $errors['name'] = 'Le nom de la permission ne doit contenir que des lettres minuscules, chiffres et underscores (_).';
        } elseif (Permission::findById(0) /* Placeholder pour une future méthode findByName */ && $this->permissionNameExists($name)) { // À remplacer par Permission::findByName($name)
             $errors['name'] = 'Ce nom de permission existe déjà.';
        }
        // Note: Il faudrait une méthode Permission::findByName($name) pour vérifier l'unicité.
        // Pour l'instant, la contrainte UNIQUE de la BDD lèvera une PDOException si le nom est dupliqué.

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('permissions/create.php', [
                'pageTitle' => 'Créer une Permission',
                'errors' => $errors,
                'permission_data' => ['name' => $name, 'description' => $description],
                'request' => $request
            ]);
        }

        $newPermission = Permission::create($name, $description);

        if ($newPermission) {
            $this->flash(FLASH_SUCCESS, 'Permission "' . htmlspecialchars($newPermission->name) . '" créée avec succès.');
            return $this->redirect('/permissions');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la création de la permission (vérifiez si le nom existe déjà).');
            return $this->view('permissions/create.php', [
                'pageTitle' => 'Créer une Permission',
                'permission_data' => ['name' => $name, 'description' => $description],
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue ou le nom de la permission est dupliqué.'
            ]);
        }
    }

    /**
     * Affiche le formulaire d'édition d'une permission.
     * @param Request $request
     * @param int $id L'ID de la permission à modifier.
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $permission = Permission::findById($id);
        if (!$permission) {
            $this->flash(FLASH_ERROR, 'Permission non trouvée.');
            return $this->redirect('/permissions');
        }

        $data = [
            'pageTitle' => 'Modifier la Permission: ' . htmlspecialchars($permission->name),
            'permission' => $permission,
            'request' => $request
        ];
        return $this->view('permissions/edit.php', $data);
    }

    /**
     * Met à jour une permission existante.
     * @param Request $request
     * @param int $id L'ID de la permission à mettre à jour.
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        $permission = Permission::findById($id);
        if (!$permission) {
            $this->flash(FLASH_ERROR, 'Permission non trouvée.');
            return $this->redirect('/permissions');
        }

        $name = trim($request->post('name', ''));
        $description = trim($request->post('description', ''));

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Le nom de la permission (code) est requis.';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $name)) {
            $errors['name'] = 'Le nom de la permission ne doit contenir que des lettres minuscules, chiffres et underscores (_).';
        } elseif ($name !== $permission->name && $this->permissionNameExists($name) /* À remplacer par Permission::findByName($name) */ ) {
             $errors['name'] = 'Ce nom de permission existe déjà.';
        }
        // Note: Il faudrait une méthode Permission::findByName($name) pour vérifier l'unicité.

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('permissions/edit.php', [
                'pageTitle' => 'Modifier la Permission: ' . htmlspecialchars($permission->name), // Utiliser le nom original pour le titre en cas d'erreur
                'permission' => $permission, // Renvoyer l'objet permission original
                'errors' => $errors,
                'request' => $request // Pour pré-remplir avec les données POST si besoin
            ]);
        }

        if (Permission::update($id, $name, $description)) {
            $this->flash(FLASH_SUCCESS, 'Permission "' . htmlspecialchars($name) . '" mise à jour avec succès.');
            return $this->redirect('/permissions');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la mise à jour de la permission (vérifiez si le nom existe déjà).');
            // Conserver les données soumises pour la vue
            $permission->name = $name; // Mettre à jour l'objet pour la vue, même si la sauvegarde a échoué pour une raison
            $permission->description = $description;
            return $this->view('permissions/edit.php', [
                'pageTitle' => 'Modifier la Permission: ' . htmlspecialchars($permission->name),
                'permission' => $permission,
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue ou le nom de la permission est dupliqué.'
            ]);
        }
    }

    /**
     * Supprime une permission.
     * @param Request $request
     * @param int $id L'ID de la permission à supprimer.
     * @return Response
     */
    public function delete(Request $request, int $id): Response
    {
        $permission = Permission::findById($id);
        if (!$permission) {
            $this->flash(FLASH_ERROR, 'Permission non trouvée.');
            return $this->redirect('/permissions');
        }

        // Optionnel: Vérifier si la permission est utilisée par des rôles avant de supprimer
        // Cela nécessiterait une méthode dans le modèle Permission ou Role.
        // if ($permission->isUsed()) {
        //    $this->flash(FLASH_ERROR, 'Cette permission est actuellement assignée à des rôles et ne peut pas être supprimée.');
        //    return $this->redirect('/permissions');
        // }


        if (Permission::delete($id)) {
            $this->flash(FLASH_SUCCESS, 'Permission "' . htmlspecialchars($permission->name) . '" supprimée avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la suppression de la permission.');
        }
        return $this->redirect('/permissions');
    }

    /**
     * Helper pour vérifier si un nom de permission existe (en attendant Permission::findByName).
     * @param string $name
     * @return bool
     */
    private function permissionNameExists(string $name): bool
    {
        // Ceci est une implémentation temporaire. Idéalement, Permission::findByName($name) ferait cela.
        $permissions = Permission::getAll();
        foreach ($permissions as $p) {
            if ($p->name === $name) {
                return true;
            }
        }
        return false;
    }
}