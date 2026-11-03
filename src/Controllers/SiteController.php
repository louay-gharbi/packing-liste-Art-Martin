<?php
// src/Controllers/SiteController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Site;
use App\Models\PackingList; // Pour vérifier les PL ouvertes avant désactivation

class SiteController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect(); // S'assurer que l'utilisateur est connecté
        // Protéger tout le contrôleur avec la permission 'site_manage'
        // Cette permission unique donne accès à toutes les actions de ce contrôleur.
        $this->authorize('site_manage', '/', "Accès non autorisé à la gestion des sites.");
    }

    /**
     * Affiche la liste de tous les sites (actifs et inactifs).
     */
    public function index(Request $request): Response
    {
        $sites = Site::getAll(); // Récupère tous les sites pour la gestion
        $data = [
            'pageTitle' => 'Gestion des Sites',
            'sites' => $sites,
            'request' => $request // Passé pour une éventuelle utilisation dans la vue (ex: filtres futurs)
        ];
        return $this->view('sites/index.php', $data);
    }

    /**
     * Affiche le formulaire de création d'un nouveau site.
     */
    public function create(Request $request): Response
    {
        $data = [
            'pageTitle' => 'Créer un Nouveau Site',
            'request' => $request,
            'site_data' => ['is_active' => true] // Pré-cocher "Actif" par défaut
        ];
        return $this->view('sites/create.php', $data);
    }

    /**
     * Enregistre un nouveau site en base de données.
     */
    public function store(Request $request): Response
    {
        $code = trim(strtoupper($request->post('code', '')));
        $name = trim($request->post('name', ''));
        $address = trim($request->post('address', ''));
        $is_active = $request->post('is_active') === 'on';

        $errors = [];
        if (empty($code)) {
            $errors['code'] = 'Le code du site est requis.';
        } elseif (strlen($code) > 20) {
            $errors['code'] = 'Le code du site ne doit pas dépasser 20 caractères.';
        } elseif (Site::findByCode($code)) { // Vérifier l'unicité du code
            $errors['code'] = 'Ce code de site est déjà utilisé.';
        }

        if (empty($name)) {
            $errors['name'] = 'Le nom du site est requis.';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'Le nom du site ne doit pas dépasser 100 caractères.';
        }

        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs dans le formulaire.');
            return $this->view('sites/create.php', [
                'pageTitle' => 'Créer un Nouveau Site',
                'errors' => $errors,
                'site_data' => ['code' => $code, 'name' => $name, 'address' => $address, 'is_active' => $is_active],
                'request' => $request
            ]);
        }

        $newSite = Site::create([
            'code' => $code, 
            'name' => $name, 
            'address' => $address ?: null, // S'assurer que c'est null si vide
            'is_active' => $is_active
        ]);

        if ($newSite) {
            $this->flash(FLASH_SUCCESS, 'Site "' . htmlspecialchars($newSite->name) . '" créé avec succès.');
            return $this->redirect('/sites');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la création du site. Le code du site est peut-être déjà utilisé.');
            return $this->view('sites/create.php', [
                'pageTitle' => 'Créer un Nouveau Site',
                'site_data' => ['code' => $code, 'name' => $name, 'address' => $address, 'is_active' => $is_active],
                'request' => $request,
                'db_error' => 'Une erreur interne est survenue ou le code du site est dupliqué.'
            ]);
        }
    }

    /**
     * Affiche le formulaire d'édition d'un site existant.
     */
    public function edit(Request $request, int $id): Response
    {
        $site = Site::findById($id);
        if (!$site) {
            $this->flash(FLASH_ERROR, 'Site non trouvé.');
            return $this->redirect('/sites');
        }

        $data = [
            'pageTitle' => 'Modifier le Site: ' . htmlspecialchars($site->name),
            'site' => $site, // Passer l'objet site à la vue
            'request' => $request // Pour pré-remplir avec $request->post() en cas d'erreur de validation
        ];
        return $this->view('sites/edit.php', $data);
    }

    /**
     * Met à jour un site existant en base de données.
     */
    public function update(Request $request, int $id): Response
    {
        $siteToUpdate = Site::findById($id);
        if (!$siteToUpdate) {
            $this->flash(FLASH_ERROR, 'Site non trouvé pour la mise à jour.');
            return $this->redirect('/sites');
        }

        $code = trim(strtoupper($request->post('code', $siteToUpdate->code)));
        $name = trim($request->post('name', $siteToUpdate->name));
        $address = trim($request->post('address', $siteToUpdate->address ?? ''));
        $is_active = $request->post('is_active') === 'on';

        $errors = [];
        if (empty($code)) $errors['code'] = 'Le code du site est requis.';
        elseif (strlen($code) > 20) $errors['code'] = 'Code trop long (max 20).';
        // Vérifier l'unicité du code seulement s'il a changé
        if (strtolower($code) !== strtolower($siteToUpdate->code) && Site::findByCode($code)) {
            $errors['code'] = 'Ce code de site est déjà utilisé par un autre site.';
        }

        if (empty($name)) $errors['name'] = 'Le nom du site est requis.';
        elseif (strlen($name) > 100) $errors['name'] = 'Nom trop long (max 100).';


        if (!empty($errors)) {
            $this->flash(FLASH_ERROR, 'Veuillez corriger les erreurs dans le formulaire.');
            return $this->view('sites/edit.php', [
                'pageTitle' => 'Modifier le Site: ' . htmlspecialchars($siteToUpdate->name),
                'site' => $siteToUpdate, // Renvoyer l'objet original
                'errors' => $errors,
                'request' => $request // Pour pré-remplir les champs avec les valeurs postées
            ]);
        }

        $updateResult = Site::update($id, [
            'code' => $code, 
            'name' => $name, 
            'address' => $address ?: null, // S'assurer que c'est null si vide
            'is_active' => $is_active
        ]);

        if ($updateResult) {
            $this->flash(FLASH_SUCCESS, 'Site "' . htmlspecialchars($name) . '" mis à jour avec succès.');
            return $this->redirect('/sites');
        } else {
            // Si updateResult est false, cela peut être dû à une erreur PDO ou parce qu'aucune ligne n'a été affectée
            // (par ex., si les données soumises étaient identiques à celles en BDD).
            $this->flash(FLASH_ERROR, 'Erreur lors de la mise à jour du site ou aucune modification détectée.');
            // Recharger le site pour la vue au cas où, mais utiliser les données postées pour le formulaire
            $siteForView = Site::findById($id);
            return $this->view('sites/edit.php', [
                'pageTitle' => 'Modifier le Site: ' . htmlspecialchars($siteForView ? $siteForView->name : 'Erreur'),
                'site' => $siteForView, // Pour le titre et potentiellement d'autres infos non postées
                'request' => $request, // Pour pré-remplir avec les valeurs postées
                'db_error' => 'Une erreur interne est survenue ou le code du site est dupliqué, ou aucune donnée n\'a été modifiée.'
            ]);
        }
    }

    /**
     * Désactive un site.
     */
    public function deactivateSite(Request $request, int $id): Response
    {
        $site = Site::findById($id);
        if (!$site) {
            $this->flash(FLASH_ERROR, 'Site non trouvé.');
            return $this->redirect('/sites');
        }

        // Vérification avant désactivation: Y a-t-il des Packing Lists OUVERTES pour ce site?
        $openPackingListsCount = PackingList::countByStatusAndSite('open',$id); // Méthode à créer/vérifier dans PackingList.php
        if ($openPackingListsCount > 0) {
            $this->flash(FLASH_ERROR, "Impossible de désactiver le site \"".htmlspecialchars($site->name)."\" car il a encore {$openPackingListsCount} Packing List(s) ouverte(s). Veuillez les fermer ou les traiter d'abord.");
            return $this->redirect('/sites');
        }

        if (Site::setActiveStatus($id, false)) { // false pour désactiver
            $this->flash(FLASH_SUCCESS, 'Site "' . htmlspecialchars($site->name) . '" a été désactivé.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la désactivation du site "' . htmlspecialchars($site->name) . '".');
        }
        return $this->redirect('/sites');
    }

    /**
     * Réactive un site.
     */
    public function reactivateSite(Request $request, int $id): Response
    {
        $site = Site::findById($id);
        if (!$site) {
            $this->flash(FLASH_ERROR, 'Site non trouvé.');
            return $this->redirect('/sites');
        }

        if (Site::setActiveStatus($id, true)) { // true pour activer
            $this->flash(FLASH_SUCCESS, 'Site "' . htmlspecialchars($site->name) . '" a été réactivé.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la réactivation du site "' . htmlspecialchars($site->name) . '".');
        }
        return $this->redirect('/sites');
    }

    /**
     * Supprime physiquement un site (action à utiliser avec extrême prudence).
     * Pourrait être protégée par une permission encore plus spécifique si nécessaire.
     */
    public function forceDeleteSite(Request $request, int $id): Response
    {
        $this->authorize('site_manage'); // S'assurer que c'est bien un admin
        // Peut-être une permission encore plus forte : 'site_force_delete'

        $site = Site::findById($id);
        if (!$site) {
            $this->flash(FLASH_ERROR, 'Site non trouvé.');
            return $this->redirect('/sites');
        }

        // Mesure de sécurité supplémentaire : ne pas permettre de supprimer si des PL existent (même fermées)
        // ou si des utilisateurs y sont encore rattachés, à moins que ce ne soit explicitement voulu.
        // $packingListsCount = PackingList::countBySiteId($id); // Méthode à créer
        // $usersCount = User::countBySiteId($id); // Méthode à créer
        // if ($packingListsCount > 0 || $usersCount > 0) {
        //    $this->flash(FLASH_ERROR, "Suppression physique impossible: le site '{$site->name}' est encore référencé.");
        //    return $this->redirect('/sites');
        // }


        if (Site::forceDelete($id)) { // Utilise la méthode de suppression physique du modèle
            $this->flash(FLASH_SUCCESS, 'Site "' . htmlspecialchars($site->name) . '" supprimé PHYSIQUEMENT avec succès.');
        } else {
            $this->flash(FLASH_ERROR, 'Erreur lors de la suppression physique du site "' . htmlspecialchars($site->name) . '".');
        }
        return $this->redirect('/sites');
    }
}