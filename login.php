<?php
require_once __DIR__ . '/lib/bootstrap.php';

// If installer hasn't been run, bounce there.
if (!file_exists(CFC_CONFIG_FILE) || !cfc_config('auth.password_hash', '')) {
    header('Location: install.php'); exit;
}

if (cfc_is_authenticated()) {
    header('Location: index.php'); exit;
}

$err = null;
$next = $_GET['next'] ?? 'index.php';
if (!preg_match('~^[a-zA-Z0-9_./?=&-]+$~', $next)) $next = 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cfc_check_csrf()) {
        $err = 'Session expired. Refresh and try again.';
    } else {
        $res = cfc_login($_POST['password'] ?? '');
        if ($res['ok']) { header('Location: ' . $next); exit; }
        $err = $res['error'];
    }
}

$siteName = cfc_config('general.site_name', 'The Castle Fun Center');
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — <?= h($siteName) ?> Monitor</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="brand">
        <img src="assets/logo.png" alt="" class="brand-logo" onerror="this.style.display='none'">
        <div class="brand-text">
            <div class="brand-name"><?= h($siteName) ?></div>
            <div class="brand-sub">Monitor Dashboard</div>
        </div>
    </div>
    <?php if ($err): ?>
        <div class="alert err"><?= h($err) ?></div>
    <?php endif; ?>
    <form method="post" class="stack">
        <?= cfc_csrf_input() ?>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" autofocus required>
        </label>
        <button class="btn primary" type="submit">Sign in</button>
    </form>
    <p class="muted small">Authorized personnel only. All access is logged.</p>
</div>
</body>
</html>
