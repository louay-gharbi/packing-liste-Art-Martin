<?php
// src/Views/maintenance/index.php
// Variables attendues: $status (null si inactif, sinon message/until/started_at/started_by), $baseUrl
$isActive = $status !== null;
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-<?= $isActive ? 'warning' : 'success' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fas fa-tools me-1"></i> État actuel</span>
                <span class="badge bg-<?= $isActive ? 'warning text-dark' : 'success' ?> fs-6"><?= $isActive ? 'EN MAINTENANCE' : 'Application ouverte' ?></span>
            </div>
            <div class="card-body">
                <?php if ($isActive): ?>
                    <p class="mb-1"><strong>Activée le</strong> <?= htmlspecialchars((new DateTime($status['started_at']))->format('d/m/Y à H:i')) ?>
                        par <strong><?= htmlspecialchars($status['started_by'] ?? '?') ?></strong></p>
                    <p class="mb-1"><strong>Retour prévu :</strong> <?= !empty($status['until']) ? htmlspecialchars((new DateTime($status['until']))->format('d/m/Y à H:i')) : 'non précisé' ?></p>
                    <p class="mb-3"><strong>Message affiché :</strong> <?= $status['message'] !== '' ? htmlspecialchars($status['message']) : '<span class="text-muted">message par défaut</span>' ?></p>
                    <div class="alert alert-warning small">
                        Seuls les utilisateurs du rôle <strong>Administrateur</strong> peuvent utiliser l'application.
                        Les autres voient la page de maintenance et toutes leurs requêtes (scans, enregistrements, exports) sont refusées.
                    </div>
                    <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/maintenance/disable" onsubmit="return confirm('Rouvrir l\'application à tous les utilisateurs ?');">
                        <?= \App\Lib\Csrf::field() ?>
                        <button type="submit" class="btn btn-success"><i class="fas fa-lock-open me-1"></i> Désactiver la maintenance</button>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/maintenance/preview" target="_blank" class="btn btn-outline-secondary ms-2"><i class="fas fa-eye me-1"></i> Voir la page utilisateur</a>
                    </form>
                <?php else: ?>
                    <p class="text-muted">L'application est accessible à tous les utilisateurs selon leurs permissions.</p>
                    <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/maintenance/enable" onsubmit="return confirm('Bloquer l\'application pour tous les utilisateurs non administrateurs ?');">
                        <?= \App\Lib\Csrf::field() ?>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message affiché aux utilisateurs</label>
                            <textarea class="form-control" id="message" name="message" rows="3" maxlength="<?= \App\Lib\Maintenance::MAX_MESSAGE_LENGTH ?>"
                                      placeholder="Ex. : Mise à jour de l'application en cours. Merci de ne pas scanner pendant l'intervention."></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="until" class="form-label">Retour prévu <span class="text-muted small">(optionnel)</span></label>
                            <input type="datetime-local" class="form-control" id="until" name="until" style="max-width: 260px;">
                        </div>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-tools me-1"></i> Activer la maintenance</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle me-1"></i> Fonctionnement</div>
            <div class="card-body small">
                <ul class="mb-0">
                    <li>Les <strong>administrateurs</strong> continuent d'utiliser l'application normalement ; un bandeau orange rappelle que la maintenance est active.</li>
                    <li>Les <strong>autres utilisateurs</strong> voient une page « Application en maintenance » avec votre message, quelle que soit la page demandée.</li>
                    <li>Les <strong>requêtes en arrière-plan</strong> (scan de colis, saisie BLC…) reçoivent une erreur 503 : rien n'est enregistré.</li>
                    <li>La page de <strong>connexion</strong> reste accessible pour qu'un administrateur puisse se connecter.</li>
                    <li>L'état est stocké dans <code>storage/maintenance.json</code> : le blocage fonctionne même si la base de données est arrêtée. En urgence, supprimer ce fichier rouvre l'application.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
