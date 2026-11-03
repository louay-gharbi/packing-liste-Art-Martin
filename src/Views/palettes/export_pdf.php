<?php
// src/Views/palettes/export_pdf.php — HTML converti en PDF par Dompdf (détail d'une palette Tunisie)
// Variables disponibles : $palette, $packingList, $colisGrouped, $nbColis, $appName, $currentDate, $logoBase64
$fmtDate = fn($d) => $d ? (new DateTime($d))->format('d/m/Y H:i') : '';
$fmtQty = fn($q) => is_numeric($q) ? rtrim(rtrim(number_format((float)$q, 3, ',', ''), '0'), ',') : '';
$totalQty = 0;
$nbLines = 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Palette <?= htmlspecialchars($palette->code) ?></title>
    <style>
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 7.5pt; margin: 0; color: #222; }
        .head { border-bottom: 1px solid #999; padding-bottom: 6px; margin-bottom: 6px; }
        .head img { height: 30px; float: right; }
        .head h1 { font-size: 13pt; margin: 0 0 3px 0; }
        .head p { margin: 1px 0; font-size: 8pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #bbb; padding: 2px 3px; text-align: left; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
        thead th { background: #2c3e50; color: #fff; font-size: 7.5pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        tr.parent td { background: #e9ecef; font-weight: bold; }
        tr.child td.num { color: #888; }
        tr.child td.fils { font-weight: bold; }
        td.qty { text-align: right; }
        .recap { width: 35%; margin-top: 8px; }
        .foot { font-size: 7pt; color: #777; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="head">
        <?php if (!empty($logoBase64)): ?><img src="data:image/jpeg;base64,<?= $logoBase64 ?>" alt="Logo"><?php endif; ?>
        <h1>Détail palette <?= htmlspecialchars($palette->code) ?></h1>
        <p><strong>Packing List :</strong> <?= htmlspecialchars($packingList->reference ?? '-') ?>
           · <strong>Colis :</strong> <?= (int)$nbColis ?>
           · <strong>Statut :</strong> <?= htmlspecialchars($palette->status) ?>
           · <strong>Édité le :</strong> <?= htmlspecialchars($currentDate) ?></p>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width: 7%;"># Colis</th>
                <th style="width: 9%;">Colis fils</th>
                <th style="width: 6%;">OF</th>
                <th style="width: 6.5%;">Commande</th>
                <th style="width: 13%;">Réf. Article</th>
                <th style="width: 28%;">Libellé</th>
                <th style="width: 5%;">Qté</th>
                <th style="width: 9.5%;">Scanné le</th>
                <th style="width: 16%;">Commentaire</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($colisGrouped as $g): ?>
            <?php $children = ($g->type === 'parent') ? ($g->children ?? []) : []; ?>
            <?php $nbLines++; if (is_numeric($g->quantity)) $totalQty += (float)$g->quantity; ?>
            <tr class="<?= $children ? 'parent' : '' ?>">
                <td class="num"><?= htmlspecialchars($g->package_number) ?></td>
                <td><?= $children ? 'Groupage · ' . count($children) . ' colis' : '' ?></td>
                <td><?= htmlspecialchars($g->of_number ?? '') ?></td>
                <td><?= htmlspecialchars($g->order_number ?? '') ?></td>
                <td><?= htmlspecialchars($g->article_reference ?? '') ?></td>
                <td><?= htmlspecialchars($g->label ?? '') ?></td>
                <td class="qty"><?= $fmtQty($g->quantity ?? null) ?></td>
                <td><?= $fmtDate($g->scan_date_time ?? null) ?></td>
                <td><?= htmlspecialchars($g->comment ?? '') ?></td>
            </tr>
            <?php foreach ($children as $c): ?>
                <?php $nbLines++; if (is_numeric($c->quantity)) $totalQty += (float)$c->quantity; ?>
                <tr class="child">
                    <td class="num"><?= htmlspecialchars($g->package_number) ?></td>
                    <td class="fils"><?= htmlspecialchars($c->package_number) ?></td>
                    <td><?= htmlspecialchars($c->of_number ?? '') ?></td>
                    <td><?= htmlspecialchars($c->order_number ?? '') ?></td>
                    <td><?= htmlspecialchars($c->article_reference ?? '') ?></td>
                    <td><?= htmlspecialchars($c->label ?? '') ?></td>
                    <td class="qty"><?= $fmtQty($c->quantity ?? null) ?></td>
                    <td><?= $fmtDate($c->scan_date_time ?? null) ?></td>
                    <td><?= htmlspecialchars($c->comment ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <table class="recap">
        <tr><th style="background:#e0e0e0; color:#222;">Colis (1er niveau)</th><td><?= (int)$nbColis ?></td></tr>
        <tr><th style="background:#e0e0e0; color:#222;">Lignes (avec colis fils)</th><td><?= $nbLines ?></td></tr>
        <tr><th style="background:#e0e0e0; color:#222;">Quantité totale</th><td><?= $fmtQty($totalQty) ?></td></tr>
    </table>
    <div class="foot">Document généré par <?= htmlspecialchars($appName ?? 'PackingList') ?></div>
</body>
</html>
