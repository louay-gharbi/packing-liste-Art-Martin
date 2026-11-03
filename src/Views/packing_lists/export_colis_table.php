<?php
// src/Views/packing_lists/export_colis_table.php
// Variables disponibles : $packingList, $colisList, $appName, $currentDate, $logoBase64
// $baseUrl n'est pas utilisé ici car tous les styles sont inline ou dans <style>
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Export Colis PL: <?= isset($packingList) ? htmlspecialchars($packingList->reference) : '' ?></title>
    <style>
        body { 
            font-family: 'DejaVu Sans', Arial, sans-serif; /* DejaVu Sans pour un bon support UTF-8 */
            font-size: 9pt; 
            margin: 0;
        }
        .header-info { 
            text-align: center; 
            margin-bottom: 15px; 
            border-bottom: 1px solid #ccc; 
            padding-bottom: 10px;
        }
        .header-info h1 {
            font-size: 16pt;
            margin: 0 0 5px 0;
        }
        .header-info p {
            font-size: 10pt;
            margin: 2px 0;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 10px; 
        }
        th, td { 
            border: 1px solid #666; 
            padding: 5px; 
            text-align: left; 
            word-wrap: break-word; /* ou overflow-wrap: break-word; */
            font-size: 8pt; /* Réduire un peu pour plus de contenu */
        }
        th { 
            background-color: #e0e0e0; 
            font-weight: bold; 
        }
        .footer-info {
            font-size: 8pt;
            text-align: center;
            margin-top: 20px;
            width: 100%;
            /* Pour le positionnement en bas avec Dompdf, c'est plus complexe, 
               on peut le mettre à la fin du contenu principal pour l'instant. */
        }
        @page {
            margin: 20mm 15mm 20mm 15mm; /* Haut, Droite, Bas, Gauche */
        }
        /* Pour le pied de page avec Dompdf (si activé) */
        /* 
        @page {
            footer: html_myFooter;
        }
        */
    </style>
</head>
<body>
    <div class="header-info">
        <?php if (!empty($logoBase64)): ?>
            <img src="data:image/jpeg;base64,<?= $logoBase64 ?>" alt="Logo" style="height:40px;" />
        <?php endif; ?>
        <h1>Liste des Colis</h1>
        <?php if (isset($packingList)): ?>
            <p><strong>Packing List :</strong> <?= htmlspecialchars($packingList->reference) ?></p>
            <p><strong>Date d'expédition :</strong> <?= htmlspecialchars((new \DateTime($packingList->ship_date))->format('d/m/Y')) ?></p>
        <?php endif; ?>
        <p><strong>Date d'export :</strong> <?= htmlspecialchars($currentDate) ?></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Palette</th>
                <th>Colis</th>
                <th>Numéro lot</th>
                <th>Quantité</th>
                <th>Unité</th>
                <th>N°CDE AMTD</th>
                <th>N°Ligne</th>
                <th>Réf. interne</th>
                <th>BLC AMF</th>
                <th>Client</th>
                <th>Code postal livraison</th>
                <th>Code article</th>
                <th>Libellé</th>
                <th>Référencement client</th>
                <th>Poids brut (Kg)</th>
                <th>Commentaire</th>
            </tr>
        </thead>
        <tbody>
            <?php if (isset($colisList) && !empty($colisList)): ?>
                <?php foreach ($colisList as $colis): ?>
                    <?php if ($colis->type === 'parent' && isset($childrenByParent[$colis->id])) continue; ?>
                    <?php
                        // Récupérer le code de la palette pour chaque colis
                        // Idéalement, cette information serait déjà jointe dans $colisList
                        // pour éviter des requêtes N+1 ici.
                        // Pour cet exemple, on va supposer que $colisList contient déjà les infos nécessaires,
                        // ou on fait un appel (moins performant).
                        // Dans Colis::findAllByPackingListId, on peut aussi SELECT p.code AS palette_code
                        $paletteCode = $colis->palette_code ?? null;
                        if (!$paletteCode && isset($colis->palette_id)) {
                            $palette = App\Models\Palette::findById($colis->palette_id);
                            if ($palette) {
                                $paletteCode = $palette->code;
                            }
                        }
                        if (!$paletteCode) { $paletteCode = 'N/A'; }
                    ?>
                    <?php if ($colis->type === 'parent'): ?>
                        <tr>
                            <td><?= htmlspecialchars($paletteCode) ?></td>
                            <td><?= htmlspecialchars($colis->package_number) ?></td>
                            <td colspan="14" style="text-align:left;"><?= htmlspecialchars($colis->comment ?? '') ?></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td><?= htmlspecialchars($paletteCode) ?></td>
                            <td><?= htmlspecialchars($colis->parent_package_number ?: $colis->package_number) ?></td>
                            <td><?= htmlspecialchars($colis->of_number ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->quantity ?? '') ?></td>
                            <td>UNI</td>
                            <td><?= htmlspecialchars($colis->order_number ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->line_number ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->internal_reference ?? '') ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($colis->client_name ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->postal_code ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->article_reference ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->label ?? '') ?></td>
                            <td><?= htmlspecialchars($colis->sku ?? '') ?></td>
                            <td></td>
                            <td><?= htmlspecialchars($colis->comment ?? '') ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="10" style="text-align:center; padding: 10px;">Aucun colis trouvé pour cette Packing List.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th colspan="2">Récapitulatif</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Nombre total des palettes</td>
                <td><?= htmlspecialchars($summaryData['total_palettes'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Nombre total des colis</td>
                <td><?= htmlspecialchars($summaryData['total_colis'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Quantité totale</td>
                <td><?= htmlspecialchars($summaryData['total_quantity'] ?? 0) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Pour un pied de page avec Dompdf, on peut utiliser une table ou des divs positionnées -->
    <!-- Ou configurer un footer via les options de Dompdf si on utilise des scripts plus complexes -->
    <div class="footer-info">
        Document généré par <?= htmlspecialchars($appName ?? 'Application PackingList') ?>
    </div>

    <!-- Si vous voulez un vrai pied de page Dompdf qui se répète sur chaque page : -->
    <!--
    <script type="text/php">
        if (isset($pdf)) {
            $text = "Page {PAGE_NUM} sur {PAGE_COUNT}";
            $font = $fontMetrics->get_font("DejaVu Sans", "normal");
            $size = 8;
            $y = $pdf->get_height() - 25; // Position en bas
            $x = $pdf->get_width() / 2 - ($fontMetrics->width($text, $font, $size) / 2);
            $pdf->page_text($x, $y, $text, $font, $size);
        }
    </script>
    -->
</body>
</html>