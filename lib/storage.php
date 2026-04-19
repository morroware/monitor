<?php
/**
 * Storage for monitors, checks, incidents, and maintenance state.
 * Flat JSON files inside uptime_data/. Atomic writes + per-monitor check
 * files so history pruning stays cheap.
 */

$CFC_STORE_CACHE = ['monitors' => null, 'checks' => []];

function cfc_load_monitors(bool $reload = false): array {
    global $CFC_STORE_CACHE;
    if ($CFC_STORE_CACHE['monitors'] === null || $reload) {
        $CFC_STORE_CACHE['monitors'] = cfc_read_json(CFC_MONITORS_FILE, []);
    }
    return $CFC_STORE_CACHE['monitors'];
}

function cfc_save_monitors(array $monitors): bool {
    global $CFC_STORE_CACHE;
    $ok = cfc_write_json(CFC_MONITORS_FILE, array_values($monitors));
    if ($ok) $CFC_STORE_CACHE['monitors'] = array_values($monitors);
    return $ok;
}

function cfc_find_monitor(string $id): ?array {
    foreach (cfc_load_monitors() as $m) {
        if (($m['id'] ?? '') === $id) return $m;
    }
    return null;
}

function cfc_checks_path(string $monitorId): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $monitorId);
    return CFC_CHECKS_DIR . '/' . $safe . '.json';
}

function cfc_load_checks(string $monitorId, ?int $max = null): array {
    $path = cfc_checks_path($monitorId);
    if (!file_exists($path)) return [];
    $checks = cfc_read_json($path, []);
    if ($max && count($checks) > $max) $checks = array_slice($checks, -$max);
    return $checks;
}

function cfc_append_check(string $monitorId, array $check): bool {
    $checks = cfc_load_checks($monitorId);
    $checks[] = $check;
    if (count($checks) > CFC_MAX_CHECKS_PER_MONITOR) {
        $checks = array_slice($checks, -CFC_MAX_CHECKS_PER_MONITOR);
    }
    return cfc_write_json(cfc_checks_path($monitorId), $checks);
}

function cfc_delete_checks(string $monitorId): void {
    $path = cfc_checks_path($monitorId);
    if (file_exists($path)) @unlink($path);
    $cool = CFC_CHECKS_DIR . '/cooldown_' . preg_replace('/[^A-Za-z0-9_-]/', '', $monitorId) . '.txt';
    if (file_exists($cool)) @unlink($cool);
}

function cfc_uptime_pct(array $checks, int $hours = 720): float {
    if (!$checks) return 100.0;
    $cutoff = time() - ($hours * 3600);
    $relevant = array_filter($checks, fn($c) => ($c['timestamp'] ?? 0) >= $cutoff);
    if (!$relevant) $relevant = $checks;
    $up = 0;
    foreach ($relevant as $c) if (($c['status'] ?? '') === 'up') $up++;
    return round(($up / count($relevant)) * 100, 3);
}

function cfc_sparkline(array $checks, int $points = CFC_MAX_SPARKLINE_POINTS): array {
    if (!$checks) return [];
    $cutoff = time() - 86400;
    $recent = array_values(array_filter($checks, fn($c) => ($c['timestamp'] ?? 0) >= $cutoff));
    if (count($recent) < 2) $recent = array_slice($checks, -50);
    $vals = [];
    foreach ($recent as $c) {
        $vals[] = ($c['status'] === 'up' && isset($c['response_time'])) ? (float)$c['response_time'] : 0;
    }
    if (count($vals) <= $points) return $vals;
    $bucket = count($vals) / $points;
    $out = [];
    for ($i = 0; $i < $points; $i++) {
        $slice = array_slice($vals, (int)($i * $bucket), max(1, (int)$bucket));
        $out[] = round(array_sum($slice) / count($slice));
    }
    return $out;
}

