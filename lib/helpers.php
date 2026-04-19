<?php
/**
 * General-purpose helpers: escaping, JSON I/O, date formatting.
 */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cfc_json_response($data, int $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function cfc_json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cfc_atomic_write(string $path, string $data): bool {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}

function cfc_read_json(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false) return $default;
    $data = json_decode($raw, true);
    return $data === null ? $default : $data;
}

function cfc_write_json(string $path, $data): bool {
    return cfc_atomic_write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function cfc_log(string $file, string $line): void {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] $line\n", FILE_APPEND | LOCK_EX);
}

function cfc_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function cfc_format_bytes($bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function cfc_format_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
}

function cfc_relative_time(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 0) return 'in the future';
    if ($diff < 10) return 'just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function cfc_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $dir;
}
