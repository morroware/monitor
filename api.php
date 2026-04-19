<?php
/**
 * Castle Fun Center — Monitor REST API
 *
 * Auth model:
 *   - Browser calls: require an active session (CSRF on state-changing verbs).
 *   - cron.php:      uses a shared secret token via ?token=... (set by installer).
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

/* ---- Authentication ---- */

$cronToken = cfc_config('auth.cron_token', '');
$providedToken = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$isCron = $cronToken !== '' && hash_equals($cronToken, (string)$providedToken);

if (!$isCron) {
    cfc_require_login_json();
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !empty($_GET['_method'])) {
    $ov = strtoupper($_GET['_method']);
    if (in_array($ov, ['PUT', 'DELETE'], true)) $method = $ov;
}
$path = $_GET['path'] ?? '';

$unsafe = in_array($method, ['POST', 'PUT', 'DELETE'], true);
if ($unsafe && !$isCron) {
    cfc_require_csrf_or_403();
}

/* ---- Routing ---- */

try {
    // ---- Monitors CRUD ----
    if ($method === 'GET' && $path === 'monitors') {
        $withChart = ($_GET['include_chart'] ?? '') === 'true';
        $out = [];
        foreach (cfc_load_monitors() as $m) $out[] = cfc_monitor_with_status($m, $withChart);
        cfc_json_response($out);
    }

    if ($method === 'GET' && preg_match('~^monitors/([A-Za-z0-9_-]+)$~', $path, $m)) {
        $mon = cfc_find_monitor($m[1]);
        if (!$mon) cfc_json_response(['error' => 'not_found'], 404);
        cfc_json_response(cfc_monitor_with_status($mon, true));
    }

    if ($method === 'POST' && $path === 'monitors') {
        $in = cfc_json_input();
        if (empty($in['target'])) cfc_json_response(['error' => 'target_required'], 400);
        $mon = cfc_build_monitor($in);
        $monitors = cfc_load_monitors();
        $monitors[] = $mon;
        cfc_save_monitors($monitors);
        // First check immediately so dashboard isn't empty.
        $check = cfc_run_check($mon);
        cfc_append_check($mon['id'], $check);
        cfc_json_response(cfc_monitor_with_status($mon, true), 201);
    }

    if ($method === 'PUT' && preg_match('~^monitors/([A-Za-z0-9_-]+)$~', $path, $m)) {
        $in = cfc_json_input();
        $monitors = cfc_load_monitors();
        $found = false;
        foreach ($monitors as $i => $mon) {
            if ($mon['id'] !== $m[1]) continue;
            $updated = cfc_build_monitor(array_merge($mon, $in), $mon);
            $updated['id'] = $mon['id'];
            $updated['created_at'] = $mon['created_at'] ?? time();
            $updated['updated_at'] = time();
            $monitors[$i] = $updated;
            $found = true;
            break;
        }
        if (!$found) cfc_json_response(['error' => 'not_found'], 404);
        cfc_save_monitors($monitors);
        cfc_json_response(cfc_monitor_with_status($monitors[$i], true));
    }

    if ($method === 'DELETE' && preg_match('~^monitors/([A-Za-z0-9_-]+)$~', $path, $m)) {
        $monitors = cfc_load_monitors();
        $kept = array_values(array_filter($monitors, fn($x) => $x['id'] !== $m[1]));
        if (count($kept) === count($monitors)) cfc_json_response(['error' => 'not_found'], 404);
        cfc_save_monitors($kept);
        cfc_delete_checks($m[1]);
        cfc_json_response(['ok' => true]);
    }

    if ($method === 'POST' && preg_match('~^monitors/([A-Za-z0-9_-]+)/check$~', $path, $m)) {
        $mon = cfc_find_monitor($m[1]);
        if (!$mon) cfc_json_response(['error' => 'not_found'], 404);
        $prev = cfc_load_checks($mon['id']);
        $prevStatus = end($prev)['status'] ?? 'unknown';
        $check = cfc_run_check($mon);
        cfc_append_check($mon['id'], $check);
        $withStatus = cfc_monitor_with_status($mon, true);
        cfc_record_incident($withStatus, $check['status'], $check['error'] ?? null);
        cfc_dispatch_alerts($withStatus, $check['status'], $prevStatus, $check['error'] ?? null, $check);
        cfc_json_response($withStatus);
    }

    if ($method === 'POST' && preg_match('~^monitors/([A-Za-z0-9_-]+)/test-alert$~', $path, $m)) {
        $mon = cfc_find_monitor($m[1]);
        if (!$mon) cfc_json_response(['error' => 'not_found'], 404);
        $withStatus = cfc_monitor_with_status($mon, false);
        cfc_clear_cooldown($mon['id']);
        $fake = ['response_time' => 250, 'http_code' => 500, 'timestamp' => time()];
        cfc_dispatch_alerts($withStatus, 'down', 'up', 'Test alert — ignore', $fake);
        cfc_json_response(['ok' => true]);
    }

    // ---- Bulk / cron ----
    if ($method === 'POST' && $path === 'check-all') {
        if (cfc_in_maintenance()) {
            cfc_json_response(['ok' => true, 'maintenance' => true, 'checked' => 0]);
        }
        $now = time();
        $checked = 0; $results = [];
        foreach (cfc_load_monitors() as $mon) {
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
            $results[] = ['name' => $mon['name'], 'status' => $check['status']];
        }
        cfc_json_response(['ok' => true, 'checked' => $checked, 'results' => $results, 'timestamp' => date('c')]);
    }

    // ---- Stats ----
    if ($method === 'GET' && $path === 'stats') {
        $monitors = cfc_load_monitors();
        $up = 0; $down = 0; $ssl = 0; $total = 0; $avg = 0;
        foreach ($monitors as $mon) {
            $m = cfc_monitor_with_status($mon, false);
            $total++;
            if ($m['status'] === 'up') $up++;
            elseif ($m['status'] === 'down') $down++;
            $avg += $m['uptime_24h'];
            if (!empty($m['ssl_info']['is_expiring_soon'])) $ssl++;
        }
        cfc_json_response([
            'total' => $total, 'up' => $up, 'down' => $down,
            'unknown' => $total - $up - $down,
            'avg_uptime_24h' => $total ? round($avg / $total, 2) : 0,
            'ssl_warnings' => $ssl,
            'maintenance' => cfc_in_maintenance(),
        ]);
    }

    // ---- Incidents ----
    if ($method === 'GET' && $path === 'incidents') cfc_json_response(cfc_load_incidents());
    if ($method === 'DELETE' && $path === 'incidents') {
        cfc_save_incidents([]);
        cfc_json_response(['ok' => true]);
    }

    // ---- Alert log ----
    if ($method === 'GET' && $path === 'logs') {
        $log = file_exists(CFC_ALERTS_LOG) ? file_get_contents(CFC_ALERTS_LOG) : '';
        cfc_json_response(['log' => $log, 'size' => strlen($log)]);
    }
    if ($method === 'DELETE' && $path === 'logs') {
        @file_put_contents(CFC_ALERTS_LOG, '');
        cfc_json_response(['ok' => true]);
    }

    // ---- Maintenance mode ----
    if ($method === 'GET' && $path === 'maintenance') cfc_json_response(cfc_maintenance());
    if ($method === 'POST' && $path === 'maintenance') {
        $in = cfc_json_input();
        cfc_set_maintenance(
            !empty($in['enabled']),
            (string)($in['message'] ?? ''),
            isset($in['start']) ? (int)$in['start'] : null,
            isset($in['end']) ? (int)$in['end'] : null
        );
        cfc_json_response(['ok' => true, 'maintenance' => cfc_maintenance()]);
    }

    // ---- Config ----
    if ($method === 'GET' && $path === 'config') {
        $cfg = cfc_config_all();
        // Scrub secrets before sending to browser.
        if (isset($cfg['auth']['password_hash'])) $cfg['auth']['password_hash'] = $cfg['auth']['password_hash'] ? '***set***' : '';
        if (isset($cfg['auth']['cron_token'])) $cfg['auth']['cron_token'] = $cfg['auth']['cron_token'] ? str_repeat('•', 8) : '';
        if (!empty($cfg['slack']['bot_token'])) $cfg['slack']['bot_token'] = '***set***';
        cfc_json_response($cfg);
    }
    if ($method === 'PUT' && $path === 'config') {
        $in = cfc_json_input();
        $current = cfc_config_all();
        // Merge to preserve unshown secrets.
        foreach ($in as $section => $values) {
            if (!is_array($values)) continue;
            foreach ($values as $k => $v) {
                if ($k === 'password_hash') continue; // can't set via API
                if ($k === 'cron_token' && $v === '' ) continue;
                if (is_string($v) && str_starts_with($v, '***')) continue; // preserve masked
                $current[$section][$k] = $v;
            }
        }
        $ok = cfc_config_save($current);
        cfc_json_response(['ok' => $ok]);
    }

    // ---- Password change ----
    if ($method === 'POST' && $path === 'change-password') {
        $in = cfc_json_input();
        $current = cfc_config_all();
        $cur = $in['current'] ?? '';
        $next = $in['new'] ?? '';
        if (!password_verify($cur, $current['auth']['password_hash'] ?? '')) {
            cfc_json_response(['error' => 'wrong_current'], 400);
        }
        if (strlen($next) < 10) cfc_json_response(['error' => 'too_short', 'message' => 'Minimum 10 characters'], 400);
        $current['auth']['password_hash'] = password_hash($next, PASSWORD_DEFAULT);
        cfc_config_save($current);
        cfc_json_response(['ok' => true]);
    }

    // ---- Slack test ----
    if ($method === 'POST' && $path === 'slack/test') {
        $in = cfc_json_input();
        $res = cfc_slack_test($in['message'] ?? null);
        cfc_json_response($res, $res['ok'] ? 200 : 500);
    }

    // ---- Tools ----
    if ($method === 'POST' && $path === 'tools/tcp') {
        $in = cfc_json_input();
        if (empty($in['target']) || empty($in['port'])) cfc_json_response(['error' => 'target_and_port_required'], 400);
        cfc_json_response(cfc_check_tcp($in['target'], (int)$in['port'], (int)($in['timeout'] ?? 5)));
    }
    if ($method === 'POST' && $path === 'tools/dns') {
        $in = cfc_json_input();
        if (empty($in['host'])) cfc_json_response(['error' => 'host_required'], 400);
        cfc_json_response(cfc_dns_lookup($in['host'], $in['type'] ?? 'A'));
    }
    if ($method === 'POST' && $path === 'tools/ssl') {
        $in = cfc_json_input();
        if (empty($in['url'])) cfc_json_response(['error' => 'url_required'], 400);
        cfc_json_response(cfc_check_ssl(cfc_normalize_url($in['url']), (int)cfc_config('thresholds.ssl_warn_days', 30)));
    }
    if ($method === 'POST' && $path === 'tools/headers') {
        $in = cfc_json_input();
        if (empty($in['url'])) cfc_json_response(['error' => 'url_required'], 400);
        cfc_json_response(cfc_http_headers($in['url']));
    }
    if ($method === 'POST' && $path === 'tools/http') {
        $in = cfc_json_input();
        if (empty($in['target'])) cfc_json_response(['error' => 'target_required'], 400);
        $dummy = ['target' => $in['target'], 'name' => 'ad-hoc', 'config' => $in['config'] ?? []];
        cfc_json_response(cfc_check_http($dummy));
    }

    // ---- Export / Import ----
    if ($method === 'GET' && $path === 'export') {
        header('Content-Disposition: attachment; filename="castle-monitors-' . date('Y-m-d') . '.json"');
        cfc_json_response([
            'version' => CFC_VERSION,
            'exported' => date('c'),
            'monitors' => cfc_load_monitors(),
        ]);
    }
    if ($method === 'POST' && $path === 'import') {
        $in = cfc_json_input();
        if (empty($in['monitors']) || !is_array($in['monitors'])) {
            cfc_json_response(['error' => 'invalid_payload'], 400);
        }
        $current = cfc_load_monitors();
        $existingIds = array_column($current, 'id');
        $added = 0;
        foreach ($in['monitors'] as $m) {
            if (!empty($m['id']) && in_array($m['id'], $existingIds, true)) continue;
            $m['id'] = $m['id'] ?? bin2hex(random_bytes(6));
            $m['created_at'] = $m['created_at'] ?? time();
            $current[] = $m;
            $added++;
        }
        cfc_save_monitors($current);
        cfc_json_response(['ok' => true, 'added' => $added]);
    }

    // ---- Storage/health ----
    if ($method === 'GET' && $path === 'health') {
        $healthy = is_writable(CFC_DATA_DIR) && is_writable(CFC_CHECKS_DIR);
        cfc_json_response([
            'status' => $healthy ? 'healthy' : 'degraded',
            'version' => CFC_VERSION,
            'data_writable' => is_writable(CFC_DATA_DIR),
            'checks_writable' => is_writable(CFC_CHECKS_DIR),
            'php_version' => PHP_VERSION,
            'curl' => function_exists('curl_init'),
            'openssl' => function_exists('openssl_x509_parse'),
            'time' => date('c'),
        ]);
    }
    if ($method === 'GET' && $path === 'storage') {
        $monitors = cfc_load_monitors();
        $bytes = 0;
        foreach ([CFC_MONITORS_FILE, CFC_INCIDENTS_FILE, CFC_ALERTS_LOG, CFC_MAINTENANCE_FILE] as $f) {
            if (file_exists($f)) $bytes += filesize($f);
        }
        $detail = [];
        foreach ($monitors as $mon) {
            $p = cfc_checks_path($mon['id']);
            $sz = file_exists($p) ? filesize($p) : 0;
            $bytes += $sz;
            $detail[] = ['id' => $mon['id'], 'name' => $mon['name'], 'size' => $sz,
                         'checks' => count(cfc_load_checks($mon['id']))];
        }
        cfc_json_response(['total_bytes' => $bytes, 'monitors' => $detail]);
    }
    if ($method === 'POST' && $path === 'cleanup') {
        $removed = 0;
        foreach (cfc_load_monitors() as $mon) {
            $checks = cfc_load_checks($mon['id']);
            if (count($checks) > CFC_MAX_CHECKS_PER_MONITOR) {
                $new = array_slice($checks, -CFC_MAX_CHECKS_PER_MONITOR);
                cfc_write_json(cfc_checks_path($mon['id']), $new);
                $removed += count($checks) - count($new);
            }
        }
        cfc_json_response(['ok' => true, 'removed' => $removed]);
    }

    cfc_json_response(['error' => 'not_found', 'path' => $path, 'method' => $method], 404);

} catch (Throwable $e) {
    error_log('[CFC API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    cfc_json_response(['error' => 'server_error', 'message' => $e->getMessage()], 500);
}

/* ---- Builder helper ---- */

function cfc_build_monitor(array $in, array $existing = []): array {
    $id = $existing['id'] ?? bin2hex(random_bytes(6));
    $now = time();
    $cfg = $in['config'] ?? [];
    $existingCfg = $existing['config'] ?? [];
    return [
        'id' => $id,
        'name' => trim($in['name'] ?? $in['target']),
        'target' => cfc_normalize_url($in['target']),
        'interval' => max(60, (int)($in['interval'] ?? $existing['interval'] ?? 300)),
        'created_at' => $existing['created_at'] ?? $now,
        'updated_at' => $now,
        'config' => array_merge([
            'check_type' => 'http',
            'method' => 'GET',
            'timeout' => 15,
            'follow_redirects' => true,
            'verify_ssl' => false,
            'expected_status' => [200, 201, 204, 301, 302, 303, 307, 308],
            'keyword' => '',
            'forbidden_keyword' => '',
            'port' => null,
            'headers' => [],
            'alerts_enabled' => true,
            'priority' => 'normal',
        ], $existingCfg, $cfg),
    ];
}
