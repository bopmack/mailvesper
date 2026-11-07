/* MailVesper: real Activity, Overview and Webhooks (Phase 4).
   Replaces the prototype pages #/overview, #/activity (+ message drawer) and #/webhooks.
   Talks to /api/v1/workspaces/{id}/{stats,activity,webhooks,webhook-deliveries}. Loaded after sending.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, toast, modal, confirmDialog, fieldError, drawer, closeDrawer, tabs, sparkline, lineChart, miniRoute, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const base = () => `/v1/workspaces/${wsId()}`;
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ts = (iso) => new Date(iso).getTime();
  const ago = (iso) => { if (!iso) return '—'; const s = Math.max(0, Math.floor((Date.now() - ts(iso)) / 1000)); return s < 60 ? s + 's ago' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };
  const clock = (iso) => new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const hhmm = (iso) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const loading = (v, t) => { v.innerHTML = `<div class="card"><div class="card-body faint" style="display:flex;gap:10px;align-items:center"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span>${esc(t)}</div></div>`; };
  const failed = (v, err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc((err && err.message) || 'Something went wrong.')}</div></div>`; };
  const PROV = { google: 'Gmail', microsoft: 'Outlook', yahoo: 'Yahoo', apple: 'iCloud', proton: 'Proton', other: 'Other' };
  const PROV_LONG = { google: 'Gmail / Google Workspace', microsoft: 'Outlook / Microsoft 365', yahoo: 'Yahoo / AOL', apple: 'Apple iCloud', proton: 'Proton', other: 'Other' };
  // Activity statuses → the prototype's route vocabulary.
  const ROUTE = { processing: 'processing', sent: 'sent', delivered: 'delivered', opened: 'opened', clicked: 'clicked', deferred: 'deferred', bounced: 'bounced', complained: 'complained', suppressed: 'dropped', failed: 'dropped' };
  const route = (s) => { const h = miniRoute(ROUTE[s] || 'processing'); return s === 'failed' ? h.replace('>Suppressed<', '>Failed<').replace('title="Suppressed"', 'title="Failed"') : h; };
  const browser = (ua) => { ua = ua || ''; const b = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : /GoogleImageProxy/.test(ua) ? 'Gmail' : ''; const o = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac OS/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : ''; return [b, o].filter(Boolean).join(' on ') || 'Unknown device'; };

  // ═══════ #/activity ═══════
  const F = [['', 'All'], ['sent', 'Sent'], ['delivered', 'Delivered'], ['opened', 'Inbox · opened'], ['clicked', 'Clicked'], ['bounced', 'Bounced'], ['deferred', 'Delayed'], ['complained', 'Reported spam'], ['suppressed', 'Suppressed'], ['failed', 'Failed'], ['processing', 'Sending']];
  const act = { status: '', q: '', days: 7, provider: '' };
  let liveTimer = null;
  pages.activity = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    clearInterval(liveTimer);
    const st = { rows: [], next: null, counts: {} };
    v.innerHTML = `<div class="page-head"><div><h1>Activity</h1><p>Every email, and how far it got.</p></div>
      <div style="display:flex;gap:10px;align-items:center"><span class="live"><i></i>Live</span><select class="select" id="days">${[[1, 'Last 24 hours'], [7, 'Last 7 days'], [30, 'Last 30 days'], [90, 'Last 90 days']].map(([d, l]) => `<option value="${d}" ${act.days === d ? 'selected' : ''}>${l}</option>`).join('')}</select><a class="btn" id="export">${ic('download', 15)}Export CSV</a></div></div>
      <div class="card"><div class="toolbar"><input class="input" id="q" placeholder="Tracking code, recipient or subject" value="${esc(act.q)}"><select class="select" id="prov" style="height:34px"><option value="">All providers</option>${Object.keys(PROV).map((k) => `<option value="${k}" ${act.provider === k ? 'selected' : ''}>${PROV_LONG[k]}</option>`).join('')}</select><div class="filter-chips" id="chips"></div></div>
        <div class="table-wrap"><table class="tbl"><thead><tr><th>Route</th><th>Recipient</th><th>Subject</th><th>Tracking</th><th>Type</th><th>Sent</th></tr></thead><tbody id="rows"><tr><td colspan="6" class="faint">Loading…</td></tr></tbody></table></div>
        <div class="tfoot"><span id="count"></span><button class="btn btn-sm" id="more" style="display:none">Load more</button></div></div>`;
    const qs = (more) => { const q = new URLSearchParams({ days: act.days }); if (act.status) q.set('status', act.status); if (act.q) q.set('q', act.q); if (act.provider) q.set('provider', act.provider); if (more && st.next) q.set('before', st.next); return q; };
    const row = (m, fresh) => `<tr data-id="${m.id}" class="${fresh ? 'new-row' : ''}"><td>${route(m.status)}</td><td><div class="cell-main">${esc(m.to)}</div><div class="cell-sub">${PROV[m.provider] || 'Other'}${m.opens > 1 ? ` · ${m.opens} opens` : ''}</div></td><td><div class="trunc">${esc(m.subject)}</div></td><td><span class="trk">${esc(m.tracking_code)}</span></td><td><span class="tag">${m.source === 'test' ? 'test' : m.type}</span></td><td class="faint" style="white-space:nowrap" title="${esc(clock(m.created_at))}">${ago(m.created_at)}</td></tr>`;
    const paint = (freshIds) => {
      const total = Object.values(st.counts).reduce((a, b) => a + b, 0);
      $('#chips').innerHTML = F.map(([k, l]) => `<button class="fchip ${act.status === k ? 'on' : ''}" data-f="${k}">${l} <b>${fmt(k ? st.counts[k] || 0 : total)}</b></button>`).join('');
      $$('#chips .fchip').forEach((b) => b.onclick = () => { act.status = b.dataset.f; load(false); });
      $('#rows').innerHTML = st.rows.map((m) => row(m, freshIds && freshIds.has(m.id))).join('') || `<tr style="cursor:default"><td colspan="6"><div class="empty"><div class="ic">${ic(total || act.q ? 'search' : 'send', 22)}</div><h3>${total || act.q || act.status ? 'Nothing matches' : 'No emails yet'}</h3><p>${total || act.q || act.status ? 'Try a different filter, date range or tracking code.' : 'Send a test from the header, or send with the API. Every email shows up here within seconds.'}</p>${total || act.q ? '' : '<div style="display:flex;gap:8px;justify-content:center;margin-top:12px"><button class="btn btn-primary btn-sm" id="em-test">Send a test</button><a class="btn btn-sm" href="#/keys">Get an API key</a></div>'}</div></td></tr>`;
      const et = $('#em-test'); if (et) et.onclick = () => S.demos.test();
      $('#count').textContent = st.rows.length ? `Showing ${fmt(st.rows.length)} of ${fmt(act.status ? st.counts[act.status] || 0 : total)} emails` : '';
      $('#more').style.display = st.next ? '' : 'none';
      $$('#rows tr[data-id]').forEach((tr) => tr.onclick = () => openActivity(tr.dataset.id));
    };
    const load = (more) => API('GET', `${base()}/activity?${qs(more)}`).then((r) => {
      if (!alive(g)) return;
      st.rows = more ? st.rows.concat(r.messages) : r.messages; st.next = r.next_before; st.counts = r.counts; paint();
      $('#export').href = `/api${base()}/activity/export?${qs(false)}`;
    }).catch((err) => alive(g) && ($('#rows').innerHTML = `<tr><td colspan="6">${esc(err.message)}</td></tr>`));
    let t; $('#q').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { act.q = e.target.value.trim(); load(false); }, 250); };
    $('#days').onchange = (e) => { act.days = +e.target.value; load(false); };
    $('#prov').onchange = (e) => { act.provider = e.target.value; load(false); };
    $('#more').onclick = (e) => { const b = e.currentTarget; b.disabled = true; load(true).then(() => { b.disabled = false; }); };
    load(false);
    // Live: refresh the first page every 10 s while the page is visible and the user hasn't paged further.
    liveTimer = setInterval(() => {
      if (!alive(g) || !location.hash.startsWith('#/activity')) return clearInterval(liveTimer);
      if (document.hidden || st.rows.length > 50) return;
      API('GET', `${base()}/activity?${qs(false)}`).then((r) => {
        if (!alive(g)) return;
        const known = new Set(st.rows.map((m) => m.id)), fresh = new Set(r.messages.filter((m) => !known.has(m.id)).map((m) => m.id));
        st.rows = r.messages; st.next = r.next_before; st.counts = r.counts; paint(fresh);
      }).catch(() => {});
    }, 10000);
  };

  // ── Message drawer ──
  const TL = {
    accepted: ['processing', 'Accepted', (d) => `Accepted by ${SL_BN()}${d.source === 'test' ? ' as a test send' : d.source === 'quick' ? ' from Quick send' : d.source === 'api' ? ' from the API' : ''}.`],
    sent: ['processing', 'Sent', () => via === 'smtp' && reported ? 'Your SMTP server accepted it, and your provider reported what happened next.' : via === 'smtp' ? 'Your SMTP server accepted it. SMTP itself doesn’t report what happens next: turn on <a href="#/ses/reports">Delivery reports</a> to get deliveries, bounces and spam complaints from your provider or by email.' : 'Handed to your Amazon SES, which accepted it for delivery.'],
    delivered: ['delivered', 'Delivered', (d) => `The receiving server accepted it${d.processing_ms ? ` ${(d.processing_ms / 1000).toFixed(1)} s after sending` : ''}.${d.smtp_response ? `<br><span class="mono">${esc(d.smtp_response)}</span>` : ''}`],
    delayed: ['deferred', 'Delayed', (d) => `A temporary problem${d.delay_type ? ` (${esc(d.delay_type)})` : ''}. SES keeps retrying${d.retrying_until ? ` until ${esc(clock(d.retrying_until))}` : ''}.${d.diagnostic ? `<br><span class="mono">${esc(d.diagnostic)}</span>` : ''}`],
    bounced: ['bounced', 'Bounced', (d) => `${d.bounce_type === 'hard' ? 'Hard bounce: the address doesn’t exist or permanently refuses mail.' : 'Soft bounce: SES stopped retrying (often a full mailbox).'}${d.diagnostic ? `<br><span class="mono">${esc(d.diagnostic)}</span>` : ''}${d.suppressed ? '<br>Added to your suppressions automatically.' : ''}`],
    complained: ['complained', 'Spam report', (d) => `The recipient marked this email as spam${d.feedback_type ? ` (${esc(d.feedback_type)})` : ''}. The address was suppressed.`],
    rejected: ['bounced', 'Rejected by SES', (d) => esc(d.reason || 'SES refused to send it.')],
    opened: ['opened', 'Opened in the inbox', (d) => `A person opened it${d.user_agent ? ` (${esc(browser(d.user_agent))})` : ''}. Gmail, Outlook and Yahoo don’t load images in the spam folder, so it reached the inbox (or Promotions).`],
    clicked: ['clicked', 'Clicked', (d, e) => `<span class="mono">${esc(d.url || '')}</span>${e.machine ? '<br>A link scanner followed this link, not a person. Not counted.' : `<br>${esc(browser(d.user_agent))}`}`],
    suppressed: ['dropped', 'Suppressed', (d) => d.reason === 'topic' ? 'Not sent: this person opted out of this email’s topic.' : `Not sent: this address is on your suppression list (${esc(String(d.reason || '').replace('_', ' '))}).`],
    failed: ['bounced', 'Failed', (d) => esc(d.reason || 'Couldn’t be sent.')],
    retrying: ['deferred', 'Retrying', (d) => `${esc(d.reason || '')}${d.next_attempt_at ? ` Next attempt ${esc(clock(d.next_attempt_at))}.` : ''}`],
    unsubscribed: ['unsub', 'Unsubscribed', (d) => d.topic ? `The recipient opted out of the “${esc(d.topic)}” topic.` : d.method === 'one_click' ? 'The recipient used the one-click unsubscribe button in their mail app.' : 'The recipient unsubscribed from the link in the email.'],
    rendering_failed: ['bounced', 'Rendering failed', (d) => esc(d.error || '')],
    replied: ['clicked', 'Replied', (d) => `${esc(d.from || 'The recipient')} replied${d.snippet ? `: “${esc(d.snippet)}”` : '.'}`],
  };
  function stations(m) {
    const first = (type, human) => (m.events.find((e) => e.type === type && (!human || !e.machine)) || {}).at;
    const sent = first('sent') || first('accepted'), end = m.events.find((e) => ['delivered', 'bounced', 'delayed', 'rejected', 'failed', 'suppressed'].includes(e.type));
    const reached = { processing: 1, sent: 1, deferred: 1, bounced: 1, failed: 1, suppressed: 0, delivered: 2, opened: 3, clicked: 4, complained: 3 }[m.status] ?? 1;
    const fail = ['bounced', 'failed', 'suppressed'].includes(m.status), dash = m.status === 'deferred';
    const smtp = m.via === 'smtp' && !fail && !dash && !m.events.some((e) => e.type === 'delivered'); // SMTP alone never reports delivery; a provider webhook can
    const names = ['Sent', fail ? (m.status === 'suppressed' ? 'Suppressed' : m.status === 'failed' ? 'Failed' : 'Bounced') : dash ? 'Retrying…' : 'Delivered', 'In inbox · opened', 'Clicked'];
    const times = [sent, end && end.at, first('opened', true), first('clicked', true)];
    return `<div class="route-lg" style="--c:var(--s-${ROUTE[m.status] === 'dropped' ? 'dropped' : ROUTE[m.status]})">${names.map((n, i) => {
      let cls = i < reached ? 'on' : '';
      if (i === reached - 1) cls += ' here';
      if (i === 1 && fail) cls = 'fail';
      if (i === 0 && m.status === 'suppressed') cls = 'fail';
      if (i === 1 && dash) cls = 'next-dash';
      return `<div class="stn ${cls}"><span class="dot"></span><div class="n">${n}</div><div class="t">${i === 1 && smtp ? 'not reported' : times[i] && (i < reached || (i === 1 && (fail || dash))) ? hhmm(times[i]) : '—'}</div></div>`;
    }).join('')}</div>`;
  }
  const explain = (m) => {
    const last = m.events[m.events.length - 1] || {}, d = last.data || {};
    if (m.status === 'bounced') return ['warn', d.bounce_type === 'hard' ? 'This address can’t receive email. ' + SL_BN() + ' won’t send to it again, which protects your reputation.' : 'The mailbox refused it for a while (often because it’s full), and SES stopped retrying. It isn’t suppressed, so the next email will try again.'];
    if (m.status === 'complained') return ['warn', 'Spam reports hurt your reputation fastest. Gmail’s limit is 0.3%; aim for under 0.1%. Make your unsubscribe link easy to find.'];
    if (m.status === 'suppressed') return ['info', 'Skipped to protect your reputation. If you’re sure the address works now, remove it on the Suppressions page.'];
    if (m.status === 'failed') return ['warn', m.error || SL_BN() + ' couldn’t send this email.'];
    if (m.status === 'deferred') return ['info', 'Delays are usually temporary rate limits. SES retries automatically; no action needed.'];
    return null;
  };
  let via = null; // how the email in the drawer went out (smtp | ses)
  let reported = false; // a delivery report arrived for it (SES, or the SMTP provider's webhook)
  function openActivity(id) {
    API('GET', `${base()}/activity/${id}`).then((m) => {
      via = m.via;
      reported = (m.events || []).some((e) => e.type === 'delivered');
      let raw = null;
      const note = explain(m);
      const panes = {
        timeline: () => `${note ? `<div class="callout ${note[0]}" style="margin:0 0 14px">${ic(note[0] === 'warn' ? 'alert' : 'info', 16)}<div>${esc(note[1])}</div></div>` : ''}<div class="timeline">${m.events.filter((e) => !e.machine).map((e) => { const [col, title, desc] = TL[e.type] || ['dropped', e.type, () => '']; return `<div class="tl-item" style="color:var(--s-${col})"><span class="tl-dot"></span><div class="tl-title" style="color:var(--text)">${title}${e.machine ? ` <span class="badge-soft">${e.type === 'clicked' ? 'Scanner click' : 'Machine open'}</span>` : ''}<span class="tl-time">${clock(e.at)}</span></div><div class="tl-desc">${desc(e.data || {}, e)}</div></div>`; }).join('')}</div>`,
        preview: () => `<div class="seg" style="margin-bottom:12px" id="pv"><button class="on" data-v="html" ${m.html ? '' : 'disabled'}>HTML</button><button data-v="text" ${m.text ? '' : 'disabled'}>Plain text</button></div><div id="pvbox">${m.html ? `<iframe class="preview-frame" sandbox srcdoc="${esc(m.html)}"></iframe>` : `<pre class="code" style="white-space:pre-wrap;background:var(--surface-2);color:var(--text)">${esc(m.text || '')}</pre>`}</div>`,
        details: () => `<dl class="kv"><dt>Tracking code</dt><dd><span class="copy" data-copy="${esc(m.tracking_code)}">${esc(m.tracking_code)} ${ic('copy', 12)}</span></dd><dt>From</dt><dd>${esc(m.from)}</dd><dt>To</dt><dd>${esc(m.to)}</dd><dt>Provider</dt><dd>${PROV_LONG[m.provider] || 'Other'}</dd><dt>Subject</dt><dd>${esc(m.subject)}</dd><dt>Type</dt><dd><span class="tag">${esc(m.type)}</span> <span class="tag">${esc(m.source)}</span></dd>${m.tags && Object.keys(m.tags).length ? `<dt>Tags</dt><dd>${Object.entries(m.tags).map(([k, val]) => `<span class="tag">${esc(k)}: ${esc(val)}</span>`).join(' ')}</dd>` : ''}<dt>Opens</dt><dd>${m.counts.opens} real · ${m.counts.machine_opens} machine</dd><dt>Clicks</dt><dd>${m.counts.clicks} (scanner clicks excluded)</dd>${m.ses_message_id ? `<dt>SES message ID</dt><dd><span class="copy mono" data-copy="${esc(m.ses_message_id)}" style="font-size:12px;word-break:break-all">${esc(m.ses_message_id)} ${ic('copy', 12)}</span></dd>` : ''}${m.attempts > 1 ? `<dt>Attempts</dt><dd>${m.attempts}</dd>` : ''}</dl>`,
        raw: () => raw ? rawView(raw) : '<p class="faint">Loading the raw SES events…</p>',
        report: () => report ? S.reportView(report) : '<p class="faint">Putting the report together…</p>',
      };
      let report = null;
      const fromEmail = (String(m.from || '').match(/<([^>]+)>/) || [null, m.from])[1];
      drawer(`<div class="drawer-head"><div class="drawer-top"><div><span class="trk">${esc(m.tracking_code)}</span><h2 style="margin-top:6px">${esc(m.subject)}</h2></div><div style="margin-left:auto;display:flex;gap:6px"><button class="icon-btn" data-dclose>${ic('x')}</button></div></div>
        <div class="drawer-meta"><span>To <b style="color:var(--text)">${esc(m.to)}</b></span><span>${PROV[m.provider] || 'Other'}</span><span>${clock(m.created_at)}</span></div>
        ${stations(m)}
        <div class="tabs">${['timeline', 'report', 'preview', 'details', 'raw'].map((t, i) => `<button class="tab ${i ? '' : 'on'}" data-t="${t}">${{ timeline: 'Event log', report: 'Report', preview: 'Preview', details: 'Details', raw: 'Raw events' }[t]}</button>`).join('')}</div></div>
        <div class="drawer-body" data-pane>${panes.timeline()}</div>`, (d) => {
        tabs(d, panes, (t, root) => {
          if (t === 'report') {
            const show = () => { const p = $('[data-pane]', root); if (p && $('.tab.on', root).dataset.t === 'report') { p.innerHTML = S.reportView(report); S.bindReport(p, fromEmail); } };
            if (report) show(); else API('GET', `${base()}/activity/${m.id}/report`).then((r) => { report = r.report; show(); }).catch((err) => toast(err.message));
          }
          if (t === 'raw' && !raw) API('GET', `${base()}/activity/${m.id}/raw`).then((r) => { raw = r.events; const p = $('[data-pane]', root); if (p && $('.tab.on', root).dataset.t === 'raw') p.innerHTML = rawView(raw); }).catch((err) => toast(err.message));
          if (t === 'preview') $$('#pv button', root).forEach((b) => b.onclick = () => { $$('#pv button', root).forEach((x) => x.classList.toggle('on', x === b)); $('#pvbox', root).innerHTML = b.dataset.v === 'html' ? `<iframe class="preview-frame" sandbox srcdoc="${esc(m.html || '')}"></iframe>` : `<pre class="code" style="white-space:pre-wrap;background:var(--surface-2);color:var(--text)">${esc(m.text || '')}</pre>`; });
        });
      });
    }).catch((err) => toast(err.message));
  }
  const json = (o) => esc(JSON.stringify(o, null, 2)).replace(/&quot;([\w-]+)&quot;:/g, '<span class="k">"$1"</span>:').replace(/: &quot;(.*?)&quot;/g, ': <span class="s">"$1"</span>').replace(/: (-?\d+\.?\d*|true|false|null)/g, ': <span class="n">$1</span>');
  const rawView = (events) => events.length ? `<p class="faint" style="font-size:12.5px;margin-bottom:10px">Exactly what Amazon SES sent, one event per block.</p>${events.map((e) => `<pre class="code" style="margin-bottom:10px;max-height:320px">${json(e)}</pre>`).join('')}` : '<p class="faint">No SES events yet. They arrive within seconds of sending.</p>';
  S.openActivity = openActivity;

  // ═══════ #/overview ═══════
  const ov = { days: 14, type: '' };
  pages.overview = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    loading(v, 'Loading your numbers…');
    const q = new URLSearchParams({ days: ov.days }); if (ov.type) q.set('type', ov.type);
    Promise.all([API('GET', `${base()}/stats?${q}`), API('GET', base() + '/domains').catch(() => ({ domains: [] })), API('GET', base() + '/ses').catch(() => ({})), API('GET', base() + '/smtp').catch(() => ({}))]).then(([s, dom, ses, sm]) => {
      if (!alive(g)) return;
      const T = s.totals, P = s.previous, ser = s.series.map((x) => Object.assign({}, x, { date: new Date(x.date + 'T00:00:00Z') }));
      const rate = (a, b) => (b ? a / b : 0);
      const delta = (now, prev, isRate, goodUp) => { if (!prev && !now) return ['—', true]; if (isRate && !P.sent) return ['—', true]; const d = isRate ? (now - prev) * 100 : prev ? ((now - prev) / prev) * 100 : null; if (d === null) return ['new', true]; const sign = d > 0 ? '+' : d < 0 ? '−' : '±'; return [`${sign}${Math.abs(d).toFixed(isRate ? 2 : 1)}${isRate ? ' pts' : '%'}`, d === 0 || (d > 0) === goodUp]; };
      const kpi = (name, val, [dl, good], spark, color, tip, note) => `<div class="card kpi">${sparkline(spark.length > 1 ? spark : [0, 0], color)}<div class="kpi-label">${name}<span class="info tip" data-tip="${esc(tip)}">${ic('info', 13)}</span></div><div class="kpi-value">${val}</div><div class="kpi-foot"><span class="delta ${good ? 'good' : 'bad'}">${dl}</span><span class="note">${note}</span></div></div>`;
      const dR = rate(T.delivered, T.sent), oR = rate(T.opened, T.delivered), cR = rate(T.clicked, T.delivered), bR = rate(T.bounced, T.sent), sR = rate(T.complained, T.delivered);
      const pd = rate(P.delivered, P.sent), po = rate(P.opened, P.delivered), pc = rate(P.clicked, P.delivered), pb = rate(P.bounced, P.sent), ps = rate(P.complained, P.delivered);
      const insight = !T.sent ? ['info', 'No emails sent in this period yet.', 'Send a test from the header or connect your app with an API key. Numbers appear here as SES reports them.']
        : T.sent < 100 ? ['info', `Early days: ${fmt(T.sent)} email${T.sent === 1 ? '' : 's'} sent in this period.`, 'Rates swing a lot on small numbers, so we’ll judge your reputation once you’ve sent a few hundred. Every email is still tracked in Activity.']
        : sR >= 0.003 || bR >= 0.05 ? ['bad', 'Your reputation needs attention.', `${sR >= 0.003 ? `Spam reports are ${pct(sR, 2)}, above Google’s 0.3% limit.` : ''} ${bR >= 0.05 ? `Bounces are ${pct(bR)}; AWS reviews accounts above 5%.` : ''} Stop sending to unengaged contacts and check your latest import.`]
          : sR >= 0.001 || bR >= 0.02 ? ['warn', 'Worth a look.', `${sR >= 0.001 ? `Spam reports (${pct(sR, 2)}) are above the 0.1% we recommend.` : ''} ${bR >= 0.02 ? `Bounces (${pct(bR)}) are above 2%.` : ''}`]
            : ['ok', 'Delivery is healthy.', `${pct(dR)} delivered, bounces ${pct(bR)} and spam reports ${pct(sR, 2)}, well inside Gmail’s and AWS’s limits.`];
      const viaSmtp = sm.method === 'smtp';
      const health = viaSmtp ? smtpHealthRows(sm, T) : healthRows(dom.domains || [], ses.connection, T);
      v.innerHTML = `<div class="page-head"><div><h1>Overview</h1><p>How your email performed over the last ${s.days} days (UTC).</p></div>
          <div style="display:flex;gap:8px"><select class="select" id="ovd">${[[7, 'Last 7 days'], [14, 'Last 14 days'], [30, 'Last 30 days']].map(([d, l]) => `<option value="${d}" ${ov.days === d ? 'selected' : ''}>${l}</option>`).join('')}</select><select class="select" id="ovt"><option value="">All email</option><option value="transactional" ${ov.type === 'transactional' ? 'selected' : ''}>Transactional</option><option value="marketing" ${ov.type === 'marketing' ? 'selected' : ''}>Marketing</option></select></div></div>
        <div class="insight ${insight[0] === 'bad' ? 'bad' : ''}"><span class="spark-ic">${ic(insight[0] === 'ok' ? 'check' : insight[0] === 'info' ? 'info' : 'alert', 16, 2)}</span><div style="flex:1"><b>${insight[1]}</b><p>${insight[2]}</p></div><a class="btn btn-sm" href="#/activity">${ic('activity', 14)}See every email</a></div>
        <div class="grid kpis">
          ${kpi('Sent', fmt(T.sent), delta(T.sent, P.sent, false, true), ser.map((x) => x.sent), 'var(--accent)', viaSmtp ? 'Emails handed to your SMTP server in this period.' : 'Emails SES accepted for delivery in this period.', `vs prev. ${s.days}d`)}
          ${kpi(viaSmtp ? 'Accepted' : 'Delivered', T.sent ? pct(dR) : '—', delta(dR, pd, true, true), ser.map((x) => rate(x.delivered, x.sent)), 'var(--s-delivered)', viaSmtp ? 'Accepted by your SMTP server. SMTP doesn’t report final delivery, so this is as far as ' + SL_BN() + ' can see.' : 'Accepted by the receiving server. Accepted mail can still be filtered to spam.', fmt(T.delivered))}
          ${kpi('Real opens', T.delivered ? pct(oR) : '—', delta(oR, po, true, true), ser.map((x) => rate(x.opened, x.delivered)), 'var(--s-opened)', 'Unique emails opened by a person. Apple Mail Privacy and security-scanner opens are excluded.', `${fmt(T.machine)} machine excl.`)}
          ${kpi('Click rate', T.delivered ? pct(cR) : '—', delta(cR, pc, true, true), ser.map((x) => rate(x.clicked, x.delivered)), 'var(--s-clicked)', 'Unique emails with a human click ÷ delivered. Link-scanner clicks are filtered out.', `${fmt(T.clicked)} clicked`)}
          ${kpi('Bounce rate', T.sent ? pct(bR, 2) : '—', delta(bR, pb, true, false), ser.map((x) => rate(x.bounced, x.sent)), 'var(--s-bounced)', 'Bounces ÷ sent. Keep below 2%; AWS reviews accounts above 5%.', `${fmt(T.bounced)} bounces`)}
          ${kpi('Spam rate', T.delivered ? pct(sR, 3) : '—', delta(sR, ps, true, false), ser.map((x) => rate(x.complained, x.delivered)), 'var(--s-complained)', 'Spam reports ÷ delivered. Google requires bulk senders to stay below 0.3%; aim for under 0.1%.', `${fmt(T.complained)} reports`)}
        </div>
        <div class="grid two" style="margin-top:16px">
          <div class="card"><div class="card-head"><div><h3>Delivery over time</h3><div class="sub">Daily, by outcome</div></div></div><div class="chart-legend" id="legend"></div><div class="chart-wrap" id="chart"></div></div>
          <div class="card"><div class="card-head"><div><h3>Delivery funnel</h3><div class="sub">Unique emails, last ${s.days} days</div></div></div>
            <div class="card-body funnel">${[['Sent', T.sent, 'var(--accent)'], ['Delivered', T.delivered, 'var(--s-delivered)'], ['Opened (real)', T.opened, 'var(--s-opened)'], ['Clicked', T.clicked, 'var(--s-clicked)']].map(([n, val, c]) => `<div class="f-row"><div class="f-top"><span>${n}</span><span><b>${fmt(val)}</b> <span class="faint">· ${T.sent ? pct(val / T.sent) : '—'}</span></span></div><div class="f-bar"><span style="width:${T.sent ? (val / T.sent) * 100 : 0}%;background:${c}"></span></div></div>`).join('')}
            <div style="font-size:12px;color:var(--text-3);border-top:1px solid var(--border);padding-top:12px">Machine opens and scanner clicks are excluded. <a href="#/activity" style="color:var(--accent-text);font-weight:600">See every email →</a></div></div></div>
        </div>
        <div class="grid two" style="margin-top:16px">
          <div class="card"><div class="card-head"><div><h3>Mailbox providers</h3><div class="sub">Detected from the receiving server, so company domains on Google or Microsoft count too</div></div></div>
            <div class="table-wrap" style="margin-top:8px"><table class="tbl"><thead><tr><th>Provider</th><th class="num">Share</th><th class="num">Delivered</th><th class="num">Real opens</th><th class="num">Bounces</th><th class="num">Spam</th></tr></thead><tbody>
            ${s.providers.length ? s.providers.map((p) => `<tr style="cursor:pointer" data-p="${p.provider}"><td class="cell-main">${PROV_LONG[p.provider] || p.name}</td><td class="num">${pct(p.sent / Math.max(1, s.providers.reduce((a, x) => a + x.sent, 0)), 0)}</td><td class="num">${pct(rate(p.delivered, p.sent))}</td><td class="num">${p.delivered ? pct(rate(p.opened, p.delivered)) : '—'}</td><td class="num ${rate(p.bounced, p.sent) > 0.02 ? 'pill-warn' : ''}">${pct(rate(p.bounced, p.sent))}</td><td class="num">${p.delivered ? pct(rate(p.complained, p.delivered), 2) : '—'}</td></tr>`).join('') : '<tr style="cursor:default"><td colspan="6" class="faint">No emails in this period.</td></tr>'}
            </tbody></table></div></div>
          <div class="card"><div class="card-head"><div><h3>Sender health</h3><div class="sub">${esc(health.title)}</div></div><a class="btn btn-sm" href="#/domains">Domains</a></div>
            <div class="card-body">${health.rows.map(([a, b, ok]) => `<div class="health-row"><span>${a}</span><span class="${ok ? 'pill-ok' : 'pill-warn'}">${b === 'ok' ? ic('check', 14, 2.5) : esc(b)}</span></div>`).join('')}</div></div>
        </div>`;
      const keys = [{ key: 'delivered', name: 'Delivered', color: '#16A34A' }, { key: 'opened', name: 'Real opens', color: '#2563EB' }, { key: 'clicked', name: 'Clicked', color: '#7C3AED' }, { key: 'bounced', name: 'Bounced', color: '#DC2626' }];
      const draw = () => { if (!$('#chart')) return; $('#legend').innerHTML = keys.map((k, i) => `<span class="legend-item ${k.off ? 'off' : ''}" data-i="${i}"><i style="background:${k.color}"></i>${k.name}</span>`).join(''); $$('.legend-item').forEach((el) => el.onclick = () => { keys[el.dataset.i].off = !keys[el.dataset.i].off; draw(); }); lineChart($('#chart'), ser, keys, { every: s.days > 14 ? 5 : 2 }); };
      draw(); window.onresize = draw;
      $('#ovd').onchange = (e) => { ov.days = +e.target.value; pages.overview(v); };
      $('#ovt').onchange = (e) => { ov.type = e.target.value; pages.overview(v); };
      $$('tr[data-p]', v).forEach((tr) => tr.onclick = () => { act.provider = tr.dataset.p; act.status = ''; location.hash = '#/activity'; });
    }).catch((err) => alive(g) && failed(v, err));
  };
  /** The same panel for a workspace that sends through its own SMTP server. */
  function smtpHealthRows(sm, T) {
    const c = sm.smtp || {}, verified = (sm.senders || []).filter((s) => s.status === 'verified'), d = verified.find((s) => s.kind === 'domain');
    const rows = [['SMTP server', c.working ? 'ok' : 'Not working', !!c.working]];
    if (!verified.length) return { title: 'No verified sender yet', rows: rows.concat([['Sender', 'Verify one under Sending', false]]) };
    if (d && d.dns_check) {
      rows.push(['SPF', d.dns_check.spf === 'found' ? 'ok' : 'No record found', d.dns_check.spf === 'found']);
      rows.push(['DMARC', d.dns_check.dmarc === 'found' ? 'ok' : 'No record found', d.dns_check.dmarc === 'found']);
    }
    const bR = T.sent ? T.bounced / T.sent : 0;
    rows.push(['Rejections', T.sent ? `${pct(bR)} · ${bR < 0.02 ? 'good' : 'high'}` : 'No data yet', bR < 0.02]);
    rows.push(['Spam reports', 'Not reported by SMTP', true]);
    return { title: verified[0].value + (verified.length > 1 ? ` +${verified.length - 1} more` : ''), rows };
  }

  function healthRows(domains, conn, T) {
    const verified = domains.filter((d) => d.status === 'verified');
    const d = verified[0];
    const rows = [];
    if (!conn) return { title: 'Amazon SES not connected', rows: [['Amazon SES', 'Not connected', false]] };
    rows.push(['Amazon SES', conn.production_access ? 'ok' : 'Sandbox: verified recipients only', conn.production_access]);
    if (!d) return { title: 'No verified domain yet', rows: rows.concat([['Sending domain', 'Add one under Domains', false]]) };
    const live = (k) => (d.records || []).find((r) => r.key === k);
    rows.push(['DKIM', d.dkim_status === 'SUCCESS' ? 'ok' : 'Not verified', d.dkim_status === 'SUCCESS']);
    rows.push(['SPF / MAIL FROM', d.mail_from_status === 'SUCCESS' ? 'ok' : 'Not set up', d.mail_from_status === 'SUCCESS']);
    const dm = live('dmarc');
    rows.push(['DMARC', dm && dm.live === 'found' ? 'ok' : 'No record found', !!(dm && dm.live === 'found')]);
    const bR = T.sent ? T.bounced / T.sent : 0, sR = T.delivered ? T.complained / T.delivered : 0;
    rows.push(['Bounce rate', T.sent ? `${pct(bR)} · ${bR < 0.02 ? 'good' : 'high'}` : 'No data yet', bR < 0.02]);
    rows.push(['Spam rate', T.delivered ? `${pct(sR, 2)} · ${sR < 0.001 ? 'good' : 'high'}` : 'No data yet', sR < 0.001]);
    return { title: d.name + (verified.length > 1 ? ` +${verified.length - 1} more` : ''), rows };
  }

  // ═══════ #/webhooks ═══════
  let EVENTS = [];
  const epStatus = (e) => !e.enabled ? (e.disabled_reason ? '<span class="chip bounced">Paused</span>' : '<span class="chip dropped">Off</span>') : e.stats_24h && e.stats_24h.retrying ? '<span class="chip deferred">Retrying</span>' : e.failure_streak >= 3 ? '<span class="chip bounced">Failing</span>' : '<span class="chip delivered">Healthy</span>';
  const evLabel = (ev) => (ev.includes('*') ? 'All events' : ev.length > 2 ? `${ev.length} events` : ev.map((x) => x.replace('email.', '')).join(', '));
  pages.webhooks = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    if (!canManage()) { v.innerHTML = `<div class="page-head"><div><h1>Webhooks</h1><p>Signed events pushed to your app in real time.</p></div></div><div class="card"><div class="card-body">Only owners and admins can manage webhooks.</div></div>`; return; }
    loading(v, 'Loading your webhooks…');
    API('GET', base() + '/webhooks').then((r) => {
      if (!alive(g)) return;
      EVENTS = r.events;
      v.innerHTML = `<div class="page-head"><div><h1>Webhooks</h1><p>A signed event at every step, pushed to your app in real time.</p></div><button class="btn btn-primary" id="add-wh">${ic('plus', 15)}Add endpoint</button></div>
        <div class="card">${r.endpoints.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Endpoint</th><th>Events</th><th class="num">Success (24h)</th><th class="num">Avg time</th><th>Status</th></tr></thead><tbody>
          ${r.endpoints.map((e) => `<tr data-id="${e.id}"><td><div class="mono" style="font-size:12.5px">${esc(e.url)}</div>${e.description ? `<div class="cell-sub">${esc(e.description)}</div>` : ''}</td><td><span class="tag">${esc(evLabel(e.events))}</span></td><td class="num">${e.stats_24h.total ? pct(e.stats_24h.success / e.stats_24h.total) : '—'}</td><td class="num faint">${e.stats_24h.avg_ms != null ? e.stats_24h.avg_ms + 'ms' : '—'}</td><td>${epStatus(e)}</td></tr>`).join('')}
          </tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No endpoints yet</b><p class="faint" style="margin:6px 0 0">Add one to get delivered, bounced, opened and clicked events in your app.</p></div>`}</div>
        <div class="grid half" style="margin-top:16px">
          <div class="card"><div class="card-head"><h3>Event types</h3></div><div class="card-body" style="display:grid;gap:8px">${r.events.map((e) => `<div style="display:flex;justify-content:space-between;gap:12px;font-size:13px"><span class="mono">${esc(e.type)}</span><span class="faint" style="text-align:right">${esc(e.description)}</span></div>`).join('')}</div></div>
          <div class="card"><div class="card-head"><div><h3>Verify the signature</h3><div class="sub">Standard Webhooks format: any of its libraries works</div></div></div><div class="card-body"><pre class="code">${esc(`// npm i standardwebhooks
import { Webhook } from "standardwebhooks";

const wh = new Webhook(process.env.MAILVESPER_WEBHOOK_SECRET); // whsec_…
// Use the raw request body, before JSON parsing.
const event = wh.verify(rawBody, {
  "webhook-id": req.headers["webhook-id"],
  "webhook-timestamp": req.headers["webhook-timestamp"],
  "webhook-signature": req.headers["webhook-signature"],
}); // throws if the signature is wrong

if (event.type === "email.bounced") { /* … */ }`)}</pre>
            <p class="faint" style="font-size:12.5px;margin-top:10px">Reply with any 2xx within 10 seconds. Anything else is retried after 1 min, 5 min, 30 min, 2 h, 6 h and 12 h. Each delivery has a <span class="mono">webhook-id</span> header you can use to ignore duplicates.</p></div></div>
        </div>`;
      $('#add-wh').onclick = addEndpoint;
      $$('tr[data-id]', v).forEach((tr) => tr.onclick = () => openEndpoint(r.endpoints.find((e) => e.id === tr.dataset.id)));
    }).catch((err) => alive(g) && failed(v, err));
  };
  const eventChecks = (selected) => `<div class="check-grid"><label class="check" style="grid-column:1/-1"><input type="checkbox" value="*" ${selected.includes('*') ? 'checked' : ''}><b>All events</b></label>${EVENTS.map((e) => `<label class="check" title="${esc(e.description)}"><input type="checkbox" value="${e.type}" ${selected.includes(e.type) || selected.includes('*') ? 'checked' : ''} ${selected.includes('*') ? 'disabled' : ''}><span class="mono">${e.type}</span></label>`).join('')}</div>`;
  const bindChecks = (root) => { const all = $('input[value="*"]', root); all.onchange = () => $$('.check-grid input:not([value="*"])', root).forEach((i) => { i.disabled = all.checked; if (all.checked) i.checked = true; }); };
  const readChecks = (root) => $('input[value="*"]', root).checked ? ['*'] : $$('.check-grid input:checked', root).map((i) => i.value);
  function addEndpoint() {
    modal({ title: 'Add webhook endpoint', desc: 'We POST signed JSON to this URL for the events you choose.', icon: 'webhooks', size: 'lg',
      body: `<div class="field"><label>Endpoint URL</label><input id="wu" placeholder="https://api.yourapp.com/webhooks/mailvesper" spellcheck="false"><span class="help">Must be a public HTTPS address that replies with 2xx within 10 seconds.</span></div>
        <div class="field"><label>Description <span class="faint">(optional)</span></label><input id="wd" maxlength="120" placeholder="e.g. Production CRM"></div>
        <div class="field"><label>Events</label>${eventChecks(['email.delivered', 'email.bounced', 'email.complained'])}</div>`,
      mount: bindChecks,
      actions: [{ label: 'Cancel' }, { label: 'Add endpoint', kind: 'primary', onClick: (m) => {
        const url = $('#wu', m.root).value.trim(), events = readChecks(m.root);
        if (!/^https:\/\/\S+\.\S+/.test(url)) return fieldError(m.root, '#wu', 'Use a full https:// address.');
        if (!events.length) return toast('Choose at least one event.');
        const b = m.root.querySelector('.m-foot .btn-primary'); b.disabled = true;
        API('POST', base() + '/webhooks', { url, events, description: $('#wd', m.root).value.trim() || null })
          .then((r) => { m.close(); showSecret(r.secret, r.endpoint); })
          .catch((err) => { b.disabled = false; if (err.fields && err.fields.url) return fieldError(m.root, '#wu', err.fields.url); toast(err.message); });
        return false;
      } }] });
  }
  function showSecret(secret, endpoint) {
    modal({ title: 'Endpoint added', desc: 'Use this signing secret to check that requests really come from ' + SL_BN() + '.', icon: 'webhooks', size: 'sm',
      body: `<div class="cs-cmd" style="padding-left:12px"><code style="white-space:normal;word-break:break-all">${esc(secret)}</code><button class="btn btn-sm" id="cps">${ic('copy', 13)}Copy</button></div><p class="faint" style="font-size:12.5px;margin-top:10px">You can see it again in the endpoint’s settings.</p><div id="tres"></div>`,
      mount: (root) => { $('#cps', root).onclick = () => { navigator.clipboard && navigator.clipboard.writeText(secret); toast('Secret copied'); }; },
      actions: [{ label: 'Send a test event', onClick: (m) => { testPing(endpoint.id, $('#tres', m.root)); return false; } }, { label: 'Done', kind: 'primary', onClick: () => S.route() }] });
  }
  function testPing(id, box) {
    if (box) box.innerHTML = '<div class="tf-res"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span>Sending a test event…</div>';
    return API('POST', `${base()}/webhooks/${id}/test`).then((r) => { const d = r.delivery; const html = `<div class="tf-res"><span>${d.status === 'success' ? `<span class="pill-ok">${d.response_code} OK</span>` : `<span class="pill-warn">${esc(d.error || 'Failed')}</span>`}</span><span class="faint">${d.duration_ms}ms</span></div>`; if (box) box.innerHTML = html; else toast(d.status === 'success' ? `Test delivered: ${d.response_code} in ${d.duration_ms}ms` : `Test failed: ${d.error}`); return d; }).catch((err) => toast(err.message));
  }
  function openEndpoint(e) {
    const statusCell = (d) => d.status === 'success' ? `<span class="pill-ok">${d.response_code}</span>` : d.status === 'retrying' ? `<span class="pill-warn">${d.response_code || 'Error'} · retrying</span>` : d.status === 'failed' ? `<span class="pill-bad">${esc(d.response_code ? String(d.response_code) : 'Failed')}</span>` : `<span class="faint">${esc(d.status)}</span>`;
    let deliveries = null;
    const panes = {
      del: () => deliveries === null ? '<p class="faint">Loading deliveries…</p>' : deliveries.length ? `<table class="tbl"><thead><tr><th>Event</th><th>Response</th><th class="num">Time</th><th>When</th><th></th></tr></thead><tbody>${deliveries.map((d) => `<tr data-d="${d.id}" style="cursor:pointer"><td><span class="mono" style="font-size:12px">${esc(d.event_type)}</span>${d.tracking_code ? `<div class="cell-sub">${esc(d.tracking_code)}</div>` : ''}</td><td>${statusCell(d)}</td><td class="num faint">${d.duration_ms != null ? d.duration_ms + 'ms' : '—'}</td><td class="faint">${ago(d.created_at)}</td><td style="text-align:right"><button class="btn btn-sm btn-ghost rp" data-d="${d.id}" title="Send again">${ic('refresh', 14)}</button></td></tr>`).join('')}</tbody></table>` : '<p class="faint">No deliveries yet. Send a test event, or wait for the next email.</p>',
      set: () => `<div class="field"><label>Endpoint URL</label><input id="eu" value="${esc(e.url)}" spellcheck="false"></div><div class="field"><label>Description</label><input id="ed" value="${esc(e.description || '')}" maxlength="120"></div><div class="field"><label>Events</label>${eventChecks(e.events)}</div><button class="btn btn-primary btn-sm" id="esave">Save changes</button>
        <div class="setting-row" style="margin-top:18px"><div><b>Enabled</b><span>${e.disabled_reason ? esc(e.disabled_reason) : 'Turn off to stop deliveries without deleting the endpoint.'}</span></div><label class="switch"><input type="checkbox" id="een" ${e.enabled ? 'checked' : ''}><span></span></label></div>
        <div class="setting-row"><div><b>Signing secret</b><span class="mono" id="sec">${esc(e.secret_hint)}</span></div><div style="display:flex;gap:6px"><button class="btn btn-sm" id="reveal">Reveal</button><button class="btn btn-sm" id="rotate">Rotate</button></div></div>
        <button class="btn btn-danger btn-sm" style="margin-top:16px" id="edel">${ic('trash', 14)}Delete endpoint</button>`,
    };
    const loadDeliveries = (d) => API('GET', `${base()}/webhooks/${e.id}/deliveries`).then((r) => { deliveries = r.deliveries; const p = $('[data-pane]', d); if (p && $('.tab.on', d).dataset.t === 'del') { p.innerHTML = panes.del(); bindDel(d); } });
    const bindDel = (d) => {
      $$('tr[data-d]', d).forEach((tr) => tr.onclick = (ev) => { if (ev.target.closest('.rp')) return; showDelivery(tr.dataset.d); });
      $$('.rp', d).forEach((b) => b.onclick = () => { b.disabled = true; API('POST', `${base()}/webhook-deliveries/${b.dataset.d}/replay`).then((r) => { toast(r.delivery.status === 'success' ? `Delivered: ${r.delivery.response_code}` : `Failed: ${r.delivery.error}`); loadDeliveries(d); }).catch((err) => { b.disabled = false; toast(err.message); }); });
    };
    const bindSet = (d) => {
      bindChecks(d);
      $('#esave', d).onclick = () => { const events = readChecks(d); if (!events.length) return toast('Choose at least one event.'); API('PATCH', `${base()}/webhooks/${e.id}`, { url: $('#eu', d).value.trim(), description: $('#ed', d).value.trim() || null, events }).then((r) => { Object.assign(e, r.endpoint); toast('Saved'); }).catch((err) => { if (err.fields && err.fields.url) return fieldError(d, '#eu', err.fields.url); toast(err.message); }); };
      $('#een', d).onchange = (ev) => API('PATCH', `${base()}/webhooks/${e.id}`, { enabled: ev.target.checked }).then((r) => { Object.assign(e, r.endpoint); toast(e.enabled ? 'Endpoint turned on' : 'Endpoint turned off'); }).catch((err) => toast(err.message));
      $('#reveal', d).onclick = () => API('GET', `${base()}/webhooks/${e.id}/secret`).then((r) => { $('#sec', d).innerHTML = `<span class="copy" data-copy="${esc(r.secret)}" style="word-break:break-all">${esc(r.secret)} ${ic('copy', 12)}</span>`; bindCopy(d); });
      $('#rotate', d).onclick = () => confirmDialog({ title: 'Rotate the signing secret?', desc: 'Requests are signed with the new secret straight away. Update your app first, or verification will fail.', label: 'Rotate', onConfirm: () => API('POST', `${base()}/webhooks/${e.id}/rotate`).then((r) => { $('#sec', d).innerHTML = `<span class="copy" data-copy="${esc(r.secret)}" style="word-break:break-all">${esc(r.secret)} ${ic('copy', 12)}</span>`; bindCopy(d); toast('Secret rotated'); }) });
      $('#edel', d).onclick = () => confirmDialog({ title: 'Delete this endpoint?', desc: 'Events stop being delivered to it immediately, and its delivery log is deleted.', onConfirm: () => API('DELETE', `${base()}/webhooks/${e.id}`).then(() => { closeDrawer(); toast('Endpoint deleted'); S.route(); }) });
    };
    const st = e.stats_24h || {};
    drawer(`<div class="drawer-head"><div class="drawer-top"><div>${epStatus(e)}<h2 style="margin-top:8px;word-break:break-all" class="mono">${esc(e.url)}</h2></div><div style="margin-left:auto;display:flex;gap:6px"><button class="btn btn-sm" id="eping">${ic('send', 14)}Send test</button><button class="icon-btn" data-dclose>${ic('x')}</button></div></div>
      <div class="drawer-meta"><span>${st.total ? pct(st.success / st.total) + ' success (24h)' : 'No deliveries in 24h'}</span>${st.avg_ms != null ? `<span>Avg ${st.avg_ms}ms</span>` : ''}<span>${esc(evLabel(e.events))}</span></div>
      <div class="tabs"><button class="tab on" data-t="del">Deliveries</button><button class="tab" data-t="set">Settings</button></div></div>
      <div class="drawer-body" data-pane>${panes.del()}</div>`, (d) => {
      tabs(d, panes, (t) => (t === 'del' ? bindDel(d) : bindSet(d)));
      $('#eping', d).onclick = (ev) => { const b = ev.currentTarget; b.disabled = true; testPing(e.id).then(() => { b.disabled = false; loadDeliveries(d); }); };
      loadDeliveries(d);
    });
  }
  function showDelivery(id) {
    API('GET', `${base()}/webhook-deliveries/${id}`).then((r) => {
      const d = r.delivery;
      modal({ title: d.event_type, desc: `${d.status === 'success' ? 'Delivered' : d.status === 'retrying' ? `Retrying${d.next_attempt_at ? ' at ' + clock(d.next_attempt_at) : ''}` : d.status === 'failed' ? 'Gave up after all retries' : d.status} · attempt ${d.attempts}`, icon: 'webhooks', size: 'lg',
        body: `<div class="cp-label" style="margin-top:0">Payload</div><pre class="code" style="max-height:260px">${json(d.payload)}</pre>
          <div class="cp-label">Response</div><div class="tf-res" style="margin-top:0"><span>${d.response_code ? `HTTP ${d.response_code}` : esc(d.error || 'No response')}</span><span class="faint">${d.duration_ms != null ? d.duration_ms + 'ms' : ''}</span></div>${d.response_body ? `<pre class="code" style="margin-top:8px;max-height:140px;white-space:pre-wrap">${esc(d.response_body)}</pre>` : ''}`,
        actions: [{ label: 'Close' }] });
    }).catch((err) => toast(err.message));
  }
})();
