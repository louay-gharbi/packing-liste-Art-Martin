<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>404 - Page Non Trouvée</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl) ?>/css/style.css"> <!-- CORRIGÉ -->
    <style>
        body { text-align: center; padding: 50px; font-family: Arial, sans-serif; }
        h1 { font-size: 48px; }
    </style>
</head>
<body>
    <h1>404</h1>
    <p>Désolé, la page que vous recherchez n'a pas été trouvée.</p>
    <?php if (isset($errorMessage) && ini_get('display_errors')): ?>
        <p><small><?= htmlspecialchars($errorMessage) ?></small></p>
    <?php endif; ?>
    <?php if (isset($errorTrace) && ini_get('display_errors')): // Ajout pour la trace ?>
        <pre style="text-align: left; background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto;"><?= htmlspecialchars($errorTrace) ?></pre>
    <?php endif; ?>
    <p><a href="<?= htmlspecialchars($baseUrl) ?>/">Retour à l'accueil</a></p> <!-- CORRIGÉ -->
</body>
</html>