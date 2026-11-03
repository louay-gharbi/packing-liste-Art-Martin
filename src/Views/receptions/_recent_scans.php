<?php
// src/Views/receptions/_recent_scans.php
// Variables attendues: $recentScans (Reception::getRecentScans)
if (empty($recentScans)) return;
?>
<small class="text-muted"><i class="fas fa-clock me-1"></i>Derniers scans</small>
<ul class="list-group list-group-flush mt-1">
    <?php foreach ($recentScans as $s): ?>
        <li class="list-group-item py-1 px-2 small d-flex justify-content-between align-items-center"
            style="border-left: 3px solid <?= (int)$s['is_unexpected'] ? '#ffc107' : '#28a745' ?>;">
            <span>
                <i class="fas <?= $s['kind'] === 'palette' ? 'fa-pallet' : 'fa-box' ?> me-1 text-muted"></i>
                <strong><?= htmlspecialchars($s['label']) ?></strong>
                <?php if ((int)$s['is_unexpected']): ?><span class="badge bg-warning text-dark ms-1">inattendu</span><?php endif; ?>
            </span>
            <small class="text-muted"><?= (new DateTime($s['scanned_at']))->format('H:i:s') ?></small>
        </li>
    <?php endforeach; ?>
</ul>
