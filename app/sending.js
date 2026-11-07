/* MailVesper: real sending pages (Phase 3).
   Replaces the prototype: #/keys (API keys), #/suppressions, and the header "Send test" button.
   Talks to /api/v1/workspaces/{id}/{api-keys,suppressions,emails/test,messages,domains}. Loaded after ses.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, modal, confirmDialog, fieldError, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const canEdit = () => myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}`;
  const API_URL = location.origin + '/api/v1/emails';
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ago = (iso) => { if (!iso) return 'Never'; const t = new Date(String(iso).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(iso) ? '' : 'Z')).getTime(); const s = Math.max(0, Math.floor((Date.now() - t) / 1000)); return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };
  const dateStr = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  const loading = (v, t) => { v.innerHTML = `<div class="card"><div class="card-body faint" style="display:flex;gap:10px;align-items:center"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span>${esc(t)}</div></div>`; };
  const failed = (v, err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc((err && err.message) || 'Something went wrong.')}</div></div>`; };

  // Verified sending domains, for From pickers and code samples.

  // ═══════ Send test (header button) ═══════
  const STATUS = { queued: ['Queued', 'processing'], sending: ['Sending', 'processing'], sent: ['Accepted by SES', 'processing'], delivered: ['Delivered', 'delivered'], delayed: ['Delayed', 'deferred'], bounced: ['Bounced', 'bounced'], complained: ['Marked as spam', 'complained'], rejected: ['Rejected', 'bounced'], failed: ['Failed', 'bounced'], suppressed: ['Suppressed', 'dropped'] };
  const statusPill = (s) => { const [label, tone] = STATUS[s] || [s, 'sent']; return `<span class="st-pill st-${tone}"><i></i>${label}</span>`; };

  async function sendTest() {
    if (!canEdit()) return toast('Viewers can’t send test emails.');
    const [ses, snd] = await Promise.all([API('GET', base() + '/ses').catch(() => ({})), S.senders()]);
    const smtp = snd.method === 'smtp';
    if (!snd.method) return modal({ title: 'Connect a way to send first', desc: 'Test emails go through your own Amazon SES account or SMTP server.', icon: 'ses', size: 'sm', body: '', actions: [{ label: 'Cancel' }, { label: 'Set up sending', kind: 'primary', onClick: () => { location.hash = '#/ses'; } }] });
    if (!S.hasSenders(snd)) return modal({ title: 'Verify a sender first', desc: smtp ? 'Emails must come from a domain or address you’ve verified for your SMTP server.' : 'Emails must come from a domain verified in your SES.', icon: 'domains', size: 'sm', body: '', actions: [{ label: 'Cancel' }, { label: smtp ? 'Verify a sender' : 'Go to Domains', kind: 'primary', onClick: () => { location.hash = smtp ? '#/ses/smtp' : '#/domains'; } }] });
    const sandbox = !smtp && ses.connection && !ses.connection.production_access;
    modal({
      title: 'Send a test email', desc: smtp ? 'It goes through your own SMTP server, exactly like a real send.' : 'It goes through your own Amazon SES, exactly like a real send.', icon: 'send', mount: (root) => S.fromPickBind(root, 'tf'),
      body: `<div class="field"><label>From</label>${S.fromPick('tf', snd, '', 'hello')}</div>
        <div class="field"><label>Send to</label><input id="tf-to" type="email" value="${esc(me().user.email || '')}"></div>
        <div class="field"><label>Subject</label><input id="tf-sub" value="Test email from ${SL_BN()}" maxlength="200"></div>
        ${sandbox ? `<div class="callout warn" style="margin-top:4px">${ic('alert', 15)}<div>Your SES is in the sandbox, so it only sends to addresses verified in SES.</div></div>` : ''}
        <div id="tf-result"></div>`,
      actions: [{ label: 'Close' }, { label: 'Send test', kind: 'primary', onClick: (m) => {
        const local = $('#tf-l', m.root).value.trim(), to = $('#tf-to', m.root).value.trim(), single = $('#tf-d', m.root).value.startsWith('e:');
        if (!single && !/^[A-Za-z0-9._%+-]+$/.test(local)) return fieldError(m.root, '#tf-l', 'Letters, digits and . _ - + only.');
        if (!/^\S+@\S+\.\S+$/.test(to)) return fieldError(m.root, '#tf-to', 'Enter a valid email address.');
        const btn = m.root.querySelector('.m-foot .btn-primary'); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>Sending…';
        API('POST', base() + '/emails/test', { from: S.fromPickValue(m.root, 'tf'), to, subject: $('#tf-sub', m.root).value.trim() || 'Test email from ' + SL_BN() })
          .then((msg) => { btn.disabled = false; btn.textContent = 'Send another'; watch(m.root, msg); })
          .catch((err) => { btn.disabled = false; btn.textContent = 'Send test'; if (err.fields) { const k = Object.keys(err.fields)[0]; return fieldError(m.root, k === 'to' ? '#tf-to' : '#tf-l', err.fields[k]); } $('#tf-result', m.root).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
        return false;
      } }],
    });
  }
  // Follows one message until it reaches a final state (or 30 s).
  function watch(root, msg) {
    const box = $('#tf-result', root); if (!box) return;
    const paint = (x) => { box.innerHTML = `<div class="tf-res"><div><span class="trk">${esc(x.tracking_code)}</span> to ${esc(x.to)}</div><div>${statusPill(x.status)}</div>${x.error ? `<p class="faint" style="font-size:12.5px;margin-top:6px;width:100%">${esc(x.error)}</p>` : ''}</div>`; };
    paint(msg);
    let n = 0;
    const t = setInterval(() => {
      if (!document.body.contains(box) || ++n > 20) return clearInterval(t);
      API('GET', `${base()}/messages/${msg.id}`).then((x) => { paint(x); if (['delivered', 'bounced', 'complained', 'rejected', 'failed', 'suppressed'].includes(x.status)) clearInterval(t); }).catch(() => {});
    }, 1500);
  }
  function bindSendTest(tries) {
    const b = document.getElementById('send-test');
    if (!b || !window.SL_ME) { if ((tries || 0) < 100) setTimeout(() => bindSendTest((tries || 0) + 1), 50); return; }
    b.onclick = sendTest;
  }
  bindSendTest();
  S.demos = Object.assign(S.demos || {}, { test: sendTest });
  S.createKey = createKey; // the command palette's "Create API key"

  // ═══════ #/keys ═══════
  pages.keys = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    if (!canManage()) { v.innerHTML = `<div class="page-head"><div><h1>API keys</h1><p>Send email from your apps with the ${SL_BN()} API.</p></div></div><div class="card"><div class="card-body">Only owners and admins can see and create API keys.</div></div>`; return; }
    loading(v, 'Loading your API keys…');
    Promise.all([API('GET', base() + '/api-keys'), S.senders()]).then(([r, snd]) => {
      if (!alive(g)) return;
      const from = S.firstFrom(snd) || 'hello@yourdomain.com';
      v.innerHTML = `<div class="page-head"><div><h1>API keys</h1><p>Send email from your apps with one HTTPS request.</p></div><button class="btn btn-primary" id="new-key">${ic('plus', 15)}Create API key</button></div>
        <div class="card" style="margin-bottom:16px">${r.keys.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>Key</th><th>Permission</th><th>Last used</th><th>Created</th><th></th></tr></thead><tbody>
          ${r.keys.map((k) => `<tr style="cursor:default"><td class="cell-main">${esc(k.name)}</td><td><span class="trk">${esc(k.prefix)}••••••••</span></td><td><span class="tag">${k.scope === 'full' ? 'Full access' : 'Sending only'}</span></td><td class="faint">${ago(k.last_used_at)}</td><td class="faint">${dateStr(k.created_at)}</td><td style="text-align:right"><button class="btn btn-sm btn-ghost" data-revoke="${esc(k.id)}" data-name="${esc(k.name)}">${ic('trash', 14)}Revoke</button></td></tr>`).join('')}
          </tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No API keys yet</b><p class="faint" style="margin:6px 0 0">Create one to send email from your app.</p></div>`}</div>
        <div id="csites"></div>
        <div class="grid half">
          <div class="card"><div class="card-head"><div><h3>Send your first email</h3><div class="sub">One request. Use a key from above. <a href="/docs/" target="_blank" rel="noopener">Full API reference ↗</a></div></div><div class="seg" id="lang"><button class="on" data-l="curl">cURL</button><button data-l="node">Node</button><button data-l="php">PHP</button></div></div><div class="card-body"><pre class="code" id="snippet"></pre></div></div>
          <div class="card"><div class="card-head"><div><h3>Good to know</h3></div></div><div class="card-body" style="font-size:13px;line-height:1.65">
            <p><b>Many recipients.</b> <span class="mono">to</span> takes up to 50 addresses; each gets its own copy and tracking code. <span class="mono">POST /v1/emails/batch</span> takes up to 100 different emails.</p>
            <p style="margin-top:8px"><b>Personalise.</b> Write <span class="mono">{{ first_name | there }}</span> and pass <span class="mono">"variables": {"first_name": "Amara"}</span>.</p>
            <p style="margin-top:8px"><b>No duplicates.</b> Send an <span class="mono">Idempotency-Key</span> header; a retried request returns the first result instead of sending again.</p>
            <p style="margin-top:8px"><b>Newsletters.</b> Add <span class="mono">"type": "marketing"</span> for one-click unsubscribe headers, and put <span class="mono">{{ unsubscribe_url }}</span> in your footer.</p>
            <p style="margin-top:8px"><b>Status.</b> <span class="mono">GET /v1/emails/{id}</span> returns queued, sent, delivered, bounced…</p></div></div>
        </div>`;
      const SN = {
        curl: `curl -X POST ${API_URL} \\\n  -H "Authorization: Bearer sl_live_…" \\\n  -H "Content-Type: application/json" \\\n  -d '{\n    "from": "Acme <${from}>",\n    "to": "amara@example.com",\n    "subject": "Welcome, {{ first_name | there }}",\n    "html": "<p>Glad you are here!</p>",\n    "variables": { "first_name": "Amara" }\n  }'\n\n# → { "data": [{ "tracking_code": "SL-8F2A-1C9D", "status": "queued" }] }`,
        node: `const res = await fetch("${API_URL}", {\n  method: "POST",\n  headers: {\n    Authorization: \`Bearer \${process.env.MAILVESPER_KEY}\`,\n    "Content-Type": "application/json",\n  },\n  body: JSON.stringify({\n    from: "Acme <${from}>",\n    to: "amara@example.com",\n    subject: "Welcome, {{ first_name | there }}",\n    html: "<p>Glad you are here!</p>",\n    variables: { first_name: "Amara" },\n  }),\n});\nconsole.log(await res.json());`,
        php: `$res = Http::withToken(env('MAILVESPER_KEY'))\n    ->post('${API_URL}', [\n        'from' => 'Acme <${from}>',\n        'to' => 'amara@example.com',\n        'subject' => 'Welcome, {{ first_name | there }}',\n        'html' => '<p>Glad you are here!</p>',\n        'variables' => ['first_name' => 'Amara'],\n    ]);`,
      };
      const show = (l) => { $('#snippet').textContent = SN[l]; $$('#lang button').forEach((b) => b.classList.toggle('on', b.dataset.l === l)); };
      $$('#lang button').forEach((b) => b.onclick = () => show(b.dataset.l)); show('curl');
      $('#new-key').onclick = createKey;
      if (S.connectedSites) S.connectedSites($('#csites', v));
      $$('[data-revoke]', v).forEach((b) => b.onclick = () => confirmDialog({ title: `Revoke “${b.dataset.name}”?`, desc: 'Apps using this key stop being able to send immediately. This can’t be undone.', label: 'Revoke', onConfirm: () => API('DELETE', `${base()}/api-keys/${b.dataset.revoke}`).then(() => { toast('Key revoked'); S.route(); }).catch((err) => toast(err.message)) }));
    }).catch((err) => alive(g) && failed(v, err));
  };
  function createKey() {
    modal({ title: 'Create API key', desc: 'Keys let your apps send email through your SES.', icon: 'keys', size: 'sm',
      body: `<div class="field"><label>Name</label><input id="kn" placeholder="e.g. Production backend" maxlength="80"></div>
        <div class="field"><label>Permission</label><div class="radio-cards" id="ksc"><label class="radio-card on" data-v="send"><span class="dot"></span><div><b>Sending only</b><span>Send email and read its status. Recommended.</span></div></label><label class="radio-card" data-v="full"><span class="dot"></span><div><b>Full access</b><span>Everything the API offers, including future endpoints.</span></div></label></div></div>`,
      mount: (root) => $$('#ksc .radio-card', root).forEach((c) => c.onclick = () => { $$('#ksc .radio-card', root).forEach((o) => o.classList.remove('on')); c.classList.add('on'); }),
      actions: [{ label: 'Cancel' }, { label: 'Create key', kind: 'primary', onClick: (m) => {
        const name = $('#kn', m.root).value.trim(); if (!name) return fieldError(m.root, '#kn', 'Give the key a name, like where it’s used.');
        API('POST', base() + '/api-keys', { name, scope: ($('#ksc .radio-card.on', m.root) || {}).dataset.v || 'send' }).then((r) => { m.close(); showSecret(r.secret); }).catch((err) => fieldError(m.root, '#kn', err.message));
        return false;
      } }] });
  }
  function showSecret(secret) {
    modal({ title: 'Copy your API key now', desc: 'For your security we only show it once. Store it in your app’s environment variables.', icon: 'keys', size: 'sm',
      body: `<div class="cs-cmd" style="padding-left:12px"><code style="white-space:normal;word-break:break-all">${esc(secret)}</code><button class="btn btn-sm" id="cpk">${ic('copy', 13)}Copy</button></div>`,
      mount: (root) => { $('#cpk', root).onclick = () => { navigator.clipboard && navigator.clipboard.writeText(secret); toast('API key copied'); }; },
      actions: [{ label: 'I’ve saved it', kind: 'primary', onClick: () => S.route() }] });
  }

  // ═══════ #/suppressions ═══════
  const REASON = { hard_bounce: ['Hard bounce', 'bounced'], complaint: ['Spam report', 'complained'], unsubscribe: ['Unsubscribed', 'unsub'], manual: ['Added by hand', 'dropped'] };
  pages.suppressions = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    const st = { reason: '', q: '', rows: [], next: null, counts: {} };
    v.innerHTML = `<div class="page-head"><div><h1>Suppressions</h1><p>Addresses ${SL_BN()} never sends to, to protect your sender reputation. Hard bounces, spam reports and unsubscribes are added automatically.</p></div><div style="display:flex;gap:8px"><a class="btn" id="sup-exp" href="/api${base()}/suppressions/export">${ic('download', 15)}Export CSV</a>${canEdit() ? `<button class="btn btn-primary" id="add-sup">${ic('plus', 15)}Add addresses</button>` : ''}</div></div>
      <div class="card"><div class="toolbar"><input class="input" id="sup-q" placeholder="Search an address"><div class="filter-chips" id="sup-f"></div></div>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Address</th><th>Reason</th><th>Details</th><th>Added</th><th></th></tr></thead><tbody id="sup-body"><tr><td colspan="5" class="faint">Loading…</td></tr></tbody></table></div>
      <div id="sup-more" style="padding:10px 16px;display:none"><button class="btn btn-sm">Load more</button></div></div>`;
    const chips = () => {
      const total = Object.values(st.counts).reduce((a, b) => a + b, 0);
      $('#sup-f').innerHTML = [['', 'All', total], ...Object.keys(REASON).map((k) => [k, REASON[k][0] + (k === 'complaint' ? 's' : k === 'hard_bounce' ? 's' : ''), st.counts[k] || 0])].map(([k, l, n]) => `<button class="fchip ${st.reason === k ? 'on' : ''}" data-r="${k}">${l} <b>${fmt(n)}</b></button>`).join('');
      $$('#sup-f .fchip').forEach((b) => b.onclick = () => { st.reason = b.dataset.r; load(false); });
    };
    const row = (s) => `<tr style="cursor:default"><td class="cell-main">${esc(s.email)}</td><td>${statusPillFor(s.reason)}</td><td class="faint" style="font-size:12.5px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(s.detail || '')}">${esc(s.detail || '')}</td><td class="faint">${ago(s.created_at)}</td><td style="text-align:right">${canEdit() && (s.reason !== 'complaint' || canManage()) ? `<button class="btn btn-sm btn-ghost rm" data-id="${s.id}" data-e="${esc(s.email)}" data-r="${s.reason}">Remove</button>` : ''}</td></tr>`;
    const load = (more) => {
      const q = new URLSearchParams(); if (st.reason) q.set('reason', st.reason); if (st.q) q.set('q', st.q); if (more && st.next) q.set('before', st.next);
      return API('GET', `${base()}/suppressions${q.toString() ? '?' + q : ''}`).then((r) => {
        if (!alive(g)) return;
        st.rows = more ? st.rows.concat(r.suppressions) : r.suppressions; st.next = r.next_before; st.counts = r.counts || {};
        chips();
        $('#sup-body').innerHTML = st.rows.length ? st.rows.map(row).join('') : `<tr><td colspan="5" class="faint" style="padding:28px 16px;text-align:center">${st.q || st.reason ? 'No matching addresses.' : 'No suppressed addresses yet. Bounces, spam reports and unsubscribes will appear here automatically.'}</td></tr>`;
        $('#sup-more').style.display = st.next ? '' : 'none';
        $$('.rm', v).forEach((b) => b.onclick = () => confirmDialog({ title: `Remove ${b.dataset.e}?`, desc: b.dataset.r === 'complaint' ? 'This person reported your email as spam. Emailing them again can hurt your reputation and break AWS rules. Only remove if they asked to hear from you again.' : SL_BN() + ' will send to this address again. If it bounces or complains, it’s added back automatically.', label: 'Remove', onConfirm: () => { const tr = b.closest('tr'); if (tr) tr.remove(); API('DELETE', `${base()}/suppressions/${b.dataset.id}`).then(() => toast('Removed from suppressions')).catch((err) => { if (err.status !== 404) toast(err.message); }).then(() => load(false)); } }));
      }).catch((err) => alive(g) && ($('#sup-body').innerHTML = `<tr><td colspan="5">${esc(err.message)}</td></tr>`));
    };
    let t; $('#sup-q').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { st.q = e.target.value.trim(); load(false); }, 250); };
    $('#sup-more button').onclick = (e) => { const b = e.currentTarget; b.disabled = true; load(true).then(() => { b.disabled = false; }); };
    const add = $('#add-sup'); if (add) add.onclick = () => addAddresses(() => load(false));
    load(false);
  };
  const statusPillFor = (reason) => { const [label, tone] = REASON[reason] || [reason, 'sent']; return `<span class="st-pill st-${tone}"><i></i>${label}</span>`; };
  function addAddresses(done) {
    modal({ title: 'Add addresses to suppressions', desc: SL_BN() + ' skips these on every send.', icon: 'suppressions',
      body: `<div class="field"><label>Email addresses</label><textarea id="sup-in" rows="6" placeholder="one@example.com&#10;two@example.com"></textarea><span class="help">One per line, or separated by commas. Or <a href="#" id="sup-file">import a CSV file</a>; we pick out every email address in it.</span><input type="file" id="sup-csv" accept=".csv,.txt,text/csv,text/plain" hidden></div>`,
      mount: (root) => {
        $('#sup-file', root).onclick = (e) => { e.preventDefault(); $('#sup-csv', root).click(); };
        $('#sup-csv', root).onchange = (e) => { const f = e.target.files[0]; if (!f) return; if (f.size > 5e6) return toast('Files up to 5 MB, please.'); f.text().then((txt) => { const found = txt.match(/[^\s,;"'<>]+@[^\s,;"'<>]+\.[A-Za-z]{2,}/g) || []; $('#sup-in', root).value = [...new Set(found.map((x) => x.toLowerCase()))].join('\n'); toast(`${fmt(found.length)} addresses found in the file`); }); };
      },
      actions: [{ label: 'Cancel' }, { label: 'Add', kind: 'primary', onClick: (m) => {
        const emails = $('#sup-in', m.root).value.split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
        if (!emails.length) return fieldError(m.root, '#sup-in', 'Add at least one address.');
        if (emails.length > 10000) return fieldError(m.root, '#sup-in', 'Up to 10,000 addresses at a time.');
        API('POST', base() + '/suppressions', { emails }).then((r) => { m.close(); toast(`${fmt(r.added)} added${r.already ? `, ${fmt(r.already)} already there` : ''}${r.invalid ? `, ${fmt(r.invalid)} not valid` : ''}`); done(); }).catch((err) => fieldError(m.root, '#sup-in', err.message));
        return false;
      } }] });
  }
})();
