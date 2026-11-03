<?php
// src/Views/packing_lists/edit.php
// Variables attendues du contrôleur :
// $pageTitle : string
// $baseUrl : string
// $appName : string
// $packingList : App\Models\PackingList (l'objet Packing List à éditer, avec $packingList->site chargé)
// $request : App\Lib\Request (pour $request->post() afin de conserver les valeurs en cas d'erreur de validation de la date)
// $errors : array (optionnel, pour les messages d'erreur, ex: $errors['ship_date'])
// $db_error : string (optionnel, pour une erreur globale de BDD si l'update échoue pour une raison non liée à la validation)

use App\Lib\Auth; // Pour les permissions d'accès aux boutons de navigation
use App\Models\PackingList as PLModel; // Pour le type hinting et les constantes de statut

// Sécurité et validation de base de la variable $packingList
if (!isset($packingList) || !$packingList instanceof PLModel) {
    echo "<div class='alert alert-danger'>Erreur: Les données de la Packing List à modifier n'ont pas pu être chargées.</div>";
    if (isset($baseUrl) && Auth::can('packinglist_read_all')) { // Vérifier la permission avant d'afficher le lien
        echo "<a href='" . htmlspecialchars($baseUrl) . "/packing-lists' class='btn btn-secondary mt-2'>Retour à la liste des Packing Lists</a>";
    }
    return; // Arrêter le rendu de cette vue si $packingList n'est pas valide
}

// Vérifier si la Packing List est bien ouverte pour modification
if ($packingList->status !== 'open') {
    echo "<div class='alert alert-warning'>Modification impossible : cette Packing List (<strong>" . htmlspecialchars($packingList->reference) . "</strong>) n'est pas ouverte. Seule la date d'expédition des Packing Lists ouvertes peut être modifiée.</div>";
    if (Auth::can('packinglist_read_one')) {
        echo "<a href='" . htmlspecialchars($baseUrl) . "/packing-lists/show/" . $packingList->id . "' class='btn btn-info mt-2'><i class='fas fa-eye'></i> Voir les détails</a> ";
    }
    if (Auth::can('packinglist_read_all')) {
        echo "<a href='" . htmlspecialchars($baseUrl) . "/packing-lists' class='btn btn-secondary mt-2'><i class='fas fa-list'></i> Retour à la liste</a>";
    }
    return; // Arrêter le rendu
}
?>

<?php if (isset($db_error)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($db_error) ?></div>
<?php endif; ?>
<?php 
    // Les messages flash globaux (succès/erreur après soumission) sont gérés par le layout admin_app.php.
    // $errors['ship_date'] sera affiché sous le champ concerné.
?>

<form action="<?= htmlspecialchars($baseUrl) ?>/packing-lists/update/<?= $packingList->id ?>" method="post" class="needs-validation" novalidate>
            <?= \App\Lib\Csrf::field() ?>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Modifier la Packing List : <?= htmlspecialchars($packingList->reference) ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="reference_edit_pl" class="form-label">Référence :</label>
                    <input type="text" class="form-control" id="reference_edit_pl" value="<?= htmlspecialchars($packingList->reference) ?>" readonly
                           title="La référence est générée automatiquement et n'est pas modifiable ici.">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="site_display_pl_edit" class="form-label">Site de Rattachement :</label>
                    <?php 
                        $siteDisplayInfo = '<span class="text-muted fst-italic">Non défini</span>';
                        if ($packingList->site) { // $packingList->site doit être chargé par le contrôleur
                            $siteDisplayInfo = htmlspecialchars($packingList->site->name) . " (" . htmlspecialchars($packingList->site->code) . ")";
                        } elseif ($packingList->site_id) {
                            // Fallback si l'objet Site n'est pas chargé mais que l'ID est là (devrait être rare)
                            $siteObjectFallback = \App\Models\Site::findById($packingList->site_id);
                            if ($siteObjectFallback) {
                                $siteDisplayInfo = htmlspecialchars($siteObjectFallback->name) . " (" . htmlspecialchars($siteObjectFallback->code) . ")";
                            } else {
                                $siteDisplayInfo = "<span class='text-danger'>Site ID: " . htmlspecialchars($packingList->site_id) . " (Invalide/Non trouvé)</span>";
                            }
                        }
                    ?>
                    <input type="text" class="form-control" id="site_display_pl_edit" value="<?= strip_tags($siteDisplayInfo) // strip_tags pour l'attribut value ?>" readonly 
                           title="Le site d'une Packing List n'est pas modifiable après sa création.">
                     <div class="form-text"><?= $packingList->site ? $siteDisplayInfo : 'Aucun site assigné (Global)' ?></div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Statut Actuel :</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($packingList->status)) ?>" readonly>
                </div>
            </div>

            <hr>
            <p class="text-info"><i class="fas fa-info-circle"></i> Seule la date d'expédition peut être modifiée pour une Packing List ouverte.</p>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="ship_date_pl_edit" class="form-label">Date d'expédition : <span class="text-danger">*</span></label>
                    <input type="date" class="form-control <?= isset($errors['ship_date']) ? 'is-invalid' : '' ?>" 
                           id="ship_date_pl_edit" name="ship_date" required 
                           value="<?= htmlspecialchars($request->post('ship_date', $packingList->ship_date)) ?>">
                    <?php if (isset($errors['ship_date'])): ?><div class="invalid-feedback"><?= htmlspecialchars($errors['ship_date']) ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mettre à jour la date</button>
            <?php if (Auth::can('packinglist_read_one')): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingList->id ?>" class="btn btn-secondary">Annuler</a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-secondary">Annuler</a>
            <?php endif; ?>
        </div>
    </div>
</form>