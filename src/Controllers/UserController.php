<?php
// src/Controllers/UserController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\User;
use App\Models\Role; 
use App\Models\Site;

class UserController extends BaseController
{
    /**
     * Constructeur pour appliquer la protection.
     * Seuls les administrateurs peuvent gérer les utilisateurs.
     */
    public function __construct()
    {
        parent::__construct();
        Auth::protect(); // S'assurer que l'utilisateur est connecté

        // Vérifier la permission méta pour gérer les utilisateurs
        $this->authorize('user_manage', '/', 'Accès non autorisé à la gestion des utilisateurs.');
    }

    /**
     * Affiche la liste de tous les utilisateurs.
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $currentUser = Auth::user();
        $filters = [];
        $availableSites = Site::getAllActive(); // Pour le dropdown de filtre

        // Si l'utilisateur actuel est lié à un site et n'est PAS admin,
        // il ne voit que les utilisateurs de son site.
        // Un admin peut voir tous les sites ou filtrer.
        if ($currentUser->site_id !== null && !$currentUser->hasRole('Administrateur')) {
            $filters['site_id'] = $currentUser->site_id;
            // On pourrait ne pas afficher le filtre de site pour cet utilisateur
            $availableSites = [Site::findById($currentUser->site_id)]; // Seulement son site dans le dropdown (ou pas de dropdown)
        } elseif ($request->get('filter_site_id') !== null && $request->get('filter_site_id') !== '') {
            $filters['site_id'] = (int)$request->get('filter_site_id');
        }

        $users = User::getAll($filters); // Passer les filtres à getAll
        foreach ($users as $userInstance) {
            $userInstance->loadRoles();
            $userInstance->loadSite(); // Charger l'objet Site pour l'affichage
        }

        $data = [
            'pageTitle' => 'Gestion des Utilisateurs',
            'users' => $users,
            'availableSites' => $availableSites, // Pour le dropdown de filtre
            'currentFilterSiteId' => $filters['site_id'] ?? null
        ];
        return $this->view('users/index.php', $data);
    }

    /**
     * Affiche le formulaire de création d'un nouvel utilisateur.
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $allRoles = Role::getAll();
        $allSites = Site::getAllActive(); // Pour le dropdown d'assignation de site

        $data = [
            'pageTitle' => 'Créer un Utilisateur',
            'request' => $request,
            'allRoles' => $allRoles,
            'allSites' => $allSites, // Passer les sites à la vue
            'user_data' => ['role_ids' => [], 'site_id' => null] // Initialiser site_id
        ];
        return $this->view('users/create.php', $data);
    }

    /**
     * Enregistre un nouvel utilisateur en base de données.
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request): Response
    {
        $username = trim($request->post('username', ''));
        $email = trim($request->post('email', ''));
        $password = $request->post('password', '');
        $confirm_password = $request->post('confirm_password', '');
        $first_name = trim($request->post('first_name', ''));
        $last_name = trim($request->post('last_name', ''));
        $is_active = $request->post('is_active') === 'on';
        $assigned_role_ids = array_map('intval', $request->post('roles', []));
        $site_id = $request->post('site_id', ''); // Récupérer site_id
        $site_id = ($site_id === '' || $site_id === '0') ? null : (int)$site_id; // Convertir en null si vide ou '0' (pas de site)
        
        // --- Validation de base ---

        $errors = [];
              
        if (empty($username)) {
            $errors['username'] = 'Le nom d\'utilisateur est requis.';
        } elseif (User::findByUsername($username)) {
            $errors['username'] = 'Ce nom d\'utilisateur est déjà pris.';
        }

        if (empty($email)) {
            $errors['email'] = 'L\'email est requis.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'L\'email n\'est pas valide.';
        } elseif (User::findByEmail($email)) {
            $errors['email'] = 'Cet email est déjà utilisé.';
        }

        if (empty($password)) {
            $errors['password'] = 'Le mot de passe est requis.';
        } elseif ($pwErr = User::validatePasswordPolicy($password)) {
            $errors['password'] = $pwErr;
        }

        if ($password !== $confirm_password) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }

        if ($site_id !== null && !Site::findById($site_id)) {
            $errors['site_id'] = 'Le site sélectionné est invalide.';
        }
        // --- Fin Validation ---

        if (!empty($errors)) {
            $user_data = [
                'username' => $username, 
                'email' => $email,
                'first_name' => $first_name, 
                'last_name' => $last_name,
                'is_active' => $is_active, 
                'role_ids' => $assigned_role_ids,
                'site_id' => $site_id
            ];
            $allRoles = Role::getAll();
            $allSites = Site::getAllActive();
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->view('users/create.php', [
                'pageTitle' => 'Créer un Utilisateur', 
                'errors' => $errors, 
                'user_data' => $user_data,
                'allRoles' => $allRoles, 
                'allSites' => $allSites, 
                'request' => $request
            ]);
        }

        $userDataToCreate = [
            'username' => $username, 
            'email' => $email, 
            'password' => $password, 
            'first_name' => $first_name ?: null, 
            'last_name' => $last_name ?: null, 
            'is_active' => $is_active, 
            'site_id' => $site_id 
        ];
        $newUser = User::create($userDataToCreate);

        if ($newUser) {
            if (!empty($assigned_role_ids)) {
                $newUser->syncRoles($assigned_role_ids); // Assigner les rôles au nouvel utilisateur
            }
            $this->flash(FLASH_SUCCESS, 'Utilisateur "' . htmlspecialchars($newUser->username) . '" créé avec succès.');
            return $this->redirect('/users');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la création de l\'utilisateur.');
            $user_data_on_error = [
                'username' => $username, 
                'email' => $email,
                'first_name' => $first_name, 
                'last_name' => $last_name,
                'is_active' => $is_active, 
                'role_ids' => $assigned_role_ids,
                'site_id' => $site_id
            ];
            $allRoles = Role::getAll();
            return $this->view('users/create.php', [
                'pageTitle' => 'Créer un Utilisateur',
                'user_data' => $user_data_on_error,
                'allRoles' => $allRoles,
                'allSites' => $allSites, 
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue lors de la création.'
            ]);
        }
    }

    /**
     * Affiche le formulaire d'édition d'un utilisateur existant.
     *
     * @param Request $request
     * @param int $id L'ID de l'utilisateur à modifier.
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $user = User::findById($id);

        if (!$user) {
            $this->flash(FLASH_ERROR, 'Utilisateur non trouvé.');
            return $this->redirect('/users');
        }

        $user->loadRoles(); // Charger les rôles actuels de l'utilisateur
        $assignedRoleIds = array_map(fn($role) => $role->id, $user->roles);
        $allRoles = Role::getAll(); // Charger tous les rôles disponibles
        $allSites = Site::getAllActive();

        $data = [
            'pageTitle' => 'Modifier l\'Utilisateur: ' . htmlspecialchars($user->username),
            'user' => $user,
            'allRoles' => $allRoles,
            'assignedRoleIds' => $assignedRoleIds,
            'allSites' => $allSites,
            'request' => $request
        ];
        return $this->view('users/edit.php', $data);
    }

    /**
     * Met à jour un utilisateur existant en base de données.
     *
     * @param Request $request
     * @param int $id L'ID de l'utilisateur à mettre à jour.
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        $user = User::findById($id);
        if (!$user) {
            $this->flash(FLASH_ERROR, 'Utilisateur non trouvé.');
            return $this->redirect('/users');
        }

        $username = trim($request->post('username', ''));
        $email = trim($request->post('email', ''));
        $password = $request->post('password', '');
        $confirm_password = $request->post('confirm_password', '');
        $first_name = trim($request->post('first_name', ''));
        $last_name = trim($request->post('last_name', ''));
        $is_active = $request->post('is_active') === 'on';
        $assigned_role_ids = array_map('intval', $request->post('roles', []));
        $site_id = $request->post('site_id', '');
        $site_id = ($site_id === '' || $site_id === '0') ? null : (int)$site_id;

        $errors = [];
        if (empty($username)) {
            $errors['username'] = 'Le nom d\'utilisateur est requis.';
        } elseif ($username !== $user->username && User::findByUsername($username)) {
            $errors['username'] = 'Ce nom d\'utilisateur est déjà pris.';
        }

        if (empty($email)) {
            $errors['email'] = 'L\'email est requis.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'L\'email n\'est pas valide.';
        } elseif ($email !== $user->email && User::findByEmail($email)) {
            $errors['email'] = 'Cet email est déjà utilisé.';
        }

        if (!empty($password)) {
            if ($pwErr = User::validatePasswordPolicy($password)) {
                $errors['password'] = $pwErr;
            }
            if ($password !== $confirm_password) {
                $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
            }
            if ($site_id !== null && !Site::findById($site_id)) {
            $errors['site_id'] = 'Le site sélectionné est invalide.';
        }
        }

        if (!empty($errors)) {
            $user->loadRoles(); // Recharger les rôles originaux pour la vue
            $originalAssignedRoleIds = array_map(fn($role) => $role->id, $user->roles);
            $allRoles = Role::getAll();
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs dans le formulaire.');
            return $this->view('users/edit.php', [
                'pageTitle' => 'Modifier l\'Utilisateur: ' . htmlspecialchars($user->username),
                'user' => $user,
                'allRoles' => $allRoles,
                // En cas d'erreur, réafficher les rôles cochés par l'utilisateur lors de la soumission
                'assignedRoleIds' => $assigned_role_ids, 
                'allSites' => $allSites,
                'errors' => $errors,
                'request' => $request
            ]);
        }

        $updateData = [
            'username' => $username,
            'email' => $email,
            'first_name' => $first_name ?: null,
            'last_name' => $last_name ?: null,
            'is_active' => $is_active,
            'site_id' => $site_id
        ];
        if (!empty($password)) {
            $updateData['password'] = $password;
        }

        $userUpdated = User::update($id, $updateData);
        // La méthode User::update ne retourne que true/false. L'objet $user n'est pas mis à jour automatiquement.
        // Nous devons recharger l'utilisateur si nous voulons appeler syncRoles sur l'objet à jour, ou passer l'ID.
        // Ici, nous avons déjà l'objet $user (avant mise à jour des champs), mais son ID est correct.
        $user->syncRoles($assigned_role_ids); // Synchroniser les rôles
        
        if ($userUpdated) { // On se base sur le succès de la mise à jour des champs principaux
            $this->flash(FLASH_SUCCESS, 'Utilisateur "' . htmlspecialchars($username) . '" mis à jour avec succès.');
            return $this->redirect('/users');
        } else {
            // Même si User::update retourne false (par ex. si aucune ligne n'est affectée car données identiques),
            // les rôles ont pu être synchronisés. Si une vraie erreur BDD s'est produite, elle devrait être loggée.
            // Pour simplifier, si User::update est false mais qu'aucune erreur n'est dans $errors,
            // on peut supposer que rien n'a changé ou qu'il y a eu une erreur non capturée par la validation.
            $this->flash(FLASH_ERROR, 'Aucune modification détectée ou erreur lors de la mise à jour de l\'utilisateur.');
            // Recharger toutes les données pour la vue
            $user = User::findById($id); // Recharger l'utilisateur pour avoir l'état actuel
            $user->loadRoles();
            $currentAssignedRoleIds = array_map(fn($role) => $role->id, $user->roles);
            $allSites = Site::getAllActive();
            $allRoles = Role::getAll();

            return $this->view('users/edit.php', [
                'pageTitle' => 'Modifier l\'Utilisateur: ' . htmlspecialchars($user->username),
                'user' => $user,
                'allRoles' => $allRoles,
                'assignedRoleIds' => $currentAssignedRoleIds,
                'allSites' => $allSites, 
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue lors de la mise à jour ou aucune donnée n\'a été modifiée.'
            ]);
        }
    }

    /**
     * Supprime un utilisateur.
     *
     * @param Request $request
     * @param int $id L'ID de l'utilisateur à supprimer.
     * @return Response
     */
    public function delete(Request $request, int $id): Response
    {
        $currentUser = Auth::user();
        // Empêcher l'auto-suppression
        if ($currentUser && $currentUser->id === $id) {
            $this->flash(FLASH_ERROR, 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirect('/users');
        }

        $user = User::findById($id);
        if (!$user) {
            $this->flash(FLASH_ERROR, 'Utilisateur non trouvé.');
            return $this->redirect('/users');
        }

        // Optionnel : Empêcher la suppression d'autres utilisateurs "protégés"
        // if ($user->hasRole('Administrateur') && $id !== $currentUser->id) {
        //     $this->flash(FLASH_ERROR, 'Vous ne pouvez pas supprimer un autre administrateur.');
        //     return $this->redirect('/users');
        // }

        if (User::delete($id)) {
            $this->flash(FLASH_SUCCESS, 'Utilisateur "' . htmlspecialchars($user->username) . '" supprimé avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la suppression de l\'utilisateur.');
        }
        return $this->redirect('/users');
    }
}