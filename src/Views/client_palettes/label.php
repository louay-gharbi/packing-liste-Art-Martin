<?php
// src/Views/client_palettes/label.php — étiquette 100 x 150 mm imprimée directement par le navigateur
// Variables disponibles : $palette, $qrDataUri, $logoBase64, $backUrl
$nameLen = mb_strlen($palette->client_name);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Étiquette <?= htmlspecialchars($palette->code) ?></title>
    <style>
        @page { size: 100mm 150mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #e9ecef; font-family: Arial, Helvetica, sans-serif; color: #000;
                     -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .label { width: 100mm; height: 150mm; padding: 5mm; margin: 5mm auto; background: #fff; overflow: hidden;
                 display: flex; flex-direction: column; align-items: center; }
        .top { width: 100%; text-align: center; border-bottom: 2px solid #000; padding-bottom: 1.5mm; }
        .top img { height: 9mm; }
        .top .title { font-size: 9pt; font-weight: bold; letter-spacing: 1px; margin-top: 1mm; }
        .client { text-align: center; font-weight: bold; margin-top: 4mm; word-break: break-word; line-height: 1.1; }
        .cp { font-size: 16pt; margin-top: 2mm; }
        .week { font-size: 11pt; margin: 2mm 0 4mm 0; }
        .qr { width: 60mm; height: 60mm; }
        .code { font-size: 12pt; font-weight: bold; margin-top: 3mm; text-align: center; word-break: break-all; }
        .controls { text-align: center; margin: 5mm 0; }
        .controls button, .controls a { font-size: 11pt; padding: 2mm 5mm; margin: 0 1mm; cursor: pointer; }
        @media print {
            html, body { background: #fff; }
            .label { margin: 0; }
            .controls { display: none; }
        }
    </style>
</head>
<body>
    <div class="controls">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="<?= htmlspecialchars($backUrl) ?>">Retour à la palette</a>
    </div>
    <div class="label">
        <div class="top">
            <?php if (!empty($logoBase64)): ?>
                <img src="data:image/jpeg;base64,<?= $logoBase64 ?>" alt="Logo">
            <?php endif; ?>
            <div class="title">PALETTE CLIENT</div>
        </div>
        <div class="client" style="font-size: <?= $nameLen > 28 ? 12 : ($nameLen > 14 ? 16 : 26) ?>pt;"><?= htmlspecialchars($palette->client_name) ?></div>
        <div class="cp">Code postal : <strong><?= htmlspecialchars($palette->postal_code !== '' ? $palette->postal_code : '—') ?></strong></div>
        <div class="week">Semaine S<?= sprintf('%02d', $palette->ship_week) ?> <?= $palette->ship_year ?> — palette n° <?= sprintf('%02d', $palette->number) ?></div>
        <img class="qr" src="<?= $qrDataUri ?>" alt="QR <?= htmlspecialchars($palette->code) ?>">
        <div class="code"><?= htmlspecialchars($palette->code) ?></div>
    </div>
    <script>
        // Impression directe à l'ouverture (après chargement des images).
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
