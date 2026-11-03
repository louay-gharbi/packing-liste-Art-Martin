<?php
// src/Controllers/ProfileController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\User;

/**
 * « Mon profil » : chaque utilisateur connecté modifie ses informations et son mot de passe.
 * Le site et les rôles restent en lecture seule (gérés par l'administrateur dans /users).
 */
class ProfileController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    public function index(Request $request): Response
    {
        return $this->renderProfile($request);
    }

    public function update(Request $request): Response
    {
        $user = Auth::user();
        $email = trim((string)$request->post('email', ''));
        $firstName = mb_substr(trim((string)$request->post('first_name', '')), 0, 100);
        $lastName = mb_substr(trim((string)$request->post('last_name', '')), 0, 100);

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'L\'email est requis.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'L\'email n\'est pas valide.';
        } elseif ($email !== $user->email && User::findByEmail($email)) {
            $errors['email'] = 'Cet email est déjà utilisé.';
        }
        if ($errors) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs.');
            return $this->renderProfile($request, $errors);
        }

        if (User::update($user->id, ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName])) {
            $this->flash(FLASH_SUCCESS, 'Vos informations ont été mises à jour.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la mise à jour de vos informations.');
        }
        return $this->redirect('/profile');
    }

    public function updatePassword(Request $request): Response
    {
        $user = Auth::user();
        $current = (string)$request->post('current_password', '');
        $password = (string)$request->post('password', '');
        $confirm = (string)$request->post('confirm_password', '');

        $errors = [];
        if (!password_verify($current, $user->password_hash)) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        if ($pwErr = User::validatePasswordPolicy($password)) {
            $errors['password'] = $pwErr;
        } elseif ($password === $current) {
            $errors['password'] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        }
        if ($password !== $confirm) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }
        if ($errors) {
            $this->flash(FLASH_ERROR, 'Mot de passe non modifié : veuillez corriger les erreurs.');
            return $this->renderProfile($request, $errors);
        }

        if (User::update($user->id, ['password' => $password])) {
            session_regenerate_id(true);
            $this->flash(FLASH_SUCCESS, 'Votre mot de passe a été modifié.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors du changement de mot de passe.');
        }
        return $this->redirect('/profile');
    }

    public function uploadPhoto(Request $request): Response
    {
        $error = User::saveAvatar(Auth::id(), $_FILES['photo'] ?? []);
        $this->flash($error ? FLASH_ERROR : FLASH_SUCCESS, $error ?? 'Votre photo de profil a été mise à jour.');
        return $this->redirect('/profile');
    }

    public function deletePhoto(Request $request): Response
    {
        User::deleteAvatar(Auth::id());
        $this->flash(FLASH_SUCCESS, 'Votre photo de profil a été supprimée.');
        return $this->redirect('/profile');
    }

    /** Sert la photo d'un utilisateur (visible par les utilisateurs connectés). */
    public function photo(Request $request, int $id): Response
    {
        $file = User::avatarFile($id);
        if (!$file) {
            return new Response('', 404);
        }
        $mime = array_search(pathinfo($file, PATHINFO_EXTENSION), User::AVATAR_TYPES, true);
        return new Response((string)file_get_contents($file), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function renderProfile(Request $request, array $errors = []): Response
    {
        $user = Auth::user();
        $user->loadRoles();
        $user->loadSite();
        return $this->view('profile/index.php', [
            'pageTitle' => 'Mon profil',
            'user' => $user,
            'errors' => $errors,
            'request' => $request,
        ]);
    }
}
