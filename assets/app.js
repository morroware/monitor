/* ============================================================
   Castle Fun Center Monitor — client JS
   Plain vanilla, no framework. Exposes window.CFC with per-page inits.
   ============================================================ */

(function () {
    'use strict';

    const CFC = window.CFC = {};

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    /* ---------- Networking ---------- */

    async function api(path, { method = 'GET', body, signal } = {}) {
        const opts = { method, headers: {}, signal, credentials: 'same-origin' };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = typeof body === 'string' ? body : JSON.stringify(body);
        }
        if (method !== 'GET' && method !== 'HEAD') {
            opts.headers['X-CSRF-Token'] = csrf;
        }
        const url = 'api.php?path=' + encodeURIComponent(path);
        const res = await fetch(url, opts);
        let data = null;
        try { data = await res.json(); } catch (_) { /* non-JSON */ }
        if (!res.ok) {
            const msg = (data && (data.message || data.error)) || `HTTP ${res.status}`;
            throw new Error(msg);
        }
        return data;
    }
    CFC.api = api;

    /* ---------- Toasts ---------- */

    function toast(message, kind = 'ok', timeout = 3200) {
        const c = document.getElementById('toast-container');
        if (!c) return;
        const el = document.createElement('div');
        el.className = 'toast ' + kind;
        el.textContent = message;
        c.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 200); }, timeout);
    }
    CFC.toast = toast;

    /* ---------- Theme toggle ---------- */

    function initTheme() {
        const stored = localStorage.getItem('cfc-theme');
        if (stored) document.documentElement.setAttribute('data-theme', stored);
        const btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.addEventListener('click', () => {
                const cur = document.documentElement.getAttribute('data-theme') || 'dark';
                const next = cur === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('cfc-theme', next);
            });
        }
    }
    document.addEventListener('DOMContentLoaded', initTheme);

    /* ---------- Formatting helpers ---------- */

    const relTime = (tsSeconds) => {
        if (!tsSeconds) return 'never';
        const diff = Math.floor(Date.now() / 1000 - tsSeconds);
        if (diff < 10) return 'just now';
        if (diff < 60) return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    };

    const fmtDuration = (seconds) => {
        if (!seconds) return '—';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
        if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
        return Math.floor(seconds / 86400) + 'd';
    };

    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    function sparklineSVG(values, w = 140, h = 28) {
        if (!values || !values.length) return '';
        const max = Math.max(...values, 1);
        const min = 0;
        const range = max - min || 1;
        const step = w / Math.max(1, values.length - 1);
        const pts = values.map((v, i) => {
            const x = i * step;
            const y = h - ((v - min) / range) * (h - 4) - 2;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');
        return `<svg class="sparkline" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
            <polyline fill="none" stroke="currentColor" stroke-width="1.5" points="${pts}"/>
        </svg>`;
    }

    /* ---------- Dashboard ---------- */

    CFC.initDashboard = function () {
        let monitors = [];

        async function refreshAll() {
            try {
                const [stats, mon, inc, maint] = await Promise.all([
                    api('stats'),
                    api('monitors'),
                    api('incidents'),
                    api('maintenance'),
                ]);
                monitors = mon;
                renderStats(stats);
                renderMonitors(mon);
                renderIncidents(inc.filter(i => i.status === 'open').slice(0, 8));
                document.getElementById('maintenance-banner').classList.toggle('hidden', !maint.enabled);
            } catch (e) {
                toast('Refresh failed: ' + e.message, 'err');
            }
        }

        function renderStats(s) {
            document.getElementById('s-total').textContent = s.total;
            document.getElementById('s-up').textContent    = s.up;
            document.getElementById('s-down').textContent  = s.down;
            document.getElementById('s-ssl').textContent   = s.ssl_warnings;
            document.getElementById('s-avg').textContent   = (s.avg_uptime_24h ?? 0) + '%';
        }

        function renderMonitors(list) {
            const wrap = document.getElementById('monitors-list');
            if (!list.length) {
                wrap.innerHTML = `<div class="loading">No monitors yet. <a href="monitors.php">Add your first one →</a></div>`;
                return;
            }
            wrap.innerHTML = list.map(m => `
                <div class="monitor-card">
                    <div>
                        <div class="monitor-head">
                            <span class="status-badge ${m.status}">${m.status}</span>
                            <div class="monitor-name">${escapeHtml(m.name)}</div>
                        </div>
                        <div class="monitor-url">${escapeHtml(m.target)}</div>
                        ${m.error ? `<div class="muted small">⚠ ${escapeHtml(m.error)}</div>` : ''}
                    </div>
                    <div class="monitor-meta">
                        ${sparklineSVG(m.sparkline)}
                        <div class="uptime-pct" title="30-day uptime">${m.uptime_30d}%</div>
                        <div class="response-ms">${m.response_time != null ? m.response_time + 'ms' : '—'}</div>
                        <div class="muted small">${relTime(m.last_check)}</div>
                        <button class="btn ghost sm" data-check="${m.id}" type="button">Check</button>
                    </div>
                </div>
            `).join('');
            wrap.querySelectorAll('[data-check]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    btn.disabled = true; btn.textContent = '...';
                    try {
                        await api('monitors/' + btn.dataset.check + '/check', { method: 'POST' });
                        await refreshAll();
                        toast('Check complete');
                    } catch (e) { toast('Check failed: ' + e.message, 'err'); }
                    finally { btn.disabled = false; btn.textContent = 'Check'; }
                });
            });
        }

        function renderIncidents(list) {
            const wrap = document.getElementById('incidents-list');
            if (!list.length) {
                wrap.innerHTML = `<div class="loading">No active incidents 🎉</div>`;
                return;
            }
            wrap.innerHTML = list.map(i => `
                <div class="status-row">
                    <div>
                        <div class="monitor-name">${escapeHtml(i.monitor_name)}</div>
                        <div class="muted small">${escapeHtml(i.error || 'Service down')}</div>
                    </div>
                    <div class="status-meta">
                        <span class="badge err">OPEN</span>
                        <span class="muted small">Started ${relTime(i.started)}</span>
                    </div>
                </div>
            `).join('');
        }

        document.getElementById('btn-refresh').addEventListener('click', refreshAll);
        document.getElementById('monitor-filter').addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            document.querySelectorAll('.monitor-card').forEach(el => {
                el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        refreshAll();
        setInterval(refreshAll, 30000);
    };

    /* ---------- Monitors page ---------- */

    CFC.initMonitorsPage = function () {
        const modal   = document.getElementById('monitor-modal');
        const form    = document.getElementById('monitor-form');
        const title   = document.getElementById('modal-title');
        const typeSel = document.getElementById('m-type');
        const httpOpts = document.getElementById('http-options');
        const tcpOpts  = document.getElementById('tcp-options');

        function openModal(mon = null) {
            title.textContent = mon ? 'Edit Monitor' : 'New Monitor';
            form.reset();
            document.getElementById('m-id').value = mon?.id || '';
            document.getElementById('m-follow').checked = true;
            document.getElementById('m-alerts').checked = true;
            if (mon) {
                document.getElementById('m-name').value    = mon.name || '';
                document.getElementById('m-target').value  = mon.target || '';
                document.getElementById('m-interval').value = mon.interval || 300;
                const c = mon.config || {};
                typeSel.value = c.check_type || 'http';
                document.getElementById('m-method').value = c.method || 'GET';
                document.getElementById('m-timeout').value = c.timeout || 15;
                document.getElementById('m-expected').value = Array.isArray(c.expected_status)
                    ? c.expected_status.join(',') : '200,201,204,301,302,303,307,308';
                document.getElementById('m-keyword').value = c.keyword || '';
                document.getElementById('m-forbidden').value = c.forbidden_keyword || '';
                document.getElementById('m-follow').checked = c.follow_redirects !== false;
                document.getElementById('m-verifyssl').checked = !!c.verify_ssl;
                document.getElementById('m-port').value = c.port || 443;
                document.getElementById('m-alerts').checked = c.alerts_enabled !== false;
            }
            updateTypeUI();
            modal.classList.remove('hidden');
            document.getElementById('m-name').focus();
        }

        function closeModal() { modal.classList.add('hidden'); }

        function updateTypeUI() {
            const t = typeSel.value;
            httpOpts.classList.toggle('hidden', t !== 'http');
            tcpOpts.classList.toggle('hidden', t !== 'tcp');
        }

        typeSel.addEventListener('change', updateTypeUI);
        modal.addEventListener('click', (e) => { if (e.target === modal || e.target.dataset.close !== undefined) closeModal(); });
        document.getElementById('btn-new-monitor').addEventListener('click', () => openModal());

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('m-id').value;
            const type = typeSel.value;
            const expected = document.getElementById('m-expected').value
                .split(',').map(s => parseInt(s.trim(), 10)).filter(Boolean);
            const payload = {
                name: document.getElementById('m-name').value.trim(),
                target: document.getElementById('m-target').value.trim(),
                interval: parseInt(document.getElementById('m-interval').value, 10) || 300,
                config: {
                    check_type: type,
                    alerts_enabled: document.getElementById('m-alerts').checked,
                    method: document.getElementById('m-method').value,
                    timeout: parseInt(document.getElementById('m-timeout').value, 10) || 15,
                    expected_status: expected.length ? expected : [200],
                    keyword: document.getElementById('m-keyword').value,
                    forbidden_keyword: document.getElementById('m-forbidden').value,
                    follow_redirects: document.getElementById('m-follow').checked,
                    verify_ssl: document.getElementById('m-verifyssl').checked,
                    port: parseInt(document.getElementById('m-port').value, 10) || null,
                }
            };
            try {
                if (id) await api('monitors/' + id, { method: 'PUT', body: payload });
                else    await api('monitors',         { method: 'POST', body: payload });
                closeModal();
                toast('Saved');
                loadList();
            } catch (err) { toast('Save failed: ' + err.message, 'err'); }
        });

        async function loadList() {
            const tbody = document.querySelector('#monitors-table tbody');
            try {
                const list = await api('monitors');
                if (!list.length) {
                    tbody.innerHTML = `<tr><td colspan="9" class="loading">No monitors yet.</td></tr>`;
                    return;
                }
                tbody.innerHTML = list.map(m => `
                    <tr>
                        <td>${escapeHtml(m.name)}</td>
                        <td class="muted">${escapeHtml(m.target)}</td>
                        <td>${escapeHtml(m.config?.check_type || 'http')}</td>
                        <td>${m.interval}s</td>
                        <td><span class="status-badge ${m.status}">${m.status}</span></td>
                        <td>${m.uptime_24h}%</td>
                        <td>${m.uptime_7d}%</td>
                        <td>${m.uptime_30d}%</td>
                        <td class="row">
                            <button class="btn ghost sm" data-check="${m.id}" type="button">Check</button>
                            <button class="btn ghost sm" data-test="${m.id}" type="button">Test alert</button>
                            <button class="btn ghost sm" data-edit="${m.id}" type="button">Edit</button>
                            <button class="btn ghost sm" data-del="${m.id}" type="button">✕</button>
                        </td>
                    </tr>
                `).join('');
                tbody.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', async () => {
                    try { const m = await api('monitors/' + b.dataset.edit); openModal(m); }
                    catch (e) { toast(e.message, 'err'); }
                }));
                tbody.querySelectorAll('[data-check]').forEach(b => b.addEventListener('click', async () => {
                    b.disabled = true;
                    try { await api('monitors/' + b.dataset.check + '/check', { method: 'POST' }); toast('Checked'); loadList(); }
                    catch (e) { toast(e.message, 'err'); } finally { b.disabled = false; }
                }));
                tbody.querySelectorAll('[data-test]').forEach(b => b.addEventListener('click', async () => {
                    if (!confirm('Send a test alert for this monitor?')) return;
                    try { await api('monitors/' + b.dataset.test + '/test-alert', { method: 'POST' }); toast('Test alert sent'); }
                    catch (e) { toast(e.message, 'err'); }
                }));
                tbody.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
                    if (!confirm('Delete this monitor and all its history?')) return;
                    try { await api('monitors/' + b.dataset.del, { method: 'DELETE' }); toast('Deleted'); loadList(); }
                    catch (e) { toast(e.message, 'err'); }
                }));
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" class="loading">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        document.getElementById('monitor-filter').addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            document.querySelectorAll('#monitors-table tbody tr').forEach(r => {
                r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });

        document.getElementById('btn-export').addEventListener('click', () => {
            window.location.href = 'api.php?path=export';
        });
        document.getElementById('import-file').addEventListener('change', async (e) => {
            const file = e.target.files[0]; if (!file) return;
            try {
                const text = await file.text();
                const parsed = JSON.parse(text);
                const res = await api('import', { method: 'POST', body: parsed });
                toast(`Imported ${res.added} monitors`); loadList();
            } catch (err) { toast('Import failed: ' + err.message, 'err'); }
            e.target.value = '';
        });

        loadList();
    };

    /* ---------- Incidents page ---------- */

    CFC.initIncidentsPage = function () {
        async function load() {
            const tbody = document.querySelector('#incidents-table tbody');
            try {
                const list = await api('incidents');
                if (!list.length) {
                    tbody.innerHTML = `<tr><td colspan="5" class="loading">No incidents yet.</td></tr>`;
                } else {
                    tbody.innerHTML = list.map(i => `
                        <tr>
                            <td>${escapeHtml(i.monitor_name)}</td>
                            <td>${new Date(i.started * 1000).toLocaleString()}</td>
                            <td>${i.duration ? fmtDuration(i.duration) : '—'}</td>
                            <td><span class="badge ${i.status === 'resolved' ? 'ok' : 'err'}">${i.status}</span></td>
                            <td class="muted small">${escapeHtml(i.error || '')}</td>
                        </tr>
                    `).join('');
                }
                const logs = await api('logs');
                document.getElementById('alert-log').textContent = logs.log || '(empty)';
            } catch (e) { toast('Load failed: ' + e.message, 'err'); }
        }
        document.getElementById('btn-clear-incidents').addEventListener('click', async () => {
            if (!confirm('Clear all incident history?')) return;
            await api('incidents', { method: 'DELETE' }); toast('Cleared'); load();
        });
        document.getElementById('btn-clear-log').addEventListener('click', async () => {
            if (!confirm('Clear the alert log?')) return;
            await api('logs', { method: 'DELETE' }); toast('Cleared'); load();
        });
        load();
    };

    /* ---------- Tools page ---------- */

    CFC.initToolsPage = function () {
        const wire = (btnId, outId, fn) => {
            document.getElementById(btnId).addEventListener('click', async () => {
                const out = document.getElementById(outId);
                out.textContent = '…';
                try {
                    const res = await fn();
                    out.textContent = JSON.stringify(res, null, 2);
                } catch (e) {
                    out.textContent = 'Error: ' + e.message;
                }
            });
        };
        wire('http-run', 'http-out', () => api('tools/http', { method: 'POST', body: {
            target: document.getElementById('http-url').value.trim()
        } }));
        wire('http-head', 'http-out', () => api('tools/http', { method: 'POST', body: {
            target: document.getElementById('http-url').value.trim(), config: { method: 'HEAD' }
        } }));
        wire('tcp-run', 'tcp-out', () => api('tools/tcp', { method: 'POST', body: {
            target: document.getElementById('tcp-host').value.trim(),
            port: parseInt(document.getElementById('tcp-port').value, 10),
            timeout: parseInt(document.getElementById('tcp-timeout').value, 10),
        } }));
        wire('dns-run', 'dns-out', () => api('tools/dns', { method: 'POST', body: {
            host: document.getElementById('dns-host').value.trim(),
            type: document.getElementById('dns-type').value,
        } }));
        wire('ssl-run', 'ssl-out', () => api('tools/ssl', { method: 'POST', body: {
            url: document.getElementById('ssl-url').value.trim()
        } }));
        wire('hdr-run', 'hdr-out', () => api('tools/headers', { method: 'POST', body: {
            url: document.getElementById('hdr-url').value.trim()
        } }));
    };

    /* ---------- Settings page ---------- */

    CFC.initSettingsPage = function (ctx) {
        function formData(form, booleanKeys = []) {
            const fd = new FormData(form);
            const obj = {};
            for (const [k, v] of fd.entries()) obj[k] = v;
            booleanKeys.forEach(k => { obj[k] = !!form.querySelector(`[name="${k}"]`)?.checked; });
            return obj;
        }
        async function saveSection(section, data) {
            await api('config', { method: 'PUT', body: { [section]: data } });
            toast('Saved');
        }

        document.getElementById('form-general').addEventListener('submit', async e => {
            e.preventDefault();
            const d = formData(e.target, ['alerts_enabled', 'public_status']);
            try { await saveSection('general', d); } catch (err) { toast(err.message, 'err'); }
        });
        document.getElementById('form-slack').addEventListener('submit', async e => {
            e.preventDefault();
            const d = formData(e.target, ['alert_on_down', 'alert_on_recovery', 'alert_on_slow', 'alert_on_ssl']);
            if (!d.bot_token) delete d.bot_token;
            try { await saveSection('slack', d); } catch (err) { toast(err.message, 'err'); }
        });
        document.getElementById('form-email').addEventListener('submit', async e => {
            e.preventDefault();
            const d = formData(e.target, ['enabled', 'alert_on_down', 'alert_on_recovery']);
            try { await saveSection('email', d); } catch (err) { toast(err.message, 'err'); }
        });
        document.getElementById('form-thresholds').addEventListener('submit', async e => {
            e.preventDefault();
            const d = formData(e.target);
            try { await saveSection('thresholds', d); } catch (err) { toast(err.message, 'err'); }
        });

        document.getElementById('btn-slack-test').addEventListener('click', async () => {
            try { const r = await api('slack/test', { method: 'POST', body: {} });
                toast(r.ok ? 'Slack test sent' : 'Failed: ' + (r.error || ''), r.ok ? 'ok' : 'err');
            } catch (e) { toast(e.message, 'err'); }
        });

        // Maintenance — seed from ctx
        if (ctx.maintenance) {
            document.getElementById('maint-enabled').checked = !!ctx.maintenance.enabled;
            document.getElementById('maint-message').value   = ctx.maintenance.message || '';
            if (ctx.maintenance.start) document.getElementById('maint-start').value = toLocalInput(ctx.maintenance.start);
            if (ctx.maintenance.end)   document.getElementById('maint-end').value   = toLocalInput(ctx.maintenance.end);
        }
        function toLocalInput(ts) {
            const d = new Date(ts * 1000);
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }
        document.getElementById('form-maintenance').addEventListener('submit', async e => {
            e.preventDefault();
            const body = {
                enabled: document.getElementById('maint-enabled').checked,
                message: document.getElementById('maint-message').value,
                start: toUnix(document.getElementById('maint-start').value),
                end:   toUnix(document.getElementById('maint-end').value),
            };
            try { await api('maintenance', { method: 'POST', body }); toast('Saved'); }
            catch (err) { toast(err.message, 'err'); }
        });
        function toUnix(local) { if (!local) return null; return Math.floor(new Date(local).getTime() / 1000); }

        document.getElementById('form-password').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            if (fd.get('new') !== fd.get('confirm')) { toast('New passwords do not match', 'err'); return; }
            try {
                await api('change-password', { method: 'POST', body: {
                    current: fd.get('current'), new: fd.get('new')
                } });
                toast('Password updated');
                e.target.reset();
            } catch (err) { toast(err.message, 'err'); }
        });

        document.getElementById('btn-copy-cron').addEventListener('click', () => {
            const text = document.getElementById('cron-line').textContent;
            navigator.clipboard.writeText(text).then(() => toast('Copied'));
        });
        document.getElementById('btn-rotate-cron').addEventListener('click', async () => {
            if (!confirm('Rotate cron token? Existing cron jobs will stop working until updated.')) return;
            const newToken = [...crypto.getRandomValues(new Uint8Array(24))]
                .map(b => b.toString(16).padStart(2, '0')).join('');
            try {
                await api('config', { method: 'PUT', body: { auth: { cron_token: newToken } } });
                document.getElementById('cron-line').textContent =
                    `curl -s "${ctx.baseUrl}/cron.php?token=${newToken}" >/dev/null`;
                toast('Token rotated — update your cron job');
            } catch (err) { toast(err.message, 'err'); }
        });
        document.getElementById('btn-run-cron').addEventListener('click', async () => {
            try { const r = await api('check-all', { method: 'POST' });
                  toast(`Ran ${r.checked || 0} checks`); }
            catch (err) { toast(err.message, 'err'); }
        });

        document.getElementById('btn-cleanup').addEventListener('click', async () => {
            try { const r = await api('cleanup', { method: 'POST' });
                  toast(`Removed ${r.removed} old records`); loadStorage(); }
            catch (err) { toast(err.message, 'err'); }
        });

        async function loadStorage() {
            try {
                const s = await api('storage');
                const kb = (s.total_bytes / 1024).toFixed(1);
                document.getElementById('storage-stats').innerHTML =
                    `Total: <strong>${kb} KB</strong> across ${s.monitors.length} monitor${s.monitors.length === 1 ? '' : 's'}.`;
            } catch (e) { /* ignore */ }
        }
        loadStorage();
    };

})();
