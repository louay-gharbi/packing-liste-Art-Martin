<?php
// src/Views/palettes/print_qrcode_single.php — QR code d'une palette (code écrit dessous), imprimé directement par le navigateur
// Variables disponibles : $palette, $qrDataUri, $backUrl
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>QR <?= htmlspecialchars($palette->code) ?></title>
    <style>
        @page { margin: 10mm; }
        html, body { margin: 0; padding: 0; background: #fff; font-family: Arial, Helvetica, sans-serif; }
        .qr { display: block; width: 90mm; margin: 10mm auto 0 auto; }
        .controls { text-align: center; margin: 5mm 0; }
        .controls button, .controls a { font-size: 11pt; padding: 2mm 5mm; margin: 0 1mm; cursor: pointer; }
        @media print {
            .controls { display: none; }
            .qr { margin-top: 0; }
        }
    </style>
</head>
<body>
    <div class="controls">
        <button type="button" onclick="window.print()">Imprimer</button>
        <a href="<?= htmlspecialchars($backUrl) ?>">Retour à la palette</a>
    </div>
    <img class="qr" src="<?= $qrDataUri ?>" alt="QR <?= htmlspecialchars($palette->code) ?>">
    <script>
        // Impression directe à l'ouverture (après chargement de l'image).
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
