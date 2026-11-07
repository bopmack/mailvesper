/* MailVesper: the platform admin's panel (#/platform). System status (health, errors, slow queries, backups, alerts)
   and settings (AI, operations, retention, and whatever Pro adds). Pro registers more tabs in S.platformTabs
   (plans, customers, billing). Visible to the installation's admins only. Loaded after edition.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || {};
  const ago = (iso) => { if (!iso) return 'never'; const s = Math.max(0, (Date.now() - new Date(String(iso).replace(' ', 'T') + (/[Z+]/.test(String(iso)) ? '' : 'Z'))) / 1000); return s < 60 ? 'just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : s < 86400 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' d ago'; };
  const bytes = (n) => (n == null ? '—' : n > 1024 ** 3 ? (n / 1024 ** 3).toFixed(1) + ' GB' : n > 1024 ** 2 ? (n / 1024 ** 2).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB');
  S.SUBTITLES = Object.assign(S.SUBTITLES || {}, { platform: 'Platform' });
  const fail = (pane) => (err) => (pane.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`);

  // Sections: core first; Pro pushes { k, label, render(pane), action? } (users, plans, customers, payments) before the
  // page draws. Each settings section shows some groups of the settings catalog; groups no section claims land in
  // "More settings", so nothing ever goes missing.
  const SECTIONS = [
    { k: 'system', label: 'System', icon: 'activity', render: system },
    { k: 'brand', label: 'Branding', icon: 'palette', render: (p) => branding(p) },
    { k: 'email', label: 'System email', icon: 'mail', groups: ['System email'], extra: mailTest },
    { k: 'signin', label: 'Sign-in & sign-up', icon: 'shield', groups: ['Sign-up', 'Sign in with Google', 'Sign in with Microsoft', 'Sign in with GitHub', 'Sign in with Apple'], intro: oauthIntro },
    { k: 'ai', label: 'AI', icon: 'sparkles', groups: ['AI', 'AI agent'], extra: aiTest },
    { k: 'sending', label: 'Sending & deliverability', icon: 'send', groups: ['Sending', 'Setup checks', 'Complaint feedback loops'] },
    { k: 'data', label: 'Backups & data', icon: 'database', groups: ['Off-site backups', 'Data retention'] },
    { k: 'alerts', label: 'Alerts', icon: 'bell', groups: ['Operations'] },
    { k: 'site', label: 'Legal & support', icon: 'link', groups: ['Your site'] },
    { k: 'admins', label: 'Admins', icon: 'users', render: (p) => admins(p) },
    { k: 'license', label: 'License', icon: 'key', render: (p) => license(p) },
  ];
  const CLAIMED = SECTIONS.flatMap((x) => x.groups || []).concat(['Branding']);
  SECTIONS.forEach((x) => { if (x.groups) x.render = (p) => groupsForm(p, x); });
  S.platformTabs = S.platformTabs || [];
  const proTabs = () => S.platformTabs.filter((t) => !SECTIONS.some((x) => x.k === t.k));

  // ─── The cron job: a bar for the installation's admins while it isn't running, and the cPanel steps ───
  const cronSteps = (cmd) => `<ol class="cron-how"><li>In cPanel, open <b>Cron Jobs</b> (in the <b>Advanced</b> section, or search “cron”).</li>
    <li>Under <b>Add New Cron Job</b>, set <b>Common Settings</b> to <b>Once Per Minute (* * * * *)</b>.</li>
    <li>Paste this into the <b>Command</b> box:<div class="cron-cmd mono" id="cron-cmd">${esc(cmd)}</div><button type="button" class="btn btn-sm" id="cron-copy">${ic('copy', 13)}Copy the command</button></li>
    <li>Click <b>Add New Cron Job</b>, then <b>Check again</b> here. It runs within a minute.</li></ol>
    <p class="faint" style="font-size:12.5px">No cPanel? Add this line to the crontab of the user that owns the files: <span class="mono">* * * * * ${esc(cmd)}</span>. Or ask your hosting company to add the command to run once per minute.</p>`;
  S.cronHelp = () => API('GET', '/v1/platform/cron').then((c) => {
    S.modal({ title: c.running ? 'The cron job is running' : 'Set up the cron job', icon: 'clock', size: 'lg', noFocus: true,
      mount: (root) => { const cp = $('#cron-copy', root); if (cp) cp.onclick = async () => { try { await navigator.clipboard.writeText(c.command); cp.textContent = 'Copied'; } catch (e) { cp.textContent = 'Select it and copy'; } }; },
      desc: c.running ? 'Scheduled campaigns, automations and backups run on time.' : 'It sends scheduled campaigns, runs automations, checks bounces and makes the nightly backup. Without it, these only happen while someone has MailVesper open.',
      body: cronSteps(c.command) + '<div id="cron-res"></div>',
      actions: [{ label: 'Close' }, { label: 'Check again', kind: 'primary', onClick: (m) => {
        const res = $('#cron-res', m.root); res.innerHTML = '<p class="faint" style="font-size:13px">Checking…</p>';
        API('GET', '/v1/platform/cron').then((x) => { res.innerHTML = x.running ? `<div class="callout ok">${ic('check', 15)}<div>It’s running. You’re all set.</div></div>` : `<div class="callout warn">${ic('alert', 15)}<div>Not yet. Wait a minute after adding it, and check the whole command was pasted (it starts with <b>cd</b>).</div></div>`; if (x.running) cronBar(); });
        return false;
      } }] });
  }).catch((e) => toast(e.message));
  const cronBar = () => {
    if (!window.SL_ME) return setTimeout(cronBar, 400);
    if (!me().platform_admin) return;
    API('GET', '/v1/platform/cron').then((c) => {
      let bar = document.getElementById('cron-bar');
      if (c.running) { if (bar) bar.remove(); return; }
      if (!bar) {
        const view = document.getElementById('view'); if (!view) return;
        bar = document.createElement('div'); bar.id = 'cron-bar'; bar.className = 'cron-bar';
        view.parentNode.insertBefore(bar, view);
      }
      bar.innerHTML = `${ic('alert', 15)}<span><b>The cron job isn’t running.</b> Scheduled campaigns and automations won’t go out on time until it’s set up.</span><button type="button" class="btn btn-sm btn-primary" id="cron-fix">Set it up</button>`;
      document.getElementById('cron-fix').onclick = S.cronHelp;
    }).catch(() => {});
  };
  cronBar();
  setInterval(cronBar, 5 * 60 * 1000);

  const navItem = () => {
    if (!window.SL_ME) return setTimeout(navItem, 300);
    if (!me().platform_admin || document.querySelector('.nav-item[data-k="platform"]')) return;
    const st = document.querySelector('.nav-item[data-k="settings"]');
    if (st) st.insertAdjacentHTML('beforebegin', `<a class="nav-item" href="#/platform" data-k="platform">${ic('shield')}Platform${S.isPro() ? '' : '<span class="pro-mark">Pro</span>'}</a>`);
  };
  navItem();

  let tab = null;
  pages.platform = (v) => {
    if (!window.SL_ME) { v.innerHTML = ''; return; }
    if (!me().platform_admin) { v.innerHTML = '<div class="card"><div class="card-body">Only the platform admin can see this.</div></div>'; return; }
    // Community: the dashboard is MailVesper Pro. What the free features need is in Settings → Installation.
    if (!S.isPro()) {
      S.proLock('platform', v);
      v.insertAdjacentHTML('beforeend', `<p class="pl-note">${ic('settings', 14)}<span>The settings the free edition needs (system email, AI, sending and setup checks) are in <a href="#/settings" id="pl-inst">Settings → Installation</a>.</span></p>`);
      $('#pl-inst', v).onclick = () => { S.settingsTab = 'installation'; };
      return;
    }
    const pro = proTabs();
    const all = [...SECTIONS.slice(0, 1), ...pro, ...SECTIONS.slice(1), { k: 'more', label: 'More settings', icon: 'settings', render: (p) => groupsForm(p, { k: 'more', label: 'More settings', groups: null }) }];
    tab = S.param('tab') || tab || (pro.find((t) => t.k === 'plans') ? 'plans' : 'system');
    if (tab === 'settings') tab = 'brand'; // the old single settings tab
    const cur = all.find((t) => t.k === tab) || all[0];
    const item = (t) => `<a class="pf-item ${t.k === cur.k ? 'on' : ''}" href="#/platform?tab=${t.k}">${ic(t.icon || 'layers', 15)}<span>${esc(t.label)}</span></a>`;
    v.innerHTML = `<div class="page-head"><div><h1>Platform</h1><p>${S.isPro('saas') ? 'Your platform: customers, plans and payments, branding, settings, and how the system is doing.' : 'Everything about this installation: branding, email, sign-in, AI, data, and how the system is doing.'}</p></div>${cur.action ? cur.action() : ''}</div>
      <div class="pf-layout"><nav class="pf-nav" aria-label="Platform sections">${item(all[0])}${pro.length ? `<div class="pf-h">Business</div>${pro.map(item).join('')}` : ''}<div class="pf-h">Settings</div>${all.slice(1 + pro.length).map(item).join('')}</nav>
      <div class="pf-main"><h2 class="pf-title">${esc(cur.label)}</h2><div id="ppane"><div class="card"><div class="card-body faint">Loading…</div></div></div></div></div>`;
    cur.render($('#ppane', v));
  };

  // ═══════ System ═══════
  function system(pane) {
    API('GET', '/v1/platform/system').then((r) => {
      const h = r.health;
      const tile = (label, ok, value, sub) => `<div class="sys-tile ${ok ? 'ok' : 'bad'}"><div class="sys-top"><span class="sys-dot"></span>${label}</div><b>${value}</b><small>${sub || ''}</small></div>`;
      const firing = Object.keys(r.alerts.firing || {}).map((k) => k.replace('alert:', ''));
      pane.innerHTML = `<div class="sys-tiles">
          ${tile('Scheduler', h.scheduler.ok, h.scheduler.ok ? 'Running' : 'Stopped', 'Last ran ' + ago(h.scheduler.last_run))}
          ${tile('Queue', h.queue.ok, fmt(h.queue.pending) + ' waiting', h.queue.pending ? 'Oldest ' + Math.round(h.queue.oldest_seconds / 60) + ' min' : 'Nothing waiting')}
          ${tile('Failed jobs', !h.queue.failed_24h, fmt(h.queue.failed_24h), 'In the last 24 hours')}
          ${tile('Errors', !h.errors.last_24h, fmt(h.errors.open) + ' open', h.errors.last_24h + ' seen in 24 hours')}
          ${tile('Backups', h.backup.ok && h.backup.enabled, h.backup.last_at ? ago(h.backup.last_at) : 'None yet', h.backup.last_size ? bytes(h.backup.last_size) : h.database.driver === 'sqlite' ? 'Need MySQL/MariaDB' : 'Daily at 02:30')}
          ${tile('Restore drill', h.drill.ok && !!h.drill.last_at, h.drill.last_at ? (h.drill.status === 'ok' ? 'Passed' : 'Failed') : 'Not run yet', h.drill.last_at ? ago(h.drill.last_at) : 'Weekly, Sunday 03:30')}
          ${tile('Disk', h.disk.ok, bytes(h.disk.free_bytes) + ' free', h.disk.total_bytes ? 'of ' + bytes(h.disk.total_bytes) : '')}
          ${tile('AI', h.ai.configured, h.ai.configured ? 'Set up' : 'Not set up', h.ai.configured ? 'Design with AI and Copilot work' : 'Add a key under AI')}
        </div>
        <div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Alerts</h3><div class="sub">Emailed to ${r.alerts.recipients.map(esc).join(', ') || 'nobody yet'} when the scheduler stops, the queue backs up, jobs fail, new errors appear, disk runs low, or a backup or drill fails. ${firing.length ? '<b style="color:var(--s-bounced)">Now: ' + firing.join(', ') + '</b>' : 'Nothing firing.'}</div></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-sm" id="sy-alert">Send a test alert</button><button class="btn btn-sm" id="sy-backup">Back up now</button><button class="btn btn-sm" id="sy-drill">Run restore drill</button></div></div>
          <div class="card-body faint" style="font-size:12.5px;padding-top:0">For when this server is down entirely, point an uptime monitor (UptimeRobot, Better Stack…) at <span class="mono">${esc(location.origin)}/api/v1/health?deep=1</span>: it answers 503 when the scheduler, queue or disk need attention.</div></div>
        <div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Errors</h3><div class="sub">Grouped by where they happen. Expected errors (wrong passwords, validation) aren’t included.</div></div></div>
          ${r.errors.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Error</th><th>Where</th><th class="num">Times</th><th>Last</th><th></th></tr></thead><tbody>${r.errors.map((e) => `<tr style="cursor:default"><td><div class="cell-main">${esc(e.class.split('\\').pop())}</div><div class="cell-sub" style="max-width:520px;white-space:normal">${esc(e.message)}</div></td><td><span class="mono" style="font-size:12px">${esc(e.file || '')}:${e.line || ''}</span><div class="cell-sub">${esc(e.url || '')}</div></td><td class="num">${fmt(e.count)}</td><td class="faint">${ago(e.last_seen_at)}</td><td style="text-align:right"><button class="btn btn-sm" data-resolve="${e.id}">Mark fixed</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="card-body faint">No open errors.</div>'}</div>
        <div class="grid two" style="margin-top:16px;align-items:start;grid-template-columns:1fr 1fr">
          <div class="card"><div class="card-head"><div><h3>Backups</h3><div class="sub">Kept for 14 days on this server. ${h.offsite.configured ? `Copied to <span class="mono">${esc(h.offsite.target)}</span>.` : 'Not copied off-site yet: add a bucket in <a href="#/platform?tab=data">Backups & data</a>.'}</div></div>${h.offsite.configured ? '<button class="btn btn-sm" id="sy-offsite">Test off-site</button>' : ''}</div>${r.backups.length ? `<div class="table-wrap"><table class="tbl"><tbody>${r.backups.map((b) => `<tr style="cursor:default"><td>${ago(b.started_at)}<div class="cell-sub">${esc(b.file || (b.status === 'failed' ? b.error || '' : 'pruned'))}</div></td><td>${S.chip(b.status === 'ok' ? 'delivered' : 'bounced', b.status === 'ok' ? bytes(b.size) : 'Failed')}</td><td class="faint" style="font-size:12.5px">${b.drill_status ? (b.drill_status === 'ok' ? 'Drill passed' : 'Drill failed') + ' ' + ago(b.drill_at) : ''}${b.offsite_status ? `<div title="${esc(b.offsite_error || '')}" style="color:var(${b.offsite_status === 'ok' ? '--s-delivered' : '--s-bounced'})">${b.offsite_status === 'ok' ? 'Copied off-site' : 'Off-site copy failed'}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="card-body faint">No backups yet.</div>'}</div>
          <div class="card"><div class="card-head"><div><h3>Slow queries</h3><div class="sub">Slower than half a second, last 7 days.</div></div></div>${r.slow_queries.length ? `<div class="card-body" style="display:grid;gap:10px">${r.slow_queries.map((q) => `<div><div class="mono" style="font-size:11.5px;word-break:break-all;color:var(--text-2)">${esc(q.sql.slice(0, 240))}</div><div class="cell-sub">${fmt(q.ms)} ms · ${esc(q.url || '')} · ${ago(q.created_at)}</div></div>`).join('')}</div>` : '<div class="card-body faint">None.</div>'}</div>
        </div>
        <p class="faint" style="font-size:12.5px;margin-top:14px">Release ${esc(h.release)} · PHP ${esc(h.php)} · ${h.edition === 'pro' ? 'MailVesper Pro' : 'MailVesper Community'} · ${esc(h.database.driver)}</p>`;
      const busy = (b, text, p) => { b.disabled = true; const t = b.textContent; b.innerHTML = `<span class="spin sm"></span>${text}`; return p.finally(() => { b.disabled = false; b.textContent = t; }); };
      $('#sy-alert', pane).onclick = (e) => busy(e.currentTarget, 'Sending…', API('POST', '/v1/platform/alerts/test').then((x) => toast(x.sent ? `Test alert sent to ${x.recipients.join(', ')}` : 'The test alert couldn’t be sent. Check the mail settings')).catch((err) => toast(err.message)));
      $('#sy-backup', pane).onclick = (e) => busy(e.currentTarget, 'Backing up…', API('POST', '/v1/platform/backups').then((x) => { toast(x.backup.status === 'ok' ? 'Backup done' : 'Backup failed: ' + x.backup.error); system(pane); }).catch((err) => toast(err.message)));
      const ot = $('#sy-offsite', pane);
      if (ot) ot.onclick = (e) => busy(e.currentTarget, 'Testing…', API('POST', '/v1/platform/backups/offsite-test').then((x) => toast(x.ok ? 'Off-site storage works. ' + x.message : x.message)).catch((err) => toast(err.message)));
      $('#sy-drill', pane).onclick = (e) => busy(e.currentTarget, 'Restoring…', API('POST', '/v1/platform/backups', { drill: true }).then((x) => { toast(x.backup.drill_status === 'ok' ? 'Restore drill passed' : 'Drill failed: ' + x.backup.drill_detail); system(pane); }).catch((err) => toast(err.message)));
      $$('[data-resolve]', pane).forEach((b) => b.onclick = () => API('POST', `/v1/platform/errors/${b.dataset.resolve}/resolve`).then(() => system(pane)).catch((err) => toast(err.message)));
    }).catch(fail(pane));
  }

  // ═══════ Settings sections ═══════
  const fieldHtml = (f) => {
    const id = 'set-' + f.key.replace(/\W/g, '_');
    const val = f.value == null ? '' : f.value;
    let input;
    if (f.type === 'bool') input = `<select class="select" id="${id}" data-k="${f.key}"><option value="1" ${f.value ? 'selected' : ''}>On</option><option value="0" ${f.value ? '' : 'selected'}>Off</option></select>`;
    else if (f.options) input = `<select class="select" id="${id}" data-k="${f.key}">${f.value ? '' : '<option value="">Default</option>'}${f.options.map((o) => `<option ${String(val) === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
    else if (f.type === 'text') input = `<textarea id="${id}" data-k="${f.key}" rows="${f.secret ? 4 : 3}" class="${f.secret ? 'mono' : ''}" ${f.secret ? 'placeholder="' + (f.value ? 'Saved. Paste a new one to replace it.' : '') + '"' : ''}>${f.secret ? '' : esc(val)}</textarea>`;
    else input = `<input id="${id}" data-k="${f.key}" type="${f.secret ? 'password' : f.type === 'int' ? 'number' : 'text'}" ${f.type === 'int' ? 'min="0"' : ''} value="${esc(val)}" autocomplete="off" ${f.secret ? 'class="mono"' : ''}>`;
    return `<div class="field"><label for="${id}">${esc(f.label)}</label>${input}${f.help ? `<span class="help">${esc(f.help)}</span>` : ''}</div>`;
  };
  /** Saves the settings shown in a pane (a secret textarea left empty keeps its saved value). */
  const saveFields = (pane, btn) => {
    const out = {};
    $$('[data-k]', pane).forEach((el) => { if (el.tagName === 'TEXTAREA' && el.classList.contains('mono') && el.value === '') return; out[el.dataset.k] = el.value; });
    btn.disabled = true;
    return API('PUT', '/v1/platform/settings', { settings: out }).then((r) => { toast('Saved'); return r; })
      .catch((err) => { const k = err.fields && Object.keys(err.fields)[0]; const el = k && $(`[data-k="${k.replace('settings.', '')}"]`, pane); if (el) S.fieldError(pane, '#' + el.id, err.fields[k]); else toast(err.message); throw err; })
      .finally(() => { btn.disabled = false; });
  };
  function groupsForm(pane, sec) {
    API('GET', '/v1/platform/settings').then((r) => {
      const groups = Object.entries(r.groups).filter(([g]) => (sec.groups ? sec.groups.includes(g) : !CLAIMED.includes(g)));
      if (sec.groups) groups.sort((a, b) => sec.groups.indexOf(a[0]) - sec.groups.indexOf(b[0]));
      if (!groups.length) { pane.innerHTML = `<div class="card"><div class="card-body faint">${sec.groups ? 'Nothing to set here on this edition.' : 'Every setting has its own section.'}</div></div>`; return; }
      pane.innerHTML = `${sec.intro ? sec.intro() : ''}<div class="set-groups">${groups.map(([g, fields]) => `<div class="card"><div class="card-head"><h3>${esc(g)}</h3></div><div class="card-body">${fields.map(fieldHtml).join('')}</div></div>`).join('')}</div>
        <div class="pf-save"><button class="btn btn-primary" id="set-save">Save</button></div><div id="set-extra"></div>`;
      $('#set-save', pane).onclick = (e) => saveFields(pane, e.currentTarget).then(() => groupsForm(pane, sec)).catch(() => {});
      if (sec.extra) sec.extra($('#set-extra', pane));
    }).catch(fail(pane));
  }

  function aiTest(box) {
    box.innerHTML = `<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Test the AI</h3><div class="sub">Asks the model a one-line question with the saved settings.</div></div><button class="btn btn-sm" id="set-aitest">Test connection</button></div><div class="card-body" id="set-aires" style="padding-top:0"></div></div>`;
    const t = $('#set-aitest', box);
    t.onclick = () => { t.disabled = true; $('#set-aires', box).innerHTML = '<p class="faint">Asking the model…</p>';
      API('POST', '/v1/platform/ai/test').then((x) => { $('#set-aires', box).innerHTML = x.ok ? `<div class="callout ok">${ic('check', 15)}<div>Working: ${esc(x.model)} answered “${esc(x.answer)}” in ${fmt(x.ms)} ms.</div></div>` : `<div class="callout warn">${ic('alert', 15)}<div>${esc(x.error)}</div></div>`; })
        .catch((err) => toast(err.message)).finally(() => { t.disabled = false; }); };
  }

  function mailTest(box) {
    box.innerHTML = `<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Send a test</h3><div class="sub">Through the settings as saved, so you see the server’s real answer.</div></div></div>
      <div class="card-body" style="padding-top:0"><div class="field" style="display:flex;flex-direction:row;gap:8px;flex-wrap:wrap;margin:0"><input id="mt-to" type="email" placeholder="you@example.com" value="${esc((me().user || {}).email || '')}" style="max-width:300px"><button class="btn" id="mt-go">Send test</button></div><div id="mt-res" style="margin-top:10px"></div></div></div>`;
    $('#mt-go', box).onclick = (e) => {
      const b = e.currentTarget, to = $('#mt-to', box).value.trim(); if (!to) return $('#mt-to', box).focus();
      b.disabled = true; $('#mt-res', box).innerHTML = '<p class="faint">Sending…</p>';
      API('POST', '/v1/platform/mail/test', { to }).then((x) => { $('#mt-res', box).innerHTML = x.ok ? `<div class="callout ok">${ic('check', 15)}<div>Sent to ${esc(to)}${x.mailer === 'log' ? ' — but this installation is set to write email to its log file, not send it. Turn on the server above.' : ` from ${esc(x.from)}. Check the inbox (and spam).`}</div></div>` : `<div class="callout warn">${ic('alert', 15)}<div>The server said: ${esc(x.error)}</div></div>`; })
        .catch((err) => toast(err.message)).finally(() => { b.disabled = false; });
    };
  }

  function oauthIntro() {
    const cb = (p) => `<span class="mono">${esc(location.origin)}/api/v1/auth/oauth/${p}/callback</span>`;
    return `<div class="callout" style="margin-bottom:16px">${ic('info', 15)}<div>Each sign-in button appears on the login and sign-up pages once its keys are saved. In each provider’s console, use these redirect URIs: Google ${cb('google')} · Microsoft ${cb('microsoft')} · GitHub ${cb('github')} · Apple ${cb('apple')}.</div></div>`;
  }

  // ── Branding ──
  function branding(pane) {
    Promise.all([API('GET', '/v1/platform/settings'), API('GET', '/v1/auth/setup')]).then(([r, setup]) => {
      const b = setup.brand || {};
      const fields = r.groups.Branding || [];
      const img = (kind, label, help, dark) => `<div class="pf-img"><div class="pf-img-box ${dark ? 'dark' : ''}">${b[kind] ? `<img src="${esc(b[kind])}" alt="">` : `<span class="faint">None</span>`}</div>
        <div><b>${label}</b><div class="help">${help}</div><div style="display:flex;gap:8px;margin-top:8px"><label class="btn btn-sm">${b[kind] ? 'Replace' : 'Upload'}<input type="file" accept="image/png,image/jpeg,image/webp" data-up="${kind}" hidden></label>${b[kind] ? `<button class="btn btn-sm btn-ghost" data-rm="${kind}">Remove</button>` : ''}</div></div></div>`;
      pane.innerHTML = `<div class="card"><div class="card-head"><div><h3>Logo and icon</h3><div class="sub">PNG keeps a transparent background. Images are checked and re-saved, so only real pictures are ever shown.</div></div></div><div class="card-body pf-imgs">
          ${img('logo', 'Logo', 'On the login pages, the app and emails. Wide works best (up to 800 × 240).', false)}
          ${img('logo_dark', 'Logo for dark backgrounds', 'Optional: used in dark mode. Empty: the logo above.', true)}
          ${img('icon', 'Icon', 'Square, at least 64 × 64: the browser tab and small places.', false)}</div></div>
        <div class="card" style="margin-top:16px"><div class="card-head"><h3>Name and colour</h3></div><div class="card-body">${fields.map(fieldHtml).join('')}
          <div class="pf-preview"><span class="pf-swatch" id="pf-sw"></span><span>Preview: <button class="btn btn-primary btn-sm" id="pf-btn" type="button">${esc(b.name || 'MailVesper')}</button></span></div></div></div>
        <div class="pf-save"><button class="btn btn-primary" id="set-save">Save</button></div>`;
      const acc = $('[data-k="brand.accent"]', pane), nm = $('[data-k="brand.name"]', pane);
      const paint = () => { const c = /^#[0-9a-f]{6}$/i.test(acc.value.trim()) ? acc.value.trim() : ''; $('#pf-sw', pane).style.background = c || 'var(--accent)'; $('#pf-btn', pane).style.background = c || ''; $('#pf-btn', pane).style.borderColor = c || ''; $('#pf-btn', pane).textContent = nm.value.trim() || 'MailVesper'; };
      if (acc) { acc.setAttribute('placeholder', '#5B5BF7'); acc.oninput = paint; }
      if (nm) { nm.setAttribute('placeholder', 'MailVesper'); nm.oninput = paint; }
      paint();
      $('#set-save', pane).onclick = (e) => saveFields(pane, e.currentTarget).then(() => { if (S.refreshBrand) S.refreshBrand(); branding(pane); }).catch(() => {});
      $$('[data-up]', pane).forEach((inp) => inp.onchange = () => {
        const f = inp.files[0]; if (!f) return;
        const fd = new FormData(); fd.append('file', f);
        const box = inp.closest('.pf-img'); box.style.opacity = '.6';
        window.SL_API('POST', '/v1/platform/brand/' + inp.dataset.up, fd).then(() => { toast('Uploaded'); if (S.refreshBrand) S.refreshBrand(); branding(pane); }).catch((err) => { box.style.opacity = ''; toast(err.message); });
      });
      $$('[data-rm]', pane).forEach((btn) => btn.onclick = () => API('DELETE', '/v1/platform/brand/' + btn.dataset.rm).then(() => { toast('Removed'); if (S.refreshBrand) S.refreshBrand(); branding(pane); }).catch((err) => toast(err.message)));
    }).catch(fail(pane));
  }

  // ── Admins ──
  function admins(pane) {
    API('GET', '/v1/platform/admins').then((r) => {
      pane.innerHTML = `<div class="card"><div class="card-head"><div><h3>Platform admins</h3><div class="sub">They see this panel and get the alerts. They must have an account already. You can’t remove yourself.</div></div></div>
        <div class="card-body"><div id="ad-list">${r.admins.map((a) => `<div class="pf-admin"><span>${S.avatar ? S.avatar(a.name || a.email, 28) : ''}</span><div><b>${esc(a.name || '')}</b><div class="faint" style="font-size:12.5px">${esc(a.email)}</div></div>${a.id === (me().user || {}).id ? '<span class="tag">You</span>' : `<button class="btn btn-sm btn-ghost" data-ad-rm="${esc(a.email)}">Remove</button>`}</div>`).join('')}</div>
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap"><input id="ad-new" type="email" placeholder="email of an existing account" style="max-width:320px"><button class="btn" id="ad-add">Add admin</button></div></div></div>`;
      const emails = r.admins.map((a) => a.email);
      const save = (list) => API('PUT', '/v1/platform/admins', { emails: list }).then(() => { toast('Admins saved'); admins(pane); }).catch((err) => toast(err.message));
      $('#ad-add', pane).onclick = () => { const e = $('#ad-new', pane).value.trim().toLowerCase(); if (!e) return $('#ad-new', pane).focus(); save(emails.concat([e])); };
      $$('[data-ad-rm]', pane).forEach((b) => b.onclick = () => S.confirmDialog({ title: `Remove ${b.dataset.adRm} as admin?`, desc: 'They keep their account and workspaces, but lose this panel.', label: 'Remove', onConfirm: () => save(emails.filter((e) => e !== b.dataset.adRm)) }));
    }).catch(fail(pane));
  }

  // ═══════ Community: Settings → Installation ═══════
  // The settings Community's features need, for its admin (on Pro they're in the Platform dashboard).
  const INSTALL = [
    { k: 'email', label: 'System email', groups: ['System email'], extra: mailTest, about: 'The server that sends this installation’s own email: sign-in codes, password resets and alerts.' },
    { k: 'ai', label: 'AI', groups: ['AI'], extra: aiTest, about: 'The key and model behind Design with AI and the AI steps in journeys.' },
    { k: 'sending', label: 'Sending & deliverability', groups: ['Sending', 'Setup checks', 'Complaint feedback loops'], about: 'Tracking domains, “Check my setup” and the complaint mailbox.' },
  ];
  let inTab = 'email';
  function installation(pane) {
    const cur = INSTALL.find((x) => x.k === inTab) || INSTALL[0];
    pane.innerHTML = `<div class="inst-head"><div class="seg" role="tablist" aria-label="Installation settings">${INSTALL.map((x) => `<button type="button" role="tab" aria-selected="${x.k === cur.k}" class="${x.k === cur.k ? 'on' : ''}" data-in="${x.k}">${esc(x.label)}</button>`).join('')}</div><p class="faint">${esc(cur.about)}</p></div><div id="in-pane"><div class="card"><div class="card-body faint">Loading…</div></div></div>`;
    $$('[data-in]', pane).forEach((b) => b.onclick = () => { inTab = b.dataset.in; installation(pane); });
    groupsForm($('#in-pane', pane), cur);
  }
  const settingsPage = pages.settings;
  pages.settings = (v) => {
    const want = S.settingsTab;
    settingsPage(v);
    const addTab = () => {
      if (!window.SL_ME) return setTimeout(addTab, 300);
      const tabs = $('.ptabs', v);
      if (S.isPro() || !me().platform_admin || !tabs || $('[data-st="installation"]', tabs)) return;
      tabs.insertAdjacentHTML('beforeend', '<button class="ptab" data-st="installation">Installation</button>');
      const b = $('[data-st="installation"]', tabs);
      b.onclick = () => { $$('[data-st]', tabs).forEach((x) => x.classList.remove('on')); b.classList.add('on'); installation($('#spane')); };
      if (want === 'installation') b.onclick();
    };
    addTab();
  };

  // ── License ──
  function license(pane) {
    API('GET', '/v1/license').then((l) => {
      const feats = (l.features || []).map((f) => `<span class="tag">${esc(f.replace(/_/g, ' '))}</span>`).join(' ');
      const pro = l.name === 'pro';
      pane.innerHTML = `<div class="card"><div class="card-head"><div><h3>${pro ? 'Pro is active' : 'Community edition'}${l.license && l.license.licensee ? ` <span class="faint" style="font-weight:400;font-size:13px">· ${esc(l.license.licensee)}</span>` : ''}</h3><div class="sub">${pro ? 'Features: ' + (feats || '—') : 'Add a Pro license key to unlock the agent, teams, Copilot chat, inbox tests and more.'}</div></div>${l.can_manage ? `<button class="btn btn-sm" id="lc-key">${pro ? 'Change license key' : 'I have a license key'}</button>` : ''}</div>${l.license_problem ? `<div class="card-body" style="padding-top:0"><div class="callout warn">${ic('alert', 15)}<div>${esc(l.license_problem)}</div></div></div>` : ''}
        <div class="card-body faint" style="font-size:13px">${l.pro_installed ? 'The Pro package is installed on this server.' : 'The Pro package isn’t installed on this server.'} The license is checked with the license server once a day; Pro keeps working through a grace period if it can’t be reached.</div></div>`;
      const k = $('#lc-key', pane); if (k) k.onclick = () => (S.licenseModal ? S.licenseModal() : (location.hash = '#/settings'));
    }).catch(fail(pane));
  }
})();
