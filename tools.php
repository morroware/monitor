<?php
require_once __DIR__ . '/lib/bootstrap.php';
cfc_require_login();
$pageTitle = 'Tools';
$activeNav = 'tools';
require __DIR__ . '/lib/header.php';
?>
<div class="page-header">
    <div>
        <h1>Diagnostic Tools</h1>
        <p class="muted">Ad-hoc checks for quick troubleshooting. None of these save history.</p>
    </div>
</div>

<div class="tool-grid">

    <div class="card">
        <div class="card-header"><h2>HTTP Probe</h2></div>
        <div class="card-body stack">
            <label>URL <input id="http-url" placeholder="https://example.com"></label>
            <div class="row">
                <button class="btn primary" id="http-run" type="button">Run</button>
                <button class="btn ghost" id="http-head" type="button">HEAD only</button>
            </div>
            <pre class="result" id="http-out">—</pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>TCP Port</h2></div>
        <div class="card-body stack">
            <label>Host <input id="tcp-host" placeholder="example.com"></label>
            <div class="grid-2">
                <label>Port <input id="tcp-port" type="number" value="443" min="1" max="65535"></label>
                <label>Timeout (s) <input id="tcp-timeout" type="number" value="5" min="1" max="30"></label>
            </div>
            <button class="btn primary" id="tcp-run" type="button">Connect</button>
            <pre class="result" id="tcp-out">—</pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>DNS Lookup</h2></div>
        <div class="card-body stack">
            <label>Host <input id="dns-host" placeholder="example.com"></label>
            <label>Type
                <select id="dns-type">
                    <option>A</option><option>AAAA</option><option>MX</option>
                    <option>TXT</option><option>NS</option><option>CNAME</option><option>SOA</option>
                </select>
            </label>
            <button class="btn primary" id="dns-run" type="button">Resolve</button>
            <pre class="result" id="dns-out">—</pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>SSL Certificate</h2></div>
        <div class="card-body stack">
            <label>URL <input id="ssl-url" placeholder="https://example.com"></label>
            <button class="btn primary" id="ssl-run" type="button">Inspect</button>
            <pre class="result" id="ssl-out">—</pre>
        </div>
    </div>

    <div class="card wide">
        <div class="card-header"><h2>HTTP Headers</h2></div>
        <div class="card-body stack">
            <label>URL <input id="hdr-url" placeholder="https://example.com"></label>
            <button class="btn primary" id="hdr-run" type="button">Fetch</button>
            <pre class="result" id="hdr-out">—</pre>
        </div>
    </div>

</div>

<script src="assets/app.js"></script>
<script>CFC.initToolsPage();</script>
<?php require __DIR__ . '/lib/footer.php'; ?>