function cfc_chart_data(array $checks, int $hours): array {
    $cutoff = time() - ($hours * 3600);
    $recent = array_values(array_filter($checks, fn($c) => ($c['timestamp'] ?? 0) >= $cutoff));
    usort($recent, fn($a, $b) => $a['timestamp'] - $b['timestamp']);
    $labels = [];
    $data = [];
    foreach ($recent as $c) {
        $labels[] = ($c['timestamp'] ?? 0) * 1000;
        $data[] = ($c['status'] === 'up' && isset($c['response_time'])) ? (float)$c['response_time'] : null;
    }
    return ['labels' => $labels, 'data' => $data, 'count' => count($recent)];
}

function cfc_monitor_with_status(array $monitor, bool $includeChart = false): array {
    $id = $monitor['id'];
    $checks = cfc_load_checks($id);
    $last = end($checks) ?: null;
    $monitor['status'] = $last['status'] ?? 'unknown';
    $monitor['last_check'] = $last ? $last['timestamp'] : null;
    $monitor['last_check_iso'] = $last ? date('c', $last['timestamp']) : null;
    $monitor['response_time'] = $last['response_time'] ?? null;
    $monitor['error'] = $last['error'] ?? null;
    $monitor['http_code'] = $last['http_code'] ?? null;
    $monitor['ssl_info'] = $last['ssl_info'] ?? null;
    $monitor['uptime_24h'] = cfc_uptime_pct($checks, 24);
    $monitor['uptime_7d']  = cfc_uptime_pct($checks, 168);
    $monitor['uptime_30d'] = cfc_uptime_pct($checks, 720);
    $monitor['check_count'] = count($checks);
    $monitor['sparkline'] = cfc_sparkline($checks);
    if ($includeChart) {
        $monitor['chart_24h'] = cfc_chart_data($checks, 24);
        $monitor['chart_7d']  = cfc_chart_data($checks, 168);
        $monitor['chart_30d'] = cfc_chart_data($checks, 720);
    }
    return $monitor;
}

/* ---- Incidents ---- */

function cfc_load_incidents(): array {
    return cfc_read_json(CFC_INCIDENTS_FILE, []);
}

function cfc_save_incidents(array $list): bool {
    // Cap history.
    $list = array_slice($list, 0, 200);
    return cfc_write_json(CFC_INCIDENTS_FILE, $list);
}

function cfc_record_incident(array $monitor, string $status, ?string $error): void {
    $incidents = cfc_load_incidents();
    $openIdx = null;
    foreach ($incidents as $i => $inc) {
        if ($inc['monitor_id'] === $monitor['id'] && $inc['status'] === 'open') {
            $openIdx = $i;
            break;
        }
    }
    if ($status === 'down' && $openIdx === null) {
        array_unshift($incidents, [
            'id' => bin2hex(random_bytes(6)),
            'monitor_id' => $monitor['id'],
            'monitor_name' => $monitor['name'],
            'title' => $monitor['name'] . ' is down',
            'error' => $error,
            'status' => 'open',
            'started' => time(),
            'resolved' => null,
            'duration' => null,
        ]);
    } elseif ($status === 'up' && $openIdx !== null) {
        $incidents[$openIdx]['status'] = 'resolved';
        $incidents[$openIdx]['resolved'] = time();
        $incidents[$openIdx]['duration'] = time() - $incidents[$openIdx]['started'];
    }
    cfc_save_incidents($incidents);
}

/* ---- Maintenance ---- */

function cfc_maintenance(): array {
    $m = cfc_read_json(CFC_MAINTENANCE_FILE, ['enabled' => false, 'message' => '', 'start' => null, 'end' => null]);
    if (!empty($m['enabled']) && !empty($m['end']) && time() > (int)$m['end']) {
        $m['enabled'] = false;
        cfc_write_json(CFC_MAINTENANCE_FILE, $m);
    }
    return $m;
}

function cfc_in_maintenance(): bool {
    $m = cfc_maintenance();
    if (empty($m['enabled'])) return false;
    $now = time();
    if (!empty($m['start']) && $now < (int)$m['start']) return false;
    if (!empty($m['end']) && $now > (int)$m['end']) return false;
    return true;
}

function cfc_set_maintenance(bool $enabled, string $message = '', ?int $start = null, ?int $end = null): void {
    cfc_write_json(CFC_MAINTENANCE_FILE, [
        'enabled' => $enabled, 'message' => $message, 'start' => $start, 'end' => $end,
    ]);
}
