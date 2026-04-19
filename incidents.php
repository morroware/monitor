<?php
require_once __DIR__ . '/lib/bootstrap.php';
cfc_require_login();
$pageTitle = 'Incidents';
$activeNav = 'incidents';
require __DIR__ . '/lib/header.php';
?>
<div class="page-header">
    <div>
        <h1>Incidents &amp; Alerts</h1>
        <p class="muted">History of outages and every alert sent by the system.</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Recent Incidents</h2>
        <button class="btn ghost sm" id="btn-clear-incidents" type="button">Clear history</button>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="incidents-table">
            <thead><tr><th>Monitor</th><th>Started</th><th>Duration</th><th>Status</th><th>Error</th></tr></thead>
            <tbody><tr><td colspan="5" class="loading">Loading…</td></tr></tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Alert Log</h2>
        <button class="btn ghost sm" id="btn-clear-log" type="button">Clear log</button>
    </div>
    <pre class="log-view" id="alert-log">Loading…</pre>
</div>

<script src="assets/app.js"></script>
<script>CFC.initIncidentsPage();</script>
<?php require __DIR__ . '/lib/footer.php'; ?>
