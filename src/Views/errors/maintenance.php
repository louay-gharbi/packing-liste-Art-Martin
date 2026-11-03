<?php
// src/Views/errors/maintenance.php — page affichée aux utilisateurs pendant la maintenance (503)
// Variables : $baseUrl, $appName, $message, $until (Y-m-d H:i ou null), $isLoggedIn, $preview (aperçu admin, optionnel)
$untilLabel = !empty($until) && strtotime($until) ? (new DateTime($until))->format('d/m/Y à H:i') : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance - <?= htmlspecialchars($appName) ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f6f9; color: #212529; }
        .box { background: #fff; max-width: 520px; margin: 16px; padding: 36px 32px; border-radius: 10px;
               box-shadow: 0 4px 18px rgba(0,0,0,.08); text-align: center; border-top: 5px solid #ffc107; }
        .icon { font-size: 52px; line-height: 1; margin-bottom: 12px; }
        h1 { font-size: 1.5rem; margin: 0 0 12px; }
        .msg { font-size: 1rem; margin: 0 0 14px; white-space: pre-line; }
        .until { color: #6c757d; font-size: .9rem; margin: 0 0 22px; }
        .actions a { display: inline-block; margin: 0 6px; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: .9rem; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-light { background: #e9ecef; color: #212529; }
        .app { margin-top: 22px; color: #adb5bd; font-size: .8rem; }
        .countdown { display: flex; justify-content: center; gap: 10px; margin: 0 0 18px; }
        .countdown div { background: #212529; color: #fff; border-radius: 8px; padding: 10px 0; width: 74px; }
        .countdown span { display: block; font-size: 1.8rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.1; }
        .countdown small { font-size: .7rem; color: #ced4da; text-transform: uppercase; letter-spacing: .5px; }
        .countdown.done div { background: #198754; }
        .check { color: #6c757d; font-size: .8rem; margin: 0 0 18px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">🛠️</div>
        <h1>Application en maintenance</h1>
        <p class="msg"><?= htmlspecialchars($message) ?></p>
        <?php if ($untilLabel): ?>
            <p class="until">Retour prévu le <strong><?= htmlspecialchars($untilLabel) ?></strong></p>
            <div class="countdown" id="countdown" data-until="<?= (int)strtotime($until) * 1000 ?>">
                <div><span id="cd-d">0</span><small>jours</small></div>
                <div><span id="cd-h">00</span><small>heures</small></div>
                <div><span id="cd-m">00</span><small>minutes</small></div>
                <div><span id="cd-s">00</span><small>secondes</small></div>
            </div>
        <?php else: ?>
            <p class="until">Merci de réessayer un peu plus tard.</p>
        <?php endif; ?>
        <p class="check" id="checkStatus">La page vérifie automatiquement le retour de l'application.</p>
        <div class="actions">
            <a href="" class="btn-primary" onclick="location.reload(); return false;">Réessayer</a>
            <?php if ($isLoggedIn): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/logout" class="btn-light">Se déconnecter</a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/login" class="btn-light">Connexion administrateur</a>
            <?php endif; ?>
        </div>
        <div class="app"><?= htmlspecialchars($appName) ?></div>
    </div>
    <script>
    (function () {
        const pad = n => String(n).padStart(2, '0');
        const cd = document.getElementById('countdown');
        const checkStatus = document.getElementById('checkStatus');
        let pollDelay = 60000; // vérification toutes les 60 s, puis toutes les 15 s une fois l'heure prévue passée

        // Compte à rebours jusqu'à l'heure de retour prévue
        if (cd) {
            const until = Number(cd.dataset.until);
            const tick = () => {
                let left = Math.max(0, Math.floor((until - Date.now()) / 1000));
                document.getElementById('cd-d').textContent = Math.floor(left / 86400);
                document.getElementById('cd-h').textContent = pad(Math.floor(left % 86400 / 3600));
                document.getElementById('cd-m').textContent = pad(Math.floor(left % 3600 / 60));
                document.getElementById('cd-s').textContent = pad(left % 60);
                if (left === 0) {
                    cd.classList.add('done');
                    checkStatus.textContent = "L'heure prévue est atteinte : vérification du retour de l'application…";
                    pollDelay = 15000;
                    return;
                }
                setTimeout(tick, 1000);
            };
            tick();
        }

        // Dès que l'application ne répond plus « maintenance » (503), on recharge la page demandée.
        const poll = () => {
            fetch(location.href, { method: 'GET', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (r.status !== 503) { checkStatus.textContent = "L'application est de nouveau disponible…"; location.reload(); } else { setTimeout(poll, pollDelay); } })
                .catch(() => setTimeout(poll, pollDelay));
        };
        <?php if (empty($preview)): ?>setTimeout(poll, pollDelay);<?php else: ?>checkStatus.textContent = 'Aperçu administrateur : pas de vérification automatique.';<?php endif; ?>

    })();
    </script>
</body>
</html>
