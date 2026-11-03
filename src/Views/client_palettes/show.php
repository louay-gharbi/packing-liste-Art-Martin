<?php
// src/Views/client_palettes/show.php
// Variables attendues: $palette, $lines, $progress, $canScan, $baseUrl
use App\Lib\Auth;

$manageUrl = $baseUrl . '/client-palettes?week=' . urlencode($palette->weekParam()) . '&client=' . urlencode($palette->destinationKey());
?>
<style>
    .cp-container { max-width: 1100px; margin: auto; }
    #cpScanInput { font-size: 1.3rem; text-align: center; }
    #cpScanInput:focus { border-color: #28a745; box-shadow: 0 0 0 .25rem rgba(40, 167, 69, .25); }
    .cp-counter { text-align: center; min-width: 90px; }
    .cp-counter .value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }
    .cp-counter .label { font-size: 0.7rem; text-transform: uppercase; color: #6c757d; letter-spacing: .5px; }

    /* Retours visuels (repris de receptions/scan.php) */
    @keyframes flashErrorOverlay { 0% { opacity: 0; } 3% { opacity: 0.7; } 8% { opacity: 0.7; } 12% { opacity: 0; } 100% { opacity: 0; } }
    .scan-error-overlay { display: none; position: fixed; inset: 0; z-index: 99998; background: #8B0000; pointer-events: none; }
    .scan-error-overlay.active { display: block; animation: flashErrorOverlay 0.8s ease-out; }
    @keyframes successFlash { 0% { background-color: transparent; } 30% { background-color: #d4edda; } 100% { background-color: transparent; } }
    .scan-success-flash .cp-scan-card { animation: successFlash 0.8s ease-out; }
    @keyframes warningFlash { 0% { background-color: transparent; } 30% { background-color: #fff3cd; } 100% { background-color: transparent; } }
    .scan-warning-flash .cp-scan-card { animation: warningFlash 1s ease-out; }
    .scan-error-flash #cpScanInput { background-color: #fff5f5; border: 3px solid #dc3545; color: #8B0000; font-weight: bold; }
    .scan-toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
    .scan-toast { pointer-events: auto; min-width: 280px; max-width: 460px; padding: 12px 20px; border-radius: 8px; color: white; font-weight: 500;
                  box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; animation: slideInRight 0.4s ease-out forwards; }
    .scan-toast-success { background: #28a745; } .scan-toast-error { background: #dc3545; } .scan-toast-warning { background: #ffc107; color: #333; }
    @keyframes slideInRight { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
</style>

<div class="scan-toast-container" id="scanToastContainer"></div>
<div class="scan-error-overlay" id="scanErrorOverlay"></div>

<div class="cp-container" id="cpContainer">
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="mb-1"><i class="fas fa-pallet me-2"></i><?= htmlspecialchars($palette->code) ?>
                    <?php if ($palette->isClosed()): ?>
                        <span class="badge bg-success align-middle">Clôturée</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark align-middle">Ouverte</span>
                    <?php endif; ?>
                </h5>
                <div class="text-muted small">
                    Client : <strong><?= htmlspecialchars($palette->client_name) ?></strong>
                    · Code postal : <strong><?= htmlspecialchars($palette->postal_code !== '' ? $palette->postal_code : 'non renseigné') ?></strong>
                    · Semaine d'expédition Tunisie S<?= sprintf('%02d', $palette->ship_week) ?> <?= $palette->ship_year ?>
                    <?php if ($palette->isClosed() && $palette->closed_at): ?>
                        · Clôturée le <?= (new DateTime($palette->closed_at))->format('d/m/Y H:i') ?> <?= $palette->closedByName ? 'par ' . htmlspecialchars($palette->closedByName) : '' ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <div class="cp-counter"><div class="value" id="cntOnPalette"><?= count($lines) ?></div><div class="label">Sur la palette</div></div>
                <div class="cp-counter"><div class="value"><span id="cntPlaced"><?= $progress['placed'] ?></span>/<span id="cntTotal"><?= $progress['total'] ?></span></div><div class="label">Colis client placés</div></div>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($manageUrl) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Palettes du client</a>
            <?php if ($palette->isClosed()): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/label" target="_blank" class="btn btn-sm btn-dark"><i class="fas fa-qrcode me-1"></i>Étiquette QR</a>
            <?php endif; ?>
            <?php if ($lines): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/export/xlsx" class="btn btn-sm btn-success"><i class="fas fa-file-excel me-1"></i>XLSX</a>
                <a href="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/export/pdf" class="btn btn-sm btn-danger"><i class="fas fa-file-pdf me-1"></i>PDF</a>
            <?php endif; ?>
            <span class="ms-auto"></span>
            <?php if (!$palette->isClosed() && Auth::can('reception_close')): ?>
                <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/close" onsubmit="return confirm('Clôturer la palette <?= htmlspecialchars($palette->code, ENT_QUOTES) ?> ?');">
                    <?= \App\Lib\Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-lock me-1"></i>Clôturer la palette</button>
                </form>
            <?php elseif ($palette->isClosed() && Auth::can('reception_close')): ?>
                <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/reopen" onsubmit="return confirm('Rouvrir la palette <?= htmlspecialchars($palette->code, ENT_QUOTES) ?> ?');">
                    <?= \App\Lib\Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-lock-open me-1"></i>Rouvrir</button>
                </form>
            <?php endif; ?>
            <?php if (!$palette->isClosed() && !$lines && Auth::can('reception_scan')): ?>
                <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/client-palettes/<?= $palette->id ?>/delete" onsubmit="return confirm('Supprimer la palette vide <?= htmlspecialchars($palette->code, ENT_QUOTES) ?> ?');">
                    <?= \App\Lib\Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Supprimer</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canScan): ?>
        <div class="card cp-scan-card mb-3">
            <div class="card-body">
                <form id="cpScanForm" autocomplete="off">
                    <input type="text" class="form-control form-control-lg" id="cpScanInput"
                           placeholder="Scanner un colis de <?= htmlspecialchars($palette->destinationKey()) ?>…" aria-label="Scan colis" autofocus>
                </form>
                <div class="d-flex justify-content-between mt-2">
                    <span class="text-muted small"><i class="fas fa-info-circle me-1"></i>Seuls les colis reçus cette semaine pour ce client et ce code postal sont acceptés. Groupages : scannez les colis enfants.</span>
                    <span class="text-muted small" id="lastScanTime"></span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="<?= $palette->isClosed() ? 'col-12' : 'col-lg-7' ?>">
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="fas fa-boxes me-2"></i>Colis sur la palette</h6></div>
                <div class="table-responsive" id="linesContainer"><?php include __DIR__ . '/_lines.php'; ?></div>
            </div>
        </div>
        <?php if (!$palette->isClosed()): ?>
            <div class="col-lg-5" id="remainingContainer"><?php include __DIR__ . '/_remaining.php'; ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canScan): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const paletteId = <?= (int)$palette->id ?>;
    const baseUrlCp = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>';
    const input = document.getElementById('cpScanInput');
    const form = document.getElementById('cpScanForm');
    const container = document.getElementById('cpContainer');
    const toastContainer = document.getElementById('scanToastContainer');

    function csrf() {
        if (window.csrfToken) return window.csrfToken();
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function focusScan() { if (input) input.focus(); }
    setTimeout(focusScan, 100);
    setInterval(function() { if (document.activeElement !== input) focusScan(); }, 2000);

    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function toast(message, type, duration) {
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle' };
        const t = document.createElement('div');
        t.className = 'scan-toast scan-toast-' + type;
        t.innerHTML = '<i class="fas ' + icons[type] + '"></i> ' + escapeHtml(message);
        toastContainer.appendChild(t);
        setTimeout(() => t.remove(), duration || 3000);
    }
    function flash(kind) {
        container.classList.remove('scan-success-flash', 'scan-warning-flash');
        if (kind === 'error') {
            const ov = document.getElementById('scanErrorOverlay');
            ov.classList.remove('active'); void ov.offsetWidth; ov.classList.add('active');
            setTimeout(() => ov.classList.remove('active'), 800);
            container.classList.add('scan-error-flash');
            return;
        }
        container.classList.remove('scan-error-flash');
        void container.offsetWidth;
        container.classList.add(kind === 'warning' ? 'scan-warning-flash' : 'scan-success-flash');
        setTimeout(() => container.classList.remove('scan-success-flash', 'scan-warning-flash'), 1000);
    }
    function applyState(data) {
        if (data.lines_html !== undefined) document.getElementById('linesContainer').innerHTML = data.lines_html;
        if (data.remaining_html !== undefined) document.getElementById('remainingContainer').innerHTML = data.remaining_html;
        if (data.counts) {
            document.getElementById('cntOnPalette').textContent = data.counts.on_palette;
            document.getElementById('cntPlaced').textContent = data.counts.placed;
            document.getElementById('cntTotal').textContent = data.counts.total;
        }
    }

    let busy = false;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const value = input.value.trim();
        if (!value || busy) return;
        busy = true;
        input.disabled = true;
        fetch(baseUrlCp + '/client-palettes/' + paletteId + '/scan-ajax', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() },
            body: new URLSearchParams({ scan_input: value })
        })
            .then(r => r.json().catch(() => ({ error: 'Réponse invalide du serveur (' + r.status + ').' })).then(data => ({ status: r.status, data })))
            .then(({ status, data }) => {
                if (status === 403 && data.reload) { toast(data.error, 'error', 5000); setTimeout(() => location.reload(), 1500); return; }
                applyState(data);
                if (status === 200) {
                    flash('success'); toast(data.message, 'success'); input.value = '';
                } else if (status === 409) {
                    flash('warning'); toast(data.message || data.error, 'warning'); input.value = '';
                } else {
                    flash('error'); toast(data.error || 'Erreur lors du scan.', 'error', 6000); input.select();
                }
                document.getElementById('lastScanTime').textContent = 'Dernier scan : ' + new Date().toLocaleTimeString('fr-FR');
            })
            .catch(() => { flash('error'); toast('Erreur réseau : scan non enregistré.', 'error', 5000); })
            .finally(() => { busy = false; input.disabled = false; setTimeout(focusScan, 50); });
    });
});
</script>
<?php endif; ?>
