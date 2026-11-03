<?php
// src/Views/palettes/print_qrcodes.php
// Variables attendues : $palettesToPrint, $labelWidthMm, $labelHeightMm, $qrSizeMm, $palettesPerRow, $printWithLabelText, $baseUrl

$itemWidthPercentage = (100 / max(1, $palettesPerRow)) - 2; // -2 pour une petite marge entre les items
$qrSizePx = intval($qrSizeMm * 3.7795275591); // Conversion mm vers pixels (approx @ 96 DPI)
$labelWidthPx = intval($labelWidthMm * 3.7795275591);
$labelHeightPx = intval($labelHeightMm * 3.7795275591);

// S'assurer que la taille du QR code n'est pas plus grande que la taille de l'étiquette
if ($qrSizePx > $labelWidthPx - 10) $qrSizePx = $labelWidthPx - 10; // -10 pour marges internes
if ($qrSizePx > $labelHeightPx - ($printWithLabelText ? 25 : 10)) $qrSizePx = $labelHeightPx - ($printWithLabelText ? 25 : 10);


?>
<style>
    .qr-grid-container {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-start; /* Ou space-around si vous préférez */
        align-items: flex-start;
        gap: 5mm; /* Espace entre les étiquettes */
    }
    .qr-label-item {
        width: <?= $labelWidthMm ?>mm;
        height: <?= $labelHeightMm ?>mm;
        border: 1px dashed #ccc; /* Pour visualiser les limites de l'étiquette à l'écran */
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding: 2mm;
        box-sizing: border-box;
        overflow: hidden; /* Pour éviter que le contenu ne dépasse */
        page-break-inside: avoid; /* Éviter de couper une étiquette sur deux pages */
    }
    .qr-label-item img {
        max-width: <?= $qrSizePx ?>px; /* Taille du QR code en pixels */
        max-height: <?= $qrSizePx ?>px; /* Taille du QR code en pixels */
        object-fit: contain;
    }
    .qr-label-item .code-text {
        margin-top: 1mm;
        font-size: 10pt; /* Ajustez selon besoin */
        font-family: Arial, sans-serif;
        word-break: break-all; /* Pour les codes longs */
    }
     @media print {
        .qr-label-item {
            border: none; /* Masquer les bordures pointillées à l'impression */
        }
    }
</style>

<div class="qr-grid-container">
    <?php foreach ($palettesToPrint as $palette): ?>
        <div class="qr-label-item">
            <?php if ($palette->qr_code_path): ?>
                <?php $fullQrUrlPrint = preg_match('#^https?://#', $palette->qr_code_path)
                    ? $palette->qr_code_path
                    : rtrim($baseUrl, '/') . $palette->qr_code_path; ?>
                <img src="<?= htmlspecialchars($fullQrUrlPrint) ?>" alt="QR Code <?= htmlspecialchars($palette->code) ?>">
                <?php if ($printWithLabelText): ?>
                    <div class="code-text"><?= htmlspecialchars($palette->code) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="code-text">QR non dispo.<br><?= htmlspecialchars($palette->code) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>