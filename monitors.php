<?php
require_once __DIR__ . '/lib/bootstrap.php';
cfc_require_login();
$pageTitle = 'Monitors';
$activeNav = 'monitors';
require __DIR__ . '/lib/header.php';
?>
<div class="page-header">
    <div>
        <h1>Monitors</h1>
        <p class="muted">Create, edit, and manage all monitored endpoints.</p>
    </div>
    <div class="page-actions">
        <button class="btn ghost" id="btn-export" type="button">Export</button>
        <label class="btn ghost">
            Import<input type="file" id="import-file" accept=".json" hidden>
        </label>
        <button class="btn primary" id="btn-new-monitor" type="button"><span class="ic">＋</span> New Monitor</button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>All Monitors</h2>
        <input type="search" id="monitor-filter" placeholder="Filter..." class="input-sm">
    </div>
    <div class="table-wrap">
        <table class="data-table" id="monitors-table">
            <thead>
                <tr>
                    <th>Name</th><th>Target</th><th>Type</th><th>Interval</th>
                    <th>Status</th><th>24h</th><th>7d</th><th>30d</th><th></th>
                </tr>
            </thead>
            <tbody><tr><td colspan="9" class="loading">Loading…</td></tr></tbody>
        </table>
    </div>
</div>

<!-- Modal: create/edit monitor -->
<div class="modal-backdrop hidden" id="monitor-modal">
    <div class="modal">
        <header class="modal-header">
            <h3 id="modal-title">New Monitor</h3>
            <button class="icon-btn" data-close type="button">✕</button>
        </header>
        <form id="monitor-form" class="modal-body stack">
            <input type="hidden" name="id" id="m-id">
            <label>Name
                <input name="name" id="m-name" required maxlength="80">
            </label>
            <label>Target URL or host
                <input name="target" id="m-target" placeholder="https://example.com" required>
            </label>
            <div class="grid-2">
                <label>Check type
                    <select name="check_type" id="m-type">
                        <option value="http">HTTP(S)</option>
                        <option value="tcp">TCP port</option>
                    </select>
                </label>
                <label>Interval (seconds)
                    <input type="number" name="interval" id="m-interval" min="60" value="300">
                </label>
            </div>
            <div class="grid-2" id="http-options">
                <label>HTTP method
                    <select name="method" id="m-method">
                        <option>GET</option><option>HEAD</option><option>POST</option>
                    </select>
                </label>
                <label>Timeout (s)
                    <input type="number" name="timeout" id="m-timeout" min="1" max="60" value="15">
                </label>
                <label class="full">Expected status (comma-separated)
                    <input name="expected_status" id="m-expected" value="200,201,204,301,302,303,307,308">
                </label>
                <label>Keyword to require
                    <input name="keyword" id="m-keyword" placeholder="(optional)">
                </label>
                <label>Keyword to forbid
                    <input name="forbidden_keyword" id="m-forbidden" placeholder="(optional)">
                </label>
                <label class="check">
                    <input type="checkbox" name="follow_redirects" id="m-follow" checked> Follow redirects
                </label>
                <label class="check">
                    <input type="checkbox" name="verify_ssl" id="m-verifyssl"> Verify SSL cert
                </label>
            </div>
            <div class="grid-2 hidden" id="tcp-options">
                <label>Port
                    <input type="number" name="port" id="m-port" min="1" max="65535" value="443">
                </label>
                <label>Timeout (s)
                    <input type="number" name="tcp_timeout" id="m-tcp-timeout" min="1" max="30" value="5">
                </label>
            </div>
            <label class="check">
                <input type="checkbox" name="alerts_enabled" id="m-alerts" checked> Send alerts for this monitor
            </label>
            <div class="modal-footer">
                <button type="button" class="btn ghost" data-close>Cancel</button>
                <button type="submit" class="btn primary" id="m-submit">Save</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/app.js"></script>
<script>CFC.initMonitorsPage();</script>
<?php require __DIR__ . '/lib/footer.php'; ?>
