<?php
/**
 * Bootstrap — single entry point every PHP file loads first.
 * Sets up session, timezone, error handling, and pulls in the library.
 */

if (defined('CFC_BOOTSTRAPPED')) return;
define('CFC_BOOTSTRAPPED', true);

define('CFC_VERSION', '1.0.0-beta');
define('CFC_ROOT', dirname(__DIR__));
define('CFC_DATA_DIR', CFC_ROOT . '/uptime_data');
define('CFC_CHECKS_DIR', CFC_DATA_DIR . '/checks');
define('CFC_CONFIG_FILE', CFC_DATA_DIR . '/config.ini');
define('CFC_MONITORS_FILE', CFC_DATA_DIR . '/monitors.json');
define('CFC_INCIDENTS_FILE', CFC_DATA_DIR . '/incidents.json');
define('CFC_MAINTENANCE_FILE', CFC_DATA_DIR . '/maintenance.json');
define('CFC_ALERTS_LOG', CFC_DATA_DIR . '/alerts.log');
define('CFC_LOGIN_LOG', CFC_DATA_DIR . '/login.log');
define('CFC_MAX_CHECKS_PER_MONITOR', 2880);
define('CFC_MAX_SPARKLINE_POINTS', 144);

// Production-grade error handling: log, don't display.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', CFC_DATA_DIR . '/php-errors.log');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Reasonable memory/time envelope for shared hosting.
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '60');

// Session hardening.
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('CFCMON');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Ensure data dirs exist and are private.
foreach ([CFC_DATA_DIR, CFC_CHECKS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}
// Emergency .htaccess for the data dir (overwritten by installer but safe here).
$dataHt = CFC_DATA_DIR . '/.htaccess';
if (!file_exists($dataHt)) {
    @file_put_contents($dataHt, "Require all denied\nOrder allow,deny\nDeny from all\n");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/alerts.php';

date_default_timezone_set(cfc_config('general.timezone', 'America/New_York'));
