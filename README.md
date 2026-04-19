# Castle Fun Center — Uptime Monitor

A self-contained PHP uptime / health monitor for **The Castle Fun Center**, built
for single-user deployment on shared cPanel hosting. Vanilla PHP + vanilla JS +
vanilla CSS — no build tools, no Composer, no database.

## Features

- **Monitor types**: HTTP(S), TCP port.
- **Per-monitor settings**: interval, method, expected status codes, keyword
  required / forbidden, follow redirects, verify SSL, alerts on/off.
- **SSL certificate tracking**: issuer, expiry date, days remaining; warns
  before renewal.
- **Incident history**: automatic open/resolve, with duration; alert log.
- **Alerts**: Slack (bot token, rich blocks, optional @mention) and email
  (via the server's `mail()`).
- **Maintenance mode**: time-boxed, silences alerts and pauses checks.
- **Public status page** (optional, toggle in Settings): lightweight, cached,
  no login required.
- **Diagnostic tools**: ad-hoc HTTP probe, TCP port check, DNS lookup, SSL
  inspector, HTTP-header inspector.
- **Session-based login** with rate-limited brute-force protection.
- **CSRF protection** on every state-changing request.
- **cPanel cron endpoint** with a shared-secret token.
- **Import / export** monitors as JSON.
- **Dark and light themes** (auto-persisted).

## Install (cPanel)

1. **Upload**: drop this entire folder into `public_html/monitor` (or any
   subdirectory) via File Manager / FTP.
2. **Permissions**: nothing special — scripts will create `uptime_data/` as
   needed. If your host forces stricter perms, ensure the `monitor/uptime_data`
   directory is writable by the web user (usually `755`).
3. **Visit** `https://yourdomain.com/monitor/install.php` in your browser.
   - Set a strong admin password (≥ 10 chars).
   - Confirm the timezone.
   - Click **Install**. The installer creates `uptime_data/config.ini`,
     generates a cron token, writes `.htaccess` hardening, and renames itself
     so it can't be re-run.
4. **Drop in your logo**: copy your `logo.png` to `monitor/assets/logo.png`.
   Recommended: square PNG, transparent background, 256×256 or larger.
5. **Configure alerts**: log in and go to **Settings → Slack Alerts**.
   - In Slack, create an app, add the `chat:write` scope, install to the
     workspace, invite the bot to your target channel.
   - Paste the **Bot User OAuth Token** (starts with `xoxb-`) and the channel.
   - Click **Send test** to verify.
6. **Add a cron job** (cPanel → Cron Jobs). Paste the command shown on the
   Settings page (copy button provided). Every 5 minutes is a good default:

   ```
   */5 * * * * curl -s "https://yourdomain.com/monitor/cron.php?token=YOUR_TOKEN" >/dev/null 2>&1
   ```

7. **Add your first monitor** on the Monitors page.

## File layout

```
monitor/
├── index.php         # Dashboard
├── login.php         # Sign in
├── logout.php
├── install.php       # One-time installer (self-disables)
├── monitors.php      # CRUD for monitors
├── incidents.php     # Incident + alert history
├── tools.php         # Diagnostic tools
├── settings.php      # Configuration UI
├── status.php        # Public (or gated) status page
├── api.php           # REST backend
├── cron.php          # Scheduled-check endpoint
├── .htaccess         # Hardening
├── lib/
│   ├── bootstrap.php # Sessions, constants, wiring
│   ├── helpers.php   # Escaping, JSON I/O, formatting
│   ├── config.php    # INI parsing + dot-path lookup
│   ├── auth.php      # Session + CSRF + throttling
│   ├── storage.php   # Monitors, checks, incidents, maintenance
│   ├── checks.php    # HTTP, TCP, SSL, DNS primitives
│   ├── alerts.php    # Slack + email dispatch
│   ├── header.php    # Shared page chrome
│   └── footer.php
├── assets/
│   ├── app.css
│   ├── app.js
│   ├── favicon.svg
│   └── logo.png      # ← you drop this in
├── uptime_data/      # Created on first run; denied from the web
│   ├── config.ini
│   ├── monitors.json
│   ├── incidents.json
│   ├── maintenance.json
│   ├── alerts.log
│   └── checks/
│       └── {id}.json
└── README.md
```

## Security notes

- The admin password is stored as `password_hash()` in `uptime_data/config.ini`.
  The `uptime_data` directory is blocked by `.htaccess`.
- A cron token (random 48-hex) is generated at install; the cron endpoint
  requires it. You can rotate it from Settings.
- Failed logins are throttled: 5 attempts / 15 minutes per IP.
- All state-changing API calls require an `X-CSRF-Token` header; the browser
  code sends it automatically from the meta tag in the page head.
- Sessions use `HttpOnly`, `SameSite=Lax`, and `Secure` when the request is
  HTTPS or behind a proxy that sets `X-Forwarded-Proto: https`.
- Secrets (Slack token, cron token) are never returned to the browser — the
  API returns `***set***` instead.

## Troubleshooting

- **Installer says the data dir isn't writable**: set the `uptime_data`
  directory to mode `755` (or `775` if your host requires it).
- **Slack test fails with `not_in_channel`**: invite the bot to the channel
  (`/invite @YourBot`).
- **No monitors getting checked**: verify the cron job runs (cPanel → Cron
  Jobs → Log). You can also press **Run now** on the Settings page to trigger
  a check manually.
- **PHP errors**: see `uptime_data/php-errors.log`. Consider setting
  `display_errors = Off` in your host's PHP config if it isn't already.
- **ICMP ping**: intentionally not supported — shared hosts almost universally
  block it. Use a TCP check against port 80 or 443 instead.

## Changing the admin password later

Settings → Change Password. You'll need the current password. If you lose it,
you can manually regenerate the hash (command line where PHP is available):

```
php -r 'echo password_hash("newpassword", PASSWORD_DEFAULT) . PHP_EOL;'
```

and paste it into `uptime_data/config.ini` under `[auth] password_hash = "..."`.

## Version

v1.0.0-beta — single-user beta build.
