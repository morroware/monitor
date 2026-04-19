<?php
/**
 * Configuration: parse, cache, dot-path lookup, atomic save.
 * Config file is a flat INI (sections + key=value). Sensitive values are
 * stored as-is but the file sits inside uptime_data/ with Deny-All htaccess.
 */

$CFC_CONFIG_CACHE = null;

function cfc_config_all(bool $reload = false): array {
    global $CFC_CONFIG_CACHE;
    if ($CFC_CONFIG_CACHE !== null && !$reload) return $CFC_CONFIG_CACHE;
    if (!file_exists(CFC_CONFIG_FILE)) {
        $CFC_CONFIG_CACHE = [];
        return $CFC_CONFIG_CACHE;
    }
    $parsed = @parse_ini_file(CFC_CONFIG_FILE, true, INI_SCANNER_TYPED);
    $CFC_CONFIG_CACHE = is_array($parsed) ? $parsed : [];
    return $CFC_CONFIG_CACHE;
}

/**
 * Fetch a value via dot-path: cfc_config('slack.bot_token', '')
 */
function cfc_config(string $path, $default = null) {
    $cfg = cfc_config_all();
    $parts = explode('.', $path);
    $cur = $cfg;
    foreach ($parts as $p) {
        if (is_array($cur) && array_key_exists($p, $cur)) { $cur = $cur[$p]; }
        else { return $default; }
    }
    return $cur;
}

function cfc_config_save(array $sections): bool {
    global $CFC_CONFIG_CACHE;
    $out = "; Castle Fun Center Monitor — saved " . date('c') . "\n";
    foreach ($sections as $section => $values) {
        if (!is_array($values)) continue;
        $out .= "\n[" . preg_replace('/[^A-Za-z0-9_]/', '', $section) . "]\n";
        foreach ($values as $key => $value) {
            $key = preg_replace('/[^A-Za-z0-9_]/', '', $key);
            if (is_bool($value)) { $value = $value ? 'true' : 'false'; }
            if (is_array($value)) { continue; }
            if ($value === null) { $value = ''; }
            $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\"'], (string)$value) . '"';
            $out .= $key . ' = ' . $quoted . "\n";
        }
    }
    $ok = cfc_atomic_write(CFC_CONFIG_FILE, $out);
    if ($ok) { @chmod(CFC_CONFIG_FILE, 0640); $CFC_CONFIG_CACHE = null; }
    return $ok;
}

function cfc_config_defaults(): array {
    return [
        'general' => [
            'site_name'      => 'The Castle Fun Center',
            'site_tagline'   => 'Monitoring Dashboard',
            'timezone'       => 'America/New_York',
            'alerts_enabled' => 'true',
            'alert_cooldown' => '15',
            'public_status'  => 'false',
        ],
        'auth' => [
            'password_hash' => '',
            'cron_token'    => '',
            'session_ttl'   => '28800', // 8 hours
        ],
        'slack' => [
            'bot_token'         => '',
            'channel'           => '#monitoring',
            'alert_on_down'     => 'true',
            'alert_on_recovery' => 'true',
            'alert_on_slow'     => 'true',
            'alert_on_ssl'      => 'true',
            'mention'           => '',
        ],
        'email' => [
            'enabled'           => 'false',
            'recipients'        => '',
            'from_name'         => 'Castle Monitor',
            'from_email'        => '',
            'alert_on_down'     => 'true',
            'alert_on_recovery' => 'true',
        ],
        'thresholds' => [
            'slow_response_ms' => '2500',
            'ssl_warn_days'    => '30',
            'fail_streak'      => '2',
        ],
    ];
}
