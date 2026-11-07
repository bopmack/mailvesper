/* MailVesper: real Amazon SES connection and sending domains (Phase 2).
   Replaces the prototype pages: #/ses, #/ses/connect, #/domains, #/domains/new, #/domains/view?id=.
   Talks to /api/v1/workspaces/{id}/ses*, /domains* and /api/v1/ses/catalog. Loaded after flows.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, confirmDialog, fieldError, menu, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const base = () => `/v1/workspaces/${wsId()}`;
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { 'domains/view': 'Domain' });

  // A page renders async; ignore results that arrive after the user navigated away.
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ago = (iso) => { if (!iso) return 'never'; const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000)); return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };
  const loading = (v, text) => { v.innerHTML = `<div class="card"><div class="card-body faint" style="display:flex;gap:10px;align-items:center"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span>${esc(text)}</div></div>`; };
  const failed = (v, err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc((err && err.message) || 'Something went wrong.')} <button class="btn btn-sm" id="rl" style="margin-left:8px">Try again</button></div></div>`; $('#rl', v).onclick = () => S.route(); };
  let catalog = null;
  const getCatalog = () => (catalog ? Promise.resolve(catalog) : API('GET', '/v1/ses/catalog').then((c) => (catalog = c)));

  const STEP_INFO = {
    config_set: ['Create configuration set “sendline-events”', 'Every email ' + SL_BN() + ' sends is tracked through it'],
    topic: ['Create SNS topic “sendline-events”', 'Where SES publishes your email events'],
    subscribe: ['Connect the topic to ' + SL_BN(), 'AWS confirms the subscription with our webhook'],
    events: ['Publish all 10 event types', 'Send, delivery, delay, bounce, complaint, reject, open, click, rendering failure, subscription'],
    domains: ['Import your domains', 'Domains already set up in SES'],
    reputation: ['Read bounce and complaint rates', 'From CloudWatch, the numbers AWS enforces'],
  };
  const stepRow = (key, st) => {
    const s = (st && st.status) || 'pending';
    const cls = s === 'done' ? 'done' : s === 'waiting' ? 'run' : s === 'failed' || s === 'warn' ? 'warn' : '';
    const mark = s === 'done' ? ic('check', 12, 3) : s === 'failed' || s === 'warn' ? '!' : '';
    const right = s === 'failed' ? '<span class="pill-warn">Needs permission</span>' : s === 'warn' ? '<span class="faint">Optional</span>' : s === 'waiting' ? '<span class="faint">Waiting for AWS…</span>' : '';
    return `<div class="ck ${cls}"><span class="st">${mark}</span><div style="min-width:0"><b style="font-weight:600">${STEP_INFO[key][0]}</b><small>${esc((st && st.detail) || STEP_INFO[key][1])}</small></div><span class="r">${right}</span></div>`;
  };
  const statusPill = (c) => c.status === 'active' ? `<span class="pill-ok">${ic('check', 13, 2.5)} Connected</span>` : c.status === 'error' ? '<span class="pill-warn">Needs attention</span>' : '<span class="pill-warn">Connecting</span>';
  const domainPill = (d) => d.status === 'verified' ? `<span class="pill-ok">${ic('check', 13, 2.5)} Verified</span>` : d.status === 'failed' ? '<span class="pill-warn" style="color:var(--s-bounced);background:var(--s-bounced-bg)">Failed</span>' : '<span class="pill-warn">Waiting for DNS</span>';

  // ═══════ #/ses/connect — connect with access keys ═══════
  const keyGuide = (cat) => {
    const policy = JSON.stringify(cat.policy, null, 2);
    return `<ol class="key-steps">
      <li><b>Create a policy.</b> In AWS, open <a href="https://console.aws.amazon.com/iam/home#/policies/create" target="_blank" rel="noopener">IAM → Policies → Create policy</a>, choose <b>JSON</b>, and paste this. Name it <span class="mono">MailVesperPolicy</span>.
        <div class="pol-wrap"><button class="btn btn-sm pol-copy" type="button" id="polcopy">${ic('copy', 13)}Copy policy</button><pre class="code" id="pol">${esc(policy)}</pre></div>
        <div class="faint" style="font-size:12.5px;margin-top:6px">It lets ${SL_BN()} send email, set up tracking in SES, and read your bounce and complaint rates. The last block (Route 53) is optional: keep it if your DNS is on Route 53 and you want us to add records for you.</div></li>
      <li><b>Create a user.</b> Open <a href="https://console.aws.amazon.com/iam/home#/users/create" target="_blank" rel="noopener">IAM → Users → Create user</a>, name it <span class="mono">mailvesper</span>, choose <b>Attach policies directly</b> and tick <span class="mono">MailVesperPolicy</span>.</li>
      <li><b>Create an access key.</b> Open the new user → <b>Security credentials</b> → <b>Create access key</b> → <b>Application running outside AWS</b>. Copy both values; AWS shows the secret only once.</li></ol>`;
  };

  pages['ses/connect'] = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    loading(v, 'Loading…');
    Promise.all([getCatalog(), API('GET', base() + '/ses')]).then(([cat, r]) => {
      if (!alive(g)) return;
      if (!canManage()) { v.innerHTML = `<div class="flow-top"><a class="back" href="#/ses">← Amazon SES</a></div><div class="card"><div class="card-body">Only owners and admins can connect Amazon SES. Ask one of them to do it.</div></div>`; return; }
      const ctx = { cat, conn: r.connection, manual: false, handoff: null, region: 'us-east-1', timers: [] };
      const clear = () => { ctx.timers.forEach(clearInterval); ctx.timers = []; };
      S.flowPage(v, {
        back: ['#/ses', 'Amazon SES'], title: 'Connect Amazon SES', sub: 'Two clicks and one paste. Email goes through your own AWS account, at AWS’s price.',
        ctx, start: ctx.conn ? (S.param('step') === 'live' ? 3 : 1) : 0, free: !!ctx.conn,
        railFoot: `${ic('shield', 13)} ${SL_BN()} gets its own IAM user that can only use SES. Delete that user in AWS at any time to cut us off.`,
        steps: [
          { label: 'Connect AWS', railSub: (x) => (x.conn ? `Account ${x.conn.aws_account_id}` : 'One command in AWS'), status: (x) => (x.conn ? 'done' : ''),
            title: (ctx.conn ? 'Your AWS account is connected' : 'Connect your AWS account'), desc: ctx.conn ? '' : 'No keys to copy. AWS CloudShell is a terminal inside the AWS console, already signed in to your account.',
            render: (x) => x.conn && !x.manual ? `<div class="callout ok" style="margin-top:0">${ic('check', 15, 2.5)}<div>Connected to account <b>${esc(x.conn.aws_account_id)}</b> in ${esc(x.conn.region_name)}. <a href="#" id="reconnect">Connect a different account</a></div></div>`
              : x.manual ? manualView(x) : `<div class="cs-steps">
                <div class="cs-step"><span class="cs-n">1</span><div><b>Open AWS CloudShell</b><small>Sign in to AWS if it asks. CloudShell opens at the bottom of the page and takes a few seconds the first time.</small><a class="btn btn-primary" id="opencs" target="_blank" rel="noopener" href="https://console.aws.amazon.com/cloudshell/home">${ic('ses', 15)}Open AWS CloudShell ${ic('link', 13)}</a></div></div>
                <div class="cs-step"><span class="cs-n">2</span><div><b>Paste this and press Enter</b><small>It creates a ${SL_BN()} user with SES-only permissions and sends its key to us securely.</small>
                  <div class="cs-cmd"><code id="cmd">${x.handoff ? esc(x.handoff.command) : 'Preparing your command…'}</code><button class="btn btn-sm" id="cpcmd" ${x.handoff ? '' : 'disabled'}>${ic('copy', 13)}Copy</button></div></div></div>
                <div class="cs-step"><span class="cs-n">3</span><div><b>That’s it</b><div class="cs-wait" id="cswait"><span class="spin"></span><span>Waiting for the command to finish… this page moves on by itself.</span></div></div></div>
              </div>
              <details class="cs-more"><summary>What does the command do?</summary><p>It runs in your AWS account with your own permissions. It finds the region where you already use SES, creates an IAM user named <span class="mono">sendline-…</span> with <a href="#" id="seepol">this policy</a>, creates an access key for it and posts that key to ${SL_BN()} over HTTPS. The command works once and expires in 30 minutes. <a href="${x.handoff ? esc(x.handoff.script_url) : '#'}" target="_blank" rel="noopener">Read the script</a>.</p></details>
              <p class="faint" style="font-size:12.5px;margin-top:14px">Can’t create IAM users, or prefer to do it by hand? <a href="#" id="tomanual">Paste access keys instead</a></p>`,
            mount: (v2, x, f) => {
              clear();
              const pn = $('#pnext', v2);
              const rc = $('#reconnect', v2); if (rc) rc.onclick = (e) => { e.preventDefault(); x.conn = null; x.handoff = null; f.render(); };
              const tm = $('#tomanual', v2); if (tm) tm.onclick = (e) => { e.preventDefault(); x.manual = true; f.render(); };
              const sp = $('#seepol', v2); if (sp) sp.onclick = (e) => { e.preventDefault(); x.manual = true; f.render(); };
              if (x.manual) return bindManual(v2, x, f);
              if (x.conn) { pn.textContent = 'Continue'; return; }
              pn.closest('.panel-foot').style.display = 'none'; // nothing to click: the page moves on by itself
              const startPoll = () => {
                $('#cmd', v2).textContent = x.handoff.command; $('#cpcmd', v2).disabled = false;
                $('#cpcmd', v2).onclick = () => { navigator.clipboard && navigator.clipboard.writeText(x.handoff.command); const b = $('#cpcmd', v2); b.innerHTML = `${ic('check', 13, 2.5)}Copied`; setTimeout(() => { if (document.body.contains(b)) b.innerHTML = `${ic('copy', 13)}Copy`; }, 1800); };
                ctx.timers.push(setInterval(() => {
                  if (!document.body.contains(v2)) return clear();
                  API('GET', `${base()}/ses/handoff/${x.handoff.id}`).then((s) => {
                    const w = $('#cswait', v2); if (!w) return;
                    if (s.status === 'connecting') w.innerHTML = '<span class="spin"></span><span>Your key arrived. Setting up event tracking in your account…</span>';
                    if (s.status === 'failed') w.innerHTML = `<span class="cs-bad">${ic('alert', 14)}</span><span>${esc(s.message || 'Something went wrong. Run the command again.')}</span>`;
                    if (s.status === 'expired') { clear(); x.handoff = null; f.render(); }
                    if (s.status === 'connected') { clear(); API('GET', base() + '/ses').then((res) => { x.conn = res.connection; toast('AWS account connected'); f.next(); }); }
                  }).catch(() => {});
                }, 2500));
              };
              if (x.handoff) startPoll();
              else API('POST', base() + '/ses/handoff').then((h) => { x.handoff = h; if (document.body.contains(v2)) startPoll(); }).catch((err) => { $('#cmd', v2).textContent = err.message; });
            } },
          { label: 'Automatic setup', railSub: (x) => (!x.conn ? 'Event tracking' : x.conn.status === 'error' ? 'Needs attention' : x.conn.events_receiving ? 'Tracking on' : 'Confirming…'), status: (x) => (!x.conn ? '' : x.conn.status === 'error' ? 'warn' : x.conn.events_receiving ? 'done' : ''),
            title: 'Setting up tracking in your SES', desc: 'Done for you. Nothing changes for email you already send elsewhere.',
            render: (x) => !x.conn ? '<p class="faint">Connect your AWS account first.</p>' : `<div class="checklist">${Object.keys(STEP_INFO).map((k) => stepRow(k, x.conn.steps[k])).join('')}</div>
              ${x.conn.status === 'error' ? `<div class="callout warn">${ic('alert', 15)}<div><b>Some steps need more permissions.</b> ${esc(x.conn.last_error || '')}<div style="margin-top:8px"><button class="btn btn-sm" id="retry">${ic('refresh', 13)}Try again</button></div></div></div>` : ''}`,
            mount: (v2, x, f) => {
              clear();
              const rt = $('#retry', v2);
              if (rt) rt.onclick = () => { rt.disabled = true; rt.innerHTML = '<span class="spin"></span>Running setup…'; API('POST', base() + '/ses/retry').then((res) => { x.conn = res.connection; f.render(); }).catch((err) => { rt.disabled = false; rt.textContent = 'Try again'; toast(err.message); }); };
              if (x.conn && x.conn.status === 'active' && !x.conn.events_receiving) {
                let n = 0;
                ctx.timers.push(setInterval(() => {
                  if (!document.body.contains(v2) || ++n > 40) return clear();
                  API('GET', base() + '/ses').then((res) => { if (res.connection && res.connection.events_receiving) { clear(); x.conn = res.connection; f.render(); } }).catch(() => {});
                }, 3000));
              }
            },
            next: 'Continue' },
          { label: 'Your domain', railSub: (x) => x.domain ? x.domain.name : 'Send from your own name', status: (x) => (x.domain && x.domain.status === 'verified' ? 'done' : ''),
            title: 'Which domain will you send from?', desc: 'Mailbox providers trust email signed by your own domain. We set it up in SES for you.',
            render: (x) => `<div id="dbox"><p class="faint">Loading…</p></div>`,
            mount: (v2, x, f) => domainStep(v2, x, f),
            next: 'Continue' },
          { label: 'Go live', railSub: (x) => (x.conn && x.conn.production_access ? 'Production access' : x.conn && x.conn.review_status === 'PENDING' ? 'Waiting for AWS' : 'Optional'), status: (x) => (x.conn && x.conn.production_access ? 'done' : ''),
            title: 'Send to anyone', desc: 'New SES accounts start in a sandbox: 200 emails a day, only to addresses you’ve verified. One request to AWS lifts that.',
            render: (x) => x.conn && x.conn.production_access
              ? `<div class="callout ok" style="margin-top:0">${ic('check', 15, 2.5)}<div><b>You’re live.</b> Up to ${fmt(x.conn.quota.max_24h)} emails a day at ${fmt(x.conn.quota.max_rate)} per second.</div></div>`
              : x.conn && x.conn.review_status === 'PENDING'
                ? `<div class="callout info" style="margin-top:0">${ic('info', 15)}<div><b>Request sent. AWS usually answers within 24 hours.</b> We check every hour.</div></div>`
                : sandboxForm(x),
            mount: (v2, x, f) => { bindSandbox(v2, x, f); if (!(x.conn && (x.conn.production_access || x.conn.review_status === 'PENDING'))) $('#pnext', v2).textContent = 'Skip for now'; },
            next: 'Finish' },
        ],
        onFinish: () => { clear(); let back = false; try { back = sessionStorage.getItem('sl-setup') === '1'; sessionStorage.removeItem('sl-setup'); } catch (e) { /* storage off */ } location.hash = back ? '#/setup' : '#/ses'; },
      });
    }).catch((err) => alive(g) && failed(v, err));
  };

  // Manual fallback: create keys by hand and paste them.
  const manualView = (x) => `${keyGuide(x.cat)}
    <div class="row2" style="margin-top:18px"><div class="field"><label>Access key ID</label><input id="akid" class="mono" autocomplete="off" spellcheck="false" placeholder="AKIA…" maxlength="20"></div><div class="field"><label>Secret access key</label><input id="asec" class="mono" type="password" autocomplete="off" spellcheck="false" placeholder="40 characters" maxlength="40"></div></div>
    <div class="field"><label>Region you send from</label><select id="areg" class="select">${x.cat.regions.map((rg) => `<option value="${rg.id}" ${rg.id === x.region ? 'selected' : ''}>${esc(rg.name)} · ${rg.id}</option>`).join('')}</select></div>
    <div id="cerr"></div><p class="faint" style="font-size:12.5px;margin-top:10px"><a href="#" id="tocs">← Use the one-command setup instead</a></p>`;
  function bindManual(v2, x, f) {
    $('#polcopy', v2).onclick = () => { navigator.clipboard && navigator.clipboard.writeText($('#pol', v2).textContent); toast('Policy copied'); };
    $('#tocs', v2).onclick = (e) => { e.preventDefault(); x.manual = false; f.render(); };
    const btn = $('#pnext', v2); btn.textContent = 'Connect';
    btn.onclick = () => {
      const key = $('#akid', v2).value.trim(), secret = $('#asec', v2).value.trim(), region = $('#areg', v2).value;
      x.region = region;
      if (!/^AKIA[A-Z0-9]{16}$/.test(key)) return fieldError(v2, '#akid', key.startsWith('ASIA') ? 'These are temporary keys. Use an IAM user’s keys (AKIA…).' : 'An access key ID is 20 characters and starts with AKIA.');
      if (secret.length !== 40) return fieldError(v2, '#asec', 'A secret access key is 40 characters.');
      btn.disabled = true; btn.innerHTML = '<span class="spin"></span>Checking with AWS…'; $('#cerr', v2).innerHTML = '';
      API('POST', base() + '/ses/connect', { access_key_id: key, secret_access_key: secret, region })
        .then((res) => { x.conn = res.connection; x.manual = false; toast('AWS account connected'); f.next(); })
        .catch((err) => {
          btn.disabled = false; btn.textContent = 'Connect';
          if (err.fields && err.fields.access_key_id) return fieldError(v2, '#akid', err.fields.access_key_id);
          if (err.fields && err.fields.secret_access_key) return fieldError(v2, '#asec', err.fields.secret_access_key);
          $('#cerr', v2).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`;
        });
    };
  }

  // Step 3: add (or pick) the sending domain right here. Route 53 records are added automatically.
  function domainStep(v2, x, f) {
    const box = $('#dbox', v2); if (!box) return;
    API('GET', base() + '/domains').then((res) => {
      if (!document.body.contains(box)) return;
      const list = res.domains;
      if (!x.domain && list.length === 1) x.domain = list[0];
      const recs = x.domain ? (x.domain.records || []) : [];
      const pending = x.domain && x.domain.status !== 'verified';
      box.innerHTML = (list.length ? `<div class="dom-pick">${list.map((d) => `<button class="dom-chip ${x.domain && x.domain.id === d.id ? 'on' : ''}" data-id="${d.id}">${esc(d.name)} ${domainPill(d)}</button>`).join('')}</div>` : '')
        + `<form id="addd" class="dom-add" novalidate><input id="dn" placeholder="${list.length ? 'Add another, e.g. mail.yourcompany.com' : 'mail.yourcompany.com'}" autocomplete="off" spellcheck="false" autocapitalize="off"><button class="btn ${list.length ? '' : 'btn-primary'}" id="dgo">${ic('plus', 14)}Add domain</button></form>
          ${list.length ? '' : '<p class="faint" style="font-size:12.5px;margin-top:6px">A subdomain like mail.yourcompany.com keeps marketing reputation separate from your company email.</p>'}
          ${x.domain ? (x.domain.status === 'verified' ? `<div class="callout ok">${ic('check', 15, 2.5)}<div><b>${esc(x.domain.name)} is verified.</b> You can send from any address at this domain.</div></div>`
            : x.autoDns ? `<div class="callout ok">${ic('check', 15, 2.5)}<div><b>We added ${x.autoDns} DNS records to Route 53 for ${esc(x.domain.name)}.</b> AWS usually verifies within minutes, sometimes up to an hour. You can continue; we’ll keep checking.</div></div>`
            : `<div class="callout info">${ic('info', 15)}<div><b>Add these ${recs.filter((r) => r.required).length} records where you manage DNS for ${esc(x.domain.name)}</b> (GoDaddy, Namecheap, Cloudflare…). They turn green as soon as we see them. You can continue now and do this later.</div></div>
              <table class="tbl" style="border:1px solid var(--border);border-radius:10px;margin-top:10px"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead><tbody>${recs.filter((r) => r.required).map((r) => `<tr style="cursor:default"><td><span class="tag">${r.type}</span></td><td><span class="copy" data-copy="${esc(r.host)}">${esc(r.host)} ${ic('copy', 12)}</span></td><td><span class="copy" data-copy="${esc(r.value)}" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-flex">${esc(r.value)} ${ic('copy', 12)}</span></td><td>${r.live === 'found' ? `<span style="color:var(--s-delivered)">${ic('check', 14, 2.5)}</span>` : ''}</td></tr>`).join('')}</tbody></table>
              <p style="margin-top:8px;font-size:12.5px"><a href="#/domains/view?id=${x.domain.id}">Open full DNS details</a></p>`) : ''}`;
      bindCopy(box);
      $$('.dom-chip', box).forEach((b) => b.onclick = () => { x.domain = list.find((d) => d.id === b.dataset.id); x.autoDns = 0; f.render(); });
      $('#addd', box).onsubmit = (e) => {
        e.preventDefault();
        const name = $('#dn', box).value.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/\.$/, '');
        if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(name)) return toast('Enter a domain like mail.yourcompany.com');
        const b = $('#dgo', box); b.disabled = true; b.innerHTML = '<span class="spin"></span>Setting up…';
        API('POST', base() + '/domains', { name }).then((r2) => { x.domain = r2.domain; x.autoDns = r2.auto_dns; f.render(); })
          .catch((err) => { b.disabled = false; b.innerHTML = `${ic('plus', 14)}Add domain`; toast(err.message); });
      };
      if (pending) {
        ctx_poll(v2, () => API('POST', `${base()}/domains/${x.domain.id}/check`).then((r3) => { if (r3.domain.status === 'verified' || JSON.stringify(r3.domain.records) !== JSON.stringify(x.domain.records)) { x.domain = r3.domain; f.render(); } }));
      }
      if (!x.domain) $('#pnext', v2).textContent = 'Skip for now';
    }).catch((err) => { box.innerHTML = `<p class="faint">${esc(err.message)}</p>`; });
  }
  // One background poll per step render, stopped when the step leaves the page.
  function ctx_poll(v2, fn) {
    let n = 0;
    const t = setInterval(() => { if (!document.body.contains(v2) || ++n > 60 || document.hidden) { if (!document.body.contains(v2) || n > 60) clearInterval(t); return; } fn().catch(() => {}); }, 20000);
  }

  const useCaseDraft = (x) => {
    const ws = ((me().workspaces || []).find((w) => w.id === wsId()) || {}).name || 'our company';
    return `We are ${ws}. We use Amazon SES through ${SL_BN()} to send email to people who signed up on our website and agreed to receive it.\n\nWhat we send: account and order emails (sign-in codes, receipts) and a newsletter about once a month.\n\nHow we built our list: every address comes from our own sign-up form. We never buy, rent or scrape lists.\n\nUnsubscribes: every marketing email has a one-click unsubscribe link and List-Unsubscribe headers. Unsubscribes are honoured immediately.\n\nBounces and complaints: ${SL_BN()} receives every SES bounce and complaint event through SNS and adds those addresses to a suppression list automatically, so we never email them again. We watch our bounce and complaint rates and pause sending if they rise.`;
  };
  const sandboxForm = (x) => `<div class="field"><label>What you send</label><div class="radio-cards" id="mtype"><label class="radio-card on" data-v="MARKETING"><span class="dot"></span><div><b>Marketing</b><span>Newsletters, offers, announcements.</span></div></label><label class="radio-card" data-v="TRANSACTIONAL"><span class="dot"></span><div><b>Transactional</b><span>Receipts, sign-in codes, notifications.</span></div></label></div></div>
    <div class="field"><label>Your website</label><input id="wurl" placeholder="https://yourcompany.com"></div>
    <div class="field"><label>How you send email <span class="faint">(AWS reads this; be specific and accurate)</span></label><textarea id="ucase" class="letter" style="width:100%;min-height:240px;max-height:none;font-family:inherit">${esc(useCaseDraft(x))}</textarea><span class="help">Edit anything that isn’t true for you. AWS approves clear, specific requests fastest.</span></div>
    <div class="field"><label>Extra contact email for AWS <span class="faint">(optional)</span></label><input id="ccon" type="email" placeholder="ops@yourcompany.com"></div>
    <button class="btn btn-primary" id="sbsend">${ic('send', 14)}Send request to AWS</button>`;
  function bindSandbox(v2, x, f) {
    const send = $('#sbsend', v2); if (!send) return;
    $$('#mtype .radio-card', v2).forEach((c) => c.onclick = () => { $$('#mtype .radio-card', v2).forEach((o) => o.classList.remove('on')); c.classList.add('on'); });
    send.onclick = () => {
      const site = $('#wurl', v2).value.trim(), use = $('#ucase', v2).value.trim(), extra = $('#ccon', v2).value.trim();
      if (!/^https?:\/\/[^\s.]+\.[^\s]+$/.test(site)) return fieldError(v2, '#wurl', 'Enter your website, starting with https://');
      if (use.length < 100) return fieldError(v2, '#ucase', 'Describe your sending in at least 100 characters.');
      send.disabled = true; send.innerHTML = '<span class="spin"></span>Sending…';
      API('POST', base() + '/ses/production-access', { mail_type: ($('#mtype .radio-card.on', v2) || {}).dataset.v || 'MARKETING', website_url: site, use_case: use, contacts: extra ? [extra] : [] })
        .then((res) => { x.conn = res.connection; toast('Request sent to AWS'); f.render(); })
        .catch((err) => { send.disabled = false; send.textContent = 'Send request to AWS'; if (err.fields) { const k = Object.keys(err.fields)[0]; const sel = { website_url: '#wurl', use_case: '#ucase' }[k] || '#ccon'; return fieldError(v2, sel, err.fields[k]); } toast(err.message); });
    };
  }

  // ═══════ #/ses — the connection at a glance ═══════
  pages.ses = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    loading(v, 'Loading your SES connection…');
    API('GET', base() + '/ses').then((r) => {
      if (!alive(g)) return;
      const c = r.connection;
      if (!c) return emptySes(v);
      const pct = (n) => (n == null ? null : n * 100);
      const bounce = pct(c.reputation.bounce_rate), complaint = pct(c.reputation.complaint_rate);
      const usedPct = c.quota.max_24h ? Math.min(100, (c.quota.sent_24h / c.quota.max_24h) * 100) : 0;
      v.innerHTML = `<div class="page-head"><div><h1>Amazon SES</h1><p>Your email goes through your own AWS account, at AWS’s price. ${SL_BN()} adds everything you can see.</p></div><div style="display:flex;gap:8px"><button class="btn" id="sync">${ic('refresh', 15)}Refresh</button><a class="btn" href="https://${esc(c.region)}.console.aws.amazon.com/ses/home?region=${esc(c.region)}#/account" target="_blank" rel="noopener">${ic('link', 15)}Open in AWS</a></div></div>
        ${c.status === 'error' ? `<div class="callout warn" style="margin:0 0 16px">${ic('alert', 15)}<div><b>Setup isn’t finished.</b> ${esc(c.last_error || '')} <a href="#/ses/connect">Fix it →</a></div></div>` : ''}
        ${!c.production_access ? `<div class="callout ${c.review_status === 'PENDING' ? 'info' : 'warn'}" style="margin:0 0 16px">${ic(c.review_status === 'PENDING' ? 'info' : 'alert', 15)}<div><b>${c.review_status === 'PENDING' ? 'AWS is reviewing your production access request.' : 'This account is in the SES sandbox.'}</b> ${c.review_status === 'PENDING' ? 'Until then you can send up to ' + fmt(c.quota.max_24h) + ' emails a day, only to verified addresses.' : 'You can only send to verified addresses, up to ' + fmt(c.quota.max_24h) + ' a day. <a href="#/ses/connect?step=live">Request production access →</a>'}</div></div>` : ''}
        <section class="card flowmap-card"><div class="card-head"><div><h3>How your email flows</h3><div class="sub">Account ${esc(c.aws_account_id)} · ${esc(c.region_name)} · ${c.production_access ? '<span style="color:var(--s-delivered);font-weight:600">Production access</span>' : '<span style="color:var(--s-deferred);font-weight:600">Sandbox</span>'}</div></div>${c.events_receiving ? `<span class="live-pill"><span class="pulse"></span>${c.last_event_at ? 'Last event ' + ago(c.last_event_at) : 'Ready for events'}</span>` : '<span class="pill-warn">Events not confirmed</span>'}</div>
          <div class="flowmap">
            <div class="fm-node"><span class="fm-ic">${ic('send', 16)}</span><b>${SL_BN()}</b><small>API, campaigns, automations</small></div>
            <div class="fm-link"><span>SendEmail</span></div>
            <div class="fm-aws"><div class="fm-aws-label">${ic('shield', 12)}Your AWS account</div>
              <div class="fm-node ses"><span class="fm-ic">${ic('ses', 16)}</span><b>Amazon SES</b><small>Config set <span class="mono">${esc(c.configuration_set || 'not created')}</span></small></div>
              <div class="fm-aws-foot">You own it and pay AWS directly. Key <span class="mono">${esc(c.access_key)}</span>.</div></div>
            <div class="fm-link"><span>SMTP</span></div>
            <div class="fm-node"><span class="fm-ic">${ic('inbox', 16)}</span><b>Mailbox providers</b><small>Gmail, Outlook, Yahoo, iCloud…</small></div>
          </div>
          <div class="fm-return"><span class="fm-ret-line"></span><span class="fm-ret-label">← ${c.events_receiving ? '10 event types back to ' + SL_BN() + ' through SNS' : 'Event tracking is waiting for AWS to confirm the SNS subscription'}</span></div>
        </section>
        <section class="card" style="margin-top:16px"><div class="card-head"><div><h3>Your rates vs AWS’s enforcement thresholds</h3><div class="sub">AWS reviews or pauses accounts that cross these. ${SL_BN()} alerts you first.</div></div></div>
          <div class="gauges">
            ${gaugeBlock('Bounce rate', 'Hard bounces, as AWS measures them', bounce, { max: 12, zones: [[0, 5, 'z-ok', ''], [5, 10, 'z-warn', 'Under review'], [10, 12, 'z-bad', 'Sending paused']], alertAt: 3, fmtv: (n) => +n.toFixed(1) + '%' }, 3, 5)}
            ${gaugeBlock('Complaint rate', 'Recipients who marked you as spam', complaint, { max: 0.6, zones: [[0, 0.1, 'z-ok', ''], [0.1, 0.5, 'z-warn', 'Under review'], [0.5, 0.6, 'z-bad', 'Paused']], alertAt: 0.05, fmtv: (n) => +n.toFixed(2) + '%' }, 0.05, 0.1)}
          </div></section>
        <div class="grid two" style="margin-top:16px;align-items:start">
          <div class="card"><div class="card-head"><div><h3>Limits</h3><div class="sub">Set by AWS for this account · updated ${ago(c.last_synced_at)}</div></div></div><div class="card-body">
            <div class="limit"><div class="lm-top"><b>Daily quota</b><span><b>${fmt(c.quota.sent_24h)}</b> of ${fmt(c.quota.max_24h)} in the last 24 hours</span></div><div class="lm-bar"><span style="width:${usedPct.toFixed(1)}%${usedPct > 80 ? ';background:var(--s-deferred)' : ''}"></span></div><small>A rolling 24-hour window.</small></div>
            <div class="limit"><div class="lm-top"><b>Send rate</b><span>Up to <b>${fmt(c.quota.max_rate)}</b> emails per second</span></div><small>If a send goes faster, SES rejects the extra email; ${SL_BN()} queues and retries it.</small></div>
            <div class="setting-row" style="margin-top:4px"><div><b>Account status</b><span>${c.sending_enabled ? 'Sending is enabled' : 'Sending is paused in this region'}${c.enforcement_status ? ' · AWS status: ' + esc(c.enforcement_status.toLowerCase()) : ''}</span></div>${c.sending_enabled ? '<span class="pill-ok">Enabled</span>' : '<span class="pill-warn">Paused</span>'}</div></div></div>
          <div class="card"><div class="card-head"><div><h3>Setup in your account</h3><div class="sub">Region ${esc(c.region)}</div></div>${canManage() ? '<a class="btn btn-sm" href="#/ses/connect">Manage</a>' : ''}</div><div class="card-body" style="padding-top:2px"><div class="checklist">${Object.keys(STEP_INFO).map((k) => stepRow(k, c.steps[k])).join('')}</div></div></div>
        </div>
        ${canManage() ? `<div class="card" style="margin-top:16px"><div class="card-body" style="padding-top:6px;padding-bottom:6px">
          <div class="setting-row"><div><b>Replace access keys</b><span>Rotate the key in AWS, then paste the new one here. Your setup stays.</span></div><a class="btn btn-sm" href="#/ses/connect">Replace keys</a></div>
          <div class="setting-row"><div><b>Disconnect this account</b><span>${SL_BN()} stops sending through it and forgets its domains. Nothing is deleted in AWS.</span></div><button class="btn btn-sm" id="disc">Disconnect</button></div></div></div>` : ''}`;
      $('#sync').onclick = (e) => { const b = e.currentTarget; b.disabled = true; API('POST', base() + '/ses/sync').then(() => { toast('Updated from AWS'); S.route(); }).catch((err) => { b.disabled = false; toast(err.message); }); };
      const d = $('#disc'); if (d) d.onclick = () => confirmDialog({ title: 'Disconnect this AWS account?', desc: SL_BN() + ' stops sending through it. Your keys are deleted from ' + SL_BN() + '; delete them in AWS too. Your email history stays.', label: 'Disconnect', onConfirm: () => API('DELETE', base() + '/ses').then(() => { toast('Amazon SES disconnected'); S.route(); }).catch((err) => toast(err.message)) });
    }).catch((err) => alive(g) && failed(v, err));
  };
  function gaugeBlock(name, sub, val, opt, alertAt, reviewAt) {
    if (val == null) return `<div class="gauge-block"><div class="gb-head"><div><b>${name}</b><small>${sub}</small></div><span class="faint" style="font-size:12.5px">No data yet</span></div><p class="ev-note">AWS starts reporting this once you’ve sent enough email. ${SL_BN()} also calculates it from your own events.</p></div>`;
    const level = val >= reviewAt ? 'bad' : val >= alertAt ? 'warn' : 'ok';
    return `<div class="gauge-block ${level === 'ok' ? '' : 'warn'}"><div class="gb-head"><div><b>${name}</b><small>${sub}</small></div><span class="${level === 'ok' ? 'pill-ok' : 'pill-warn'}">${level === 'ok' ? 'Healthy' : level === 'warn' ? 'Close to review' : 'Under review risk'}</span></div>
      ${S.thresholdGauge(Object.assign({ val: Math.min(val, opt.max), alertLabel: SL_BN() + ' alerts' }, opt))}
      <p class="ev-note">${level === 'ok' ? `${(reviewAt - val).toFixed(reviewAt < 1 ? 2 : 1)} points of headroom before AWS reviews the account.` : 'Above ' + SL_BN() + '’s alert line. Look at recent imports and unengaged contacts.'}</p></div>`;
  }
  function emptySes(v) {
    v.innerHTML = `<div class="page-head"><div><h1>Amazon SES</h1><p>${SL_BN()} sends through your own Amazon SES account, so you pay AWS’s price: about $0.10 per 1,000 emails.</p></div></div>
      <section class="card ses-empty"><div class="se-main"><span class="se-ic">${ic('ses', 26)}</span><h2>Connect your Amazon SES</h2><p>Create an access key for ${SL_BN()} in AWS, paste it here, and we set up tracking for every email in your account. About 3 minutes.</p>
        ${canManage() ? `<a class="btn btn-primary" href="#/ses/connect" id="go-connect">${ic('ses', 15)}Connect Amazon SES</a>` : '<p class="faint">Ask an owner or admin of this workspace to connect it.</p>'}</div>
        <div class="se-points">${[['shield', 'You own the account', 'Your sending reputation, your AWS bill, no markup on sending.'], ['activity', 'Every event tracked', 'Deliveries, bounces, complaints, opens and clicks flow back automatically.'], ['domains', 'DNS made easy', 'If your domain is on Route 53, we add the records for you.']].map(([i, a, b]) => `<div><span>${ic(i, 16)}</span><div><b>${a}</b><small>${b}</small></div></div>`).join('')}</div></section>`;
  }

  // ═══════ #/domains ═══════
  pages.domains = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    loading(v, 'Loading your domains…');
    Promise.all([API('GET', base() + '/ses'), API('GET', base() + '/domains')]).then(([s, r]) => {
      if (!alive(g)) return;
      const head = `<div class="page-head"><div><h1>Domains</h1><p>Authenticate the domains you send from, so mailbox providers trust your email.</p></div>${s.connection && canManage() ? `<a class="btn btn-primary" href="#/domains/new">${ic('plus', 15)}Add domain</a>` : ''}</div>`;
      if (!s.connection) { v.innerHTML = head + `<div class="card"><div class="card-body" style="text-align:center;padding:40px 20px"><b style="font-size:15px">Connect Amazon SES first</b><p class="faint" style="margin:6px 0 14px">Domains are set up inside your own SES account.</p><a class="btn btn-primary" href="#/ses">${ic('ses', 15)}Go to Amazon SES</a></div></div>`; return; }
      if (!r.domains.length) { v.innerHTML = head + `<div class="card"><div class="card-body" style="text-align:center;padding:40px 20px"><b style="font-size:15px">No domains yet</b><p class="faint" style="margin:6px 0 14px">Add the domain you’ll send from, like mail.yourcompany.com.</p>${canManage() ? `<a class="btn btn-primary" href="#/domains/new">${ic('plus', 15)}Add domain</a>` : ''}</div></div>`; return; }
      v.innerHTML = head + `<div class="card"><div class="table-wrap"><table class="tbl"><thead><tr><th>Domain</th><th>Status</th><th>DKIM</th><th>MAIL FROM</th><th>DNS</th><th>Checked</th></tr></thead><tbody>${r.domains.map((d) => {
        const recs = d.records || [], ok = recs.filter((x) => x.required && x.live === 'found').length, need = recs.filter((x) => x.required).length;
        return `<tr data-id="${d.id}"><td><div class="cell-main">${esc(d.name)}</div><div class="cell-sub">${esc(d.region)}${d.imported ? ' · from SES' : ''}</div></td><td>${domainPill(d)}</td><td>${sesState(d.dkim_status)}</td><td>${d.mail_from_domain ? sesState(d.mail_from_status) : '<span class="faint">Not set</span>'}</td><td>${need ? `${ok} of ${need} records` : '—'}${d.dns_provider === 'route53' ? ' <span class="tag">Route 53</span>' : d.dns_provider === 'cloudflare' ? ' <span class="tag">Cloudflare</span>' : ''}</td><td class="faint">${ago(d.last_checked_at)}</td></tr>`;
      }).join('')}</tbody></table></div></div><p class="faint" style="font-size:12.5px;margin-top:10px">${ic('refresh', 12)} We re-check waiting domains every 5 minutes.</p><div id="cf-card"></div>`;
      $$('tr[data-id]', v).forEach((tr) => tr.onclick = () => (location.hash = '#/domains/view?id=' + tr.dataset.id));
      cloudflareCard($('#cf-card', v), g);
    }).catch((err) => alive(g) && failed(v, err));
  };
  // Cloudflare: one API token, then "Add records automatically" on each domain whose zone it can edit.
  function cloudflareCard(box, g) {
    if (!box) return;
    API('GET', base() + '/dns/cloudflare').then((r) => {
      if (!alive(g)) return;
      const c = r.cloudflare, m = canManage();
      box.innerHTML = `<div class="card" style="margin-top:16px"><div class="card-body" style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap"><div class="logos" style="display:flex"><i style="background:#F38020;color:#fff;font-style:normal;font-weight:700;font-size:11px;border-radius:8px;width:34px;height:34px;display:grid;place-items:center">CF</i></div>
        <div style="flex:1;min-width:240px"><b style="font-size:14px">DNS on Cloudflare?</b><p class="faint" style="margin:4px 0 0;font-size:13px">${c.connected ? `Connected. ${c.domains.length ? `${SL_BN()} can add records for ${c.domains.map(esc).join(', ')}.` : 'None of your domains is in a zone this token can edit yet.'}` : 'Connect a Cloudflare API token and we add the DNS records for you. In Cloudflare: My Profile → API Tokens → Create Token → “Edit zone DNS” template, for your domain’s zone.'}</p>
        ${m && !c.connected ? `<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><input id="cf-token" type="password" autocomplete="off" placeholder="Cloudflare API token" style="flex:1;min-width:220px"><button class="btn btn-sm btn-primary" id="cf-save">Connect</button></div>` : ''}</div>
        ${m && c.connected ? '<button class="btn btn-sm btn-ghost" id="cf-del">Disconnect</button>' : ''}</div></div>`;
      const save = $('#cf-save', box); if (save) save.onclick = () => { const token = $('#cf-token', box).value.trim(); if (!token) return toast('Paste the token first.'); save.disabled = true; API('PUT', base() + '/dns/cloudflare', { token }).then((x) => { toast(x.cloudflare.domains.length ? `Cloudflare connected: ${x.cloudflare.domains.length} domain${x.cloudflare.domains.length > 1 ? 's' : ''} found` : 'Cloudflare connected'); S.route(); }).catch((err) => { save.disabled = false; toast(err.message); }); };
      const del = $('#cf-del', box); if (del) del.onclick = () => confirmDialog({ title: 'Disconnect Cloudflare?', desc: SL_BN() + ' forgets the token. Records it added stay in Cloudflare.', onConfirm: () => API('DELETE', base() + '/dns/cloudflare').then(() => { toast('Cloudflare disconnected'); S.route(); }) });
    }).catch(() => {});
  }
  const sesState = (s) => !s ? '<span class="faint">—</span>' : s === 'SUCCESS' ? `<span style="color:var(--s-delivered);font-weight:600">${ic('check', 13, 2.5)} Verified</span>` : s === 'FAILED' || s === 'NOT_FOUND' ? `<span style="color:var(--s-bounced);font-weight:600">${s === 'NOT_FOUND' ? 'Deleted in SES' : 'Failed'}</span>` : `<span style="color:var(--s-deferred);font-weight:600">${s === 'TEMPORARY_FAILURE' ? 'Retrying' : 'Pending'}</span>`;

  // ═══════ #/domains/new ═══════
  pages['domains/new'] = (v) => {
    gen++;
    v.innerHTML = `<div class="flow-top"><a class="back" href="#/domains">← Domains</a></div>
      <div class="page-head" style="margin-bottom:18px"><div><h1>Add a sending domain</h1><p>We create it in your SES account and give you the DNS records to publish.</p></div></div>
      <section class="card" style="max-width:640px"><div class="card-body"><form id="dform" novalidate><div class="field"><label>Domain</label><input id="dn" placeholder="mail.yourcompany.com" autocomplete="off" spellcheck="false" autocapitalize="off"><span class="help">We recommend a subdomain such as <span class="mono">mail.yourcompany.com</span>, so your marketing reputation stays separate from your company email.</span></div>
        <div style="display:flex;gap:8px;margin-top:8px"><button class="btn btn-primary" id="dgo">Create DNS records</button><a class="btn" href="#/domains">Cancel</a></div></form></div></section>`;
    if (!canManage()) { $('#dform', v).innerHTML = '<p>Only owners and admins can add domains.</p>'; return; }
    setTimeout(() => $('#dn', v) && $('#dn', v).focus(), 30);
    $('#dform', v).onsubmit = (e) => {
      e.preventDefault();
      const name = $('#dn', v).value.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/\.$/, '');
      if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(name)) return fieldError(v, '#dn', 'Enter a domain like mail.yourcompany.com');
      const b = $('#dgo', v); b.disabled = true; b.innerHTML = '<span class="spin"></span>Creating in SES…';
      API('POST', base() + '/domains', { name }).then((r) => { location.hash = '#/domains/view?id=' + r.domain.id; })
        .catch((err) => { b.disabled = false; b.textContent = 'Create DNS records'; if (err.fields && err.fields.name) return fieldError(v, '#dn', err.fields.name); toast(err.message); });
    };
  };

  // ═══════ #/domains/view?id= ═══════
  pages['domains/view'] = (v) => {
    const g = (seq = S.routeSeq, ++gen), id = S.param('id');
    loading(v, 'Loading domain…');
    let timer = null;
    const stop = () => { if (timer) { clearTimeout(timer); timer = null; } };
    const draw = (d) => {
      if (!alive(g)) return stop();
      const recs = d.records || [];
      const liveDot = (s) => s === 'found' ? `<span class="dns-status" style="color:var(--s-delivered)"><i></i>Found</span>` : s === 'mismatch' ? `<span class="dns-status" style="color:var(--s-bounced)"><i></i>Different value</span>` : `<span class="dns-status" style="color:var(--s-deferred)"><i></i>Not found yet</span>`;
      $('#title').textContent = d.name;
      v.innerHTML = `<div class="flow-top"><a class="back" href="#/domains">← Domains</a><div class="spacer"></div>${canManage() ? `<button class="btn btn-sm" id="dmenu">${ic('more', 15)}</button>` : ''}</div>
        <div class="page-head" style="margin-bottom:16px"><div><h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">${esc(d.name)} ${domainPill(d)}</h1><p>${d.status === 'verified' ? 'Ready to send. Keep these records in place; removing them breaks authentication.' : 'Publish these records at your DNS provider. They turn green as soon as we see them, and SES verifies the domain within about an hour of that.'}</p></div><button class="btn" id="chk">${ic('refresh', 15)}Check now</button></div>
        ${d.dns_provider === 'cloudflare' && d.status !== 'verified' && canManage() ? `<div class="auto-dns"><div class="logos"><i style="background:#F38020">CF</i></div><div style="flex:1"><b style="font-size:13.5px">This domain’s DNS is on Cloudflare</b><div class="faint" style="font-size:12.5px">We can add the records with your Cloudflare token, DNS-only (not proxied). An existing DMARC policy is left alone.</div></div><button class="btn btn-primary btn-sm" id="cfa">Add records automatically</button></div>` : ''}
        ${d.dns_provider === 'route53' && d.status !== 'verified' && canManage() ? `<div class="auto-dns"><div class="logos"><i style="background:#8C4FFF">R53</i></div><div style="flex:1"><b style="font-size:13.5px">This domain’s DNS is on Amazon Route 53</b><div class="faint" style="font-size:12.5px">We can add the records to your hosted zone with the same keys. An existing DMARC policy is left alone.</div></div><button class="btn btn-primary btn-sm" id="r53">Add records automatically</button></div>` : ''}
        ${d.status === 'verified' && canEdit() ? `<div class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>Is it ready for the inbox?</h3><div class="sub">Sends a test from ${esc(d.name)} through your Amazon SES and reads it back the way Gmail does: route, SPF, DKIM, DMARC, blocklists.</div></div><button class="btn btn-sm btn-primary" id="dvrun">${ic('check', 13)}Check my setup</button></div><div class="card-body" id="dvres" style="padding-top:0"></div></div>` : ''}
        <div class="card"><div class="card-head"><div><h3>DNS records</h3><div class="sub">Checked ${ago(d.last_checked_at)}${d.status !== 'verified' ? ' · we check again every 5 minutes' : ''}</div></div><button class="btn btn-sm" id="zone">${ic('download', 14)}Download</button></div>
          <div class="table-wrap" style="margin-top:8px"><table class="tbl"><thead><tr><th>Type</th><th>Host / name</th><th>Value</th><th>Purpose</th><th>Status</th></tr></thead><tbody>${recs.map((x) => `<tr style="cursor:default"><td><span class="tag">${x.type}</span></td><td><span class="copy" data-copy="${esc(x.host)}">${esc(x.host)} ${ic('copy', 12)}</span></td><td><span class="copy" data-copy="${esc(x.value)}" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-flex">${esc(x.value)} ${ic('copy', 12)}</span></td><td class="faint" style="font-size:12.5px">${esc(x.purpose)}${x.required ? '' : ' · recommended'}</td><td>${liveDot(x.live)}</td></tr>`).join('') || '<tr><td colspan="5" class="faint">SES hasn’t returned records for this domain yet. Try Check now.</td></tr>'}</tbody></table></div>
          <div class="card-body" style="padding-top:10px"><p class="faint" style="font-size:12.5px">Some DNS providers add your domain to the end of the host automatically. If yours does, enter only the part before <span class="mono">.${esc(d.name)}</span>. ${recs.some((x) => x.live === 'mismatch') ? '<b>A record shows a different value:</b> for SPF, add <span class="mono">include:amazonses.com</span> to your existing record instead of creating a second one.' : ''}</p></div></div>
        <div class="grid two" style="margin-top:16px;align-items:start">
          <div class="card"><div class="card-head"><div><h3>In Amazon SES</h3><div class="sub">Region ${esc(d.region)}</div></div></div><div class="card-body" style="padding-top:2px">
            <div class="setting-row"><div><b>DKIM signing</b><span>2048-bit keys managed by AWS (Easy DKIM)</span></div>${sesState(d.dkim_status)}</div>
            <div class="setting-row"><div><b>Custom MAIL FROM</b><span>${d.mail_from_domain ? esc(d.mail_from_domain) + ' handles bounces, so SPF aligns with your domain' : 'Not set up'}</span></div>${d.mail_from_domain ? sesState(d.mail_from_status) : ''}</div></div></div>
          <div class="card"><div class="card-head"><div><h3>Why each record matters</h3></div></div><div class="card-body" style="font-size:13px;line-height:1.6;padding-top:4px">
            <p><b>DKIM</b> signs every email so Gmail and Outlook know it really came from you. Required.</p>
            <p style="margin-top:8px"><b>MX + SPF on the MAIL FROM domain</b> receive bounces and prove SES may send for you. Required for DMARC alignment.</p>
            <p style="margin-top:8px"><b>DMARC</b> tells mailbox providers what to do with email that fails these checks. Gmail and Yahoo require one for bulk senders. Start with <span class="mono">p=none</span>.</p></div></div>
        </div>`;
      bindCopy(v);
      $('#chk').onclick = (e) => { const b = e.currentTarget; b.disabled = true; b.innerHTML = '<span class="spin"></span>Checking…'; API('POST', `${base()}/domains/${id}/check`).then((r) => { draw(r.domain); toast(r.domain.status === 'verified' ? 'Domain verified' : 'Checked'); }).catch((err) => { b.disabled = false; b.textContent = 'Check now'; toast(err.message); }); };
      const dvr = $('#dvrun'); if (dvr) dvr.onclick = () => { stop(); dvr.disabled = true; API('GET', base() + '/senders').then((snd) => S.runSetupCheck($('#dvres'), snd.default && snd.default.email.endsWith('@' + d.name) ? snd.default.email : 'hello@' + d.name, () => { dvr.disabled = false; })).catch((err) => { dvr.disabled = false; toast(err.message); }); };
      const cfa = $('#cfa'); if (cfa) cfa.onclick = () => { cfa.disabled = true; cfa.innerHTML = '<span class="spin"></span>Adding…'; API('POST', `${base()}/domains/${id}/cloudflare`).then((r) => { toast(r.written ? `${r.written} records added in Cloudflare` : 'Cloudflare already has every record'); cfa.textContent = 'Records added'; schedule(20000); }).catch((err) => { cfa.disabled = false; cfa.textContent = 'Add records automatically'; toast(err.message); }); };
      const r53 = $('#r53'); if (r53) r53.onclick = () => { r53.disabled = true; r53.innerHTML = '<span class="spin"></span>Adding…'; API('POST', `${base()}/domains/${id}/route53`).then((r) => { toast(`${r.written} records added to Route 53`); r53.textContent = 'Records added'; schedule(20000); }).catch((err) => { r53.disabled = false; r53.textContent = 'Add records automatically'; toast(err.message); }); };
      $('#zone').onclick = () => { const lines = recs.map((x) => `${x.host}.\t300\tIN\t${x.type}\t${x.type === 'TXT' ? '"' + x.value + '"' : x.type === 'CNAME' ? x.value + '.' : x.value.replace(/(\S+)$/, '$1.')}`); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([`; ${SL_BN()} DNS records for ${d.name}\n` + lines.join('\n') + '\n'], { type: 'text/plain' })); a.download = `${d.name}-dns-records.txt`; a.click(); };
      const dm = $('#dmenu'); if (dm) dm.onclick = (e) => { e.stopPropagation(); menu(dm, [{ label: 'Remove from ' + SL_BN(), icon: 'trash', danger: true, onClick: () => confirmDialog({ title: `Remove ${d.name}?`, desc: SL_BN() + ' stops using it. The domain stays in your SES account, so other apps that send with it keep working.', label: 'Remove', onConfirm: () => API('DELETE', `${base()}/domains/${id}`).then(() => { toast('Domain removed'); location.hash = '#/domains'; }).catch((err) => toast(err.message)) }) }]); };
      if (d.status !== 'verified') schedule(30000);
    };
    const schedule = (ms) => { stop(); timer = setTimeout(() => { if (!alive(g) || document.hidden) return schedule(ms); API('POST', `${base()}/domains/${id}/check`).then((r) => draw(r.domain)).catch(() => schedule(ms * 2)); }, ms); };
    API('GET', `${base()}/domains/${id}`).then((r) => draw(r.domain)).catch((err) => alive(g) && failed(v, err));
  };
})();
