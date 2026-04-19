<?php
/**
 * One-time installer.
 * Creates config.ini with a secure password hash and a cron token, verifies
 * writable paths, seeds .htaccess, and disables itself on success.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$alreadyInstalled = file_exists(CFC_CONFIG_FILE) && cfc_config('auth.password_hash', '') !== '';
$messages = [];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';
    $siteName = trim($_POST['site_name'] ?? 'The Castle Fun Center');
    $timezone = $_POST['timezone'] ?? 'America/New_York';

    if (strlen($password) < 10) $errors[] = 'Password must be at least 10 characters.';
    if ($password !== $confirm)  $errors[] = 'Password confirmation does not match.';
    if (!in_array($timezone, timezone_identifiers_list(), true)) $errors[] = 'Invalid timezone.';

    if (!$errors) {
        $defaults = cfc_config_defaults();
        $defaults['general']['site_name'] = $siteName;
        $defaults['general']['timezone']  = $timezone;
        $defaults['auth']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $defaults['auth']['cron_token']    = bin2hex(random_bytes(24));

        if (!cfc_config_save($defaults)) {
            $errors[] = 'Could not write config file: ' . CFC_CONFIG_FILE;
        } else {
            @chmod(CFC_CONFIG_FILE, 0640);
            $messages[] = 'Configuration saved.';

            // Seed protective .htaccess files.
            @file_put_contents(CFC_DATA_DIR . '/.htaccess', "Require all denied\nOrder allow,deny\nDeny from all\n");
            $rootHt = CFC_ROOT . '/.htaccess';
            $htBlock = "# --- Castle Monitor hardening ---\n"
                     . "<FilesMatch \"\\.(ini|log|md)$\">\n    Require all denied\n</FilesMatch>\n"
                     . "DirectoryIndex index.php index.html\n"
                     . "Options -Indexes\n";
            if (!file_exists($rootHt)) @file_put_contents($rootHt, $htBlock);
            $messages[] = 'Security files written.';

            // Rename installer to prevent reuse.
            @rename(__FILE__, __FILE__ . '.disabled-' . date('YmdHis'));
            $messages[] = 'Installer disabled. Delete install.php.disabled-* when ready.';
            $done = true;
        }
    }
}

$tzList = timezone_identifiers_list();
$siteName = cfc_config('general.site_name', 'The Castle Fun Center');
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — <?= h($siteName) ?> Monitor</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="brand">
        <div class="brand-mark">🏰</div>
        <div class="brand-text">
            <div class="brand-name"><?= h($siteName) ?></div>
            <div class="brand-sub">Monitor · Installation</div>
        </div>
    </div>

<?php if ($alreadyInstalled): ?>
    <div class="alert warn">
        This monitor is already installed. For security, delete <code>install.php</code>
        and use the dashboard to change the password.
    </div>
    <a class="btn primary" href="login.php">Go to Login</a>

<?php elseif ($done): ?>
    <div class="alert ok">
        <?php foreach ($messages as $m) echo '<div>' . h($m) . '</div>'; ?>
    </div>
    <p>Your <strong>cron token</strong> has been generated. Open Settings later
    to view or rotate it, and add this cron job in cPanel (recommend every 5 min):</p>
    <pre class="code">curl -s "<?= h(cfc_base_url()) ?>/cron.php?token=YOUR_TOKEN" &gt;/dev/null</pre>
    <a class="btn primary" href="login.php">Continue to Login</a>

<?php else: ?>
    <p class="muted">Set a strong admin password. You'll use this to sign in to the monitor dashboard.</p>
    <?php foreach ($errors as $e): ?>
        <div class="alert err"><?= h($e) ?></div>
    <?php endforeach; ?>
    <form method="post" class="stack" autocomplete="off">
        <label>Site Name
            <input type="text" name="site_name" value="<?= h($siteName) ?>" required>
        </label>
        <label>Timezone
            <select name="timezone" required>
                <?php foreach ($tzList as $tz): ?>
                    <option value="<?= h($tz) ?>" <?= $tz === 'America/New_York' ? 'selected' : '' ?>><?= h($tz) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Admin Password
            <input type="password" name="password" minlength="10" required>
        </label>
        <label>Confirm Password
            <input type="password" name="confirm" minlength="10" required>
        </label>
        <button class="btn primary" type="submit">Install</button>
    </form>
    <p class="muted small">Writable checks:
        data dir: <strong><?= is_writable(CFC_DATA_DIR) ? 'OK' : 'NOT WRITABLE' ?></strong>,
        checks dir: <strong><?= is_writable(CFC_CHECKS_DIR) ? 'OK' : 'NOT WRITABLE' ?></strong>,
        curl: <strong><?= function_exists('curl_init') ? 'OK' : 'MISSING' ?></strong>,
        openssl: <strong><?= function_exists('openssl_x509_parse') ? 'OK' : 'MISSING' ?></strong>.
    </p>
<?php endif; ?>
</div>
</body>
</html>
