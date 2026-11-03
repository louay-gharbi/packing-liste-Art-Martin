<?php
// src/Views/receptions/export_client_packing.php — HTML converti en PDF par Dompdf (une page par client)
// Variables disponibles : $year, $week, $monday, $sunday, $clients, $appName, $currentDate, $logoBase64
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Colisage client S<?= sprintf('%02d', $week) ?> <?= $year ?></title>
    <style>
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; margin: 0; }
        .header-info { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 8px; }
        .header-info h1 { font-size: 15pt; margin: 0 0 4px 0; }
        .header-info p { font-size: 10pt; margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #666; padding: 4px; text-align: left; word-wrap: break-word; font-size: 7.5pt; }
        th { background-color: #e0e0e0; font-weight: bold; }
        .client-page { page-break-after: always; }
        .client-page:last-child { page-break-after: auto; }
        .footer-info { font-size: 8pt; text-align: center; margin-top: 15px; }
        @page { margin: 15mm 12mm 15mm 12mm; }
    </style>
</head>
<body>
<?php foreach ($clients as $client => $lines): ?>
    <?php $totalQty = array_sum(array_map(fn($l) => $l['quantity'] ?? 0, $lines)); ?>
    <div class="client-page">
        <div class="header-info">
            <?php if (!empty($logoBase64)): ?>
                <img src="data:image/jpeg;base64,<?= $logoBase64 ?>" alt="Logo" style="height:36px;" />
            <?php endif; ?>
            <h1>Liste de colisage — <?= htmlspecialchars($client) ?></h1>
            <p><strong>Semaine d'expédition :</strong> S<?= sprintf('%02d', $week) ?> <?= $year ?>
               (du <?= $monday->format('d/m/Y') ?> au <?= $sunday->format('d/m/Y') ?>)</p>
            <p><strong>Date d'édition :</strong> <?= htmlspecialchars($currentDate) ?></p>
        </div>
        <table>
            <thead>
                <tr><th>N° commande</th><th>Ligne</th><th>Colis</th><th>Groupage</th><th>OF</th><th>Réf. interne</th><th>Code article</th>
                    <th>Libellé</th><th>Réf. client</th><th>Qté</th><th>Unité</th><th>Code postal</th><th>Palette</th><th>Packing List</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td><?= htmlspecialchars($l['order_number'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['line_number'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['package_number']) ?></td>
                    <td><?= htmlspecialchars($l['groupage_number'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['of_number'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['internal_reference'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['article_reference'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['sku'] ?? '') ?></td>
                    <td><?= $l['quantity'] !== null ? htmlspecialchars((string)$l['quantity']) : '' ?></td>
                    <td><?= htmlspecialchars($l['unit'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['postal_code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($l['palette_code']) ?></td>
                    <td><?= htmlspecialchars($l['pl_reference']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <table style="width: 40%;">
            <thead><tr><th colspan="2">Récapitulatif</th></tr></thead>
            <tbody>
                <tr><td>Nombre de commandes</td><td><?= count(array_unique(array_map(fn($l) => (string)$l['order_number'], $lines))) ?></td></tr>
                <tr><td>Nombre de colis</td><td><?= count($lines) ?></td></tr>
                <tr><td>Quantité totale</td><td><?= htmlspecialchars((string)$totalQty) ?></td></tr>
            </tbody>
        </table>
        <div class="footer-info">Document généré par <?= htmlspecialchars($appName ?? 'Application PackingList') ?></div>
    </div>
<?php endforeach; ?>
</body>
</html>
