<?php
require_once __DIR__ . '/lib/bootstrap.php';
cfc_require_login();
$pageTitle = 'Settings';
$activeNav = 'settings';
$cfg = cfc_config_all();
require __DIR__ . '/lib/header.php';

$cronToken = $cfg['auth']['cron_token'] ?? '';
$baseUrl = cfc_base_url();
?>
<div class="page-header">
    <div>
        <h1>Settings</h1>
        <p class="muted">Configure alerts, thresholds, and administrative options.</p>
    </div>
</div>

<div class="settings-grid">

    <div class="card">
        <div class="card-header"><h2>General</h2></div>
        <form class="card-body stack" id="form-general">
            <label>Site name
                <input name="site_name" value="<?= h($cfg['general']['site_name'] ?? 'The Castle Fun Center') ?>">
            </label>
            <label>Timezone
                <select name="timezone">
                    <?php foreach (timezone_identifiers_list() as $tz): ?>
                        <option value="<?= h($tz) ?>" <?= ($cfg['general']['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= h($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check">
                <input type="checkbox" name="alerts_enabled" <?= !empty($cfg['general']['alerts_enabled']) ? 'checked' : '' ?>>
                Global alerts enabled
            </label>
            <label>Alert cooldown (minutes)
                <input type="number" name="alert_cooldown" min="1" max="1440" value="<?= h($cfg['general']['alert_cooldown'] ?? 15) ?>">
            </label>
            <label class="check">
                <input type="checkbox" name="public_status" <?= !empty($cfg['general']['public_status']) ? 'checked' : '' ?>>
                Publish public status page (status.php accessible without login)
            </label>
            <button class="btn primary" type="submit">Save general</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Slack Alerts</h2></div>
        <form class="card-body stack" id="form-slack">
            <p class="muted small">Create a Slack app with <code>chat:write</code>, install it in your workspace, invite the bot into the target channel, then paste the <strong>Bot User OAuth Token</strong> below.</p>
            <label>Bot token
                <input name="bot_token" type="password" placeholder="<?= !empty($cfg['slack']['bot_token']) ? '(configured — leave blank to keep)' : 'xoxb-...' ?>">
            </label>
            <label>Channel
                <input name="channel" value="<?= h($cfg['slack']['channel'] ?? '#monitoring') ?>" placeholder="#monitoring">
            </label>
            <label>Mention on alert (optional)
                <input name="mention" value="<?= h($cfg['slack']['mention'] ?? '') ?>" placeholder="<!channel> or <@U12345>">
            </label>
            <div class="grid-2">
                <label class="check"><input type="checkbox" name="alert_on_down" <?= !empty($cfg['slack']['alert_on_down']) ? 'checked' : '' ?>> On down</label>
                <label class="check"><input type="checkbox" name="alert_on_recovery" <?= !empty($cfg['slack']['alert_on_recovery']) ? 'checked' : '' ?>> On recovery</label>
                <label class="check"><input type="checkbox" name="alert_on_slow" <?= !empty($cfg['slack']['alert_on_slow']) ? 'checked' : '' ?>> On slow</label>
                <label class="check"><input type="checkbox" name="alert_on_ssl" <?= !empty($cfg['slack']['alert_on_ssl']) ? 'checked' : '' ?>> On SSL warning</label>
            </div>
            <div class="row">
                <button class="btn primary" type="submit">Save Slack</button>
                <button class="btn ghost" type="button" id="btn-slack-test">Send test</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Email Alerts</h2></div>
        <form class="card-body stack" id="form-email">
            <p class="muted small">Uses the server's PHP <code>mail()</code> — works on most cPanel hosts without extra config.</p>
            <label class="check">
                <input type="checkbox" name="enabled" <?= !empty($cfg['email']['enabled']) ? 'checked' : '' ?>>
                Enable email alerts
            </label>
            <label>Recipients (comma-separated)
                <input name="recipients" value="<?= h($cfg['email']['recipients'] ?? '') ?>" placeholder="ops@example.com, oncall@example.com">
            </label>
            <div class="grid-2">
                <label>From name <input name="from_name" value="<?= h($cfg['email']['from_name'] ?? 'Castle Monitor') ?>"></label>
                <label>From email <input name="from_email" value="<?= h($cfg['email']['from_email'] ?? '') ?>" placeholder="noreply@yourdomain.com"></label>
            </div>
            <div class="grid-2">
                <label class="check"><input type="checkbox" name="alert_on_down" <?= !empty($cfg['email']['alert_on_down']) ? 'checked' : '' ?>> On down</label>
                <label class="check"><input type="checkbox" name="alert_on_recovery" <?= !empty($cfg['email']['alert_on_recovery']) ? 'checked' : '' ?>> On recovery</label>
            </div>
            <button class="btn primary" type="submit">Save email</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Thresholds</h2></div>
        <form class="card-body stack" id="form-thresholds">
            <label>Slow response (ms)
                <input type="number" name="slow_response_ms" min="100" max="60000" value="<?= h($cfg['thresholds']['slow_response_ms'] ?? 2500) ?>">
            </label>
            <label>SSL warning days
                <input type="number" name="ssl_warn_days" min="1" max="365" value="<?= h($cfg['thresholds']['ssl_warn_days'] ?? 30) ?>">
            </label>
            <label>Consecutive failures before alert
                <input type="number" name="fail_streak" min="1" max="10" value="<?= h($cfg['thresholds']['fail_streak'] ?? 2) ?>">
            </label>
            <button class="btn primary" type="submit">Save thresholds</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Maintenance Mode</h2></div>
        <form class="card-body stack" id="form-maintenance">
            <label class="check">
                <input type="checkbox" name="enabled" id="maint-enabled">
                Pause all checks and suppress alerts
            </label>
            <label>Message <input name="message" id="maint-message" placeholder="Scheduled maintenance"></label>
            <div class="grid-2">
                <label>Starts (optional) <input type="datetime-local" name="start" id="maint-start"></label>
                <label>Ends (optional) <input type="datetime-local" name="end" id="maint-end"></label>
            </div>
            <button class="btn primary" type="submit">Apply</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Change Password</h2></div>
        <form class="card-body stack" id="form-password" autocomplete="off">
            <label>Current password <input type="password" name="current" required></label>
            <label>New password (min 10 chars) <input type="password" name="new" minlength="10" required></label>
            <label>Confirm new <input type="password" name="confirm" minlength="10" required></label>
            <button class="btn primary" type="submit">Update password</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Cron Setup</h2></div>
        <div class="card-body stack">
            <p class="muted small">Add this cron job in cPanel (every 5 minutes recommended):</p>
            <pre class="code" id="cron-line">curl -s "<?= h($baseUrl) ?>/cron.php?token=<?= h($cronToken) ?>" &gt;/dev/null</pre>
            <div class="row">
                <button class="btn ghost" id="btn-copy-cron" type="button">Copy</button>
                <button class="btn ghost" id="btn-rotate-cron" type="button">Rotate token</button>
                <button class="btn ghost" id="btn-run-cron" type="button">Run now</button>
            </div>
            <p class="muted small">Token is stored in <code>uptime_data/config.ini</code>. Keep it secret.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Storage</h2></div>
        <div class="card-body stack">
            <div id="storage-stats" class="muted">Loading…</div>
            <div class="row">
                <button class="btn ghost" id="btn-cleanup" type="button">Run cleanup</button>
                <a class="btn ghost" href="api.php?path=export" target="_blank">Export monitors</a>
            </div>
        </div>
    </div>

</div>

<script src="assets/app.js"></script>
<script>CFC.initSettingsPage(<?= json_encode([
    'maintenance' => cfc_maintenance(),
    'cronToken' => $cronToken,
    'baseUrl' => $baseUrl,
]) ?>);</script>
<?php require __DIR__ . '/lib/footer.php'; ?>
