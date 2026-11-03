<?php
/**
 * Modal pour le déplacement massif de colis vers une autre palette.
 *
 * Variables attendues :
 * - $baseUrl (string) : URL de base de l'application
 * - $sourcePaletteId (int) : ID de la palette source
 * - $sourcePaletteCode (string) : code de la palette source
 */
?>
<div class="modal fade" id="massiveMoveColisModal" tabindex="-1" aria-labelledby="massiveMoveColisModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/colis/massive-move" id="massiveMoveForm">
        <?= \App\Lib\Csrf::field() ?>
        <input type="hidden" name="source_palette_id" value="<?= (int)($sourcePaletteId ?? 0) ?>">
        <input type="hidden" name="redirect_url" value="/palettes/show/<?= (int)($sourcePaletteId ?? 0) ?>">
        <input type="hidden" name="redirect_after" value="source">
        
        <!-- Tableau caché des IDs sélectionnés -->
        <input type="hidden" name="colis_ids[]" id="massiveMoveColisIdsInput" value="">

        <div class="modal-header bg-info text-white">
          <h5 class="modal-title" id="massiveMoveColisModalLabel">
            <i class="fas fa-truck-moving me-2"></i>Déplacement Massif de Colis
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Vous allez déplacer <strong id="massiveMoveCountDisplay">0</strong> colis 
            de la palette <strong id="massiveMoveSourcePalette"><?= htmlspecialchars($sourcePaletteCode ?? '') ?></strong>
            vers une autre palette.
          </div>
          
          <div class="alert alert-warning d-none" id="massiveMoveWarning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Les groupes parent/enfant seront déplacés ensemble.
          </div>

          <div class="form-group mb-3">
            <label for="massiveMoveDestinationPalette" class="form-label fw-bold">
              <i class="fas fa-pallet me-1"></i> Code Palette de Destination :
            </label>
            <input type="text" class="form-control form-control-lg" 
                   id="massiveMoveDestinationPalette" 
                   name="destination_palette_code" 
                   placeholder="Scanner ou saisir le code de la palette de destination"
                   required 
                   autofocus>
            <small class="form-text text-muted">
              La palette de destination doit être ouverte et avoir de la capacité disponible.
            </small>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="redirectToDestination" name="redirect_after" value="destination">
            <label class="form-check-label" for="redirectToDestination">
              Rediriger vers la palette de destination après le déplacement
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i> Annuler
          </button>
          <button type="submit" class="btn btn-info text-white" id="massiveMoveSubmitBtn" disabled>
            <i class="fas fa-truck-moving me-1"></i> Déplacer <span id="massiveMoveBtnCount">0</span> colis
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const massiveMoveModal = document.getElementById('massiveMoveModal');
    if (!massiveMoveModal) {
        // La modale peut être ouverte via data-bs-target="#massiveMoveColisModal"
    }

    // Fonction pour ouvrir la modale avec les IDs sélectionnés
    window.openMassiveMoveModal = function() {
        const checkboxes = document.querySelectorAll('.colis-massive-move-checkbox:checked');
        const ids = Array.from(checkboxes).map(cb => cb.value);
        
        if (ids.length === 0) {
            alert('Veuillez sélectionner au moins un colis à déplacer.');
            return;
        }

        // Mettre à jour le champ caché avec les IDs séparés par des virgules
        document.getElementById('massiveMoveColisIdsInput').value = ids.join(',');
        
        // Mettre à jour le compteur
        document.getElementById('massiveMoveCountDisplay').textContent = ids.length;
        document.getElementById('massiveMoveBtnCount').textContent = ids.length;
        
        // Activer le bouton submit
        document.getElementById('massiveMoveSubmitBtn').disabled = false;
        
        // Ouvrir la modale via Bootstrap
        const modal = new bootstrap.Modal(document.getElementById('massiveMoveColisModal'));
        modal.show();
        
        // Focus sur le champ destination
        setTimeout(() => {
            document.getElementById('massiveMoveDestinationPalette').focus();
        }, 500);
    };

    // Gestion du formulaire : avant soumission, reconstruire les IDs
    document.getElementById('massiveMoveForm')?.addEventListener('submit', function(e) {
        const idsStr = document.getElementById('massiveMoveColisIdsInput').value;
        if (!idsStr) {
            e.preventDefault();
            alert('Aucun colis sélectionné.');
            return;
        }

        // Supprimer l'input caché unique et ajouter des inputs individuels
        const ids = idsStr.split(',').filter(id => id.trim() !== '');
        const container = this;
        // Supprimer l'ancien input
        const oldInput = document.getElementById('massiveMoveColisIdsInput');
        if (oldInput) oldInput.remove();

        // Ajouter un input hidden pour chaque ID
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'colis_ids[]';
            input.value = id.trim();
            container.appendChild(input);
        });
    });
});
</script>