<?php
// Nouvelle vue AJAX pour la recherche de colis
?>
<div class="card mb-3">
    <div class="card-header">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filters">Filtres</button>
    </div>
    <div class="collapse" id="filters">
        <div class="card-body">
            <div class="row g-2">
                <div class="col"><input type="text" class="form-control form-control-sm" id="f_site" placeholder="Site"></div>
                <div class="col"><input type="text" class="form-control form-control-sm" id="f_package" placeholder="N° colis"></div>
                <div class="col"><input type="text" class="form-control form-control-sm" id="f_sku" placeholder="SKU"></div>
                <div class="col"><input type="text" class="form-control form-control-sm" id="f_of" placeholder="OF"></div>
                <div class="col"><input type="text" class="form-control form-control-sm" id="f_order" placeholder="Commande"></div>
                <div class="col"><input type="date" class="form-control form-control-sm" id="f_from"></div>
                <div class="col"><input type="date" class="form-control form-control-sm" id="f_to"></div>
                <div class="col"><button id="applyFilters" class="btn btn-sm btn-primary">Rechercher</button></div>
            </div>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table id="colisGrid" class="table table-striped table-bordered table-sm" style="width:100%">
        <thead>
            <tr>
                <th>N° Colis</th>
                <th>Parent</th>
                <th>Palette</th>
                <th>Statut</th>
                <th>PL</th>
                <th>Site</th>
                <th>SKU</th>
                <th>Qté</th>
                <th>Date Scan</th>
                <th>Commentaire</th>
                <th class="no-export"></th>
            </tr>
        </thead>
    </table>
</div>
<?php $pageScripts = ['colis_search.js']; ?>
