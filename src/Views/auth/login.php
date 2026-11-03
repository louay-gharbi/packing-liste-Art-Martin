<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Lib\Csrf::token(), ENT_QUOTES) ?>">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Connexion' ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl ?? BASE_URL) ?>/css/style.css">
    <style>
        body { font-family: Arial, sans-serif; display: flex; flex-direction: column; align-items: center; margin-top: 50px; }
        .login-container { padding: 15px; border: 1px solid #ccc; border-radius: 5px; background-color: #f9f9f9; width: 300px; margin: auto;}
        .login-container h1 { text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input { width: 93%; padding: 8px; border: 1px solid #ddd; border-radius: 3px; }
        .form-group button { width: 100%; padding: 10px; background-color: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        .form-group button:hover { background-color: #0056b3; }
        .flash-message { padding: 10px; margin-bottom: 15px; border-radius: 3px; text-align: center; }
        .flash-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .flash-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .flash-info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    </style>
</head>
<body>
    <div class="login-container">
       <!-- <h3><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Connexion' ?></h3> -->

        <!-- Affichage des messages flash -->
        <?php
        // Assurer que la session est démarrée pour lire les messages flash
        if (session_status() === PHP_SESSION_NONE) { session_start(); }

        $flashTypes = [
            ['key' => FLASH_SUCCESS, 'class' => 'flash-success'],
            ['key' => FLASH_ERROR, 'class' => 'flash-error'],
            ['key' => FLASH_INFO, 'class' => 'flash-info'],
            ['key' => FLASH_WARNING, 'class' => 'flash-warning'] // Non utilisé actuellement mais pour la complétude
        ];

        foreach ($flashTypes as $flash) {
            if (isset($_SESSION[$flash['key']])) {
                ?>
                <div class="flash-message <?= htmlspecialchars($flash['class']) ?>">
                    <?= htmlspecialchars($_SESSION[$flash['key']]); ?>
                </div>
                <?php
                unset($_SESSION[$flash['key']]); // Supprimer le message après affichage
            }
        }
        // Afficher l'erreur spécifique au login si elle est passée via $data['error']
        if (isset($error) && !empty($error)): ?>
            <div class="flash-message flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <!-- Fin Affichage des messages flash -->

        <form action="<?= htmlspecialchars($baseUrl ?? BASE_URL) ?>/login" method="post">
            <?= \App\Lib\Csrf::field() ?>
            <div class="form-group">
                <label for="username">Nom d'utilisateur :</label>
                <input type="text" id="username" name="username" required value="<?= htmlspecialchars($request->post('username', '')) /* Conserver la valeur en cas d'erreur */ ?>">
            </div>
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <input type="password" id="password" name="password" required>
            </div>
            <!-- Optionnel: Se souvenir de moi
            <div class="form-group">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">Se souvenir de moi</label>
            </div>
            -->
            <div class="form-group">
                <button type="submit">Se connecter</button>
            </div>
        </form>
        <!-- Optionnel: Liens pour "Mot de passe oublié ?" ou "S'inscrire" -->
    </div>
</body>
</html>