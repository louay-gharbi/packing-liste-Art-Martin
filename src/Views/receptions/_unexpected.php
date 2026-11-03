<?php
// src/Views/receptions/_unexpected.php
// Variables attendues: $state
if (empty($state['unexpected_palettes']) && empty($state['unexpected_colis'])) return;
?>
<div class="card border-warning mt-3">
    <div class="card-header bg-warning text-dark">
        <h6 class="mb-0"><i class="fas fa-question-circle me-2"></i>Inattendus (<?= count($state['unexpected_palettes']) + count($state['unexpected_colis']) ?>)</h6>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($state['unexpected_palettes'] as $up): ?>
            <li class="list-group-item small">
                <i class="fas fa-pallet me-1"></i><strong><?= htmlspecialchars($up['code']) ?></strong>
                <span class="text-muted ms-2">PL d'origine : <?= htmlspecialchars($up['origin_pl_reference']) ?></span>
            </li>
        <?php endforeach; ?>
        <?php foreach ($state['unexpected_colis'] as $uc): ?>
            <li class="list-group-item small">
                <i class="fas fa-box me-1"></i><strong><?= htmlspecialchars($uc['package_number']) ?></strong>
                <span class="text-muted ms-2"><?= $uc['origin_pl_reference'] ? "PL d'origine : " . htmlspecialchars($uc['origin_pl_reference']) : 'Inconnu en base' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
