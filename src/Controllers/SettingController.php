<?php
// src/Controllers/SettingController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Models\Setting;

class SettingController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
        // Vérifier la permission méta pour gérer les paramètres
        $this->authorize('settings_manage', '/', 'Accès non autorisé aux paramètres de l\'application.');
    }

    /**
     * Affiche le formulaire d'édition des paramètres.
     * La plupart des applications ont une seule page de paramètres.
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response // On utilise index comme page d'édition
    {
        // Clés des paramètres que nous voulons gérer dans le formulaire
        // Ces clés doivent correspondre à celles de la table 'settings'
        // ou être celles que vous prévoyez de créer.
        $settingKeys = [
            'packing_list_reference_pattern' => 'PL-{YYYY}{MM}{DD}-{ID_PL}',
            'palette_code_pattern' => 'PAL-{YYYY}{MM}-{COUNTER_MONTHLY_3DIGITS}',
            // Ajoutez d'autres clés de paramètres ici si nécessaire
            // 'app_name' => APP_NAME, // Si vous voulez le rendre configurable
            // 'items_per_page' => 25,
        ];

        $currentSettings = [];
        foreach ($settingKeys as $key => $defaultValue) {
            // Récupérer la valeur actuelle de la BDD, ou utiliser la valeur par défaut si non trouvée.
            // Le deuxième argument de Setting::get() est la valeur par défaut.
            $currentSettings[$key] = Setting::get($key, $defaultValue);
        }

        // Descriptions des paramètres pour affichage dans le formulaire
        $settingDescriptions = [
            'packing_list_reference_pattern' => 'Patron pour la référence des Packing Lists. Placeholders: {YYYY}, {MM}, {DD}, {ID_PL}, {COUNTER_GLOBAL}, {COUNTER_YEARLY_XDIGITS}, {COUNTER_MONTHLY_XDIGITS}.',
            'palette_code_pattern' => 'Patron pour le code des Palettes. Placeholders: {YYYY}, {MM}, {DD}, {COUNTER_GLOBAL_XDIGITS}, {COUNTER_YEARLY_XDIGITS}, {COUNTER_MONTHLY_XDIGITS}.',
            // ... descriptions pour d'autres clés ...
        ];


        $data = [
            'pageTitle' => 'Paramètres de l\'Application',
            'settings' => $currentSettings, // Tableau clé => valeur
            'settingDescriptions' => $settingDescriptions, // Tableau clé => description
            'request' => $request
        ];
        return $this->view('settings/edit.php', $data);
    }

    /**
     * Met à jour les paramètres de l'application.
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        // Récupérer tous les paramètres postés
        // On ne met à jour que les clés attendues pour des raisons de sécurité et de propreté
        $allowedKeys = [
            'packing_list_reference_pattern', 
            'palette_code_pattern',
            // 'app_name',
            // 'items_per_page'
        ];
        
        $allPostData = $request->allPost();
        $settingsToUpdate = array_intersect_key($allPostData, array_flip($allowedKeys));

        $successCount = 0;
        $errorCount = 0;

        foreach ($settingsToUpdate as $key => $value) {
            // La description pourrait être stockée ou passée différemment si elle est aussi modifiable
            // Pour l'instant, on ne met à jour que la valeur.
            if (Setting::set($key, trim($value))) {
                $successCount++;
            } else {
                $errorCount++;
                error_log("Échec de la mise à jour du paramètre : {$key}");
            }
        }

        if ($errorCount > 0) {
            $this->flash(FLASH_ERROR, "Certains paramètres n'ont pas pu être sauvegardés. ({$errorCount} erreurs)");
        } else if ($successCount > 0) {
            $this->flash(FLASH_SUCCESS, 'Paramètres mis à jour avec succès.');
        } else {
            $this->flash(FLASH_INFO, 'Aucun paramètre n\'a été modifié.');
        }

        return $this->redirect('/settings');
    }
}