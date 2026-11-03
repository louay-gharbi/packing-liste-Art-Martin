<?php
// src/Views/colis/scan.php
// Variables attendues: $pageTitle, $baseUrl, $appName, $request, $activePalette (objet ou null), $colisScannedRecently
use App\Lib\Auth;

$showGroupageAlert = isset($_SESSION['active_parent_colis_id']) && $_SESSION['active_parent_colis_id'];
$parentColis = null;
$childCount = 0;
if ($showGroupageAlert) {
    $parentColis = \App\Models\Colis::findById((int)$_SESSION['active_parent_colis_id']);
    if ($parentColis) {
        $children = \App\Models\Colis::findChildren($parentColis->id);
        $childCount = count($children);
    }
}
?>
<style>
    /* ===== ANIMATIONS & TRANSITIONS ===== */
    .scan-interface-container { max-width: 800px; margin: auto; }

    /* ===== OVERLAY FLASH ERREUR — TRÈS IMPOSANT ===== */
    @keyframes flashErrorOverlay {
        0% { opacity: 0; }
        3% { opacity: 0.7; }
        8% { opacity: 0.7; }
        12% { opacity: 0; }
        100% { opacity: 0; }
    }
    .scan-error-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 99998;
        background: #8B0000;
        pointer-events: none;
    }
    .scan-error-overlay.active {
        display: block;
        animation: flashErrorOverlay 0.8s ease-out;
    }
    
    /* Screen shake */
    @keyframes screenShake {
        0% { transform: translateX(0); }
        5% { transform: translateX(-15px) rotate(-1deg); }
        10% { transform: translateX(15px) rotate(1deg); }
        15% { transform: translateX(-10px) rotate(-0.5deg); }
        20% { transform: translateX(10px) rotate(0.5deg); }
        25% { transform: translateX(-5px); }
        30% { transform: translateX(5px); }
        35% { transform: translateX(0); }
        100% { transform: translateX(0); }
    }
    .scan-error-flash-body {
        animation: screenShake 1.5s ease-out !important;
    }

    /* Bordures rouges vives */
    .scan-error-flash .card {
        border: 4px solid #dc3545 !important;
        box-shadow: 0 0 40px rgba(220, 53, 69, 0.8), inset 0 0 40px rgba(220, 53, 69, 0.1) !important;
    }
    .scan-error-flash .card-header {
        background-color: #8B0000 !important;
        color: white !important;
        border-bottom: 3px solid #dc3545 !important;
    }
    .scan-error-flash #colisQrPayload {
        background-color: #fff5f5 !important;
        border: 3px solid #dc3545 !important;
        color: #8B0000 !important;
        font-weight: bold !important;
        box-shadow: 0 0 20px rgba(220, 53, 69, 0.5) !important;
    }
    .scan-error-flash .palette-info .card-header,
    .scan-error-flash .colis-scan-area .card-header {
        background-color: #8B0000 !important;
    }
    .scan-error-flash .progress {
        border: 2px solid #dc3545 !important;
    }

    /* Animation pulse pour le champ de scan */
    @keyframes scanPulse {
        0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
    }
    #colisQrPayload:focus {
        animation: scanPulse 1.5s infinite;
        border-color: #28a745;
    }

    /* Animation succès (checkmark) */
    @keyframes successFlash {
        0% { background-color: transparent; }
        30% { background-color: #d4edda; }
        100% { background-color: transparent; }
    }
    .scan-success-flash .colis-scan-area {
        animation: successFlash 0.8s ease-out;
    }

    /* Loading spinner overlay */
    .scan-loading-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.3);
        z-index: 9999;
        justify-content: center;
        align-items: flex-start;
        padding-top: 20%;
    }
    .scan-loading-overlay.active { display: flex; }
    .scan-loading-overlay .spinner {
        width: 60px; height: 60px;
        border: 5px solid #f3f3f3;
        border-top: 5px solid #3498db;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    /* ===== TOAST FLOTTANT ===== */
    .scan-toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
    }
    .scan-toast {
        pointer-events: auto;
        min-width: 280px;
        max-width: 420px;
        padding: 12px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(120%);
        animation: slideInRight 0.4s ease-out forwards, fadeOut 0.4s 2.6s ease-in forwards;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .scan-toast i { font-size: 1.2rem; }
    .scan-toast-success { background: #28a745; }
    .scan-toast-error { background: #dc3545; }
    .scan-toast-info { background: #17a2b8; }
    .scan-toast-warning { background: #ffc107; color: #333; }

    @keyframes slideInRight {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }

    /* ===== BADGE GROUPAGE ===== */
    .groupage-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: linear-gradient(135deg, #ffc107, #ff9800);
        color: #333;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.9rem;
        box-shadow: 0 2px 8px rgba(255, 152, 0, 0.4);
        animation: badgePulse 2s infinite;
    }
    @keyframes badgePulse {
        0% { box-shadow: 0 2px 8px rgba(255, 152, 0, 0.4); }
        50% { box-shadow: 0 2px 20px rgba(255, 152, 0, 0.8); }
        100% { box-shadow: 0 2px 8px rgba(255, 152, 0, 0.4); }
    }
    .groupage-badge .groupage-count {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        width: 24px; height: 24px;
        background: white;
        color: #e65100;
        border-radius: 50%;
        font-size: 0.8rem;
        font-weight: bold;
    }
    @keyframes countBounce {
        0% { transform: scale(1); }
        50% { transform: scale(1.3); }
        100% { transform: scale(1); }
    }
    .groupage-badge .groupage-count.updated {
        animation: countBounce 0.3s ease;
    }

    /* ===== RECENT SCANS TOAST ===== */
    #colisQrPayload { font-size: 1.2rem; text-align: center; }
    #paletteCodeInput { font-size: 1.2rem; text-align: center; }

    /* Compteur progress bar animé */
    .scan-progress {
        height: 100%;
        transition: width 0.5s ease;
    }
</style>

<!-- Conteneur de toasts flottants -->
<div class="scan-toast-container" id="scanToastContainer"></div>

<!-- Erreur Overlay (flash rouge sur tout l'écran) -->
<div class="scan-error-overlay" id="scanErrorOverlay"></div>

<!-- Loading Overlay -->
<div class="scan-loading-overlay" id="scanLoadingOverlay">
    <div class="spinner"></div>
</div>

<div class="scan-interface-container" id="scanInterfaceContainer">

    <!-- Section Activation Palette -->
    <div class="card palette-activation-area">
        <div class="card-header"><h5 class="mb-0"><i class="fas fa-pallet me-2"></i>1. Activer une Palette pour le Scan</h5></div>
        <div class="card-body">
            <form action="<?= htmlspecialchars($baseUrl) ?>/colis/process-palette-scan" method="post" id="paletteActivationForm">
                <?= \App\Lib\Csrf::field() ?>
                <div class="input-group mb-3">
                    <input type="text" class="form-control form-control-lg" 
                           id="paletteCodeInput" name="palette_code_input" 
                           placeholder="Scanner ou saisir le code de la palette" 
                           value="<?= htmlspecialchars($palette_code_input ?? '') ?>"
                           aria-label="Code Palette" required autofocus>
                    <button class="btn btn-primary" type="submit" id="activatePaletteBtn">Activer Palette</button>
                </div>
            </form>
            <?php if (isset($activePalette) && $activePalette): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/colis/deactivate-palette-scan" class="btn btn-sm btn-outline-secondary mt-2" id="deactivatePaletteLink">
                    <i class="fas fa-times-circle me-1"></i>Désactiver la palette actuelle
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section Infos Palette Active et Scan Colis -->
    <?php if (isset($activePalette) && $activePalette): ?>
        <div class="card palette-info mt-3">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Palette Active : <?= htmlspecialchars($activePalette->code) ?></h5>
                <span class="badge bg-light text-dark"><?= $activePalette->getColisCount() ?> / <?= htmlspecialchars($activePalette->max_colis ?? '∞') ?> colis</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Max Colis :</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars($activePalette->max_colis ?? 'Non défini') ?></dd>
                            <dt class="col-sm-4">Progression :</dt>
                            <dd class="col-sm-8">
                                <div class="progress" style="height: 20px;">
                                    <?php 
                                        $progressPercent = ($activePalette->max_colis > 0) 
                                            ? min(100, round(($activePalette->getColisCount() / $activePalette->max_colis) * 100)) 
                                            : 0;
                                    ?>
                                    <div class="progress-bar scan-progress <?= $progressPercent >= 100 ? 'bg-success' : ($progressPercent >= 75 ? 'bg-warning' : '') ?>" 
                                         id="paletteProgressBar"
                                         role="progressbar" 
                                         style="width: <?= $progressPercent ?>%;" 
                                         aria-valuenow="<?= $progressPercent ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?= $progressPercent ?>%
                                    </div>
                                </div>
                            </dd>
                            <dt class="col-sm-4">Packing List :</dt>
                            <dd class="col-sm-8">
                                <?php $pl = \App\Models\PackingList::findById($activePalette->packing_list_id); ?>
                                <?= $pl ? htmlspecialchars($pl->reference) : 'N/A' ?>
                            </dd>
                        </dl>
                    </div>
                    <div class="col-md-4 text-end">
                        <!-- Badge Groupage animé -->
                        <div id="groupageBadgeContainer" style="<?= $showGroupageAlert && $parentColis ? '' : 'display:none;' ?>">
                            <div class="groupage-badge" id="groupageBadge">
                                <i class="fas fa-object-group"></i>
                                <span>Groupage : <strong id="groupageParentNumber"><?= $parentColis ? htmlspecialchars($parentColis->package_number) : '' ?></strong></span>
                                <span class="groupage-count" id="groupageChildCount"><?= $childCount ?></span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-warning mt-2" id="stopGroupageBtn" title="Terminer le groupage">
                                <i class="fas fa-check-circle me-1"></i>Terminer le groupage
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Zone de scan -->
        <div class="card colis-scan-area mt-3" id="colisScanArea">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-qrcode me-2"></i>2. Scanner les Colis</h5>
                <span class="text-muted small" id="lastScanTime"></span>
            </div>
            <div class="card-body">
                <form id="colisScanForm" method="post">
            <?= \App\Lib\Csrf::field() ?>
                    <div class="form-group">
                        <label for="colisQrPayload" class="form-label visually-hidden">Payload QR Code Colis :</label>
                        <input type="text" class="form-control form-control-lg" 
                               id="colisQrPayload" name="colis_qr_payload" 
                               placeholder="Scanner le QR code du colis ici..." 
                               required autocomplete="off">
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Le scan est soumis automatiquement après la saisie (touche "Entrée")
                        </small>
                        <span class="text-success d-none" id="scanSuccessIndicator">
                            <i class="fas fa-check-circle"></i> Scan réussi
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Derniers scans (minimaliste, rafraîchi en AJAX) -->
        <div class="mt-3" id="recentScansContainer" style="<?= empty($colisScannedRecently) ? 'display:none;' : '' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted"><i class="fas fa-clock me-1"></i>Derniers colis scannés</small>
                <small class="text-muted" id="scannedCountDisplay">Total: <?= $activePalette->getColisCount() ?></small>
            </div>
            <ul class="list-group list-group-flush mt-1" id="recentScansList">
                <?php if (!empty($colisScannedRecently)): ?>
                    <?php foreach($colisScannedRecently as $c): ?>
                        <li class="list-group-item py-1 px-2 small d-flex justify-content-between align-items-center recent-scan-item">
                            <span>
                                <strong><?= htmlspecialchars($c->package_number) ?></strong>
                                <span class="text-muted ms-2"><?= htmlspecialchars($c->of_number ?? '') ?></span>
                            </span>
                            <small class="text-muted"><?= (new DateTime($c->scan_date_time))->format('H:i:s') ?></small>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

    <?php elseif (!isset($_SESSION['active_scan_palette_id']) && $request->getMethod() === 'GET'): ?>
        <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle me-2"></i>
            Veuillez scanner ou saisir le code d'une palette et cliquer sur "Activer Palette" pour commencer le scan des colis.
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const paletteCodeInput = document.getElementById('paletteCodeInput');
    const colisQrPayloadInput = document.getElementById('colisQrPayload');
    const colisScanForm = document.getElementById('colisScanForm');
    const scanInterface = document.getElementById('scanInterfaceContainer');
    const toastContainer = document.getElementById('scanToastContainer');
    const loadingOverlay = document.getElementById('scanLoadingOverlay');
    const baseUrl = window.baseUrl || '<?= htmlspecialchars($baseUrl) ?>';

    // ===== FOCUS PERMANENT =====
    function setFocusOnScanField() {
        if (colisQrPayloadInput && document.querySelector('.palette-info')) {
            colisQrPayloadInput.focus();
        } else if (paletteCodeInput && !document.querySelector('.palette-info')) {
            paletteCodeInput.focus();
        }
    }

    // Focus initial
    setTimeout(setFocusOnScanField, 100);

    // Forcer le focus en permanence (toutes les 2s si perdu)
    setInterval(function() {
        if (colisQrPayloadInput && document.querySelector('.palette-info') && document.activeElement !== colisQrPayloadInput) {
            colisQrPayloadInput.focus();
        }
    }, 2000);

    // Focus après chaque clic ailleurs
    document.addEventListener('click', function(e) {
        // Ne pas voler le focus si l'utilisateur clique sur un bouton ou un autre input
        const tag = e.target.tagName.toLowerCase();
        if (tag === 'input' || tag === 'button' || tag === 'select' || tag === 'textarea' || tag === 'a') {
            return;
        }
        setTimeout(setFocusOnScanField, 50);
    });

    // ===== TOAST FLOTTANT =====
    function showScanToast(message, type = 'success') {
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            info: 'fas fa-info-circle',
            warning: 'fas fa-exclamation-triangle'
        };
        const toast = document.createElement('div');
        toast.className = 'scan-toast scan-toast-' + type;
        toast.innerHTML = '<i class="' + (icons[type] || icons.info) + '"></i> ' + message;
        toastContainer.appendChild(toast);
        // Supprimer après l'animation
        setTimeout(() => {
            if (toast.parentNode) toast.remove();
        }, 3000);
    }

    // ===== FLASH ANIMATIONS =====
    // Le flash erreur PERJURE jusqu'à un scan valide
    function triggerErrorFlash() {
        // Overlay rouge sur tout l'écran (z-index 99998)
        const overlay = document.getElementById('scanErrorOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            void overlay.offsetWidth; // Force reflow
            overlay.classList.add('active');
            setTimeout(() => overlay.classList.remove('active'), 800);
        }
        
        // Screen shake sur le body (animation courte)
        document.body.classList.remove('scan-error-flash-body');
        void document.body.offsetWidth;
        document.body.classList.add('scan-error-flash-body');
        setTimeout(() => document.body.classList.remove('scan-error-flash-body'), 1500);
        
        // Bordures rouges + glow — ON reste activé jusqu'à scan valide
        scanInterface.classList.remove('scan-success-flash');
        scanInterface.classList.add('scan-error-flash');
        // PAS de setTimeout pour retirer scan-error-flash — il reste actif
    }

    function triggerSuccessFlash() {
        // Retirer le flash erreur dès qu'un scan est valide
        scanInterface.classList.remove('scan-error-flash');
        scanInterface.classList.add('scan-success-flash');
        setTimeout(() => {
            scanInterface.classList.remove('scan-success-flash');
        }, 800);
    }

    // ===== MISE À JOUR DU BADGE GROUPAGE =====
    function updateGroupageBadge(data) {
        const container = document.getElementById('groupageBadgeContainer');
        const badge = document.getElementById('groupageBadge');
        const parentSpan = document.getElementById('groupageParentNumber');
        const countSpan = document.getElementById('groupageChildCount');
        const stopBtn = document.getElementById('stopGroupageBtn');

        if (data.is_grouping) {
            container.style.display = 'block';
            if (parentSpan) parentSpan.textContent = data.parent_package_number || '';
            if (countSpan) {
                countSpan.textContent = data.child_count || 0;
                // Animation bounce
                countSpan.classList.remove('updated');
                void countSpan.offsetWidth; // Force reflow
                countSpan.classList.add('updated');
            }
        } else {
            container.style.display = 'none';
        }
    }

    // ===== ARRÊT DU GROUPAGE (AJAX) =====
    document.getElementById('stopGroupageBtn')?.addEventListener('click', function() {
        // Rescanner le parent pour terminer le groupage
        const parentNumber = document.getElementById('groupageParentNumber')?.textContent;
        if (parentNumber && colisQrPayloadInput) {
            colisQrPayloadInput.value = parentNumber;
            colisQrPayloadInput.focus();
            colisQrPayloadInput.select();
            showScanToast('Rescanner le parent pour terminer le groupage', 'info');
        }
    });

    // ===== MISE À JOUR DE LA PROGRESS BAR =====
    function updateProgressBar(colisCount, maxColis) {
        const bar = document.getElementById('paletteProgressBar');
        const badge = document.querySelector('.card-header.bg-success .badge');
        const countDisplay = document.getElementById('scannedCountDisplay');
        
        if (bar) {
            const pct = maxColis > 0 ? Math.min(100, Math.round((colisCount / maxColis) * 100)) : 0;
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
            bar.classList.toggle('bg-success', pct < 75);
            bar.classList.toggle('bg-warning', pct >= 75 && pct < 100);
        }
        if (badge) badge.textContent = colisCount + ' / ' + (maxColis || '∞') + ' colis';
        if (countDisplay) countDisplay.textContent = 'Total: ' + colisCount;
    }

    // ===== MISE À JOUR DES DERNIERS SCANS =====
    function updateRecentScans(data) {
        const container = document.getElementById('recentScansContainer');
        const list = document.getElementById('recentScansList');
        if (!container || !list) return;

        container.style.display = 'block';

        // Ajouter le nouveau scan en haut
        const li = document.createElement('li');
        li.className = 'list-group-item py-1 px-2 small d-flex justify-content-between align-items-center recent-scan-item';
        li.style.borderLeft = '3px solid #28a745';
        li.innerHTML = '<span><strong>' + (data.package_number || '') + '</strong><span class="text-muted ms-2">' + (data.of_number || '') + '</span></span>' +
                       '<small class="text-muted">' + (data.time || '') + '</small>';
        list.prepend(li);

        // Limiter à 5 éléments
        while (list.children.length > 5) {
            list.removeChild(list.lastChild);
        }
    }

    // ===== SOUMISSION AJAX DU SCAN =====
    if (colisScanForm && colisQrPayloadInput) {
        // Désactiver la soumission standard
        colisScanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const payload = colisQrPayloadInput.value.trim();
            if (!payload) return;

            // Ajouter un indicateur visuel
            colisQrPayloadInput.disabled = true;
            colisQrPayloadInput.placeholder = 'Scan en cours...';

            // Envoyer en AJAX
            fetch(baseUrl + '/colis/process-scan-ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': window.csrfToken ? window.csrfToken() : ''
                },
                body: new URLSearchParams({
                    'colis_qr_payload': payload
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                // Succès
                triggerSuccessFlash();
                showScanToast(data.message || 'Colis scanné avec succès', 'success');
                
                // Mettre à jour l'interface
                if (data.colis_count !== undefined && data.max_colis !== undefined) {
                    updateProgressBar(data.colis_count, data.max_colis);
                }
                if (data.last_scan) {
                    updateRecentScans(data.last_scan);
                }
                if (data.is_grouping !== undefined) {
                    updateGroupageBadge(data);
                }

                // Afficher l'heure du dernier scan
                const timeSpan = document.getElementById('lastScanTime');
                if (timeSpan) {
                    timeSpan.textContent = 'Dernier scan: ' + new Date().toLocaleTimeString('fr-FR');
                }

                // Vider et focus
                colisQrPayloadInput.value = '';
                colisQrPayloadInput.disabled = false;
                colisQrPayloadInput.placeholder = 'Scanner le QR code du colis ici...';
                
                // Animation de validation visuelle sur le champ
                colisQrPayloadInput.style.borderColor = '#28a745';
                setTimeout(() => {
                    colisQrPayloadInput.style.borderColor = '';
                }, 500);

                setTimeout(function() {
                    colisQrPayloadInput.focus();
                }, 50);
            })
            .catch(error => {
                // Erreur
                const errMsg = error.message || error.error || 'Erreur lors du scan';
                triggerErrorFlash();
                showScanToast(errMsg, 'error');
                
                colisQrPayloadInput.disabled = false;
                colisQrPayloadInput.placeholder = 'Scanner le QR code du colis ici...';
                colisQrPayloadInput.value = payload; // Conserver pour correction
                colisQrPayloadInput.style.borderColor = '#dc3545';
                setTimeout(() => {
                    colisQrPayloadInput.style.borderColor = '';
                }, 1500);

                setTimeout(function() {
                    colisQrPayloadInput.focus();
                    colisQrPayloadInput.select();
                }, 100);
            });
        });

        // Soumission sur Enter
        colisQrPayloadInput.addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.value.trim() !== '') {
                    colisScanForm.dispatchEvent(new Event('submit'));
                }
            }
        });
    }

    // ===== ACTIVATION PALETTE EN AJAX =====
    const paletteForm = document.getElementById('paletteActivationForm');
    if (paletteForm && paletteCodeInput) {
        paletteForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const code = paletteCodeInput.value.trim();
            if (!code) return;

            paletteCodeInput.disabled = true;
            document.getElementById('activatePaletteBtn').disabled = true;
            document.getElementById('activatePaletteBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Activation...';

            fetch(baseUrl + '/colis/process-palette-scan-ajax', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': window.csrfToken ? window.csrfToken() : ''
                },
                body: new URLSearchParams({
                    'palette_code_input': code
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => Promise.reject(err));
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showScanToast(data.message || 'Palette activée', 'success');
                    // Recharger la page pour voir la nouvelle interface
                    setTimeout(() => window.location.reload(), 500);
                }
            })
            .catch(error => {
                const errMsg = error.message || error.error || 'Erreur d\'activation';
                showScanToast(errMsg, 'error');
                paletteCodeInput.disabled = false;
                document.getElementById('activatePaletteBtn').disabled = false;
                document.getElementById('activatePaletteBtn').innerHTML = 'Activer Palette';
                paletteCodeInput.focus();
                paletteCodeInput.select();
            });
        });
    }

    // ===== GESTION DES ERREURS PRÉEXISTANTES (flash session -> toast) =====
    <?php if (isset($_SESSION[FLASH_SUCCESS])): ?>
    showScanToast('<?= addslashes($_SESSION[FLASH_SUCCESS]) ?>', 'success');
    <?php unset($_SESSION[FLASH_SUCCESS]); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION[FLASH_ERROR])): ?>
    showScanToast('<?= addslashes($_SESSION[FLASH_ERROR]) ?>', 'error');
    triggerErrorFlash();
    <?php unset($_SESSION[FLASH_ERROR]); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION[FLASH_INFO])): ?>
    showScanToast('<?= addslashes($_SESSION[FLASH_INFO]) ?>', 'info');
    <?php unset($_SESSION[FLASH_INFO]); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION[FLASH_WARNING])): ?>
    showScanToast('<?= addslashes($_SESSION[FLASH_WARNING]) ?>', 'warning');
    <?php unset($_SESSION[FLASH_WARNING]); ?>
    <?php endif; ?>
});
</script>