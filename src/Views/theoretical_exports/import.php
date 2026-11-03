<?php
// src/Views/theoretical_exports/import.php
// Variables attendues: $pageTitle, $baseUrl, $availablePackingLists, $request
use App\Models\PackingList as PLModel;
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Importer un Fichier Théorique pour une Packing List</h5>
    </div>
    <div class="card-body">
        <p class="card-text text-muted">
            Sélectionnez la Packing List ouverte pour laquelle vous souhaitez importer les données théoriques, puis choisissez le fichier CSV.
        </p>
        <p class="card-text text-danger small">
            <i class="fas fa-exclamation-triangle"></i> <strong>Attention :</strong> Cette action <strong>écrasera</strong> toutes les données théoriques précédemment importées pour la Packing List sélectionnée.
        </p>
        <hr>

        <form action="<?= htmlspecialchars($baseUrl) ?>/theoretical-exports/process-import" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
            
            <div class="row mb-3">
                <div class="col-md-8">
                    <label for="packing_list_id_import" class="form-label">Packing List Cible : <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($errors['packing_list_id']) ? 'is-invalid' : '' ?>" 
                            id="packing_list_id_import" name="packing_list_id" required>
                        <option value="">-- Sélectionnez une Packing List ouverte --</option>
                        
                        <?php // CORRECTION : Utilisation de la variable $availablePackingLists au lieu de $availableSites ?>
                        <?php if (isset($availablePackingLists) && !empty($availablePackingLists)): ?>
                            <?php foreach ($availablePackingLists as $pl): ?>
                                <?php if (!$pl instanceof PLModel) continue; ?>
                                <option value="<?= $pl->id ?>" <?= ($request->post('packing_list_id') == $pl->id) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pl->reference) ?> 
                                    (Site: <?= htmlspecialchars($pl->site->name ?? 'N/A') ?>, Exp: <?= (new DateTime($pl->ship_date))->format('d/m/Y') ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($availablePackingLists)): ?>
                        <div class="form-text text-warning">Aucune Packing List ouverte n'est disponible pour un import. Veuillez d'abord en créer une.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-8">
                    <label for="theoretical_file" class="form-label">Fichier CSV Théorique : <span class="text-danger">*</span></label>
                    <input type="file" class="form-control <?= isset($errors['file']) ? 'is-invalid' : '' ?>" 
                           id="theoretical_file" name="theoretical_file" accept=".csv" required>
                    <small class="form-text text-muted">Colonnes attendues : OF; Commande; Réf. Article; Qté. Séparateur : point-virgule (;)</small>
                </div>
            </div>

            <div class="form-group mt-4">
                <button type="submit" class="btn btn-primary" <?= empty($availablePackingLists) ? 'disabled' : '' ?>>
                    <i class="fas fa-upload"></i> Importer le Théorique
                </button>
            </div>
        </form>
    </div>
</div>