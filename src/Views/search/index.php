<?php
// src/Views/search/index.php
// Variables: pageTitle, request
$pageScripts = ['search.js'];
?>

<style>
    /* --- Styles généraux pour la page de recherche --- */
    #main-search-bar, #of-search-bar, #sku-search-bar {
        font-size: 1.5rem; /* Police plus grande pour les champs de saisie */
        height: calc(3.5rem + 2px);
    }
    .search-group input:disabled { background-color: #e9ecef; cursor: not-allowed; }
    .spinner-border { width: 1.5rem; height: 1.5rem; }

    /* --- Styles pour les cartes de résultats de la recherche globale --- */
    .result-card {
        display: flex; align-items: center; margin-bottom: 1rem; padding: 1rem;
        border: 1px solid #dee2e6; border-radius: .375rem; background-color: #fff;
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.075);
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    }
    .result-card:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); }
    .result-card .icon { font-size: 2.5rem; color: #6c757d; margin-right: 1.5rem; width: 50px; text-align: center; }
    .result-card .content h5 { margin-bottom: 0.25rem; font-size: 1.25rem; }
    .result-card .content h5 a { text-decoration: none; color: #495057; }
    .result-card .details { font-size: 0.9rem; color: #495057; }
    .result-card .context { font-size: 0.85rem; color: #6c757d; }
    .result-card .context a { text-decoration: none; border-bottom: 1px dotted #6c757d; }
    .result-card .context a:hover { color: #0d6efd; border-bottom-color: #0d6efd; }
    .result-card .actions { margin-left: auto; white-space: nowrap; }

    /* --- Styles pour la nouvelle carte récapitulative --- */
    .recap-card { border: 1px solid #0d6efd; background-color: #f7faff; }
    .recap-card .recap-title { font-size: 2rem; font-weight: 300; color: #495057; }
    .recap-card .recap-title strong { font-weight: 600; color: #495057; }
    .recap-card .stat-item { text-align: center; padding: 1rem 0; }
    .recap-card .stat-value { font-size: 2.8rem; font-weight: 700; color: #495057; line-height: 1; }
    .recap-card .stat-label { font-size: 1rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
    .recap-card .dataTables_wrapper { font-size: 0.9rem; } /* Police plus grande pour la DataTable */
</style>

<!-- Champs de Recherche (inchangé) -->
<div class="row g-3 mb-4">
    <div class="col-md-4 search-group">
        <label for="of-search-bar" class="form-label">Recherche par N° OF</label>
        <input type="search" id="of-search-bar" class="form-control" placeholder="Entrez un N° OF exact...">
    </div>
    <div class="col-md-4 search-group">
        <label for="sku-search-bar" class="form-label">Recherche par SKU</label>
        <input type="search" id="sku-search-bar" class="form-control" placeholder="Entrez un SKU exact...">
    </div>
    <div class="col-md-4 search-group">
        <label for="main-search-bar" class="form-label">Recherche Globale (par mot-clé)</label>
        <div class="input-group">
             <input type="search" id="main-search-bar" class="form-control" placeholder="Scanner ou rechercher...">
             <span class="input-group-text" id="search-spinner" style="display: none;">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </span>
        </div>
    </div>
</div>

<div id="search-results-container">
    <div class="text-center text-muted p-4">
        <i class="fas fa-search fa-3x mb-3"></i>
        <p>Utilisez l'un des champs ci-dessus pour lancer une recherche.</p>
    </div>
</div>

<!-- Template pour les cartes de résultats classiques (inchangé) -->
<template id="result-card-template">
    <div class="result-card">
        <div class="icon"><i class="fas"></i></div>
        <div class="content">
            <h5 class="title"></h5>
            <div class="details"></div>
            <div class="context small mt-1"></div>
        </div>
        <div class="actions"></div>
    </div>
</template>

<!-- Template pour la carte récapitulative OF / SKU (amélioré) -->
<template id="recap-card-template">
    <div class="card recap-card">
        <div class="card-body p-4">
            <h3 class="card-title recap-title mb-4" style="font-size: 1.8rem;"></h3>
            
            <!-- Statistiques globales -->
            <div class="row mb-4 border-bottom pb-3">
                <div class="col-md-4 stat-item">
                    <div class="stat-value total-quantity"></div>
                    <div class="stat-label">Pièces Scannées</div>
                </div>
                <div class="col-md-4 stat-item">
                    <div class="stat-value total-colis"></div>
                    <div class="stat-label">Colis Scannés</div>
                </div>
                 <div class="col-md-4 stat-item">
                    <div class="stat-value total-palettes"></div>
                    <div class="stat-label">Sur Palettes</div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <h5 class="mb-3">Packing Lists Concernées</h5>
                    <div class="list-container packing-lists-container" style="max-height: 200px; overflow-y: auto;">
                        <ul class="list-group">
                            <!-- Le JavaScript insérera les PL ici -->
                        </ul>
                    </div>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-3">Palettes Concernées</h5>
                    <div class="list-container palettes-container" style="max-height: 200px; overflow-y: auto;">
                        <ul class="list-group">
                             <!-- Le JavaScript insérera les palettes ici -->
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Conteneur pour la DataTable des colis -->
            <h5 class="mt-4 mb-3">Détail de Tous les Colis</h5>
            <div class="table-responsive">
                <div class="colis-details-table-container">
                    <!-- Le JavaScript insérera la table ici -->
                </div>
            </div>
        </div>
    </div>
</template>