<?php
// src/Views/dashboard/index.php
// Variables : $pageTitle, $baseUrl, $appName, $request, $currentUser,
//             $activeScanPalette, $stats, $pendingPackingLists, $currentUserSiteName,
//             $availableSitesForFilter, $currentDashboardSiteFilter,
//             $dailyScansStats, $recentActivity

use App\Lib\Auth;
use App\Models\PackingList as PLModel;
use App\Models\Palette as PaletteModel;
use App\Models\Site as SiteModel;
?>

<style>
/* ===== DASHBOARD V2 ===== */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
}

/* Top bar (bandeau dashboard) */
.dash-topbar {
    position: relative;
    background: #1e293b;            /* slate 800 — neutre, institutionnel */
    border-radius: 10px;
    padding: 22px 28px;
    color: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    overflow: hidden;
}
/* Filet d'accent discret à gauche */
.dash-topbar::before {
    content: "";
    position: absolute;
    top: 0; left: 0; bottom: 0;
    width: 4px;
    background: #3b82f6;            /* bleu accent sobre */
}
.dash-topbar .topbar-main { min-width: 0; }
.dash-topbar .topbar-title {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0;
    letter-spacing: 0.01em;
    color: #f8fafc;
}
.dash-topbar .topbar-meta {
    margin: 6px 0 0;
    font-size: 0.8rem;
    color: #94a3b8;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
}
.dash-topbar .topbar-meta .meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.dash-topbar .topbar-meta .meta-sep { color: #475569; }
.dash-topbar .topbar-meta .meta-value { color: #cbd5e1; font-weight: 500; }
.dash-topbar .topbar-time { text-align: right; flex-shrink: 0; }
.dash-topbar .topbar-time .current-time {
    font-size: 1.5rem;
    font-weight: 600;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    color: #f1f5f9;
}
.dash-topbar .topbar-time .current-date {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 2px;
}

/* Widget cards */
.dashboard-widget {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #e9ecef;
    overflow: hidden;
}
.dashboard-widget .widget-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.dashboard-widget .widget-header h5 {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
}
.dashboard-widget .widget-body { padding: 16px 20px; }
.dashboard-widget .widget-body:last-child { padding-bottom: 20px; }

/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.kpi-card {
    background: #f8f9fc;
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    border: 1px solid #e9ecef;
    transition: transform 0.15s;
}
.kpi-card:hover { transform: translateY(-2px); }
.kpi-card .kpi-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    font-size: 1.1rem;
}
.kpi-card .kpi-value {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.2;
}
.kpi-card .kpi-label {
    font-size: 0.78rem;
    color: #6c757d;
    margin-top: 2px;
}
.kpi-icon-pl { background: #e8f5e9; color: #2e7d32; }
.kpi-icon-palette { background: #e3f2fd; color: #1565c0; }
.kpi-icon-colis { background: #fce4ec; color: #c62828; }
.kpi-icon-close { background: #fff3e0; color: #e65100; }

/* Palette active widget */
.active-palette-card {
    display: flex;
    align-items: center;
    gap: 16px;
}
.active-palette-card .palette-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #28a745, #20c997);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.active-palette-card .palette-details { flex: 1; min-width: 0; }
.active-palette-card .palette-details .palette-code {
    font-weight: 700;
    font-size: 1.1rem;
}
.active-palette-card .palette-details .palette-meta {
    font-size: 0.8rem;
    color: #6c757d;
}
.active-palette-card .palette-details .progress {
    height: 8px;
    margin-top: 6px;
    background: #e9ecef;
}
.active-palette-card .palette-actions { flex-shrink: 0; }

/* Graphique barres */
.activity-chart {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    height: 120px;
    padding-top: 8px;
}
.activity-chart .bar-wrapper {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
    justify-content: flex-end;
}
.activity-chart .bar {
    width: 100%;
    max-width: 40px;
    min-height: 4px;
    border-radius: 4px 4px 0 0;
    background: linear-gradient(180deg, #667eea, #764ba2);
    transition: height 0.3s ease;
    position: relative;
}
.activity-chart .bar-wrapper:hover .bar {
    opacity: 0.8;
}
.activity-chart .bar-label {
    font-size: 0.6rem;
    color: #6c757d;
    margin-top: 4px;
    text-transform: uppercase;
}
.activity-chart .bar-count {
    font-size: 0.65rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 2px;
}

/* Recent activity */
.activity-feed { list-style: none; padding: 0; margin: 0; }
.activity-feed li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.85rem;
}
.activity-feed li:last-child { border-bottom: none; }
.activity-feed .activity-time {
    color: #adb5bd;
    font-size: 0.75rem;
    flex-shrink: 0;
    min-width: 48px;
}
.activity-feed .activity-icon {
    width: 28px; height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    flex-shrink: 0;
}
.activity-feed .activity-text { flex: 1; min-width: 0; }
.activity-feed .activity-text strong { font-weight: 600; }
.activity-feed .activity-text .text-muted { font-size: 0.8rem; }

/* Tasks list */
.task-list { list-style: none; padding: 0; margin: 0; }
.task-list li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}
.task-list li:last-child { border-bottom: none; }
.task-list .task-priority {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.task-list .task-info { flex: 1; min-width: 0; }
.task-list .task-info .task-ref {
    font-weight: 600;
    font-size: 0.85rem;
}
.task-list .task-info .task-meta {
    font-size: 0.75rem;
    color: #6c757d;
}

/* Quick actions */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 8px;
}
.quick-actions a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
    text-decoration: none;
    color: #495057;
    font-size: 0.82rem;
    transition: all 0.15s;
}
.quick-actions a:hover {
    background: #f0f4ff;
    border-color: #0d6efd;
    color: #0d6efd;
}

/* Responsive */
@media (min-width: 768px) {
    .dashboard-grid {
        grid-template-columns: 1fr 1fr;
    }
    .dashboard-grid .dashboard-full { grid-column: 1 / -1; }
}
</style>

<div class="dashboard-grid">

    <!-- ===== BANDEAU DASHBOARD ===== -->
    <div class="dashboard-full dash-topbar">
        <div class="topbar-main">
            <h2 class="topbar-title">Tableau de bord opérationnel</h2>
            <p class="topbar-meta">
                <span class="meta-item">
                    <i class="fas fa-warehouse"></i>
                    <span class="meta-value"><?= htmlspecialchars($currentUserSiteName) ?></span>
                </span>
                <span class="meta-sep">|</span>
                <span class="meta-item">
                    <i class="fas fa-user-shield"></i>
                    <span class="meta-value"><?= htmlspecialchars(implode(', ', array_map(function($r) { return $r->name; }, $currentUser->roles ?? []))) ?></span>
                </span>
            </p>
        </div>
        <div class="topbar-time">
            <div class="current-time" id="dashboardClock">--:--:--</div>
            <div class="current-date" id="dashboardDate">--</div>
        </div>
    </div>

    <!-- ===== FILTRE SITE ===== -->
    <?php if (Auth::can('dashboard_view_kpis_all_sites') && !empty($availableSitesForFilter) && count($availableSitesForFilter) > 1): ?>
    <div class="dashboard-full" style="margin-top: -0.5rem;">
        <form method="get" action="<?= htmlspecialchars($baseUrl) ?>/" class="d-flex align-items-center gap-2" style="justify-content: flex-end;">
            <label for="dashboard_site_filter" class="small text-muted mb-0"><i class="fas fa-filter me-1"></i>Site :</label>
            <select name="dashboard_site_filter" id="dashboard_site_filter" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                <option value="">Global (Tous Sites)</option>
                <?php foreach ($availableSitesForFilter as $site): ?>
                    <option value="<?= $site->id ?>" <?= ($currentDashboardSiteFilter == $site->id) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($site->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <!-- ===== LIGNE 1 : PALETTE ACTIVE + KPIs ===== -->
    <?php if (Auth::can('dashboard_view_operator_widget') || Auth::can('dashboard_view_kpis_own_site') || Auth::can('dashboard_view_kpis_all_sites')): ?>
    
        <?php if (Auth::can('dashboard_view_operator_widget')): ?>
        <div class="dashboard-widget">
            <div class="widget-header">
                <h5><i class="fas fa-pallet text-success me-2"></i>Palette Active</h5>
                <?php if (Auth::can('colis_scan')): ?>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-qrcode me-1"></i>Scan
                    </a>
                <?php endif; ?>
            </div>
            <div class="widget-body">
                <?php if (isset($activeScanPalette) && $activeScanPalette instanceof PaletteModel): ?>
                    <div class="active-palette-card">
                        <div class="palette-icon"><i class="fas fa-pallet"></i></div>
                        <div class="palette-details">
                            <div class="palette-code"><?= htmlspecialchars($activeScanPalette->code) ?></div>
                            <div class="palette-meta">
                                PL: <?= htmlspecialchars($activeScanPalette->packingListReference ?? 'N/A') ?>
                                <?php if (!empty($activeScanPalette->siteName)): ?> · <?= htmlspecialchars($activeScanPalette->siteName) ?><?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?= $activeScanPalette->getColisCount() ?> / <?= htmlspecialchars($activeScanPalette->max_colis ?? '∞') ?> colis</span>
                                <span class="fw-bold">
                                    <?php $pct = ($activeScanPalette->max_colis > 0) ? round(($activeScanPalette->getColisCount() / $activeScanPalette->max_colis) * 100) : 0; ?>
                                    <?= $pct ?>%
                                </span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($pct >= 75 ? 'bg-warning' : 'bg-primary') ?>"
                                     style="width: <?= $pct ?>%; transition: width 0.5s ease;"></div>
                            </div>
                        </div>
                        <div class="palette-actions">
                            <a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan" class="btn btn-success btn-sm">
                                <i class="fas fa-arrow-right me-1"></i>Continuer
                            </a>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/colis/deactivate-palette-scan" class="btn btn-sm btn-outline-secondary mt-1" title="Désactiver">
                                <i class="fas fa-power-off"></i>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-3">
                        <p class="text-muted mb-2"><i class="fas fa-pallet fa-2x"></i></p>
                        <p class="text-muted mb-2">Aucune palette active</p>
                        <?php if (Auth::can('colis_scan')): ?>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/colis/scan" class="btn btn-primary btn-sm">
                                <i class="fas fa-play-circle me-1"></i>Démarrer un scan
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (Auth::can('dashboard_view_kpis_own_site') || Auth::can('dashboard_view_kpis_all_sites')): ?>
        <div class="dashboard-widget">
            <div class="widget-header">
                <h5><i class="fas fa-chart-line text-primary me-2"></i>KPIs · <?= htmlspecialchars($currentUserSiteName) ?></h5>
            </div>
            <div class="widget-body">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon kpi-icon-pl"><i class="fas fa-list-alt"></i></div>
                        <div class="kpi-value text-success"><?= (int)($stats['packingListsOpen'] ?? 0) ?></div>
                        <div class="kpi-label">PL Ouvertes</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon kpi-icon-palette"><i class="fas fa-pallet"></i></div>
                        <div class="kpi-value text-info"><?= (int)($stats['palettesOpen'] ?? 0) ?></div>
                        <div class="kpi-label">Palettes Ouvertes</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon kpi-icon-colis"><i class="fas fa-box"></i></div>
                        <div class="kpi-value text-danger"><?= (int)($stats['colisScannedToday'] ?? 0) ?></div>
                        <div class="kpi-label">Colis Aujourd'hui</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon kpi-icon-close"><i class="fas fa-check-circle"></i></div>
                        <div class="kpi-value text-warning"><?= (int)($stats['packingListsClosedToday'] ?? 0) ?></div>
                        <div class="kpi-label">PL Fermées Auj.</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ===== LIGNE 2 : GRAPHIQUE ACTIVITÉ + ACCÈS RAPIDES ===== -->
    <?php if (!empty($dailyScansStats) && (Auth::can('dashboard_view_kpis_own_site') || Auth::can('dashboard_view_kpis_all_sites'))): ?>
    <div class="dashboard-widget">
        <div class="widget-header">
            <h5><i class="fas fa-chart-bar text-purple me-2" style="color:#764ba2;"></i>Activité Hebdomadaire</h5>
            <small class="text-muted">Colis scannés par jour</small>
        </div>
        <div class="widget-body">
            <?php
                $maxCount = 1;
                foreach ($dailyScansStats as $d) { if ($d['count'] > $maxCount) $maxCount = $d['count']; }
                $dayLabels = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
            ?>
            <div class="activity-chart" id="activityChart">
                <?php foreach ($dailyScansStats as $i => $day): 
                    $height = $maxCount > 0 ? max(4, round(($day['count'] / $maxCount) * 100)) : 4;
                    $dayIndex = (int)date('N', strtotime($day['date'])) - 1;
                    $label = $dayLabels[$dayIndex] ?? substr($day['date'], 8);
                ?>
                    <div class="bar-wrapper" title="<?= htmlspecialchars((new DateTime($day['date']))->format('d/m/Y')) ?> : <?= $day['count'] ?> colis">
                        <span class="bar-count"><?= $day['count'] ?></span>
                        <div class="bar" style="height: <?= $height ?>%;"></div>
                        <span class="bar-label"><?= $label ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="dashboard-widget">
        <div class="widget-header">
            <h5><i class="fas fa-bolt text-warning me-2"></i>Accès Rapides</h5>
        </div>
        <div class="widget-body">
            <div class="quick-actions">
                <?php if (Auth::can('packinglist_read_all')): ?>
                    <a href="<?= $baseUrl ?>/packing-lists"><i class="fas fa-list-alt text-success"></i> Packing Lists</a>
                <?php endif; ?>
                <?php if (Auth::can('palette_read_all')): ?>
                    <a href="<?= $baseUrl ?>/palettes"><i class="fas fa-pallet text-info"></i> Palettes</a>
                <?php endif; ?>
                <?php if (Auth::can('colis_scan')): ?>
                    <a href="<?= $baseUrl ?>/colis/scan"><i class="fas fa-qrcode text-danger"></i> Scan Colis</a>
                <?php endif; ?>
                <?php if (Auth::can('theoretical_import_csv')): ?>
                    <a href="<?= $baseUrl ?>/theoretical-exports/import"><i class="fas fa-file-import text-warning"></i> Import Théorique</a>
                <?php endif; ?>
                <?php if (Auth::can('user_manage')): ?>
                    <a href="<?= $baseUrl ?>/users"><i class="fas fa-users-cog text-dark"></i> Utilisateurs</a>
                <?php endif; ?>
                <?php if (Auth::can('search')): ?>
                    <a href="<?= $baseUrl ?>/search"><i class="fas fa-search text-secondary"></i> Recherche</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== LIGNE 3 : TÂCHES EN ATTENTE + ACTIVITÉ RÉCENTE ===== -->
    <?php if (Auth::can('dashboard_view_pending_tasks_own_site') || Auth::can('dashboard_view_pending_tasks_all_sites')): ?>
    <div class="dashboard-widget">
        <div class="widget-header">
            <h5>
                <i class="fas fa-exclamation-circle text-warning me-2"></i>Tâches en Attente
                <span class="badge bg-warning text-dark ms-1"><?= count($pendingPackingLists ?? []) ?></span>
            </h5>
            <?php if (Auth::can('packinglist_read_all') && count($pendingPackingLists ?? []) > 0): ?>
                <a href="<?= $baseUrl ?>/packing-lists?filter_status=open" class="btn btn-sm btn-outline-warning">Voir tout</a>
            <?php endif; ?>
        </div>
        <div class="widget-body">
            <?php if (isset($pendingPackingLists) && !empty($pendingPackingLists)): ?>
                <ul class="task-list">
                    <?php foreach(array_slice($pendingPackingLists, 0, 6) as $pl): ?>
                        <?php if (!$pl instanceof PLModel) continue; 
                            $daysLeft = (new DateTime())->diff(new DateTime($pl->ship_date))->days;
                            $isUrgent = $daysLeft <= 2 && $pl->ship_date >= date('Y-m-d');
                            $isOverdue = $pl->ship_date < date('Y-m-d');
                        ?>
                        <li>
                            <span class="task-priority" style="background: <?= $isOverdue ? '#dc3545' : ($isUrgent ? '#ffc107' : '#28a745') ?>;"></span>
                            <div class="task-info">
                                <div class="task-ref">
                                    <a href="<?= $baseUrl ?>/packing-lists/show/<?= $pl->id ?>"><?= htmlspecialchars($pl->reference) ?></a>
                                </div>
                                <div class="task-meta">
                                    Site: <?= htmlspecialchars($pl->site->name ?? ($pl->site_id ? 'ID '.$pl->site_id : 'N/A')) ?> · 
                                    Exp: <?= (new DateTime($pl->ship_date))->format('d/m/Y') ?>
                                    <?php if ($isOverdue): ?>
                                        <span class="text-danger fw-bold">(DÉPASSÉE)</span>
                                    <?php elseif ($isUrgent): ?>
                                        <span class="text-warning fw-bold">(J-<?= $daysLeft ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?= $baseUrl ?>/packing-lists/show/<?= $pl->id ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
                                <i class="fas fa-eye fa-xs"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted text-center py-3 mb-0">
                    <i class="fas fa-check-circle text-success fa-2x mb-2 d-block"></i>
                    Aucune tâche en attente
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentActivity) && (Auth::can('dashboard_view_kpis_own_site') || Auth::can('dashboard_view_kpis_all_sites'))): ?>
    <div class="dashboard-widget">
        <div class="widget-header">
            <h5><i class="fas fa-stream text-info me-2"></i>Activité Récente</h5>
            <small class="text-muted">Derniers colis scannés</small>
        </div>
        <div class="widget-body">
            <ul class="activity-feed">
                <?php foreach ($recentActivity as $act): ?>
                    <li>
                        <span class="activity-time"><?= (new DateTime($act['scan_date_time']))->format('H:i') ?></span>
                        <span class="activity-icon" style="background: #e3f2fd; color: #1565c0;">
                            <i class="fas fa-box"></i>
                        </span>
                        <span class="activity-text">
                            <strong><?= htmlspecialchars($act['package_number']) ?></strong>
                            <?php if (!empty($act['of_number'])): ?>
                                <span class="text-muted">· OF <?= htmlspecialchars($act['of_number']) ?></span>
                            <?php endif; ?>
                            <br>
                            <span class="text-muted">
                                <?= htmlspecialchars($act['palette_code'] ?? '') ?>
                                <?php if (!empty($act['palette_code']) && !empty($act['pl_reference'])): ?>·<?php endif; ?>
                                <?= htmlspecialchars($act['pl_reference'] ?? '') ?>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Administration (Admin) -->
    <?php if (Auth::user()->hasRole('Administrateur')): ?>
    <div class="dashboard-widget dashboard-full">
        <div class="widget-header bg-dark text-white">
            <h5 class="text-white"><i class="fas fa-user-shield me-2"></i>Administration Système</h5>
        </div>
        <div class="widget-body">
            <div class="quick-actions">
                <?php if (Auth::can('user_manage')): ?>
                    <a href="<?= $baseUrl ?>/users"><i class="fas fa-users"></i> Utilisateurs</a>
                <?php endif; ?>
                <?php if (Auth::can('role_manage')): ?>
                    <a href="<?= $baseUrl ?>/roles"><i class="fas fa-tags"></i> Rôles</a>
                <?php endif; ?>
                <?php if (Auth::can('permission_manage')): ?>
                    <a href="<?= $baseUrl ?>/permissions"><i class="fas fa-shield-alt"></i> Permissions</a>
                <?php endif; ?>
                <?php if (Auth::can('site_manage')): ?>
                    <a href="<?= $baseUrl ?>/sites"><i class="fas fa-industry"></i> Sites</a>
                <?php endif; ?>
                <?php if (Auth::can('settings_manage')): ?>
                    <a href="<?= $baseUrl ?>/settings"><i class="fas fa-cogs"></i> Paramètres</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Horloge
    function updateClock() {
        const now = new Date();
        const clock = document.getElementById('dashboardClock');
        const dateEl = document.getElementById('dashboardDate');
        if (clock) clock.textContent = now.toLocaleTimeString('fr-FR');
        if (dateEl) dateEl.textContent = now.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    updateClock();
    setInterval(updateClock, 1000);
});
</script>