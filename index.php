<?php
require_once __DIR__ . '/lib/bootstrap.php';

// Route to installer if not yet installed.
if (!file_exists(CFC_CONFIG_FILE) || !cfc_config('auth.password_hash', '')) {
    header('Location: install.php'); exit;
}

cfc_require_login();

$siteName = cfc_config('general.site_name', 'The Castle Fun Center');
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/lib/header.php';
?>

<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Live status of all monitors. Updates every 30 seconds.</p>
    </div>
    <div class="page-actions">
        <button class="btn ghost" id="btn-refresh" type="button">
            <span class="ic">↻</span> Refresh
        </button>
        <a class="btn primary" href="monitors.php">
            <span class="ic">＋</span> Add Monitor
        </a>
    </div>
</div>

<div id="maintenance-banner" class="banner warn hidden">
    <strong>Maintenance mode is active.</strong> Monitoring paused — no alerts will be sent.
</div>

<div class="stat-grid" id="stat-grid">
    <div class="stat-card"><div class="stat-label">Total Monitors</div><div class="stat-value" id="s-total">—</div></div>
    <div class="stat-card ok"><div class="stat-label">Operational</div><div class="stat-value" id="s-up">—</div></div>
    <div class="stat-card err"><div class="stat-label">Down</div><div class="stat-value" id="s-down">—</div></div>
    <div class="stat-card warn"><div class="stat-label">SSL Warnings</div><div class="stat-value" id="s-ssl">—</div></div>
    <div class="stat-card"><div class="stat-label">24h Uptime</div><div class="stat-value" id="s-avg">—</div></div>
</div>

<div class="card" id="monitors-card">
    <div class="card-header">
        <h2>Monitors</h2>
        <input type="search" id="monitor-filter" placeholder="Filter..." class="input-sm">
    </div>
    <div id="monitors-list" class="monitors-list">
        <div class="loading">Loading monitors…</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Active Incidents</h2><a href="incidents.php" class="muted small">View all →</a></div>
    <div id="incidents-list" class="incidents-list"></div>
</div>

<script src="assets/app.js"></script>
<script>
(function(){
    CFC.initDashboard();
})();
</script>
<?php require __DIR__ . '/lib/footer.php'; ?>
