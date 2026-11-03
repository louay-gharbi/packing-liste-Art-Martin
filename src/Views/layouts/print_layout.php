<?php
// src/Views/layouts/print_layout.php
$baseUrl = $baseUrl ?? BASE_URL;
$appName = $appName ?? APP_NAME;
$pageTitle = $pageTitle ?? 'Impression';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Lib\Csrf::token(), ENT_QUOTES) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { margin: 0; background-color: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .print-page {
            width: 210mm; /* A4 width */
            min-height: 297mm; /* A4 height, mais le contenu déterminera la vraie hauteur */
            padding: 10mm; /* Marges d'impression typiques */
            margin: auto;
            box-sizing: border-box;
        }
        .print-controls { display: none; } /* Masqué par défaut */

        @media print {
            body { margin: 0; padding: 0; }
            .print-page { margin: 0; padding: 0; width: auto; min-height: 0; box-shadow: none; border: none;}
            .print-controls { display: none !important; }
            .no-print { display: none !important; } /* Classe pour masquer des éléments à l'impression */
            /* Forcer l'arrière-plan à s'imprimer si besoin (navigateur dépendant) */
             .qr-label-item {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="container print-controls my-3 text-center no-print">
        <button onclick="window.print();" class="btn btn-primary"><i class="fas fa-print"></i> Imprimer</button>
        <?php if(isset($packingListIdForReturn) && $packingListIdForReturn): ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists/show/<?= $packingListIdForReturn ?>" class="btn btn-secondary">Retour à la Packing List</a>
        <?php else: ?>
            <a href="<?= htmlspecialchars($baseUrl) ?>/packing-lists" class="btn btn-secondary">Retour aux Packing Lists</a>
        <?php endif; ?>
    </div>

    <div class="print-page">
        <?= $content ?? 'Aucun contenu à imprimer.' ?>
    </div>
</body>
</html>