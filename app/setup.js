/* MailVesper: #/setup, the "set up sending" checklist new workspaces land on (any step can be skipped and finished
   later): 1. a sending provider (Amazon SES, or any SMTP server), 2. a verified domain, 3. the default sender
   address. Also puts a "finish setting up" card on Overview until sending works. Loaded after smtp.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast, fieldError, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const base = () => `/v1/workspaces/${wsId()}`;
  let gen = 0, seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq;
  const busy = (b, text, p) => { b.disabled = true; const t = b.innerHTML; b.innerHTML = `<span class="spin sm"></span>${text}`; return p.finally(() => { b.disabled = false; b.innerHTML = t; }); };
  const SMTP_PRESETS = [['SendGrid', 'smtp.sendgrid.net', 587, 'tls', 'apikey'], ['Mailgun', 'smtp.mailgun.org', 587, 'tls', ''], ['Brevo', 'smtp-relay.brevo.com', 587, 'tls', ''],
    ['Postmark', 'smtp.postmarkapp.com', 587, 'tls', ''], ['Gmail', 'smtp.gmail.com', 587, 'tls', ''], ['Microsoft 365', 'smtp.office365.com', 587, 'tls', ''], ['Zoho', 'smtp.zoho.com', 465, 'ssl', '']];

  /** Everything the checklist needs, in one go. */
  const load = () => Promise.all([API('GET', base() + '/smtp'), API('GET', base() + '/senders'), API('GET', base() + '/domains').catch(() => ({ domains: [] }))])
    .then(([st, snd, dom]) => {
      const ses = st.method === 'ses';
      const domains = ses ? (dom.domains || []).map((d) => ({ id: d.id, name: d.name, verified: d.status === 'verified', records: d.records || [], ses: true }))
        : (st.senders || []).filter((x) => x.kind === 'domain').map((x) => ({ id: x.id, name: x.value, verified: x.status === 'verified', record: x.record, ses: false }));
      const providerDone = !!st.method && (st.method === 'ses' ? st.ses_connected : !!(st.smtp && st.smtp.working));
      const domainDone = domains.some((d) => d.verified) || (snd.emails || []).length > 0;
      return { st, snd, domains, providerDone, domainDone, senderDone: !!snd.default };
    });
  S.sendingSetup = load;

  pages.setup = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>';
    load().then((x) => alive(g) && draw(v, g, x)).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  /** Delivery reports and seed mailboxes: not needed to send, but they're how you learn what happened to it. */
  function optional(v, g, x) {
    const box = $('#su-opt', v);
    const row = (icon, title, sub, href, label, ok) => `<div class="su-step"><div class="su-head"><span class="su-num ${ok ? 'ok' : ''}">${ok ? ic('check', 14, 2.6) : ic(icon, 14)}</span><div><b>${title}</b><small>${sub}</small></div><a class="btn btn-sm ${ok ? 'btn-ghost' : ''}" href="${href}">${label}</a></div></div>`;
    Promise.all([API('GET', base() + '/delivery-reports').catch(() => null), S.seedMailboxes ? S.seedMailboxes() : Promise.resolve(undefined)]).then(([dr, seeds]) => {
      if (!alive(g) || !box) return;
      const smtp = x.st.method === 'smtp';
      const reportsOn = dr && (dr.hook || (dr.mailbox && dr.mailbox.enabled));
      const okSeeds = Array.isArray(seeds) ? seeds.filter((s) => s.status === 'ok').length : 0;
      box.innerHTML = (smtp ? row('activity', 'Delivery reports', reportsOn ? 'Your provider’s or mailbox’s reports are coming in: deliveries, bounces and spam complaints.' : 'SMTP only says your server took the email. Connect your provider’s webhook or a mailbox to learn about deliveries, bounces and spam complaints.', '#/ses/reports', reportsOn ? 'Open' : 'Set up', reportsOn) : '')
        + row('inbox', 'Seed mailboxes', seeds === undefined ? 'Test accounts at Gmail, Outlook, Yahoo and iCloud that show whether your email lands in the inbox or spam (MailVesper Pro).'
          : okSeeds ? `${okSeeds} connected. Run an inbox test before a big send.` : 'Test accounts at Gmail, Outlook, Yahoo and iCloud. Inbox tests send to them and show whether each copy landed in the inbox or spam.', '#/ses/seeds', okSeeds ? 'Manage' : 'Add', okSeeds > 0);
    });
  }

  function draw(v, g, x) {
    const done = [x.providerDone, x.domainDone, x.senderDone], n = done.filter(Boolean).length;
    const skipped = (S.param('skip') || '').split(',').filter(Boolean);
    // The step to work on: the first one not done and not skipped.
    const open = [0, 1, 2].find((i) => !done[i] && !skipped.includes(String(i))) ?? -1;
    const stepHead = (i, title, sub) => `<div class="su-head"><span class="su-num ${done[i] ? 'ok' : open === i ? 'on' : ''}">${done[i] ? ic('check', 14, 2.6) : i + 1}</span><div><b>${title}</b><small>${sub}</small></div>${!done[i] && open !== i ? `<button class="btn btn-sm btn-ghost" data-open="${i}">${skipped.includes(String(i)) ? 'Do it now' : 'Open'}</button>` : ''}</div>`;
    const skip = (i) => `<button class="btn btn-ghost btn-sm" data-skip="${i}">Skip for now</button>`;
    const ro = !canManage();
    v.innerHTML = `<style>.su{max-width:760px}.su-step{border:1px solid var(--border);border-radius:14px;background:var(--surface);margin-bottom:12px}.su-head{display:flex;gap:12px;align-items:center;padding:16px 18px}.su-head>div{flex:1}.su-head b{display:block;font-size:15px}.su-head small{color:var(--text-2);font-size:13px}
      .su-num{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-weight:700;font-size:13px;background:var(--surface-2);color:var(--text-2);flex:none}.su-num.on{background:var(--accent);color:#fff}.su-num.ok{background:var(--s-delivered-bg);color:var(--s-delivered)}
      .su-body{padding:0 18px 18px 58px}.su-choice{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:12px}.su-choice button{text-align:left;border:1px solid var(--border);background:var(--surface);border-radius:12px;padding:14px;cursor:pointer;color:var(--text)}.su-choice button.on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}.su-choice b{display:block;font-size:14px}.su-choice small{color:var(--text-2);font-size:12.5px}
      .su-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}.su-rec{font-size:12.5px;display:grid;gap:6px;margin:8px 0}@media(max-width:640px){.su-body{padding-left:18px}}</style>
      <div class="su"><div class="page-head"><div><h1>Set up sending</h1><p>${n === 3 ? 'All set: your workspace can send.' : `${n} of 3 done. Skip anything and come back from the sidebar (Get started → Set up sending).`}</p></div>${n === 3 ? '<a class="btn btn-primary" href="#/overview">Go to Overview</a>' : ''}</div>
      ${n === 3 && !ro ? `<div class="su-step"><div class="su-head"><span class="su-num">${ic('check', 14, 2.6)}</span><div><b>Check it reaches inboxes</b><small>Sends a test from ${esc(x.snd.default.email)} through your setup and reads it back the way Gmail does: route, SPF, DKIM, DMARC, blocklists.</small></div><button class="btn btn-sm btn-primary" id="su-dv">Check my setup</button></div><div class="su-body" id="su-dvres"></div></div>` : ''}
      ${ro ? `<div class="callout info" style="margin-bottom:12px">${ic('info', 15)}<div>Owners and admins set up sending. You can see where things stand.</div></div>` : ''}

      <div class="su-step">${stepHead(0, 'Connect how you send', x.providerDone ? (x.st.method === 'ses' ? 'Amazon SES is connected.' : `Sending through ${esc(x.st.smtp.host)}.`) : 'Your own Amazon SES account, or any SMTP server (SendGrid, Mailgun, Brevo, Postmark, Gmail, Microsoft 365…).')}
        ${open === 0 && !ro ? `<div class="su-body"><div class="su-choice"><button data-p="ses" class="${S.param('p') === 'ses' ? 'on' : ''}"><b>Amazon SES</b><small>Your own AWS account, at AWS prices. About two minutes with the guided setup.</small></button><button data-p="smtp" class="${S.param('p') !== 'ses' ? 'on' : ''}"><b>SMTP or another provider</b><small>SendGrid, Mailgun, Brevo, Postmark, Gmail, Microsoft 365, Zoho or your own server.</small></button></div><div id="su-p"></div><div class="su-actions">${skip(0)}</div></div>` : ''}</div>

      <div class="su-step">${stepHead(1, 'Verify your domain', x.domainDone ? `${esc((x.domains.find((d) => d.verified) || {}).name || 'An address is verified')}${x.domains.find((d) => d.verified) ? ' is verified: any address on it can send.' : '.'}` : 'One DNS record proves the domain is yours. Then any address on it can send, like hello@ or billing@.')}
        ${open === 1 && !ro ? `<div class="su-body" id="su-d"></div>` : ''}</div>

      <div class="su-step">${stepHead(2, 'Choose your sender address', x.senderDone ? `${esc(x.snd.default.name ? x.snd.default.name + ' · ' : '')}${esc(x.snd.default.email)} is the default sender.` : 'The From address new campaigns, journeys and forms start with. You can change it on each one.')}
        ${open === 2 && !ro ? `<div class="su-body" id="su-s"></div>` : ''}</div>
      <div class="ins-label" style="margin:22px 0 8px">Optional, recommended</div><div id="su-opt"></div></div>`;
    optional(v, g, x);

    const goSkip = (i) => { const s = new Set(skipped); s.add(String(i)); location.hash = '#/setup?skip=' + [...s].join(','); };
    v.onclick = (e) => { const b = e.target.closest && e.target.closest('[data-skip]'); if (b) goSkip(b.dataset.skip); }; // also the buttons drawn inside a step later
    $$('[data-open]', v).forEach((b) => b.onclick = () => { location.hash = '#/setup?skip=' + skipped.filter((s) => s !== b.dataset.open && +s < +b.dataset.open).concat([0, 1, 2].filter((i) => i < +b.dataset.open && !done[i]).map(String)).join(','); });
    const redraw = () => load().then((y) => alive(g) && draw(v, g, y));
    const dv = $('#su-dv', v); if (dv) dv.onclick = () => { dv.disabled = true; S.runSetupCheck($('#su-dvres', v), x.snd.default.email, () => { dv.disabled = false; }); };
    if (ro) return;
    if (open === 0) provider(v, g, x, redraw);
    if (open === 1) domain(v, g, x, redraw, skip(1));
    if (open === 2) sender(v, g, x, redraw, skip(2));
  }

  // ── 1. Provider ──
  function provider(v, g, x, redraw) {
    const box = $('#su-p', v);
    const show = (p) => {
      $$('[data-p]', v).forEach((b) => b.classList.toggle('on', b.dataset.p === p));
      if (p === 'ses') { box.innerHTML = `<p class="faint" style="font-size:13.5px;margin:0 0 10px">You’ll run one command in AWS CloudShell (or paste keys). ${SL_BN()} sets up events, bounces and complaints in your account.</p><a class="btn btn-primary" href="#/ses/connect" id="su-ses">${ic('ses', 15)}Connect Amazon SES</a>`; $('#su-ses', box).onclick = () => { try { sessionStorage.setItem('sl-setup', '1'); } catch (e) { /* storage off */ } }; return; }
      const c = x.st.smtp || {};
      box.innerHTML = `${c.last_error ? `<div class="callout warn" style="margin-bottom:10px">${ic('alert', 15)}<div>${esc(c.last_error)}</div></div>` : ''}
        <div class="field"><label>Provider</label><div style="display:flex;gap:6px;flex-wrap:wrap">${SMTP_PRESETS.map((q, i) => `<button type="button" class="btn btn-sm" data-pre="${i}">${esc(q[0])}</button>`).join('')}<button type="button" class="btn btn-sm" data-pre="-1">Other</button></div></div>
        <div class="row2"><div class="field"><label>SMTP host</label><input id="su-host" value="${esc(c.host || '')}" placeholder="smtp.example.com" spellcheck="false" autocapitalize="off"></div>
          <div class="row2"><div class="field"><label>Port</label><input id="su-port" type="number" min="1" max="65535" inputmode="numeric" list="su-port-l" value="${esc(String(c.port || 587))}" ><datalist id="su-port-l"><option value="587">STARTTLS</option><option value="465">SSL/TLS</option><option value="2525"></option><option value="25"></option></datalist></div>
          <div class="field"><label>Security</label><select id="su-enc" class="select"><option value="tls" ${(c.encryption || 'tls') === 'tls' ? 'selected' : ''}>STARTTLS</option><option value="ssl" ${c.encryption === 'ssl' ? 'selected' : ''}>SSL/TLS</option></select></div></div></div>
        <div class="row2"><div class="field"><label>Username</label><input id="su-user" value="${esc(c.username || '')}" autocomplete="off" spellcheck="false"></div>
          <div class="field"><label>Password or API key</label><input id="su-pass" type="password" autocomplete="new-password" placeholder="${c.host ? 'Saved; type to replace' : ''}"></div></div>
        <button class="btn btn-primary" id="su-save">${ic('check', 15)}Save and test</button>`;
      $$('[data-pre]', box).forEach((b) => b.onclick = () => {
        const q = SMTP_PRESETS[+b.dataset.pre]; if (!q) { $('#su-host', box).value = ''; $('#su-host', box).focus(); return; }
        $('#su-host', box).value = q[1]; $('#su-port', box).value = String(q[2]); $('#su-enc', box).value = q[3]; if (q[4]) $('#su-user', box).value = q[4];
      });
      $('#su-port', box).onchange = (e) => { if (e.target.value === '465') $('#su-enc', box).value = 'ssl'; else if (['587', '2525', '25'].includes(e.target.value)) $('#su-enc', box).value = 'tls'; };
      $('#su-save', box).onclick = (e) => {
        const body = { host: $('#su-host', box).value.trim(), port: +$('#su-port', box).value, encryption: $('#su-enc', box).value, username: $('#su-user', box).value.trim() || null };
        if ($('#su-pass', box).value) body.password = $('#su-pass', box).value;
        if (!/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(body.host)) return fieldError(box, '#su-host', 'Enter the server name, like smtp.sendgrid.net.');
        if (!(body.port >= 1 && body.port <= 65535)) return fieldError(box, '#su-port', 'Enter the port your provider gives (1–65535).');
        busy(e.currentTarget, 'Testing…', API('PUT', base() + '/smtp', body).then((r) => {
          if (r.test && r.test.ok) { toast('Connected: ' + r.test.message); redraw(); } else { toast('Saved, but the test failed'); x.st = r; show('smtp'); }
        }).catch((err) => toast(err.message)));
      };
    };
    $$('[data-p]', v).forEach((b) => b.onclick = () => show(b.dataset.p));
    show(S.param('p') === 'ses' ? 'ses' : 'smtp');
  }

  // ── 2. Domain ──
  function domain(v, g, x, redraw, skipBtn) {
    const box = $('#su-d', v), d = x.domains.find((y) => !y.verified);
    const recs = (y) => y.ses ? y.records.filter((r) => r.required || r.key === 'dmarc') : [{ type: 'TXT', host: y.record.host, value: y.record.value }];
    const table = (y) => `<p class="faint" style="font-size:13px;margin:0 0 6px">Add ${recs(y).length > 1 ? 'these records' : 'this record'} at your DNS provider${y.ses ? '' : ''}, then check:</p>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Type</th><th>Host / name</th><th>Value</th></tr></thead><tbody>${recs(y).map((r) => `<tr style="cursor:default"><td><span class="tag">${esc(r.type)}</span></td><td><span class="copy mono" data-copy="${esc(r.host)}" style="font-size:12px">${esc(r.host)} ${ic('copy', 12)}</span></td><td><span class="copy mono" data-copy="${esc(r.value)}" style="font-size:12px;word-break:break-all">${esc(r.value)} ${ic('copy', 12)}</span></td></tr>`).join('')}</tbody></table></div>
      <div class="su-actions"><button class="btn btn-primary btn-sm" id="su-check">Check now</button>${y.ses ? `<a class="btn btn-sm" href="#/domains/view?id=${esc(y.id)}">Domain details</a>` : '<a class="btn btn-sm" href="#/domains">All domains</a>'}${skipBtn}</div>
      <p class="faint" style="font-size:12px;margin-top:6px">DNS changes can take a few minutes. We keep checking${y.ses ? ' every 5 minutes' : ' when you press Check'}; on Cloudflare or Route 53, the domain page can add them for you.</p>`;
    if (d) {
      box.innerHTML = `<div class="field"><label>${esc(d.name)}</label></div>${table(d)}`;
      bindCopy(box);
      $('#su-check', box).onclick = (e) => busy(e.currentTarget, 'Checking…', API('POST', d.ses ? `${base()}/domains/${d.id}/check` : `${base()}/smtp/senders/${d.id}/check`).then((r) => {
        const ok = d.ses ? r.domain.status === 'verified' : r.sender.status === 'verified';
        toast(ok ? d.name + ' is verified' : 'Not there yet. DNS can take a few minutes.'); if (ok) redraw();
      }).catch((err) => toast(err.message)));
      return;
    }
    box.innerHTML = `<div class="field"><label>Your domain</label><div style="display:flex;gap:8px;flex-wrap:wrap"><input id="su-dom" placeholder="yourcompany.com" style="flex:1;min-width:220px" spellcheck="false" autocapitalize="off"><button class="btn btn-primary" id="su-add">Add domain</button></div>
      <span class="help">${x.st.method === 'ses' ? 'Created in your Amazon SES account. A subdomain like mail.yourcompany.com keeps marketing reputation apart from your team’s email.' : 'Verified by one TXT record. Works for any SMTP provider, and before you’ve connected one.'}</span></div><div class="su-actions">${skipBtn}</div>`;
    $('#su-add', box).onclick = (e) => {
      const name = $('#su-dom', box).value.trim();
      if (!name) return fieldError(box, '#su-dom', 'Enter your domain.');
      busy(e.currentTarget, 'Adding…', API('POST', x.st.method === 'ses' ? base() + '/domains' : base() + '/smtp/senders', x.st.method === 'ses' ? { name } : { domain: name })
        .then(() => { toast('Added. Now publish the DNS record.'); redraw(); }).catch((err) => toast(err.message)));
    };
  }

  // ── 3. Sender address ──
  function sender(v, g, x, redraw, skipBtn) {
    const box = $('#su-s', v);
    const domains = x.snd.domains || [], emails = x.snd.emails || [];
    if (!x.providerDone) {
      box.innerHTML = `<p class="faint" style="font-size:13.5px;margin:0">Connect a sending provider first (step 1); then pick the address here.</p><div class="su-actions">${skipBtn}</div>`;
      return;
    }
    if (!domains.length && !emails.length) {
      box.innerHTML = `<p class="faint" style="font-size:13.5px;margin:0 0 8px">Verify a domain first (step 2)${x.st.method === 'smtp' ? ', or verify a single address: we email it a code through your SMTP server.' : '.'}</p>
        ${x.st.method === 'smtp' ? `<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="su-one" type="email" placeholder="you@yourcompany.com" style="flex:1;min-width:220px"><button class="btn" id="su-onebtn">Send a code</button></div><div id="su-code"></div>` : ''}<div class="su-actions">${skipBtn}</div>`;
      const ob = $('#su-onebtn', box);
      if (ob) ob.onclick = (e) => busy(e.currentTarget, 'Sending…', API('POST', base() + '/smtp/senders', { email: $('#su-one', box).value.trim() }).then((r) => {
        $('#su-code', box).innerHTML = `<div style="display:flex;gap:8px;margin-top:8px"><input id="su-c" inputmode="numeric" maxlength="6" placeholder="123456" style="max-width:140px"><button class="btn btn-primary" id="su-cbtn">Confirm</button></div>`;
        $('#su-cbtn', box).onclick = (ev) => busy(ev.currentTarget, '…', API('POST', `${base()}/smtp/senders/${r.sender.id}/confirm`, { code: $('#su-c', box).value }).then(() => API('PUT', base() + '/senders/default', { email: r.sender.value })).then(() => { toast('Verified and set as your sender'); redraw(); }).catch((err) => toast(err.message)));
      }).catch((err) => toast(err.message)));
      return;
    }
    const wsName = ((me().workspaces || []).find((w) => w.id === wsId()) || {}).name || '';
    box.innerHTML = `<div class="row2"><div class="field"><label>Sender name</label><input id="su-name" maxlength="100" value="${esc(wsName)}" placeholder="Your company"></div>
      <div class="field"><label>Address</label>${S.fromPick('su-from', x.snd, '', 'hello')}</div></div>
      <p class="faint" style="font-size:12.5px;margin:0">${domains.length ? 'Any address on a verified domain works. Replies go here unless you set a reply-to.' : ''}${x.st.method === 'smtp' && S.smtpProvider(x.st.smtp.host) ? ' ' + esc(S.smtpProvider(x.st.smtp.host).note) : ''}</p>
      <div class="su-actions"><button class="btn btn-primary" id="su-set">Use this sender</button>${skipBtn}</div>`;
    S.fromPickBind(box, 'su-from');
    $('#su-set', box).onclick = (e) => busy(e.currentTarget, 'Saving…', API('PUT', base() + '/senders/default', { email: S.fromPickValue(box, 'su-from'), name: $('#su-name', box).value.trim() || null })
      .then(() => { toast('Sender saved'); redraw(); }).catch((err) => toast(err.message)));
  }

  // ── Overview: a reminder until sending works ──
  const overview = pages.overview;
  pages.overview = (v) => {
    overview(v);
    if (!canManage()) return;
    const at = S.routeSeq;
    load().then((x) => {
      const n = [x.providerDone, x.domainDone, x.senderDone].filter(Boolean).length;
      if (n === 3 || at !== S.routeSeq || !document.body.contains(v)) return;
      const card = document.createElement('div');
      card.className = 'callout info';
      card.style.margin = '0 0 16px';
      card.innerHTML = `${ic('info', 15)}<div style="flex:1"><b>Finish setting up sending</b> · ${n} of 3 done. ${!x.providerDone ? 'Connect Amazon SES or an SMTP server.' : !x.domainDone ? 'Verify the domain you send from.' : 'Choose your sender address.'}</div><a class="btn btn-sm btn-primary" href="#/setup">Continue</a>`;
      v.prepend(card);
    }).catch(() => {});
  };
})();
