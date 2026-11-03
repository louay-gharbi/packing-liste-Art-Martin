<?php
// src/Views/theoretical_exports/deviations_follow_up.php
// Variables : $pageTitle, $baseUrl, $comparisonData, $packingList
use App\Models\PackingList as PLModel;
?>

<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-balance-scale"></i> Suivi des Écarts pour la Packing List
            </h5>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingList->id ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Retour à la PL
            </a>
        </div>
    </div>
    <div class="card-body">
         <p class="card-text">
            <strong>Packing List :</strong> <?= htmlspecialchars($packingList->reference) ?><br>
            <strong>Site :</strong> <?= htmlspecialchars($packingList->site->name ?? 'N/A') ?><br>
            <strong>Date d'expédition :</strong> <?= (new DateTime($packingList->ship_date))->format('d/m/Y') ?>
        </p>
    </div>
</div>

<?php if (empty($comparisonData)): ?>
    <div class="alert alert-info">
        Aucun écart à afficher. Soit aucun théorique n'a été importé pour cette Packing List, soit aucun colis n'a encore été scanné.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm datatable" id="deviationsFollowUpTable">
            <thead class="table-light">
                <tr>
                    <th>OF</th>
                    <th>Commande</th>
                    <th>Référence article</th>
                    <th class="text-end">Qté Attendue</th>
                    <th class="text-end">Qté Scannée</th>
                    <th class="text-end">Écart</th>
                    <th class="text-center">Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comparisonData as $row): ?>
                    <?php
                        $deviationClass = '';
                        if ($row['deviation'] < 0) $deviationClass = 'table-danger';
                        elseif ($row['deviation'] > 0) $deviationClass = 'table-warning';
                        elseif ($row['expected_quantity'] > 0 && $row['deviation'] == 0) $deviationClass = 'table-success';
                    ?>
                    <tr class="<?= $deviationClass ?>">
                        <td><?= htmlspecialchars($row['of_number']) ?></td>
                        <td><?= htmlspecialchars($row['order_number']) ?></td>
                        <td><?= htmlspecialchars($row['article_reference']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($row['expected_quantity']) ?></td>
                        <td class="text-end"><?= htmlspecialchars($row['scanned_quantity']) ?></td>
                        <td class="text-end fw-bold"><?= (($row['deviation'] > 0) ? '+' : '') . $row['deviation'] ?></td>
                        <td class="text-center">
                            <?php if($deviationClass === 'table-danger'): ?> <span class="badge bg-danger">Manquant</span>
                            <?php elseif($deviationClass === 'table-warning'): ?> <span class="badge bg-warning text-dark">Surplus</span>
                            <?php elseif($deviationClass === 'table-success'): ?> <span class="badge bg-success">Complet</span>
                            <?php else: ?> <span class="badge bg-info">Scan (non attendu)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>