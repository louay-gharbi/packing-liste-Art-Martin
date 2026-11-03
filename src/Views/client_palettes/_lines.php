<?php
// src/Views/client_palettes/_lines.php — colis posés sur la palette client (dernier scanné en premier)
// Variables attendues: $palette, $lines, $canScan, $baseUrl
?>
<?php if (!$lines): ?>
    <div class="card-body text-muted small">Aucun colis sur cette palette.</div>
<?php else: ?>
    <table class="table table-sm table-striped mb-0 small">
        <thead>
            <tr><th>Colis</th><th>Commande / ligne</th><th>Code article</th><th>Libellé</th><th>Qté</th>
                <th>Palette Tunisie</th><th>Packing List</th><?php if ($canScan): ?><th></th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $i => $l): ?>
            <tr class="<?= $i === 0 && $canScan ? 'table-success' : '' ?>">
                <td><strong><?= htmlspecialchars($l['package_number']) ?></strong><?= $l['groupage_number'] ? '<br><span class="text-muted">groupage ' . htmlspecialchars($l['groupage_number']) . '</span>' : '' ?></td>
                <td><?= htmlspecialchars((string)($l['order_number'] ?? '')) ?> / <?= htmlspecialchars((string)($l['line_number'] ?? '')) ?></td>
                <td><?= htmlspecialchars($l['article_reference'] ?? '') ?></td>
                <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
                <td><?= $l['quantity'] !== null ? htmlspecialchars((string)$l['quantity']) : '' ?></td>
                <td><?= htmlspecialchars($l['palette_code'] ?? '') ?></td>
                <td><?= htmlspecialchars($l['pl_reference'] ?? '') ?></td>
                <?php if ($canScan): ?>
                    <td>
                        <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/remove/<?= (int)$l['line_id'] ?>"
                              onsubmit="return confirm('Retirer le colis <?= htmlspecialchars($l['package_number'], ENT_QUOTES) ?> de la palette ?');">
                            <?= \App\Lib\Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Retirer de la palette"><i class="fas fa-times"></i></button>
                        </form>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
