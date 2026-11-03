<?php
/**
 * Reusable modal for moving a parcel (colis).
 *
 * Variables expected:
 * - $modalId (string) : HTML id of the modal
 * - $formAction (string) : form action URL. Can be changed via JS.
 * - $packageNumber (string) : parcel number for title (optional)
 * - $sourcePaletteCode (string) : source palette code displayed in modal (optional)
 * - $redirectUrl (string) : optional redirect url hidden input
 */
?>
<div class="modal fade move-colis-modal" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($modalId) ?>Label" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= htmlspecialchars($formAction ?? '') ?>" class="move-colis-form">
        <?= \App\Lib\Csrf::field() ?>
        <?php if (!empty($redirectUrl)) : ?>
            <input type="hidden" name="source_palette_redirect_url" value="<?= htmlspecialchars($redirectUrl) ?>">
        <?php endif; ?>
        <div class="modal-header">
          <h5 class="modal-title move-modal-title" id="<?= htmlspecialchars($modalId) ?>Label">Déplacer Colis <?= htmlspecialchars($packageNumber ?? '') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (!empty($sourcePaletteCode)) : ?>
          <p class="source-palette-wrapper">Palette source : <strong class="source-palette-code"><?= htmlspecialchars($sourcePaletteCode) ?></strong></p>
          <?php else: ?>
          <p class="source-palette-wrapper" style="display:none;">Palette source : <strong class="source-palette-code"></strong></p>
          <?php endif; ?>
          <div class="form-group mb-3">
            <label class="form-label" for="<?= htmlspecialchars($modalId) ?>_dest">Code Palette Destination :</label>
            <input type="text" class="form-control destination-palette-code-input" id="<?= htmlspecialchars($modalId) ?>_dest" name="destination_palette_code" placeholder="Scanner ou saisir code nouvelle palette" required autofocus>
          </div>
          <small class="form-text text-muted">La palette de destination doit être ouverte et avoir de la capacité.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Déplacer</button>
        </div>
      </form>
    </div>
  </div>
</div>