<?php
// src/Views/receptions/_palettes_table.php
// Partiel rendu à l'affichage et après chaque scan AJAX.
// Variables attendues: $state (Reception::getState), $reception
?>
<table class="table table-sm table-hover align-middle mb-0 reception-palettes-table">
    <thead class="table-light">
        <tr>
            <th style="width: 32px;"></th>
            <th>Palette</th>
            <th class="text-center">Colis</th>
            <th>Statut</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($state['palettes'])): ?>
        <tr><td colspan="4" class="text-muted text-center py-3">Aucune palette attendue sur cette Packing List.</td></tr>
    <?php endif; ?>
    <?php foreach ($state['palettes'] as $p): ?>
        <tr data-palette-id="<?= $p['id'] ?>">
            <td>
                <button type="button" class="btn btn-sm btn-link p-0 js-toggle-colis" data-target="colis-of-<?= $p['id'] ?>" title="Voir les colis">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </td>
            <td><strong><?= htmlspecialchars($p['code']) ?></strong></td>
            <td class="text-center"><?= $p['nb_colis'] ?></td>
            <td>
                <?php if ($p['received']): ?>
                    <span class="badge bg-success">Reçue</span>
                    <?php if ($p['scanned_at']): ?><small class="text-muted"><?= (new DateTime($p['scanned_at']))->format('H:i') ?></small><?php endif; ?>
                <?php else: ?>
                    <span class="badge bg-secondary">À scanner</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr class="reception-colis-row d-none" id="colis-of-<?= $p['id'] ?>">
            <td></td>
            <td colspan="3" class="bg-light">
                <?php if (empty($p['colis'])): ?>
                    <span class="text-muted small">Aucun colis.</span>
                <?php else: ?>
                    <table class="table table-sm mb-0 small">
                        <thead><tr><th>Colis</th><th>OF</th><th>Article</th><th>Qté</th></tr></thead>
                        <tbody>
                        <?php foreach ($p['colis'] as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['package_number']) ?><?= $c['type'] === 'parent' ? ' <span class="badge bg-light text-dark border">groupage</span>' : '' ?></td>
                                <td><?= htmlspecialchars($c['of_number'] ?? '') ?></td>
                                <td><?= htmlspecialchars($c['article_reference'] ?? '') ?></td>
                                <td><?= $c['quantity'] !== null ? htmlspecialchars((string)(float)$c['quantity']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
