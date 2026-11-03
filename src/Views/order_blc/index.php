<?php
// src/Views/order_blc/index.php
// Variables attendues: $orders, $filters, $weeks, $nbMissing, $baseUrl
?>
<style>
    .blc-input.is-saving { background-color: #fff8e1; }
    .blc-input.is-invalid { border-color: #dc3545; background-color: #fff5f5; }
    .blc-cell { min-width: 220px; }
    .blc-view { justify-content: space-between; }
    .blc-value { font-family: var(--bs-font-monospace); font-weight: 600; background: #f1f3f5; border: 1px solid #dee2e6;
                 border-radius: .25rem; padding: .1rem .6rem; min-width: 110px; }
    .blc-value:empty { background: transparent; border-color: transparent; padding: 0; min-width: 0; }
    .blc-value:empty::before { content: 'Aucun BLC'; font-family: var(--bs-body-font-family); font-weight: 400; font-style: italic; color: #adb5bd; }
    .blc-cell.is-saved .blc-value { color: #198754; background: #e8f5e9; border-color: #a3cfbb; }
    .blc-edit-btn { padding: .1rem .5rem; font-size: .8rem; }
    .blc-edit-btn.btn-light { color: #6c757d; border-color: #dee2e6; }
    .blc-edit-btn.btn-light:hover { color: #0d6efd; border-color: #0d6efd; background: #fff; }
    .blc-msg { font-size: .75rem; min-height: 1em; }
</style>

<form method="get" action="<?= htmlspecialchars($baseUrl) ?>/commandes-blc" class="row gx-2 gy-2 align-items-end mb-3">
    <div class="col-md-3">
        <label for="week" class="form-label form-label-sm mb-0 small">Semaine d'expédition</label>
        <select name="week" id="week" class="form-select form-select-sm">
            <option value="all" <?= $filters['week'] === 'all' ? 'selected' : '' ?>>Toutes les semaines</option>
            <?php foreach ($weeks as $w): ?>
                <?php $val = sprintf('%d-W%02d', $w['year'], $w['week']); ?>
                <option value="<?= $val ?>" <?= $filters['week'] === $val ? 'selected' : '' ?>>S<?= sprintf('%02d', $w['week']) ?> <?= $w['year'] ?> — <?= $w['nb_pl'] ?> PL</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label for="blc_status" class="form-label form-label-sm mb-0 small">BLC AMF</label>
        <select name="blc_status" id="blc_status" class="form-select form-select-sm">
            <option value="">Toutes les commandes</option>
            <option value="missing" <?= $filters['blc_status'] === 'missing' ? 'selected' : '' ?>>Sans BLC</option>
            <option value="filled" <?= $filters['blc_status'] === 'filled' ? 'selected' : '' ?>>Avec BLC</option>
        </select>
    </div>
    <div class="col-md-3">
        <label for="q" class="form-label form-label-sm mb-0 small">Recherche</label>
        <input type="text" name="q" id="q" class="form-control form-control-sm" placeholder="N° commande, client, code postal ou BLC" value="<?= htmlspecialchars($filters['q']) ?>">
    </div>
    <div class="col-md-4">
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-filter"></i> Filtrer</button>
        <a href="<?= htmlspecialchars($baseUrl) ?>/commandes-blc" class="btn btn-sm btn-outline-secondary" title="Réinitialiser"><i class="fas fa-times"></i></a>
    </div>
</form>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
    <div>
        <strong><?= count($orders) ?></strong> commande(s)
        · <span class="badge bg-<?= $nbMissing ? 'warning text-dark' : 'success' ?>" id="missingBadge"><span id="missingCount"><?= $nbMissing ?></span> sans BLC</span>
    </div>
    <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Cliquez sur <strong>Ajouter</strong> ou <strong>Modifier</strong>, saisissez le BLC puis <kbd>Entrée</kbd> : il est enregistré et la commande suivante sans BLC s'ouvre. Videz le champ pour retirer un BLC.</small>
</div>

<?php if (!$orders): ?>
    <div class="alert alert-info">Aucune commande pour ces critères.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle" id="blcTable">
            <thead class="table-light">
                <tr>
                    <th>N° commande</th>
                    <th>Client</th>
                    <th>Code postal</th>
                    <th class="text-center">Colis</th>
                    <th>Packing List(s)</th>
                    <th>Expédiée le</th>
                    <th style="width: 260px;">BLC AMF</th>
                    <th>Modifié</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($o['order_number']) ?></strong></td>
                    <td><?= htmlspecialchars($o['client_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($o['postal_codes'] ?? '') ?></td>
                    <td class="text-center"><?= (int)$o['nb_colis'] ?></td>
                    <td class="small"><?= htmlspecialchars($o['pl_references'] ?? '') ?></td>
                    <td class="small"><?= $o['last_ship_date'] ? (new DateTime($o['last_ship_date']))->format('d/m/Y') : '' ?></td>
                    <?php $blc = $o['blc_amf'] ?? ''; ?>
                    <td class="blc-cell" data-order="<?= htmlspecialchars($o['order_number']) ?>" data-original="<?= htmlspecialchars($blc) ?>">
                        <div class="blc-view d-flex align-items-center gap-2">
                            <span class="blc-value"><?= htmlspecialchars($blc) ?></span>
                            <button type="button" class="btn btn-sm blc-edit-btn <?= $blc === '' ? 'btn-outline-success' : 'btn-light' ?>" title="<?= $blc === '' ? 'Ajouter le BLC' : 'Modifier le BLC' ?>">
                                <?= $blc === '' ? '<i class="fas fa-plus"></i> Ajouter' : '<i class="fas fa-pen"></i> Modifier' ?>
                            </button>
                        </div>
                        <div class="blc-edit input-group input-group-sm d-none">
                            <input type="text" class="form-control blc-input" maxlength="100" autocomplete="off"
                                   value="<?= htmlspecialchars($blc) ?>" placeholder="BLC AMF" aria-label="BLC AMF commande <?= htmlspecialchars($o['order_number']) ?>">
                            <button type="button" class="btn btn-success blc-save-btn" title="Enregistrer (Entrée)"><i class="fas fa-check"></i></button>
                            <button type="button" class="btn btn-outline-secondary blc-cancel-btn" title="Annuler (Échap)"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="blc-msg text-danger"></div>
                    </td>
                    <td class="small text-muted blc-updated">
                        <?= $o['updated_at'] ? (new DateTime($o['updated_at']))->format('d/m/Y H:i') . ' · ' . htmlspecialchars($o['updated_by_name'] ?? '') : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const baseUrlBlc = '<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>';
    const cells = Array.from(document.querySelectorAll('.blc-cell'));
    const missingCount = document.getElementById('missingCount');
    const missingBadge = document.getElementById('missingBadge');

    function csrf() {
        if (window.csrfToken) return window.csrfToken();
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function refreshMissing() {
        const n = cells.filter(c => c.dataset.original === '').length;
        missingCount.textContent = n;
        missingBadge.className = 'badge bg-' + (n ? 'warning text-dark' : 'success');
    }
    function isEditing(cell) { return !cell.querySelector('.blc-edit').classList.contains('d-none'); }

    // Affichage : BLC + bouton « Ajouter » (vide) ou « Modifier » (rempli).
    function showView(cell) {
        const blc = cell.dataset.original;
        const btn = cell.querySelector('.blc-edit-btn');
        cell.querySelector('.blc-value').textContent = blc;
        btn.className = 'btn btn-sm blc-edit-btn ' + (blc === '' ? 'btn-outline-success' : 'btn-light');
        btn.title = blc === '' ? 'Ajouter le BLC' : 'Modifier le BLC';
        btn.innerHTML = blc === '' ? '<i class="fas fa-plus"></i> Ajouter' : '<i class="fas fa-pen"></i> Modifier';
        cell.querySelector('.blc-edit').classList.add('d-none');
        cell.querySelector('.blc-view').classList.remove('d-none');
    }
    function showEdit(cell) {
        const input = cell.querySelector('.blc-input');
        input.value = cell.dataset.original;
        input.classList.remove('is-invalid');
        cell.querySelector('.blc-msg').textContent = '';
        cell.classList.remove('is-saved');
        cell.querySelector('.blc-view').classList.add('d-none');
        cell.querySelector('.blc-edit').classList.remove('d-none');
        input.focus();
        input.select();
    }
    function cancel(cell) {
        cell.querySelector('.blc-msg').textContent = '';
        showView(cell);
    }

    function save(cell) {
        const input = cell.querySelector('.blc-input');
        const value = input.value.trim();
        input.value = value;
        if (cell.dataset.saving === '1') return Promise.resolve(false);
        if (value === cell.dataset.original) { showView(cell); return Promise.resolve(true); }
        const msg = cell.querySelector('.blc-msg');
        cell.dataset.saving = '1';
        input.classList.remove('is-invalid');
        input.classList.add('is-saving');
        return fetch(baseUrlBlc + '/commandes-blc/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() },
            body: new URLSearchParams({ order_number: cell.dataset.order, blc_amf: value })
        })
            .then(r => r.json().catch(() => ({ error: 'Réponse invalide du serveur (' + r.status + ').' })).then(data => ({ status: r.status, data })))
            .then(({ status, data }) => {
                input.classList.remove('is-saving');
                if (status === 200 && data.success) {
                    cell.dataset.original = data.blc_amf;
                    msg.textContent = '';
                    cell.closest('tr').querySelector('.blc-updated').textContent = data.updated;
                    showView(cell);
                    cell.classList.add('is-saved');
                    refreshMissing();
                    return true;
                }
                input.classList.add('is-invalid');
                msg.textContent = data.error || 'Erreur lors de l\'enregistrement.';
                input.focus();
                return false;
            })
            .catch(() => { input.classList.remove('is-saving'); input.classList.add('is-invalid'); msg.textContent = 'Erreur réseau : BLC non enregistré.'; return false; })
            .finally(() => { cell.dataset.saving = ''; });
    }

    cells.forEach((cell, idx) => {
        const input = cell.querySelector('.blc-input');
        cell.querySelector('.blc-edit-btn').addEventListener('click', () => showEdit(cell));
        cell.querySelector('.blc-save-btn').addEventListener('click', () => save(cell));
        cell.querySelector('.blc-cancel-btn').addEventListener('click', () => cancel(cell));
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Saisie à la chaîne : après enregistrement, ouvrir la commande suivante sans BLC.
                save(cell).then(ok => {
                    const next = ok && cells.slice(idx + 1).find(c => c.dataset.original === '' && !isEditing(c));
                    if (next) showEdit(next);
                });
            } else if (e.key === 'Escape') {
                cancel(cell);
            }
        });
    });

    // Avertir si on quitte la page avec une saisie non enregistrée.
    window.addEventListener('beforeunload', function (e) {
        if (cells.some(c => isEditing(c) && c.querySelector('.blc-input').value.trim() !== c.dataset.original)) { e.preventDefault(); e.returnValue = ''; }
    });
});
</script>
