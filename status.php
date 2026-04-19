<?php
/**
 * Public status page (read-only). Gated by the `public_status` setting; if
 * disabled, requires an authenticated session. Renders entirely server-side
 * by calling the library directly — no internal HTTP round-trip.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$isPublic = (bool)cfc_config('general.public_status', false);
if (!$isPublic) {
    cfc_require_login();
}

$siteName = cfc_config('general.site_name', 'The Castle Fun Center');
$monitors = [];
foreach (cfc_load_monitors() as $m) $monitors[] = cfc_monitor_with_status($m, false);
$maintenance = cfc_maintenance();

$totalUp   = count(array_filter($monitors, fn($m) => $m['status'] === 'up'));
$totalDown = count(array_filter($monitors, fn($m) => $m['status'] === 'down'));
$overall = 'operational';
if (!empty($maintenance['enabled']) && cfc_in_maintenance()) $overall = 'maintenance';
elseif ($totalDown > 0) $overall = $totalDown === count($monitors) ? 'major_outage' : 'partial_outage';

$overallLabel = [
    'operational'    => 'All Systems Operational',
    'partial_outage' => 'Partial Service Outage',
    'major_outage'   => 'Major Service Outage',
    'maintenance'    => 'Scheduled Maintenance',
][$overall];
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Status — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/app.css">
<meta http-equiv="refresh" content="60">
</head>
<body class="status-public">
<header class="status-header">
    <div class="container">
        <div class="brand">
            <img src="assets/logo.png" alt="" class="brand-logo" onerror="this.style.display='none'">
            <div class="brand-text">
                <div class="brand-name"><?= h($siteName) ?></div>
                <div class="brand-sub">System Status</div>
            </div>
        </div>
    </div>
</header>

<main class="container">
    <div class="status-banner status-<?= h($overall) ?>">
        <h1><?= h($overallLabel) ?></h1>
        <?php if (!empty($maintenance['enabled']) && cfc_in_maintenance() && !empty($maintenance['message'])): ?>
            <p><?= h($maintenance['message']) ?></p>
        <?php endif; ?>
        <p class="muted">As of <?= h(date('Y-m-d H:i:s T')) ?></p>
    </div>

    <div class="card">
        <div class="card-header"><h2>Services</h2></div>
        <div class="status-list">
            <?php if (!$monitors): ?>
                <p class="muted" style="padding:1rem">No monitors configured.</p>
            <?php else: foreach ($monitors as $m): ?>
                <div class="status-row">
                    <div>
                        <div class="status-name"><?= h($m['name']) ?></div>
                        <div class="status-target muted small"><?= h(parse_url($m['target'], PHP_URL_HOST) ?: $m['target']) ?></div>
                    </div>
                    <div class="status-meta">
                        <span class="uptime-pill" title="30-day uptime"><?= h($m['uptime_30d']) ?>%</span>
                        <span class="status-badge <?= h($m['status']) ?>">
                            <?= h(ucfirst($m['status'])) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <?php
    $incidents = array_slice(cfc_load_incidents(), 0, 10);
    if ($incidents): ?>
        <div class="card">
            <div class="card-header"><h2>Recent Incidents</h2></div>
            <ul class="incident-log">
                <?php foreach ($incidents as $inc): ?>
                    <li>
                        <span class="badge <?= $inc['status'] === 'resolved' ? 'ok' : 'err' ?>">
                            <?= h(ucfirst($inc['status'])) ?>
                        </span>
                        <strong><?= h($inc['monitor_name']) ?></strong>
                        — <?= h(date('M j, H:i', (int)$inc['started'])) ?>
                        <?php if (!empty($inc['duration'])): ?>
                            <span class="muted">(<?= h(cfc_format_duration((int)$inc['duration'])) ?>)</span>
                        <?php endif; ?>
                        <?php if (!empty($inc['error'])): ?>
                            <div class="muted small"><?= h($inc['error']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <span><?= h($siteName) ?> · Powered by Castle Monitor</span>
</footer>
</body>
</html>
