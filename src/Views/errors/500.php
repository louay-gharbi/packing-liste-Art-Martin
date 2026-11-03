<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>500 - Erreur Serveur</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/css/style.css"> <!-- CORRIGÉ -->
    <style>
        body { text-align: center; padding: 50px; font-family: Arial, sans-serif; }
        h1 { font-size: 48px; color: #cc0000; }
    </style>
</head>
<body>
    <h1>Erreur Serveur</h1>
    <p>Une erreur interne s'est produite. Nous travaillons à la résoudre.</p>
    <?php if (isset($errorMessage) && ini_get('display_errors')): ?>
        <p><small>Détail (pour dev): <?= htmlspecialchars($errorMessage) ?></small></p>
    <?php endif; ?>
    <?php if (isset($errorTrace) && ini_get('display_errors')): // Ajout pour la trace ?>
        <pre style="text-align: left; background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto;"><?= htmlspecialchars($errorTrace) ?></pre>
    <?php endif; ?>
    <p><a href="<?= htmlspecialchars($baseUrl) ?>/">Retour à l'accueil</a></p> <!-- CORRIGÉ -->
</body>
</html>