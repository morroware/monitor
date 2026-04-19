<?php
/**
 * Auth: session login, CSRF tokens, login throttling.
 * Single-user password-based auth. Stored as password_hash() in config.
 */

function cfc_is_authenticated(): bool {
    if (empty($_SESSION['cfc_auth']['uid'])) return false;
    $ttl = (int)cfc_config('auth.session_ttl', 28800);
    if ($ttl > 0 && (time() - ($_SESSION['cfc_auth']['at'] ?? 0)) > $ttl) {
        cfc_logout();
        return false;
    }
    $_SESSION['cfc_auth']['at'] = time();
    return true;
}

function cfc_require_login(): void {
    if (!cfc_is_authenticated()) {
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $target = $dir . '/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . $target);
        exit;
    }
}

function cfc_require_login_json(): void {
    if (!cfc_is_authenticated()) {
        cfc_json_response(['error' => 'unauthorized', 'message' => 'Login required'], 401);
    }
}

function cfc_login(string $password): array {
    $ip = cfc_client_ip();
    if (cfc_is_throttled($ip)) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Try again in a few minutes.'];
    }
    $hash = cfc_config('auth.password_hash', '');
    if (!$hash) {
        return ['ok' => false, 'error' => 'No password configured. Run install.php.'];
    }
    if (!password_verify($password, $hash)) {
        cfc_record_failed_login($ip);
        return ['ok' => false, 'error' => 'Invalid password'];
    }
    cfc_clear_failed_logins($ip);
    session_regenerate_id(true);
    $_SESSION['cfc_auth'] = [
        'uid' => 'admin',
        'at'  => time(),
        'ip'  => $ip,
        'ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
    ];
    cfc_log(CFC_LOGIN_LOG, "LOGIN OK ip=$ip");
    return ['ok' => true];
}

function cfc_logout(): void {
    $ip = cfc_client_ip();
    cfc_log(CFC_LOGIN_LOG, "LOGOUT ip=$ip");
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

function cfc_csrf_token(): string {
    if (empty($_SESSION['cfc_csrf'])) {
        $_SESSION['cfc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cfc_csrf'];
}

function cfc_csrf_input(): string {
    return '<input type="hidden" name="csrf" value="' . h(cfc_csrf_token()) . '">';
}

function cfc_check_csrf(): bool {
    $tok = $_POST['csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
        ?? '';
    $tok = is_string($tok) ? $tok : '';
    return $tok !== '' && hash_equals($_SESSION['cfc_csrf'] ?? '', $tok);
}

function cfc_require_csrf_or_403(): void {
    if (!cfc_check_csrf()) {
        cfc_json_response(['error' => 'csrf_invalid'], 403);
    }
}

/* ---- login rate limiting ---- */

function cfc_throttle_file(string $ip): string {
    return CFC_DATA_DIR . '/login_' . md5($ip) . '.json';
}

function cfc_is_throttled(string $ip): bool {
    $f = cfc_throttle_file($ip);
    $d = cfc_read_json($f, ['count' => 0, 'last' => 0]);
    if (($d['count'] ?? 0) < 5) return false;
    return (time() - ($d['last'] ?? 0)) < 900; // 15 min
}

function cfc_record_failed_login(string $ip): void {
    $f = cfc_throttle_file($ip);
    $d = cfc_read_json($f, ['count' => 0, 'last' => 0]);
    $d['count'] = (int)($d['count'] ?? 0) + 1;
    $d['last']  = time();
    cfc_write_json($f, $d);
    cfc_log(CFC_LOGIN_LOG, "LOGIN FAIL ip=$ip attempts={$d['count']}");
}

function cfc_clear_failed_logins(string $ip): void {
    $f = cfc_throttle_file($ip);
    if (file_exists($f)) @unlink($f);
}
