<?php
/**
 * Health-check primitives: HTTP, TCP port, SSL certificate, DNS, keyword.
 * ICMP ping is intentionally not used — shared cPanel hosts almost never
 * allow it. We use a TCP probe ("availability") as the drop-in equivalent.
 */

/* ---- HTTP check ---- */

function cfc_check_http(array $monitor): array {
    $start = microtime(true);
    $url = cfc_normalize_url($monitor['target']);
    $cfg = $monitor['config'] ?? [];
    $method = strtoupper($cfg['method'] ?? 'GET');
    $timeout = max(1, min(60, (int)($cfg['timeout'] ?? 15)));

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => (bool)($cfg['follow_redirects'] ?? true),
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => (bool)($cfg['verify_ssl'] ?? true),
        CURLOPT_SSL_VERIFYHOST => ($cfg['verify_ssl'] ?? true) ? 2 : 0,
        CURLOPT_USERAGENT => 'CastleMonitor/' . CFC_VERSION,
        CURLOPT_HEADER => false,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_ENCODING => '',
    ]);
    if (!empty($cfg['headers']) && is_array($cfg['headers'])) {
        $hdrs = [];
        foreach ($cfg['headers'] as $k => $v) $hdrs[] = "$k: $v";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    }

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $err = curl_error($ch);
    curl_close($ch);

    $responseMs = (int)round($totalTime * 1000);
    $expected = $cfg['expected_status'] ?? [200, 201, 204, 301, 302, 303, 307, 308];
    if (!is_array($expected)) $expected = [200];

    $status = 'down';
    $errorMsg = null;
    if ($err) {
        $errorMsg = $err;
    } elseif (!in_array($httpCode, $expected, true)) {
        $errorMsg = "Unexpected HTTP $httpCode";
    } else {
        $status = 'up';
        if (!empty($cfg['keyword']) && $method !== 'HEAD' && stripos((string)$body, $cfg['keyword']) === false) {
            $status = 'down';
            $errorMsg = 'Keyword "' . $cfg['keyword'] . '" not found';
        }
        if (!empty($cfg['forbidden_keyword']) && $method !== 'HEAD' && stripos((string)$body, $cfg['forbidden_keyword']) !== false) {
            $status = 'down';
            $errorMsg = 'Forbidden keyword "' . $cfg['forbidden_keyword'] . '" present';
        }
    }

    $sslInfo = null;
    if (strpos($url, 'https://') === 0) {
        $sslInfo = cfc_check_ssl($url);
    }

    return [
        'status' => $status,
        'type' => 'http',
        'response_time' => $status === 'up' ? $responseMs : null,
        'http_code' => $httpCode ?: null,
        'error' => $errorMsg,
        'ssl_info' => $sslInfo,
        'timestamp' => time(),
    ];
}

/* ---- TCP port check ---- */

function cfc_check_tcp(string $host, int $port, int $timeout = 5): array {
    $start = microtime(true);
    $host = cfc_hostname_from_target($host);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (int)round((microtime(true) - $start) * 1000);
    if ($fp) {
        fclose($fp);
        return ['status' => 'up', 'type' => 'tcp', 'response_time' => $ms, 'port' => $port, 'error' => null, 'timestamp' => time()];
    }
    return ['status' => 'down', 'type' => 'tcp', 'response_time' => null, 'port' => $port,
            'error' => $errstr ?: "Port $port unreachable", 'timestamp' => time()];
}

/* ---- SSL cert inspection ---- */

function cfc_check_ssl(string $url, int $warnDays = 30): array {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https') return ['error' => 'not_https'];
    $host = $p['host']; $port = $p['port'] ?? 443;
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true]]);
    $errno = 0; $errstr = '';
    $stream = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$stream) return ['error' => $errstr ?: 'ssl_connect_failed'];
    $params = stream_context_get_params($stream);
    fclose($stream);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) return ['error' => 'no_certificate'];
    $parsed = openssl_x509_parse($cert);
    if (!$parsed) return ['error' => 'cert_parse_failed'];
    $validTo = (int)($parsed['validTo_time_t'] ?? 0);
    $days = (int)floor(($validTo - time()) / 86400);
    return [
        'issuer' => $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? 'Unknown',
        'subject' => $parsed['subject']['CN'] ?? 'Unknown',
        'valid_from' => date('Y-m-d', (int)($parsed['validFrom_time_t'] ?? 0)),
        'valid_to' => date('Y-m-d', $validTo),
        'days_remaining' => $days,
        'is_valid' => $days > 0,
        'is_expiring_soon' => $days <= $warnDays && $days > 0,
        'is_expired' => $days <= 0,
    ];
}

/* ---- DNS lookup (public tool) ---- */

function cfc_dns_lookup(string $host, string $type = 'A'): array {
    $host = cfc_hostname_from_target($host);
    $map = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'SOA' => DNS_SOA];
    $type = strtoupper($type);
    if (!isset($map[$type])) return ['error' => 'unsupported type'];
    $recs = @dns_get_record($host, $map[$type]);
    return ['host' => $host, 'type' => $type, 'records' => $recs ?: []];
}

/* ---- Main dispatcher ---- */

function cfc_run_check(array $monitor): array {
    $type = $monitor['config']['check_type'] ?? 'http';
    if ($type === 'tcp') {
        $host = cfc_hostname_from_target($monitor['target']);
        $port = (int)($monitor['config']['port'] ?? 80);
        $timeout = (int)($monitor['config']['timeout'] ?? 5);
        return cfc_check_tcp($host, $port, $timeout);
    }
    return cfc_check_http($monitor);
}

/* ---- URL helpers ---- */

function cfc_normalize_url(string $target): string {
    $target = trim($target);
    if (!preg_match('~^https?://~i', $target)) $target = 'http://' . $target;
    return $target;
}

function cfc_hostname_from_target(string $target): string {
    $target = cfc_normalize_url($target);
    $p = parse_url($target);
    return $p['host'] ?? $target;
}

/* ---- HTTP header inspection (tool page) ---- */

function cfc_http_headers(string $url, int $timeout = 10): array {
    $url = cfc_normalize_url($url);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => false,
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'CastleMonitor/' . CFC_VERSION,
    ]);
    $out = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$out) return ['error' => $err ?: 'no_response'];
    $headerSize = $info['header_size'];
    $rawHeaders = substr($out, 0, $headerSize);
    $headers = [];
    foreach (explode("\r\n", trim($rawHeaders)) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[trim($k)] = trim($v);
        } else {
            $headers['_status'] = $line;
        }
    }
    return [
        'url' => $url,
        'http_code' => $info['http_code'],
        'total_time_ms' => (int)round(($info['total_time'] ?? 0) * 1000),
        'redirect_count' => $info['redirect_count'] ?? 0,
        'primary_ip' => $info['primary_ip'] ?? null,
        'headers' => $headers,
    ];
}
