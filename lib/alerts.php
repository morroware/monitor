<?php
/**
 * Alert delivery: Slack (bot token) + optional email via mail().
 * Respects cooldowns, maintenance mode, and per-monitor enablement.
 */

function cfc_dispatch_alerts(array $monitor, string $newStatus, string $prevStatus, ?string $error, array $check): void {
    if (!cfc_config('general.alerts_enabled', true)) return;
    if (($monitor['config']['alerts_enabled'] ?? true) === false) return;
    if (cfc_in_maintenance()) return;
    if ($newStatus === $prevStatus) return;

    if (!cfc_alert_cooldown_ok($monitor['id'])) return;

    $slow = null;
    if (!empty($check['response_time'])) {
        $threshold = (int)cfc_config('thresholds.slow_response_ms', 2500);
        if ($check['response_time'] > $threshold) $slow = $check['response_time'];
    }

    $slackOk = cfc_send_slack($monitor, $newStatus, $prevStatus, $error, $check);
    $emailOk = cfc_send_email($monitor, $newStatus, $prevStatus, $error, $check);

    cfc_log(CFC_ALERTS_LOG, sprintf(
        '%s -> %s monitor=%s slack=%s email=%s err=%s',
        $prevStatus, $newStatus, $monitor['name'],
        $slackOk === null ? 'skip' : ($slackOk ? 'ok' : 'fail'),
        $emailOk === null ? 'skip' : ($emailOk ? 'ok' : 'fail'),
        $error ?? ''
    ));
}

/* ---- Cooldown ---- */

function cfc_cooldown_file(string $id): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
    return CFC_CHECKS_DIR . '/cooldown_' . $safe . '.txt';
}

function cfc_alert_cooldown_ok(string $id): bool {
    $file = cfc_cooldown_file($id);
    $last = file_exists($file) ? (int)@file_get_contents($file) : 0;
    $minutes = (int)cfc_config('general.alert_cooldown', 15);
    if ((time() - $last) < ($minutes * 60)) return false;
    @file_put_contents($file, time());
    return true;
}

function cfc_clear_cooldown(string $id): void {
    $file = cfc_cooldown_file($id);
    if (file_exists($file)) @unlink($file);
}

/* ---- Slack bot ---- */

function cfc_send_slack(array $monitor, string $status, string $prev, ?string $error, array $check) {
    $token = trim((string)cfc_config('slack.bot_token', ''));
    $channel = trim((string)cfc_config('slack.channel', ''));
    if (!$token || !$channel) return null;
    if ($status === 'down' && !cfc_config('slack.alert_on_down', true)) return null;
    if ($status === 'up'   && !cfc_config('slack.alert_on_recovery', true)) return null;

    $mention = trim((string)cfc_config('slack.mention', ''));
    $emoji   = $status === 'up' ? ':large_green_circle:' : ':rotating_light:';
    $verb    = $status === 'up' ? 'Recovered' : 'Down';

    $fields = [
        ['type' => 'mrkdwn', 'text' => "*Monitor*\n" . $monitor['name']],
        ['type' => 'mrkdwn', 'text' => "*Status*\n" . ucfirst($status) . " (was " . $prev . ")"],
        ['type' => 'mrkdwn', 'text' => "*Target*\n<" . $monitor['target'] . "|" . parse_url($monitor['target'], PHP_URL_HOST) . ">"],
        ['type' => 'mrkdwn', 'text' => "*Uptime 24h*\n" . ($monitor['uptime_24h'] ?? 'n/a') . '%'],
    ];
    if (!empty($check['response_time'])) {
        $fields[] = ['type' => 'mrkdwn', 'text' => "*Response*\n" . $check['response_time'] . ' ms'];
    }
    if (!empty($check['http_code'])) {
        $fields[] = ['type' => 'mrkdwn', 'text' => "*HTTP*\n" . $check['http_code']];
    }

    $blocks = [
        ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "$emoji $verb — " . $monitor['name']]],
        ['type' => 'section', 'fields' => $fields],
    ];
    if ($error && $status === 'down') {
        $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Error*\n```" . substr($error, 0, 400) . "```"]];
    }
    $text = $verb . ': ' . $monitor['name'] . ($mention ? ' ' . $mention : '');
    if ($mention) {
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $mention]]];
    }

    $payload = ['channel' => $channel, 'text' => $text, 'blocks' => $blocks];

    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$resp, true);
    return $http === 200 && !empty($decoded['ok']);
}

function cfc_slack_test(?string $customMessage = null): array {
    $token = trim((string)cfc_config('slack.bot_token', ''));
    $channel = trim((string)cfc_config('slack.channel', ''));
    if (!$token || !$channel) return ['ok' => false, 'error' => 'Slack not configured'];
    $msg = $customMessage ?: ':test_tube: Test alert from Castle Monitor — ' . date('Y-m-d H:i:s');
    $ch = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['channel' => $channel, 'text' => $msg]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode((string)$resp, true) ?: [];
    if ($http === 200 && !empty($decoded['ok'])) return ['ok' => true];
    return ['ok' => false, 'error' => $decoded['error'] ?? ('HTTP ' . $http)];
}

/* ---- Email (via PHP mail — fine on cPanel) ---- */

function cfc_send_email(array $monitor, string $status, string $prev, ?string $error, array $check) {
    if (!cfc_config('email.enabled', false)) return null;
    $recipients = trim((string)cfc_config('email.recipients', ''));
    if (!$recipients) return null;
    if ($status === 'down' && !cfc_config('email.alert_on_down', true)) return null;
    if ($status === 'up'   && !cfc_config('email.alert_on_recovery', true)) return null;

    $fromName  = cfc_config('email.from_name', 'Castle Monitor');
    $fromEmail = cfc_config('email.from_email', '') ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $subject = '[Castle Monitor] ' . $monitor['name'] . ' is ' . strtoupper($status);
    $body = "Monitor: {$monitor['name']}\n"
          . "Target:  {$monitor['target']}\n"
          . "Status:  " . ucfirst($status) . " (was $prev)\n"
          . "Time:    " . date('Y-m-d H:i:s') . "\n";
    if (!empty($check['response_time'])) $body .= "Response: {$check['response_time']} ms\n";
    if (!empty($check['http_code']))     $body .= "HTTP:     {$check['http_code']}\n";
    if ($error) $body .= "Error:    $error\n";

    $headers = "From: $fromName <$fromEmail>\r\n"
             . "Reply-To: $fromEmail\r\n"
             . "X-Mailer: CastleMonitor/" . CFC_VERSION . "\r\n"
             . "Content-Type: text/plain; charset=utf-8";
    return @mail($recipients, $subject, $body, $headers);
}
