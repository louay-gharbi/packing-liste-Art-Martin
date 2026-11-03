<?php
// src/Views/receptions/scan.php
// Variables attendues: $reception, $packingList, $state, $recentScans, $baseUrl
use App\Lib\Auth;

$counts = $state['counts'];
?>
<style>
    .reception-container { max-width: 1000px; margin: auto; }
    #receptionScanInput { font-size: 1.3rem; text-align: center; }
    #receptionScanInput:focus { animation: scanPulse 1.5s infinite; border-color: #28a745; }
    @keyframes scanPulse {
        0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
    }
    .reception-counter { text-align: center; min-width: 90px; }
    .reception-counter .value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }
    .reception-counter .label { font-size: 0.7rem; text-transform: uppercase; color: #6c757d; letter-spacing: .5px; }

    /* Retours visuels (repris de colis/scan.php) */
    @keyframes flashErrorOverlay { 0% { opacity: 0; } 3% { opacity: 0.7; } 8% { opacity: 0.7; } 12% { opacity: 0; } 100% { opacity: 0; } }
    .scan-error-overlay { display: none; position: fixed; inset: 0; z-index: 99998; background: #8B0000; pointer-events: none; }
    .scan-error-overlay.active { display: block; animation: flashErrorOverlay 0.8s ease-out; }
    @keyframes screenShake { 0%,35%,100% { transform: translateX(0); } 5% { transform: translateX(-15px) rotate(-1deg); } 10% { transform: translateX(15px) rotate(1deg); } 15% { transform: translateX(-10px); } 20% { transform: translateX(10px); } 25% { transform: translateX(-5px); } 30% { transform: translateX(5px); } }
    .scan-error-flash-body { animation: screenShake 1.5s ease-out !important; }
    .scan-error-flash #receptionScanInput { background-color: #fff5f5; border: 3px solid #dc3545; color: #8B0000; font-weight: bold; }
    @keyframes successFlash { 0% { background-color: transparent; } 30% { background-color: #d4edda; } 100% { background-color: transparent; } }
    .scan-success-flash .reception-scan-card { animation: successFlash 0.8s ease-out; }
    @keyframes warningFlash { 0% { background-color: transparent; } 30% { background-color: #fff3cd; } 100% { background-color: transparent; } }
    .scan-warning-flash .reception-scan-card { animation: warningFlash 1s ease-out; }

    .scan-toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
    .scan-toast { pointer-events: auto; min-width: 280px; max-width: 420px; padding: 12px 20px; border-radius: 8px; color: white; font-weight: 500;
                  box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; animation: slideInRight 0.4s ease-out forwards; }
    .scan-toast-success { background: #28a745; } .scan-toast-error { background: #dc3545; }
    .scan-toast-info { background: #17a2b8; } .scan-toast-warning { background: #ffc107; color: #333; }
    @keyframes slideInRight { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    .reception-palettes-table .js-toggle-colis i { transition: transform .2s; }
    .reception-palettes-table .js-toggle-colis.open i { transform: rotate(90deg); }
    @media (max-width: 576px) {
        .reception-counter { min-width: 70px; }
        .reception-counter .value { font-size: 1.1rem; }
    }
</style>

<div class="scan-toast-container" id="scanToastContainer"></div>
<div class="scan-error-overlay" id="scanErrorOverlay"></div>

<div class="reception-container" id="receptionContainer">

    <!-- En-tête PL + compteurs -->
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="mb-1"><i class="fas fa-truck-loading me-2"></i><?= htmlspecialchars($packingList->reference ?? '') ?></h5>
                <div class="text-muted small">
                    Site d'origine : <strong><?= htmlspecialchars($packingList->site->name ?? 'N/D') ?></strong>
                    · Expédiée le <?= $packingList ? (new DateTime($packingList->ship_date))->format('d/m/Y') : '' ?>
                    · Réception démarrée le <?= (new DateTime($reception->started_at))->format('d/m/Y H:i') ?>
                    <?= $reception->startedByName ? 'par ' . htmlspecialchars($reception->startedByName) : '' ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <div class="reception-counter"><div class="value"><span id="cntPalRecv"><?= $counts['palettes_received'] ?></span>/<span id="cntPalExp"><?= $counts['palettes_expected'] ?></span></div><div class="label">Palettes</div></div>
                <div class="reception-counter"><div class="value"><span id="cntColRecv"><?= $counts['colis_received'] ?></span>/<span id="cntColExp"><?= $counts['colis_expected'] ?></span></div><div class="label">Colis reçus</div></div>
                <div class="reception-counter"><div class="value text-warning" id="cntUnexp"><?= $counts['unexpected'] ?></div><div class="label">Inattendus</div></div>
            </div>
        </div>
    </div>

    <!-- Champ de scan unique -->
    <div class="card reception-scan-card mb-3">
        <div class="card-body">
            <form id="receptionScanForm" autocomplete="off">
                <input type="text" class="form-control form-control-lg" id="receptionScanInput"
                       placeholder="Scanner l'étiquette d'une palette…" aria-label="Scan palette" autofocus>
            </form>
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 gap-2">
                <span class="text-muted small"><i class="fas fa-info-circle me-1"></i>Réception par palette complète (filmée) : scannez l'étiquette de chaque palette, tous ses colis sont reçus.</span>
                <span class="text-muted small" id="lastScanTime"></span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-pallet me-2"></i>Palettes attendues</h6>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/report/<?= $reception->id ?>" class="small"><i class="fas fa-file-alt me-1"></i>Voir les écarts</a>
                </div>
                <div class="table-responsive" id="palettesTableContainer">
                    <?php include __DIR__ . '/_palettes_table.php'; ?>
                </div>
            </div>
            <div id="unexpectedContainer"><?php include __DIR__ . '/_unexpected.php'; ?></div>
        </div>
        <div class="col-lg-4 mt-3 mt-lg-0">
            <div id="recentScansContainer"><?php include __DIR__ . '/_recent_scans.php'; ?></div>

            <?php if (Auth::can('reception_close')): ?>
                <button type="button" class="btn btn-danger w-100 mt-3" data-bs-toggle="modal" data-bs-target="#closeReceptionModal">
                    <i class="fas fa-lock me-1"></i>Clôturer la réception
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (Auth::can('reception_close')): ?>
<!-- Modale : clôture -->
<div class="modal fade" id="closeReceptionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= htmlspecialchars($baseUrl) ?>/receptions/close/<?= $reception->id ?>">
            <?= \App\Lib\Csrf::field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i>Clôturer la réception</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <ul class="mb-3" id="closeSummary">
                    <li>Palettes reçues : <strong class="js-sum-pal"></strong></li>
                    <li>Colis reçus : <strong class="js-sum-col"></strong></li>
                    <li>Inattendus : <strong class="js-sum-unexp"></strong></li>
                </ul>
                <p class="small text-muted">
                    Les palettes non scannées seront déclarées <strong>manquantes</strong>, avec tous leurs colis.
                    <a href="<?= htmlspecialchars($baseUrl) ?>/receptions/report/<?= $reception->id ?>" target="_blank">Voir le détail des écarts</a>.
                </p>
                <label for="closeComment" class="form-label">Commentaire de réception (facultatif)</label>
                <textarea class="form-control" name="comment" id="closeComment" rows="3" maxlength="5000"></textarea>
                <div class="alert alert-warning small mt-3 mb-0">Après clôture, plus aucun scan ni modification ne sera possible.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-lock me-1"></i>Clôturer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const receptionId = <?= (int)$reception->id ?>;
    const baseUrlR = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>';
    const input = document.getElementById('receptionScanInput');
    const form = document.getElementById('receptionScanForm');
    const container = document.getElementById('receptionContainer');
    const toastContainer = document.getElementById('scanToastContainer');

    function csrf() {
        if (window.csrfToken) return window.csrfToken();
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function modalOpen() { return document.querySelector('.modal.show') !== null; }

    // ===== FOCUS PERMANENT (sauf modale ouverte) =====
    function focusScan() { if (input && !modalOpen()) input.focus(); }
    setTimeout(focusScan, 100);
    setInterval(function() { if (document.activeElement !== input && !modalOpen()) focusScan(); }, 2000);
    document.addEventListener('click', function(e) {
        const tag = e.target.tagName.toLowerCase();
        if (['input', 'button', 'select', 'textarea', 'a', 'i'].includes(tag)) return;
        setTimeout(focusScan, 50);
    });

    // ===== TOASTS & FLASHS =====
    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function toast(message, type, duration) {
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
        const t = document.createElement('div');
        t.className = 'scan-toast scan-toast-' + type;
        t.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> ' + escapeHtml(message);
        toastContainer.appendChild(t);
        setTimeout(() => t.remove(), duration || 3000);
    }
    function flash(kind) {
        container.classList.remove('scan-success-flash', 'scan-warning-flash');
        if (kind === 'error') {
            const ov = document.getElementById('scanErrorOverlay');
            ov.classList.remove('active'); void ov.offsetWidth; ov.classList.add('active');
            setTimeout(() => ov.classList.remove('active'), 800);
            document.body.classList.remove('scan-error-flash-body'); void document.body.offsetWidth;
            document.body.classList.add('scan-error-flash-body');
            setTimeout(() => document.body.classList.remove('scan-error-flash-body'), 1500);
            container.classList.add('scan-error-flash'); // reste actif jusqu'au prochain scan valide
            return;
        }
        container.classList.remove('scan-error-flash');
        void container.offsetWidth;
        container.classList.add(kind === 'warning' ? 'scan-warning-flash' : 'scan-success-flash');
        setTimeout(() => container.classList.remove('scan-success-flash', 'scan-warning-flash'), 1000);
    }

    // ===== MISE À JOUR DE L'ÉCRAN =====
    function openedColisRows() {
        return Array.from(document.querySelectorAll('.reception-colis-row:not(.d-none)')).map(r => r.id);
    }
    function applyState(data) {
        const opened = openedColisRows();
        if (data.palettes_html !== undefined) document.getElementById('palettesTableContainer').innerHTML = data.palettes_html;
        if (data.unexpected_html !== undefined) document.getElementById('unexpectedContainer').innerHTML = data.unexpected_html;
        if (data.recent_html !== undefined) document.getElementById('recentScansContainer').innerHTML = data.recent_html;
        opened.forEach(id => toggleColis(id, true));
        if (data.counts) {
            document.getElementById('cntPalRecv').textContent = data.counts.palettes_received;
            document.getElementById('cntPalExp').textContent = data.counts.palettes_expected;
            document.getElementById('cntColRecv').textContent = data.counts.colis_received;
            document.getElementById('cntColExp').textContent = data.counts.colis_expected;
            document.getElementById('cntUnexp').textContent = data.counts.unexpected;
        }
    }

    function toggleColis(rowId, forceOpen) {
        const row = document.getElementById(rowId);
        if (!row) return;
        const btn = document.querySelector('.js-toggle-colis[data-target="' + rowId + '"]');
        const open = forceOpen === true ? true : row.classList.contains('d-none');
        row.classList.toggle('d-none', !open);
        if (btn) btn.classList.toggle('open', open);
    }
    document.addEventListener('click', function(e) {
        const t = e.target.closest('.js-toggle-colis');
        if (t) toggleColis(t.dataset.target);
    });

    function post(url, params) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() },
            body: new URLSearchParams(params)
        }).then(r => r.json().catch(() => ({ error: 'Réponse invalide du serveur (' + r.status + ').' })).then(data => ({ status: r.status, data })));
    }

    // ===== SCAN =====
    let busy = false;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const value = input.value.trim();
        if (!value || busy) return;
        busy = true;
        input.disabled = true;

        post(baseUrlR + '/receptions/' + receptionId + '/scan-ajax', { scan_input: value })
            .then(({ status, data }) => {
                if (status === 403 && data.reload) { toast(data.error, 'error', 5000); setTimeout(() => location.reload(), 1500); return; }
                applyState(data);
                if (status === 200) {
                    flash('success');
                    toast(data.message, 'success');
                    input.value = '';
                } else if (status === 409 || status === 422) {
                    flash('warning');
                    toast(data.message || data.error, 'warning', status === 422 ? 5000 : 3000);
                    input.value = '';
                } else {
                    flash('error');
                    toast(data.error || 'Erreur lors du scan.', 'error', 5000);
                    input.select();
                }
                document.getElementById('lastScanTime').textContent = 'Dernier scan : ' + new Date().toLocaleTimeString('fr-FR');
            })
            .catch(() => { flash('error'); toast('Erreur réseau : scan non enregistré.', 'error', 5000); })
            .finally(() => { busy = false; input.disabled = false; setTimeout(focusScan, 50); });
    });

    // ===== CLÔTURE : résumé à jour à l'ouverture =====
    const closeModalEl = document.getElementById('closeReceptionModal');
    if (closeModalEl) {
        closeModalEl.addEventListener('show.bs.modal', function() {
            const txt = id => document.getElementById(id).textContent;
            closeModalEl.querySelector('.js-sum-pal').textContent = txt('cntPalRecv') + ' / ' + txt('cntPalExp');
            closeModalEl.querySelector('.js-sum-col').textContent = txt('cntColRecv') + ' / ' + txt('cntColExp');
            closeModalEl.querySelector('.js-sum-unexp').textContent = txt('cntUnexp');
        });
        closeModalEl.addEventListener('hidden.bs.modal', () => setTimeout(focusScan, 50));
    }
});
</script>
