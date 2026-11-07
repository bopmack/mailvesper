/* MailVesper: send through your own SMTP server instead of Amazon SES (one method per workspace, switchable).
   Adds #/ses/smtp, puts an "Amazon SES | SMTP server" switch on #/ses, shows SMTP senders on #/domains for SMTP
   workspaces, and provides the From picker helpers (S.senders, S.fromPick*) used across the app.
   Talks to /api/v1/workspaces/{id}/smtp*, /senders and /sending/method. Loaded after ses.js and copilot.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, confirmDialog, fieldError, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const canEdit = () => !!myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}`;
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { 'ses/smtp': 'Sending', 'ses/reports': 'Sending', 'ses/seeds': 'Sending', 'ses/mailbox': 'Sending', 'ses/tracking': 'Sending' });

  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ago = (iso) => { if (!iso) return 'never'; const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000)); return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };
  const busy = (b, text, p) => { b.disabled = true; const t = b.innerHTML; b.innerHTML = `<span class="spin sm"></span>${text}`; return p.finally(() => { b.disabled = false; b.innerHTML = t; }); };

  // ── From pickers (campaigns, journeys, test sends): verified domains and single addresses ──
  S.senders = () => API('GET', base() + '/senders').catch(() => ({ method: null, domains: [], emails: [] }));
  S.hasSenders = (s) => !!(s && ((s.domains || []).length || (s.emails || []).length));
  /** "name @ domain", where the domain list also offers verified single addresses (then the name box hides). */
  S.firstFrom = (s, local = 'hello') => (s.default && s.default.email) || ((s.domains || [])[0] ? `${local}@${s.domains[0]}` : (s.emails || [])[0]);
  S.fromPick = (id, s, current, local = 'hello', disabled = false) => {
    const cur = String(current || '').toLowerCase(), curDomain = cur.split('@')[1] || '';
    const isEmail = (s.emails || []).includes(cur);
    const opts = (s.domains || []).map((d) => `<option value="d:${esc(d)}" ${!isEmail && d === curDomain ? 'selected' : ''}>${esc(d)}</option>`)
      .concat((s.emails || []).map((e) => `<option value="e:${esc(e)}" ${isEmail && e === cur ? 'selected' : ''}>${esc(e)}</option>`)).join('');
    const loc = cur && !isEmail ? cur.split('@')[0] : local;
    const d = disabled ? 'disabled' : '';
    return `<div class="from-pick" data-fp="${id}"><input id="${id}-l" value="${esc(loc)}" spellcheck="false" autocapitalize="off" ${d}><span id="${id}-at">@</span><select id="${id}-d" class="select" ${d}>${opts}</select></div>`;
  };
  S.fromPickBind = (root, id, onChange) => {
    const sel = $(`#${id}-d`, root), loc = $(`#${id}-l`, root), at = $(`#${id}-at`, root);
    if (!sel) return;
    const sync = () => { const e = sel.value.startsWith('e:'); loc.style.display = e ? 'none' : ''; at.style.display = e ? 'none' : ''; };
    sel.addEventListener('change', () => { sync(); onChange && onChange(); });
    loc.addEventListener('change', () => onChange && onChange());
    sync();
  };
  S.fromPickValue = (root, id) => {
    const v = ($(`#${id}-d`, root) || {}).value || '';
    return v.startsWith('e:') ? v.slice(2) : `${($(`#${id}-l`, root) || {}).value.trim()}@${v.slice(2)}`;
  };

  const state = () => API('GET', base() + '/smtp');
  const tabs = (active, st) => `<div class="ptabs" style="margin:0 0 16px">
      <a class="ptab ${active === 'ses' ? 'on' : ''}" href="#/ses?view=ses">Amazon SES${st.method === 'ses' ? ' <span class="tag" style="margin-left:6px">In use</span>' : ''}</a>
      <a class="ptab ${active === 'smtp' ? 'on' : ''}" href="#/ses/smtp">SMTP server${st.method === 'smtp' ? ' <span class="tag" style="margin-left:6px">In use</span>' : ''}</a>
      <a class="ptab ${active === 'reports' ? 'on' : ''}" href="#/ses/reports">Delivery reports</a>
      <a class="ptab ${active === 'seeds' ? 'on' : ''}" href="#/ses/seeds">Seed mailboxes</a>
      <a class="ptab ${active === 'mailbox' ? 'on' : ''}" href="#/ses/mailbox">Mailbox</a>
      <a class="ptab ${active === 'tracking' ? 'on' : ''}" href="#/ses/tracking">Link tracking</a></div>
      ${(st.alerts || []).map((a) => `<div class="callout ${a.level === 'urgent' ? 'error' : 'warn'}" style="margin:0 0 12px">${ic('alert', 15)}<div><b>${esc(a.title)}</b><br>${esc(a.detail)}</div></div>`).join('')}`;
  const head = `<div class="page-head"><div><h1>Sending</h1><p>Send through your own Amazon SES account or your own SMTP server. One is in use at a time, and you can switch.</p></div></div>`;
  /** A Sending tab drawn by another module (Pro's seed mailboxes, Community's lock): the page head and tabs, then fill(body). */
  S.sendingPage = (v, active, fill) => {
    const g = (seq = S.routeSeq, ++gen);
    state().then((st) => {
      if (!alive(g)) return;
      v.innerHTML = head + tabs(active, st) + '<div id="sp-body"></div>';
      fill($('#sp-body', v), () => alive(g));
    }).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };
  const switchTo = (method) => API('POST', base() + '/sending/method', { method }).then(() => { toast(method === 'smtp' ? 'Now sending through your SMTP server' : 'Now sending through Amazon SES'); S.route(); }).catch((err) => toast(err.message));

  // ═══════ #/ses: the SES page, under the switch ═══════
  const sesPage = pages.ses;
  pages.ses = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    state().then((st) => {
      if (!alive(g)) return;
      if (st.method === 'smtp' && S.param('view') !== 'ses') { location.replace('#/ses/smtp'); return; }
      v.innerHTML = head + tabs('ses', st)
        + (st.method === 'smtp' && st.ses_connected && canManage() ? `<div class="callout info" style="margin:0 0 16px">${ic('info', 15)}<div>This workspace sends through your SMTP server right now. <a href="#" id="use-ses">Use Amazon SES instead</a></div></div>` : '')
        + '<div id="ses-body"></div>';
      const u = $('#use-ses', v); if (u) u.onclick = (e) => { e.preventDefault(); switchTo('ses'); };
      sesPage($('#ses-body', v));
    }).catch(() => sesPage(v));
  };

  // ═══════ #/ses/smtp ═══════
  const PRESETS = [
    ['Google Workspace', 'smtp.gmail.com', 587, 'tls', '', 'Use an app password, not your normal one.'],
    ['Microsoft 365', 'smtp.office365.com', 587, 'tls', '', 'SMTP AUTH must be turned on for the mailbox.'],
    ['SendGrid', 'smtp.sendgrid.net', 587, 'tls', 'apikey', 'The password is your SendGrid API key.'],
    ['Mailgun', 'smtp.mailgun.org', 587, 'tls', '', 'Use the SMTP credentials of your Mailgun domain.'],
    ['Brevo', 'smtp-relay.brevo.com', 587, 'tls', '', 'Use your Brevo SMTP login and SMTP key.'],
    ['Postmark', 'smtp.postmarkapp.com', 587, 'tls', '', 'Username and password are both your server API token.'],
    ['Zoho Mail', 'smtp.zoho.com', 465, 'ssl', '', 'Use an app-specific password if two-factor is on.'],
  ];
  pages['ses/smtp'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = head + '<div class="card"><div class="card-body faint">Loading…</div></div>';
    state().then((st) => alive(g) && draw(v, g, st)).catch((err) => alive(g) && (v.innerHTML = head + `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  function draw(v, g, st) {
    const c = st.smtp, mg = canManage(), dis = mg ? '' : 'disabled';
    const status = !c ? '<span class="faint">Not set up</span>' : c.working ? '<span class="pill-ok">Working</span>' : '<span class="pill-bad">Not working</span>';
    const banner = !c ? '' : st.method === 'smtp'
      ? `<div class="callout ok" style="margin:0 0 16px">${ic('check', 15)}<div>This workspace sends through <b>${esc(c.host)}</b>.${st.ses_connected && mg ? ' <a href="#" id="use-ses">Use Amazon SES instead</a>' : ''}</div></div>`
      : c.working && mg ? `<div class="callout info" style="margin:0 0 16px">${ic('info', 15)}<div>This workspace sends through Amazon SES. <a href="#" id="use-smtp">Send through ${esc(c.host)} instead</a></div></div>` : '';
    v.innerHTML = head + tabs('smtp', st) + banner + `
      <div class="grid two" style="align-items:start;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr)">
        <section class="card"><div class="card-head"><div><h3>SMTP server</h3><div class="sub">${status}${c && c.last_checked_at ? ' · tested ' + ago(c.last_checked_at) : ''}</div></div>${c && mg ? `<button class="btn btn-sm" id="sm-test">Test again</button>` : ''}</div>
          <div class="card-body">
            ${c && c.last_error ? `<div class="callout warn" style="margin:0 0 14px">${ic('alert', 15)}<div>${esc(c.last_error)}</div></div>` : ''}
            ${mg ? `<div class="field"><label>Fill in for</label><div style="display:flex;gap:6px;flex-wrap:wrap">${PRESETS.map((p, i) => `<button type="button" class="btn btn-sm" data-pre="${i}">${esc(p[0])}</button>`).join('')}</div><span class="help" id="sm-hint"></span></div>` : ''}
            <div class="row2"><div class="field"><label>Host</label><input id="sm-host" value="${esc(c ? c.host : '')}" placeholder="smtp.example.com" spellcheck="false" autocapitalize="off" ${dis}></div>
              <div class="field"><label>Port</label><input id="sm-port" type="number" min="1" max="65535" inputmode="numeric" list="sm-port-l" value="${esc(String(c ? c.port : 587))}" ${dis}><datalist id="sm-port-l"><option value="587">STARTTLS</option><option value="465">SSL/TLS</option><option value="2525"></option><option value="25"></option></datalist></div></div>
            <div class="field"><label>Encryption</label><select id="sm-enc" class="select" ${dis}>${[['tls', 'STARTTLS (usually port 587)'], ['ssl', 'SSL/TLS (usually port 465)'], ['none', 'None (no sign-in; trusted networks only)']].map(([k, l]) => `<option value="${k}" ${(c ? c.encryption : 'tls') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            <div class="row2"><div class="field"><label>Username</label><input id="sm-user" value="${esc(c && c.username ? c.username : '')}" autocomplete="off" spellcheck="false" ${dis}></div>
              <div class="field"><label>Password or API key</label><input id="sm-pass" type="password" value="${c && c.password ? c.password : ''}" autocomplete="new-password" ${dis}></div></div>
            <div class="row2"><div class="field"><label>Emails per second</label><input id="sm-rate" type="number" min="1" max="100" value="${c ? c.max_per_second : 5}" ${dis}><span class="help">${SL_BN()} never sends faster than this.</span></div>
              <div class="field"><label>Daily limit <span class="faint">(optional)</span></label><input id="sm-day" type="number" min="1" value="${c && c.max_per_day ? c.max_per_day : ''}" placeholder="No limit" ${dis}><span class="help">Email over it waits for the next day.</span></div></div>
            ${mg ? `<div style="display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-top:6px"><button class="btn btn-primary" id="sm-save">${ic('check', 15)}Save and test</button>${c ? '<button class="btn btn-danger" id="sm-del">Remove</button>' : ''}</div>` : '<p class="faint">Only owners and admins can change how this workspace sends.</p>'}
          </div></section>
        <div>
          <section class="card" id="senders-card">${sendersHtml(st)}</section>
          <section class="card" style="margin-top:16px"><div class="card-head"><div><h3>What SMTP reports</h3></div></div><div class="card-body" style="font-size:13px;line-height:1.65">
            <p><b>Tracked:</b> sent (your server accepted it), opens, clicks, unsubscribes, and addresses your server rejects straight away, which are suppressed.</p>
            <p style="margin-top:6px"><b>Not reported by SMTP:</b> bounces that arrive later as bounce emails, and spam complaints. Amazon SES reports both, which is why its reputation checks go further.</p>
          </div></section>
        </div>
      </div>`;
    bindCopy(v);
    const ue = $('#use-ses', v); if (ue) ue.onclick = (e) => { e.preventDefault(); switchTo('ses'); };
    const us = $('#use-smtp', v); if (us) us.onclick = (e) => { e.preventDefault(); switchTo('smtp'); };
    $$('[data-pre]', v).forEach((b) => b.onclick = () => {
      const p = PRESETS[+b.dataset.pre];
      $('#sm-host', v).value = p[1]; $('#sm-port', v).value = String(p[2]); $('#sm-enc', v).value = p[3];
      if (p[4]) $('#sm-user', v).value = p[4];
      $('#sm-hint', v).textContent = p[0] + ': ' + p[5];
    });
    const port = $('#sm-port', v); if (port) port.onchange = () => { if (port.value === '465') $('#sm-enc', v).value = 'ssl'; else if (['587', '2525', '25'].includes(port.value)) $('#sm-enc', v).value = 'tls'; };
    const save = $('#sm-save', v);
    if (save) save.onclick = () => {
      const body = { host: $('#sm-host', v).value.trim(), port: +$('#sm-port', v).value, encryption: $('#sm-enc', v).value, username: $('#sm-user', v).value.trim() || null,
        password: $('#sm-pass', v).value || null, max_per_second: +$('#sm-rate', v).value || 5, max_per_day: +$('#sm-day', v).value || null };
      if (!/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(body.host)) return fieldError(v, '#sm-host', 'Enter the server name, like smtp.example.com.');
      if (!(body.port >= 1 && body.port <= 65535)) return fieldError(v, '#sm-port', 'Enter the port your provider gives (1–65535).');
      busy(save, 'Testing…', API('PUT', base() + '/smtp', body).then((r) => {
        toast(r.test.ok ? 'Saved. ' + r.test.message : 'Saved, but the test failed');
        if (alive(g)) draw(v, g, r);
      }).catch((err) => toast(err.message)));
    };
    const t = $('#sm-test', v);
    if (t) t.onclick = () => busy(t, 'Testing…', API('POST', base() + '/smtp/test').then((r) => { toast(r.test.ok ? r.test.message : 'The test failed'); if (alive(g)) draw(v, g, r); }).catch((err) => toast(err.message)));
    const del = $('#sm-del', v);
    if (del) del.onclick = () => confirmDialog({ title: 'Remove these SMTP settings?', desc: st.method === 'smtp' ? 'This workspace stops sending until you connect Amazon SES or add SMTP settings again. Your senders stay.' : 'Your senders stay, in case you add SMTP settings again.', label: 'Remove',
      onConfirm: () => API('DELETE', base() + '/smtp').then(() => { toast('SMTP settings removed'); S.route(); }).catch((err) => toast(err.message)) });
    bindSenders(v, g, st);
  }

  // What each SMTP provider needs on its side before it sends for a domain: MailVesper's verification proves the
  // domain is yours; the provider also has to accept it.
  const PROVIDERS = [
    [/sendgrid/, 'SendGrid', 'sendgrid.net', 'SendGrid also needs this domain authenticated: Settings → Sender Authentication → Authenticate your domain.'],
    [/mailgun/, 'Mailgun', 'mailgun.org', 'Mailgun sends only for domains added and verified under Sending → Domains in Mailgun.'],
    [/brevo|sendinblue/, 'Brevo', 'spf.brevo.com', 'Brevo also needs the domain authenticated: Senders, Domains & Dedicated IPs → Domains.'],
    [/postmark/, 'Postmark', 'spf.mtasv.net', 'Postmark needs the domain (DKIM and Return-Path) set up under Sender Signatures.'],
    [/amazonaws\.com/, 'Amazon SES (SMTP)', 'amazonses.com', 'Amazon SES needs the domain verified in the SES console too.'],
    [/gmail|google/, 'Google', '_spf.google.com', 'Gmail and Google Workspace send only as the signed-in account or its aliases (Settings → Accounts → Send mail as).'],
    [/office365|outlook/, 'Microsoft 365', 'spf.protection.outlook.com', 'Microsoft 365 sends only as the signed-in mailbox, its aliases, or mailboxes it has Send As permission for.'],
    [/zoho/, 'Zoho', 'zoho', 'Zoho sends only as the signed-in account or addresses added under Mail Accounts → Send Mail As.'],
  ];
  S.smtpProvider = (host) => { const h = String(host || '').toLowerCase(); const p = h && PROVIDERS.find((x) => x[0].test(h)); return p ? { name: p[1], spf: p[2], note: p[3] } : (h ? { name: h, spf: null, note: 'Your SMTP server must also allow sending for this domain (and include it in your SPF record).' } : null); };

  // ── Senders: a domain (TXT record) or one address (emailed code) ──
  function sendersHtml(st) {
    const mg = canManage(), list = st.senders || [];
    const pill = (s) => s.status === 'verified' ? '<span class="pill-ok">Verified</span>' : '<span class="pill-warn">Waiting</span>';
    const prov = S.smtpProvider((st.smtp || {}).host);
    const spfOk = (s) => s.dns_check && s.dns_check.spf_record && prov && prov.spf ? s.dns_check.spf_record.toLowerCase().includes(prov.spf) : null;
    const chips = (s) => s.kind === 'domain' && s.dns_check ? ['spf', 'dmarc'].map((k) => `<span class="tag" style="margin-right:4px;${s.dns_check[k] === 'found' ? '' : 'color:var(--s-deferred)'}">${k.toUpperCase()} ${s.dns_check[k] === 'found' ? '✓' : 'missing'}</span>`).join('')
      + (spfOk(s) === false ? `<span class="tag" style="color:var(--s-deferred)">SPF doesn’t include ${esc(prov.spf)}</span>` : spfOk(s) ? `<span class="tag">SPF includes ${esc(prov.name)} ✓</span>` : '') : '';
    const needs = (s) => s.kind === 'domain' && s.status === 'verified' && prov ? `<p class="faint" style="font-size:12.5px;margin:6px 0 0">${ic('info', 12)} ${esc(prov.note)}</p>` : '';
    const row = (s) => `<div class="sender" data-id="${s.id}" style="padding:12px 0;border-top:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><div style="min-width:0"><div class="cell-main" style="word-break:break-all">${esc(s.value)}</div><div class="cell-sub">${s.kind === 'domain' ? 'Any address on this domain' : 'This address only'} · ${pill(s)}</div></div>
          ${mg ? `<button class="icon-btn" data-rm="${s.id}" aria-label="Remove">${ic('trash', 15)}</button>` : ''}</div>
        ${s.status === 'verified' ? (chips(s) ? `<div style="margin-top:6px">${chips(s)}</div>` : '') + needs(s) + (s.kind === 'domain' && st.method === 'smtp' && canEdit() ? `<div style="margin-top:8px"><button class="btn btn-sm" data-dvc="${esc(s.value)}">${ic('check', 13)}Check my setup</button><div data-dvbox="${esc(s.value)}" style="margin-top:8px"></div></div>` : '') : s.kind === 'domain' ? `
          <p class="faint" style="font-size:12.5px;margin:8px 0 6px">Publish this TXT record at your DNS host, then check:</p>
          <div style="display:grid;gap:6px;font-size:12.5px"><div><span class="faint">Name</span> <span class="copy" data-copy="${esc(s.record.host)}">${esc(s.record.host)} ${ic('copy', 12)}</span></div>
            <div><span class="faint">Value</span> <span class="copy" data-copy="${esc(s.record.value)}" style="max-width:100%;overflow:hidden;text-overflow:ellipsis">${esc(s.record.value)} ${ic('copy', 12)}</span></div></div>
          ${canEdit() ? `<button class="btn btn-sm" data-check="${s.id}" style="margin-top:8px">Check now</button>` : ''}${s.last_checked_at ? ` <span class="faint" style="font-size:12px">Not found ${ago(s.last_checked_at)}</span>` : ''}` : `
          <p class="faint" style="font-size:12.5px;margin:8px 0 6px">${s.code_sent ? 'We emailed a 6-digit code to this address through your SMTP server.' : 'The code expired.'}</p>
          ${canEdit() ? `<div style="display:flex;gap:6px"><input data-code="${s.id}" inputmode="numeric" maxlength="6" placeholder="123456" style="max-width:120px"><button class="btn btn-sm btn-primary" data-confirm="${s.id}">Confirm</button>${mg ? `<button class="btn btn-sm" data-resend="${s.id}">Send a new code</button>` : ''}</div>` : ''}`}
      </div>`;
    return `<div class="card-head"><div><h3>Senders</h3><div class="sub">${SL_BN()} only sends from domains and addresses you’ve proven are yours.</div></div></div>
      <div class="card-body" style="padding-top:4px">
        ${mg ? `<div class="field" style="margin-bottom:8px"><div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><div class="seg" id="sd-kind"><button class="on" data-k="domain">Domain</button><button data-k="email">One address</button></div>
          <input id="sd-val" placeholder="acme.com" style="flex:1;min-width:160px" spellcheck="false" autocapitalize="off"><button class="btn btn-primary btn-sm" id="sd-add">Add</button></div>
          <span class="help" id="sd-help">Verify a whole domain with one DNS record; then any address on it can send.</span></div>` : ''}
        ${list.length ? list.map(row).join('') : '<p class="faint" style="margin-top:10px">No senders yet.</p>'}
      </div>`;
  }

  function bindSenders(v, g, st) {
    bindCopy(v);
    const redraw = () => state().then((s) => { if (!alive(g)) return; if ($('#senders-card', v)) { $('#senders-card', v).innerHTML = sendersHtml(s); bindSenders(v, g, s); } else { draw(v, g, s); } });
    let kind = 'domain';
    $$('#sd-kind button', v).forEach((b) => b.onclick = () => {
      kind = b.dataset.k; $$('#sd-kind button', v).forEach((x) => x.classList.toggle('on', x === b));
      $('#sd-val', v).placeholder = kind === 'domain' ? 'acme.com' : 'you@example.com';
      $('#sd-help', v).textContent = kind === 'domain' ? 'Verify a whole domain with one DNS record; then any address on it can send.' : 'For an address on a domain you can’t change DNS for (like Gmail). We email it a code through your SMTP server.';
    });
    const add = $('#sd-add', v);
    if (add) add.onclick = () => {
      const val = $('#sd-val', v).value.trim();
      if (!val) return fieldError(v, '#sd-val', kind === 'domain' ? 'Enter your domain.' : 'Enter the address.');
      busy(add, 'Adding…', API('POST', base() + '/smtp/senders', kind === 'domain' ? { domain: val } : { email: val }).then((r) => {
        toast(kind === 'domain' ? 'Now publish the TXT record' : 'Code sent to ' + r.sender.value); redraw();
      }).catch((err) => toast(err.message)));
    };
    $$('[data-dvc]', v).forEach((b) => b.onclick = () => {
      const dom = b.dataset.dvc;
      b.disabled = true;
      API('GET', base() + '/senders').then((snd) => {
        const from = snd.default && snd.default.email.endsWith('@' + dom) ? snd.default.email : 'hello@' + dom;
        S.runSetupCheck($(`[data-dvbox="${dom}"]`, v), from, () => { b.disabled = false; });
      }).catch((err) => { b.disabled = false; toast(err.message); });
    });
    $$('[data-check]', v).forEach((b) => b.onclick = () => busy(b, 'Checking…', API('POST', `${base()}/smtp/senders/${b.dataset.check}/check`).then((r) => {
      toast(r.sender.status === 'verified' ? r.sender.value + ' is verified' : 'The record isn’t there yet. DNS can take a few minutes.'); redraw();
    }).catch((err) => toast(err.message))));
    $$('[data-confirm]', v).forEach((b) => b.onclick = () => {
      const code = ($(`[data-code="${b.dataset.confirm}"]`, v) || {}).value || '';
      busy(b, '…', API('POST', `${base()}/smtp/senders/${b.dataset.confirm}/confirm`, { code }).then((r) => { toast(r.sender.value + ' is verified'); redraw(); }).catch((err) => toast(err.message)));
    });
    $$('[data-resend]', v).forEach((b) => b.onclick = () => busy(b, 'Sending…', API('POST', `${base()}/smtp/senders/${b.dataset.resend}/resend`).then(() => { toast('New code sent'); redraw(); }).catch((err) => toast(err.message))));
    $$('[data-rm]', v).forEach((b) => b.onclick = () => confirmDialog({ title: 'Remove this sender?', desc: 'Email from it stops until you verify it again.', label: 'Remove',
      onConfirm: () => API('DELETE', `${base()}/smtp/senders/${b.dataset.rm}`).then(() => { toast('Sender removed'); redraw(); }).catch((err) => toast(err.message)) }));
  }

  // ═══════ #/domains: SMTP workspaces manage senders here too ═══════
  const domainsPage = pages.domains;
  pages.domains = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    state().then((st) => {
      if (!alive(g)) return;
      if (st.method === 'ses') return domainsPage(v);
      const none = st.method !== 'smtp';
      v.innerHTML = `<div class="page-head"><div><h1>Domains</h1><p>${none ? 'Verify the domain you’ll send from. It’s ready the moment you connect a sending provider.' : `This workspace sends through ${esc((st.smtp || {}).host || 'your SMTP server')}, so domains are verified here (no Amazon SES needed).`}</p></div><a class="btn" href="${none ? '#/setup' : '#/ses/smtp'}">${none ? 'Set up sending' : 'SMTP settings'}</a></div>
        ${none ? `<div class="callout info" style="margin:0 0 16px">${ic('info', 15)}<div>No sending provider yet. Connect <a href="#/ses/connect">Amazon SES</a> or <a href="#/ses/smtp">an SMTP server</a> (SendGrid, Mailgun, Brevo, Postmark, Gmail, Microsoft 365…). With Amazon SES, domains are verified in SES instead.</div></div>` : ''}<section class="card" id="senders-card">${sendersHtml(st)}</section>`;
      bindSenders(v, g, st);
    }).catch(() => domainsPage(v));
  };


  // Adding a domain in Amazon SES only makes sense for SES workspaces; the others add it on #/domains.
  const sesNewDomain = pages['domains/new'];
  pages['domains/new'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    state().then((st) => { if (!alive(g)) return; if (st.method === 'ses') sesNewDomain(v); else location.replace('#/domains'); }).catch(() => sesNewDomain(v));
  };

  // ═══════ #/ses/mailbox: replies, late bounces and DMARC reports, read over IMAP ═══════
  const IMAP = [['Gmail', 'imap.gmail.com'], ['Microsoft 365', 'outlook.office365.com'], ['Zoho', 'imap.zoho.com'], ['Yahoo', 'imap.mail.yahoo.com'], ['iCloud', 'imap.mail.me.com']];
  pages['ses/mailbox'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    Promise.all([state(), API('GET', base() + '/mailbox')]).then(([st, r]) => {
      if (!alive(g)) return;
      const b = r.mailbox, ro = !canManage(), dis = ro ? 'disabled' : '';
      const stat = (n, l) => `<div><b>${fmt(n)}</b><small>${l}</small></div>`;
      v.innerHTML = head + tabs('mailbox', st) + `<div class="card"><div class="card-head" style="display:flex;justify-content:space-between;align-items:center"><b>Mailbox</b>${b ? (b.last_error ? '<span class="chip bounced">Can’t connect</span>' : b.enabled ? '<span class="chip delivered">Reading</span>' : '<span class="chip paused">Paused</span>') : '<span class="chip processing">Not set up</span>'}</div><div class="card-body">
        <p class="faint" style="margin:0 0 14px;font-size:13.5px;max-width:720px">Connect the mailbox your replies and bounce notices land in (often your From or Reply-To address). ${SL_BN()} checks it every five minutes and only reads it: nothing is marked read, moved or deleted. It picks up <b>replies</b> (shown on the email and available to journeys as “Replied”), <b>bounces that arrive later by email</b> (common with SMTP sending), <b>spam complaints</b> from providers’ feedback loops, and <b>DMARC reports</b> (for Copilot, if this is your <span class="mono">rua</span> address).</p>
        ${b ? `<div class="kpis" style="margin-bottom:14px">${stat(b.replies, 'Replies')}${stat(b.bounces, 'Late bounces')}${stat(b.complaints || 0, 'Spam complaints')}${stat(b.reports, 'DMARC reports')}<div><b>${esc(ago(b.last_checked_at))}</b><small>Last check</small></div></div>${b.last_error ? `<div class="callout warn" style="margin-bottom:14px">${ic('alert', 15)}<div>${esc(b.last_error)}</div></div>` : ''}` : ''}
        ${ro ? '<p class="faint">Owners and admins set up the mailbox.</p>' : `
        <div class="field"><label>Provider</label><div class="chips-row">${IMAP.map(([n, h]) => `<button class="btn btn-sm" data-imap="${h}">${n}</button>`).join('')}</div></div>
        <div class="row3"><div class="field"><label>IMAP server</label><input id="mb-host" value="${esc(b ? b.host : '')}" placeholder="imap.yourdomain.com" ${dis}></div>
          <div class="field"><label>Security</label><select class="select" id="mb-enc" ${dis}><option value="ssl" ${!b || b.encryption === 'ssl' ? 'selected' : ''}>SSL/TLS, port 993</option><option value="tls" ${b && b.encryption === 'tls' ? 'selected' : ''}>STARTTLS, port 143</option></select></div>
          <div class="field"><label>Folder</label><input id="mb-folder" value="${esc(b ? b.folder : 'INBOX')}" ${dis}></div></div>
        <div class="row2"><div class="field"><label>Username</label><input id="mb-user" value="${esc(b ? b.username : '')}" placeholder="you@yourdomain.com" autocomplete="off" ${dis}></div>
          <div class="field"><label>Password ${b ? '<span class="faint">(saved; type to replace)</span>' : ''}</label><input id="mb-pass" type="password" autocomplete="new-password" placeholder="${b ? '••••••••' : 'App password for Gmail, Outlook, Yahoo'}" ${dis}></div></div>
        <div class="field"><label>Read</label>${[['read_replies', 'Replies'], ['read_bounces', 'Late bounces and spam complaints'], ['read_dmarc', 'DMARC reports']].map(([k, l]) => `<label class="check" style="display:inline-flex;margin-right:16px"><input type="checkbox" data-k="${k}" ${!b || b[k] ? 'checked' : ''}> ${l}</label>`).join('')}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" id="mb-save">${b ? 'Save' : 'Connect'}</button>${b ? `<button class="btn btn-sm" id="mb-check">Check now</button><button class="btn btn-sm" id="mb-pause">${b.enabled ? 'Pause' : 'Resume'}</button><button class="btn btn-sm btn-ghost" id="mb-del">Remove</button>` : ''}</div>`}
      </div></div>`;
      if (ro) return;
      $$('[data-imap]', v).forEach((x) => x.onclick = () => { $('#mb-host').value = x.dataset.imap; $('#mb-enc').value = 'ssl'; });
      $('#mb-save').onclick = (e) => {
        const body = { host: $('#mb-host').value.trim(), encryption: $('#mb-enc').value, folder: $('#mb-folder').value.trim() || 'INBOX', username: $('#mb-user').value.trim() };
        $$('[data-k]', v).forEach((c) => { body[c.dataset.k] = c.checked; });
        const pw = $('#mb-pass').value; if (pw) body.password = pw;
        if (!body.host) return fieldError(v, '#mb-host', 'Enter the IMAP server.');
        if (!b && !pw) return fieldError(v, '#mb-pass', 'Enter the password.');
        busy(e.currentTarget, 'Checking…', API('PUT', base() + '/mailbox', body).then(() => { toast('Mailbox connected. Reading starts with new mail.'); S.route(); }).catch((err) => toast(err.message)));
      };
      const ck = $('#mb-check'); if (ck) ck.onclick = (e) => busy(e.currentTarget, 'Checking…', API('POST', base() + '/mailbox/check').then((x) => { toast(`Read ${x.found.read} new: ${x.found.replies} replies, ${x.found.bounces} bounces, ${x.found.complaints || 0} complaints, ${x.found.reports} reports`); S.route(); }).catch((err) => toast(err.message)));
      const pz = $('#mb-pause'); if (pz) pz.onclick = () => API('PUT', base() + '/mailbox', { enabled: !b.enabled }).then(() => S.route()).catch((err) => toast(err.message));
      const dl = $('#mb-del'); if (dl) dl.onclick = () => confirmDialog({ title: 'Remove the mailbox?', desc: SL_BN() + ' stops reading it. What it already found stays.', onConfirm: () => API('DELETE', base() + '/mailbox').then(() => { toast('Mailbox removed'); S.route(); }) });
    }).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };


  // ═══════ #/ses/reports: what SMTP can't report by itself (provider webhook, feedback loops, Gmail) ═══════
  // Where each provider's event webhook lives, and the events to tick (their names, as each provider labels them).
  const RELAY = {
    sendgrid: ['SendGrid’s Event Webhook settings', 'Delivered, Deferred, Bounced, Dropped and Spam Reports', 'To have each request signed, turn on the signed event webhook and paste its verification key below.'],
    mailgun: ['Mailgun’s webhooks for your sending domain', 'Delivered, Permanent failure, Temporary failure and Spam complaints', 'Paste Mailgun’s HTTP webhook signing key below so each request is checked.'],
    postmark: ['your Postmark server’s message stream → Webhooks', 'Delivery, Bounce and Spam complaint', null],
    brevo: ['Brevo’s transactional webhooks', 'Delivered, Deferred, Soft bounce, Hard bounce, Invalid email, Blocked, Error and Marked as spam', null],
    sparkpost: ['SparkPost’s webhooks', 'Delivery, Delay, Bounce, Out-of-band, Policy rejection and Spam complaint', null],
  };
  pages['ses/reports'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    Promise.all([state(), API('GET', base() + '/delivery-reports')]).then(([st, r]) => {
      if (!alive(g)) return;
      const ro = !canManage(), h = r.hook, ses = r.method === 'ses';
      const pick = (h && h.provider) || r.provider_guess || '';
      const relay = RELAY[pick];
      const chip = h ? (h.last_error ? '<span class="chip bounced">Check it</span>' : h.last_event_at ? '<span class="chip delivered">Receiving</span>' : '<span class="chip deferred">Waiting for the first event</span>') : '<span class="chip processing">Off</span>';
      const comp = r.complaints || { total: 0, by: [] };
      const fblTo = r.fbl.address || (r.mailbox ? 'the mailbox you connected under Mailbox' : null);
      const step = (n, title, body) => `<div class="setting-row" style="align-items:flex-start"><div><b>${n}. ${title}</b><span style="display:block;max-width:760px">${body}</span></div></div>`;
      v.innerHTML = head + tabs('reports', st)
        + (ses ? `<div class="callout info" style="margin:0 0 16px">${ic('info', 15)}<div>This workspace sends through Amazon SES, which reports deliveries, bounces and spam complaints by itself. The webhook below is only for sending over SMTP.</div></div>` : '')
        + `<div class="card"><div class="card-head" style="display:flex;justify-content:space-between;align-items:center"><b>Your provider’s delivery events</b>${chip}</div><div class="card-body">
        <p class="faint" style="margin:0 0 14px;font-size:13.5px;max-width:760px">SMTP only tells ${SL_BN()} that your server took the email. Relay providers know what happened next and can send it here: <b>delivered</b> (with the receiving server’s answer), <b>bounced</b>, <b>delayed</b>, <b>dropped</b> and <b>marked as spam</b>. Bounced and complained addresses are suppressed, and journeys and your webhooks hear about it, as with Amazon SES.</p>
        ${r.smtp_host && !r.provider_guess && !h ? `<div class="callout warn" style="margin:0 0 14px">${ic('alert', 15)}<div><span class="mono">${esc(r.smtp_host)}</span> isn’t a relay provider that sends delivery events. Use the mailbox and feedback loops below instead.</div></div>` : ''}
        ${h && h.last_error ? `<div class="callout warn" style="margin:0 0 14px">${ic('alert', 15)}<div>${esc(h.last_error)}</div></div>` : ''}
        ${h ? `<div class="kpis" style="margin-bottom:14px"><div><b>${fmt(h.events)}</b><small>Events applied</small></div><div><b>${esc(ago(h.last_event_at))}</b><small>Last request</small></div><div><b>${h.signed ? 'Signed' : 'Secret URL'}</b><small>Checked by</small></div></div>` : ''}
        ${ro ? '<p class="faint">Owners and admins set up delivery reports.</p>' : `
        <div class="row2"><div class="field"><label>Provider</label><select class="select" id="dr-prov"><option value="">Choose…</option>${(r.providers || []).map((p) => `<option value="${p.key}" ${p.key === pick ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></div>
          <div class="field" id="dr-key-f" style="${relay && relay[2] ? '' : 'display:none'}"><label>Signing key <span class="faint">(optional${h && h.signed ? '; saved, type to replace' : ''})</span></label><input id="dr-key" type="password" autocomplete="off" placeholder="${h && h.signed ? '••••••••' : 'Paste the provider’s key'}"></div></div>
        ${h && h.url ? `<div class="field"><label>Webhook URL <span class="faint">(secret: anyone with it can post events)</span></label><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><span class="copy mono" data-copy="${esc(h.url)}" style="word-break:break-all">${esc(h.url)} ${ic('copy', 12)}</span></div></div>` : ''}
        <div id="dr-how"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" id="dr-save">${h ? 'Save' : 'Turn on and get the URL'}</button>${h ? '<button class="btn btn-sm" id="dr-rotate">New URL</button><button class="btn btn-sm btn-ghost" id="dr-del">Turn off</button>' : ''}</div>`}
      </div></div>
      <div class="card" style="margin-top:16px"><div class="card-head" style="display:flex;justify-content:space-between;align-items:center"><b>Spam complaints from mailbox providers</b><span class="chip ${comp.total ? 'bounced' : 'delivered'}">${fmt(comp.total)} in ${comp.days} days</span></div><div class="card-body">
        <p class="faint" style="margin:0 0 6px;font-size:13.5px;max-width:760px">When someone presses “Report spam”, some providers tell the sender which email it was. They send these reports to an address you give them. Each one is matched to the email it’s about, and the address is suppressed so it never gets mail from you again.</p>
        ${step(1, 'Yahoo and AOL', `Enrol your domain in Yahoo’s Complaint Feedback Loop at <a href="https://senders.yahooinc.com/" target="_blank" rel="noopener">senders.yahooinc.com</a>. It works by your DKIM domain, so it works through any SMTP server. Give it ${r.fbl.address ? `<span class="copy mono" data-copy="${esc(r.fbl.address)}">${esc(r.fbl.address)} ${ic('copy', 12)}</span>` : fblTo ? esc(fblTo) : 'a mailbox you connect under <a href="#/ses/mailbox">Mailbox</a>'} as the address for reports.`)}
        ${step(2, 'Outlook and Hotmail', 'Microsoft’s Junk Mail Reporting Program works by sending IP, so it’s only for a server whose IP address is yours. On a shared relay (SendGrid, Mailgun and the like), the provider receives these and passes them on through the webhook above.')}
        ${step(3, 'Gmail', 'Gmail never says who complained. Every email from ' + SL_BN() + ' carries a <span class="mono">Feedback-ID</span> header, so after you add your domain to <a href="https://postmaster.google.com/" target="_blank" rel="noopener">Google Postmaster Tools</a> you see your Gmail spam rate per campaign. Google asks bulk senders to stay under 0.1% and never reach 0.3%.')}
        ${comp.by.length ? `<div class="table-wrap" style="margin-top:12px"><table class="tbl"><thead><tr><th>Reported by</th><th style="text-align:right">Complaints</th></tr></thead><tbody>${comp.by.map((x) => `<tr style="cursor:default"><td>${esc(x.source)}</td><td style="text-align:right">${fmt(x.count)}</td></tr>`).join('')}</tbody></table></div>` : ''}
      </div></div>
      <div class="card" style="margin-top:16px"><div class="card-head"><b>Bounces that come back by email</b></div><div class="card-body"><p class="faint" style="margin:0;font-size:13.5px;max-width:760px">Some servers accept an email and only later send a bounce notice to your address. ${r.mailbox ? `Your mailbox is ${r.mailbox.enabled && r.mailbox.read_bounces ? 'reading them' : 'connected but not reading bounces'}: ${fmt(r.mailbox.bounces)} bounces and ${fmt(r.mailbox.complaints)} complaints so far.` : 'Connect the mailbox they land in under Mailbox to have them applied.'} <a href="#/ses/mailbox">Mailbox settings</a></p></div></div>`;
      bindCopy && bindCopy(v);
      if (ro) return;
      const how = () => {
        const p = $('#dr-prov', v).value, x = RELAY[p];
        $('#dr-key-f', v).style.display = x && x[2] ? '' : 'none';
        $('#dr-how', v).innerHTML = x ? `<p class="ins-help" style="margin:0 0 12px">${h && h.url ? 'In' : 'After you turn it on, open'} ${esc(x[0])}, add the webhook URL, and choose these events: ${esc(x[1])}.${x[2] ? ' ' + esc(x[2]) : ''}${p !== r.provider_guess && r.smtp_host ? ` <b>Your SMTP server is ${esc(r.smtp_host)}</b>: pick the provider that runs it.` : ''}</p>` : '';
      };
      $('#dr-prov', v).onchange = how;
      how();
      $('#dr-save', v).onclick = (e) => {
        const provider = $('#dr-prov', v).value;
        if (!provider) return fieldError(v, '#dr-prov', 'Choose your provider.');
        const body = { provider }, key = $('#dr-key', v).value.trim();
        if (key) body.signing_key = key;
        busy(e.currentTarget, 'Saving…', API('PUT', base() + '/delivery-reports', body).then(() => { toast(h ? 'Saved' : 'Delivery reports on. Paste the URL at your provider.'); S.route(); }).catch((err) => toast(err.message)));
      };
      const rot = $('#dr-rotate', v);
      if (rot) rot.onclick = () => confirmDialog({ title: 'Make a new webhook URL?', desc: 'The current URL stops working at once. Paste the new one at your provider.', label: 'New URL',
        onConfirm: () => API('POST', base() + '/delivery-reports/rotate').then(() => { toast('New URL made'); S.route(); }).catch((err) => toast(err.message)) });
      const del = $('#dr-del', v);
      if (del) del.onclick = () => confirmDialog({ title: 'Turn off delivery reports?', desc: 'Events your provider sends are refused from now on. What was already applied stays.', label: 'Turn off',
        onConfirm: () => API('DELETE', base() + '/delivery-reports').then(() => { toast('Delivery reports off'); S.route(); }).catch((err) => toast(err.message)) });
    }).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  // ═══════ #/ses/tracking: the workspace's own link-tracking domain ═══════
  pages['ses/tracking'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    Promise.all([state(), API('GET', base() + '/tracking-domain')]).then(([st, r]) => {
      if (!alive(g)) return;
      const t = r.tracking_domain, ro = !canManage(), on = t.status === 'active';
      v.innerHTML = head + tabs('tracking', st) + `<div class="card"><div class="card-head" style="display:flex;justify-content:space-between;align-items:center"><b>Your own tracking domain</b>${!t.host ? '<span class="chip processing">Not set</span>' : on ? '<span class="chip delivered">In use</span>' : '<span class="chip deferred">Waiting</span>'}</div><div class="card-body">
        <p class="faint" style="margin:0 0 14px;font-size:13.5px;max-width:740px">Tracked links and the open pixel use a shared address until you add your own, like <span class="mono">links.yourcompany.com</span>. Links on your own domain look more trustworthy to people and to spam filters. It switches on by itself once it works over HTTPS, and switches back if it ever stops.</p>
        ${t.host ? `<div class="setting-row"><div><b class="mono">${esc(t.host)}</b><span>${on ? `In use since ${esc(ago(t.verified_at))}${st.method === 'ses' ? (t.ses_ready ? ' · ' + SL_BN() + ' tracks your SES emails on it' : ' · SES emails keep SES’s tracking until your SES setup finishes') : ''}` : 'Checked ' + esc(ago(t.last_checked_at)) + ' · we check again every 15 minutes'}</span></div></div>` : ''}
        ${t.last_error ? `<div class="callout warn" style="margin:10px 0">${ic('alert', 15)}<div>${esc(t.last_error)}</div></div>` : ''}
        <div class="field" style="margin-top:12px"><label>1. Add this record at your DNS provider</label><div class="table-wrap"><table class="tbl"><thead><tr><th>Type</th><th>Host / name</th><th>Points to</th></tr></thead><tbody><tr style="cursor:default"><td><span class="tag">CNAME</span></td><td class="mono">${esc(t.host || 'links.yourcompany.com')}</td><td><span class="copy mono" data-copy="${esc(t.target)}">${esc(t.target)} ${ic('copy', 12)}</span></td></tr></tbody></table></div><p class="ins-help">On Cloudflare you can leave the proxy on (orange cloud): Cloudflare then provides the HTTPS certificate. Otherwise the ${SL_BN()} server needs a certificate for it; on a self-hosted install, see INSTALL.md.</p></div>
        ${ro ? '<p class="faint">Owners and admins set the tracking domain.</p>' : `<div class="field"><label>2. Your tracking domain</label><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="td-host" value="${esc(t.host || '')}" placeholder="links.yourcompany.com" style="flex:1;min-width:240px"><button class="btn btn-primary btn-sm" id="td-save">${t.host ? 'Save and check' : 'Add and check'}</button>${t.host ? '<button class="btn btn-sm" id="td-check">Check now</button><button class="btn btn-sm btn-ghost" id="td-del">Remove</button>' : ''}</div></div>`}
      </div></div>`;
      bindCopy && bindCopy(v);
      if (ro) return;
      $('#td-save').onclick = (e) => { const host = $('#td-host').value.trim(); if (!host) return fieldError(v, '#td-host', 'Enter the domain.'); busy(e.currentTarget, 'Checking…', API('PUT', base() + '/tracking-domain', { host }).then((x) => { toast(x.tracking_domain.status === 'active' ? 'Tracking domain in use' : 'Saved. It switches on once it works.'); S.route(); }).catch((err) => toast(err.message))); };
      const ck = $('#td-check'); if (ck) ck.onclick = (e) => busy(e.currentTarget, 'Checking…', API('POST', base() + '/tracking-domain/check').then((x) => { toast(x.tracking_domain.status === 'active' ? 'Working: in use' : 'Not working yet'); S.route(); }).catch((err) => toast(err.message)));
      const dl = $('#td-del'); if (dl) dl.onclick = () => confirmDialog({ title: 'Remove the tracking domain?', desc: 'New emails use the shared tracking address again. Links in emails already sent keep working only while the record stays in place.', onConfirm: () => API('DELETE', base() + '/tracking-domain').then(() => { toast('Tracking domain removed'); S.route(); }) });
    }).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

})();
