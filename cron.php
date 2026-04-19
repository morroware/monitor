<?php
/**
 * Cron endpoint — called by cPanel cron every 5 minutes.
 * Usage:
 *   curl -s "https://example.com/monitor/cron.php?token=YOURTOKEN" >/dev/null
 *
 * Does not require a session. Validates a shared secret token. Refuses to
 * run if no token is configured (fresh install).
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$cronToken = cfc_config('auth.cron_token', '');
$provided  = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if ($cronToken === '' || !hash_equals((string)$cronToken, (string)$provided)) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

if (cfc_in_maintenance()) {
    echo "maintenance\n";
    exit;
}

$now = time();
$checked = 0;
$errors = [];
foreach (cfc_load_monitors() as $mon) {
    try {
        $history = cfc_load_checks($mon['id']);
        $last = end($history);
        $interval = max(60, (int)($mon['interval'] ?? 300));
        if ($last && ($now - (int)$last['timestamp']) < $interval) continue;

        $prevStatus = $last['status'] ?? 'unknown';
        $check = cfc_run_check($mon);
        cfc_append_check($mon['id'], $check);
        $withStatus = cfc_monitor_with_status($mon, false);

        if ($check['status'] !== $prevStatus) {
            cfc_record_incident($withStatus, $check['status'], $check['error'] ?? null);
        }
        cfc_dispatch_alerts($withStatus, $check['status'], $prevStatus, $check['error'] ?? null, $check);
        $checked++;
    } catch (Throwable $e) {
        $errors[] = $mon['name'] . ': ' . $e->getMessage();
        error_log('[CFC cron] ' . $e->getMessage());
    }
}

echo "ok checked=$checked errors=" . count($errors) . "\n";
foreach ($errors as $e) echo "! $e\n";
