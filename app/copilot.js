/* MailVesper: Copilot on the real API (Phase 9): deterministic findings from the workspace's own data (score,
   evidence, fixes) and DMARC reports. The chat that explains them and inbox tests are MailVesper Pro (copilot-chat.js,
   inbox.js in the Pro package); Community shows what they add (edition.js). Loaded after journeys.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, toast, bindCopy, modal } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => !!myRole() && myRole() !== 'viewer';
  const isManager = () => ['owner', 'admin'].includes(myRole());
  const base = () => `/v1/workspaces/${wsId()}`;
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ago = (iso) => { const s = Math.max(0, (Date.now() - new Date(iso)) / 1000); return s < 60 ? 'just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : s < 86400 ? Math.round(s / 3600) + ' h ago' : Math.round(s / 86400) + ' d ago'; };

  // ═══════ Copilot ═══════
  const CHAIN = [['Amazon SES', ['Amazon SES']], ['Authentication', ['Authentication']], ['Reputation', ['Reputation']], ['Mailbox providers', ['Mailbox providers']], ['Your list', ['List quality', 'Engagement']]];
  const areaOf = (f) => f.area.split(' · ')[0];
  const fmtRate = (n, d = 1) => (n * 100).toFixed(d).replace(/\.0+$/, '') + '%';
  const evidence = (f) => {
    const e = f.evidence || {};
    if (e.type === 'dns') {
      return `<div class="ev-cap">TXT record at <span class="mono">${esc(e.host)}</span></div><div class="diff">${e.found ? `<div class="dl del"><i>−</i>${esc(e.found)}</div>` : '<div class="dl" style="color:var(--text-3)"><i>∅</i>No DMARC record found</div>'}<div class="dl add"><i>+</i>${esc(e.proposed)}</div></div>
        ${e.reports ? `<div class="proof" style="margin-top:12px"><div class="pf"><span>Passed DMARC (reports)</span><span class="pf-bar"><span style="width:${(e.reports.passed / Math.max(1, e.reports.messages)) * 100}%"></span></span><span class="mono">${fmt(e.reports.passed)} / ${fmt(e.reports.messages)}</span></div></div><p class="ev-note">From ${fmt(e.reports.sources)} sending source${e.reports.sources === 1 ? '' : 's'} in your DMARC reports, last 30 days.</p>` : ''}`;
    }
    if (e.type === 'gauge') {
      const marks = Object.fromEntries((e.marks || []).map(([v, l]) => [v, l]));
      return `<div class="ev-cap">${e.metric === 'bounce' ? 'Hard bounce rate' : 'User-reported spam rate'}, last 30 days</div>${S.thresholdGauge({ max: e.max, val: Math.min(e.value, e.max), zones: e.zones.map(([a, b, cls]) => [a, b, 'z-' + cls, marks[a] || '']), fmtv: (n) => fmtRate(n, e.metric === 'bounce' ? 1 : 2) })}
        <p class="ev-note">${fmt(e.n[0])} of ${fmt(e.n[1])} ${e.metric === 'bounce' ? 'sent emails bounced' : 'delivered emails were reported as spam'}.</p>`;
    }
    if (e.type === 'codes') {
      const rows = Object.entries(e.rows || {});
      return `<div class="ev-cap">${fmt(e.n[0])} of ${fmt(e.n[1])} emails were held back. What the server said:</div>${rows.length ? `<div class="codes">${rows.map(([code, n]) => `<div class="code-row"><span class="mono">${esc(code)}</span><b>${fmt(n)}×</b></div>`).join('')}</div>` : '<p class="ev-note">No reply text was recorded.</p>'}`;
    }
    if (e.type === 'bars') {
      const max = Math.max(1, ...e.rows.map((r) => r[1]));
      return `<div class="src-bars">${e.rows.map(([a, n]) => `<div class="sb"><div><b>${esc(a)}</b></div><span class="sb-bar"><span style="width:${(n / max) * 100}%"></span></span><span class="mono">${fmt(n)}${e.unit || ''}</span></div>`).join('')}</div>`;
    }
    if (e.type === 'stats') return `<div class="ev-stats">${e.rows.map(([a, b]) => `<div><span>${esc(a)}</span><b>${typeof b === 'number' ? (b > 0 && b < 1 ? fmtRate(b, 2) : fmt(b)) : esc(b == null ? '–' : b)}</b></div>`).join('')}</div>`;
    return '<p class="ev-note">Checked from your account’s data.</p>';
  };
  const fixHtml = (f) => {
    if (!f.fix) return '';
    const acts = (f.fix.actions || []).map((a, i) => {
      if (a.type === 'copy') return `<button class="btn btn-sm" data-copy="${esc(a.value)}">${ic('copy', 14)}${esc(a.label)}</button>`;
      if (a.type === 'link') return `<a class="btn btn-sm" href="${esc(a.href)}">${esc(a.label)}</a>`;
      if (a.type === 'fix') return isManager() ? `<button class="btn btn-primary btn-sm" data-fix="${i}">${ic('zap', 14)}${esc(a.label)}</button>` : '';
      if (a.type === 'toggle') return `<label class="case-toggle"><span><b>${esc(a.label)}</b><small>${a.on ? 'On' : 'Off'}</small></span><span class="switch"><input type="checkbox" data-toggle="${esc(a.setting)}" ${a.on ? 'checked' : ''} ${canEdit() ? '' : 'disabled'}><span></span></span></label>`;
      return '';
    }).join('');
    return `<div class="cp-label">Fix</div><p>${esc(f.fix.text)}</p>${acts ? `<div class="case-actions">${acts}</div>` : ''}`;
  };
  pages.copilot = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = '<div class="card"><div class="card-body faint">Copilot is checking your account…</div></div>';
    API('GET', base() + '/copilot').then((r) => alive(g) && draw(v, g, r.copilot)).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };
  function draw(v, g, c) {
    const F = c.findings, gain = F.reduce((a, f) => a + f.gain, 0), s = c.summary;
    const lo = Math.max(0, Math.min(60, (c.score ?? 60) - 10)), X = (p) => ((p - lo) / (100 - lo)) * 100;
    let acc = c.score ?? 0;
    const segs = c.score == null ? '' : F.filter((f) => f.gain).map((f, i) => { const a = acc; acc += f.gain; return `<span class="fc-seg ${f.severity}" style="left:${X(a)}%;width:${X(Math.min(100, acc)) - X(a)}%" title="${esc(f.title)}">+${f.gain}</span>`; }).join('');
    const verdict = c.score == null ? 'Not enough sending yet for a score.' : !F.length ? 'Excellent. Nothing to fix right now.' : F.some((f) => f.severity === 'bad') ? `${F.filter((f) => f.severity === 'bad').length === 1 ? 'One problem needs' : F.filter((f) => f.severity === 'bad').length + ' problems need'} fixing first.` : `Good, with ${F.length === 1 ? 'one fix' : F.length + ' fixes'} worth making.`;
    const noSes = F.some((f) => f.id === 'ses_missing');
    const chain = CHAIN.map(([name, areas]) => {
      const fs = F.filter((f) => areas.includes(areaOf(f)));
      if (fs.length) return [name, fs[0].title, fs.some((f) => f.severity === 'bad') ? 'bad' : 'warn', fs[0].id];
      // Say so when an area couldn't be judged, instead of calling it fine.
      if (noSes && name !== 'Amazon SES') return [name, 'Not checked yet', 'na', null];
      if (!s.enough_data && ['Reputation', 'Mailbox providers', 'Your list'].includes(name)) return [name, 'Not enough sending yet', 'na', null];
      return [name, 'No problems found', 'ok', null];
    });
    v.innerHTML = `<div class="page-head"><div><h1>Copilot</h1><p>Your deliverability engineer. Every finding comes from your own sending data and DNS; the AI only explains it.</p></div><div style="display:flex;gap:8px;align-items:center"><span class="faint" style="font-size:12.5px">Checked ${ago(c.checked_at)}</span><button class="btn" id="rescan">${ic('refresh', 15)}Re-check</button></div></div>
      <section class="card cpx-hero">
        <div class="cpx-top"><div class="cpx-num"><b>${c.score ?? '–'}</b>${c.score != null && gain ? `<span>→ ${Math.min(100, c.score + gain)}</span>` : ''}</div><div><b class="cpx-verdict">${esc(verdict)}</b><p class="faint">${c.score == null ? `Copilot needs about 100 sent emails for reputation checks. It has seen ${fmt(s.sent)} in the last ${s.days} days; authentication is checked already.` : `Deliverability score now, and after each fix. From ${fmt(s.sent)} emails in the last ${s.days} days, your DNS and your SES account.`}</p></div></div>
        ${c.score != null ? `<div class="fc"><div class="fc-track"><span class="fc-fill" style="width:${X(c.score)}%"></span>${segs}<span class="fc-now" style="left:${X(c.score)}%"></span></div><div class="fc-scale">${[lo, lo + (100 - lo) / 4, lo + (100 - lo) / 2, lo + 3 * (100 - lo) / 4, 100].map((n) => `<span style="left:${X(n)}%">${Math.round(n)}</span>`).join('')}</div>
          ${F.some((f) => f.gain) ? `<div class="fc-legend">${F.filter((f) => f.gain).map((f) => `<a href="#" data-go="${esc(f.id)}"><i class="${f.severity}"></i>+${f.gain} ${esc(f.title)}</a>`).join('')}</div>` : ''}</div>` : ''}
        <div class="cp-label" style="margin:22px 0 12px">Where your email’s path breaks</div>
        <ol class="chain">${chain.map(([a, b, st, id]) => `<li class="ch ${st}" ${id ? `data-go="${esc(id)}"` : ''}><span class="ch-dot">${st === 'ok' ? ic('check', 12, 3) : st === 'na' ? '–' : '!'}</span><b>${a}</b><small>${esc(b)}</small></li>`).join('')}</ol>
      </section>
      <div class="cpx-layout"><div>
        ${F.map((f, i) => `<article class="case ${f.severity}" id="f-${esc(f.id).replace(/[^a-z0-9_-]/gi, '_')}" data-i="${i}"><header><span class="case-n">${String(i + 1).padStart(2, '0')}</span><div style="min-width:0"><div class="case-area">${esc(f.area)}</div><h3>${esc(f.title)}</h3></div>${f.gain ? `<span class="case-gain">+${f.gain} pts<small>estimated</small></span>` : ''}</header>
          <div class="case-grid"><div class="case-ev"><div class="cp-label">Evidence</div>${evidence(f)}</div><div class="case-fix"><p class="case-why">${esc(f.why)}</p>${fixHtml(f)}${f.sources && f.sources.length ? `<div class="case-foot">${ic('info', 13)}${f.sources.map((x) => `<a href="${esc(x.url)}" target="_blank" rel="noopener">${esc(x.label)}</a>`).join(' · ')}</div>` : ''}</div></div></article>`).join('') || `<div class="card" style="margin-bottom:16px"><div class="card-body" style="text-align:center;padding:30px"><b>No problems found.</b><p class="faint" style="margin-top:6px">Copilot checks again every day, and whenever you click Re-check.</p></div></div>`}
        <div class="also"><div class="cp-label">Also checked · no action needed</div>${c.passed.map((p) => `<div class="also-row"><span class="also-ic ok">${ic('check', 13, 3)}</span><div class="also-main"><b>${esc(p.title)}</b><p class="faint">${esc(p.detail)}</p></div></div>`).join('') || '<p class="faint" style="font-size:13px">Nothing else to report yet.</p>'}</div>
        <div class="card" style="margin-top:16px" id="dmarc"><div class="card-head"><div><h3>DMARC reports</h3><div class="sub">Who sends email as your domains, and whether it passes. Upload the XML, .gz or .zip reports mailbox providers send to your DMARC <span class="mono">rua</span> address.</div></div>${canEdit() ? `<label class="btn btn-sm" style="white-space:nowrap;flex-shrink:0">${ic('upload', 14)}Upload report<input type="file" id="dmf" accept=".xml,.gz,.zip,application/xml,application/gzip,application/zip" hidden></label>` : ''}</div><div class="card-body" id="dmb"><span class="faint">Loading…</span></div></div>
      </div>
        ${c.ai.pro && S.copilotChatPanel ? S.copilotChatPanel(c, s) : (S.copilotChatTeaser ? S.copilotChatTeaser() : '')}</div>`;
    bindCopy(v);
    navBadge(F);
    $$('[data-go]', v).forEach((el) => el.onclick = (e) => { e.preventDefault(); const t = $('#f-' + el.dataset.go.replace(/[^a-z0-9_-]/gi, '_')); if (!t) return; t.scrollIntoView({ behavior: 'smooth', block: 'start' }); t.classList.remove('flash'); void t.offsetWidth; t.classList.add('flash'); });
    $('#rescan').onclick = (e) => { e.currentTarget.disabled = true; API('POST', base() + '/copilot/rescan').then((r) => { toast('Re-checked'); alive(g) && draw(v, g, r.copilot); }).catch((err) => { toast(err.message); e.currentTarget.disabled = false; }); };
    $$('.case', v).forEach((card) => {
      const f = F[+card.dataset.i];
      $$('[data-fix]', card).forEach((b) => b.onclick = () => { const a = f.fix.actions[+b.dataset.fix]; modal({ title: 'Publish this DMARC record?', desc: `${SL_BN()} writes it to _dmarc.${a.domain} in your Route 53 hosted zone, replacing any DMARC record there.`, icon: 'zap', size: 'sm', body: `<div class="diff"><div class="dl add"><i>+</i>${esc(a.value)}</div></div>`,
        actions: [{ label: 'Cancel' }, { label: 'Publish', kind: 'primary', onClick: () => { API('POST', base() + '/copilot/fix', { fix: 'dmarc', domain: a.domain }).then((r) => { toast(r.note); }).catch((err) => toast(err.message)); } }] }); });
      $$('[data-toggle]', card).forEach((t) => t.onchange = () => API('POST', base() + '/copilot/fix', { fix: t.dataset.toggle, on: t.checked }).then((r) => { toast(t.checked ? 'Gentle Outlook pacing is on for your next campaigns' : 'Gentle Outlook pacing is off'); alive(g) && draw(v, g, r.copilot); }).catch((err) => { t.checked = !t.checked; toast(err.message); }));
    });
    loadDmarc(g);
    const dmf = $('#dmf'); if (dmf) dmf.onchange = () => {
      const file = dmf.files[0]; if (!file) return;
      const fd = new FormData(); fd.append('file', file);
      const x = window.SL_XSRF();
      toast('Reading the report…');
      fetch('/api' + base() + '/copilot/dmarc', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(x || ''), 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then((r) => r.json().then((d) => (r.ok ? d : Promise.reject(d.error || {}))))
        .then((d) => { toast(d.report.duplicate ? 'Already had that report' : `Report from ${d.report.org}: ${fmt(d.report.messages)} emails`); loadDmarc(g); })
        .catch((err) => toast(err.message || 'That file couldn’t be read'));
      dmf.value = '';
    };
    if (c.ai.pro && S.bindCopilotChat) S.bindCopilotChat(v);
  }
  // The sidebar badge counts the real open findings (the prototype had a fixed number).
  const navBadge = (F) => {
    const a = document.querySelector('.nav-item[data-k="copilot"]'); if (!a) return;
    let b = a.querySelector('.count'); const n = F.filter((f) => f.severity !== 'info').length;
    if (!b) { if (!n) return; b = document.createElement('span'); b.className = 'nav-badge'; a.appendChild(b); }
    b.textContent = n ? `${n} issue${n === 1 ? '' : 's'}` : ''; b.style.display = n ? '' : 'none';
  };
  // Sidebar usage: real emails this month; the meter is today's share of the SES quota, or of the SMTP daily limit.
  S.refreshUsage = () => API('GET', base() + '/usage').then((r) => {
    const u = r.usage, box = document.getElementById('usage'); if (!box) return;
    box.querySelector('b').textContent = fmt(u.month);
    let pctToday = 0, line;
    if (u.smtp) {
      pctToday = u.smtp.max_per_day ? Math.min(100, (u.smtp.sent_today / u.smtp.max_per_day) * 100) : 0;
      line = u.smtp.max_per_day ? `SMTP today: ${fmt(u.smtp.sent_today)} of ${fmt(u.smtp.max_per_day)}` : `Sending through ${u.smtp.host}`;
    } else {
      pctToday = u.ses && u.ses.max_24h ? Math.min(100, (u.ses.sent_24h / u.ses.max_24h) * 100) : 0;
      line = u.ses ? (u.ses.max_24h ? `SES today: ${fmt(u.ses.sent_24h)} of ${fmt(u.ses.max_24h)}` : 'SES quota not synced yet') : 'Connect Amazon SES or SMTP to send';
    }
    box.querySelector('.meter span').style.width = pctToday + '%';
    box.querySelector('small').textContent = line;
  }).catch(() => {});

  // The header bell: open problems first, each linking to Copilot.
  S.loadAlerts = () => API('GET', base() + '/copilot').then((r) => r.copilot.findings.filter((f) => f.severity !== 'info')
    .sort((a, b) => (a.severity === 'bad' ? 0 : 1) - (b.severity === 'bad' ? 0 : 1)).slice(0, 5)
    .map((f) => ({ html: `<span style="color:var(${f.severity === 'bad' ? '--s-bounced' : '--s-deferred'})">${ic('alert', 16)}</span><span>${esc(f.title)}<br><span class="faint" style="font-size:12px">${esc(f.area)}</span></span>`, onClick: () => (location.hash = '#/copilot') })))
    .catch(() => []);
  S.refreshCopilotBadge = () => API('GET', base() + '/copilot').then((r) => navBadge(r.copilot.findings)).catch(() => {});
  const loadDmarc = (g) => API('GET', base() + '/copilot/dmarc').then((r) => {
    const box = $('#dmb'); if (!box || !alive(g)) return;
    const doms = Object.entries(r.dmarc);
    box.innerHTML = doms.length ? doms.map(([d, x]) => `<div class="dm-dom"><div class="dm-head"><b>${esc(d)}</b><span class="faint">${fmt(x.messages)} emails · ${pct(x.passed / Math.max(1, x.messages))} passed DMARC</span></div>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Sending IP</th><th>Signed by</th><th class="num">Emails</th><th class="num">Passed</th></tr></thead><tbody>${x.sources.map((s) => `<tr style="cursor:default"><td class="mono">${esc(s.ip)}</td><td>${esc(s.dkim_domain || '–')}</td><td class="num">${fmt(s.messages)}</td><td class="num" style="color:${s.passed === s.messages ? 'var(--s-delivered)' : s.passed ? 'var(--s-deferred)' : 'var(--s-bounced)'}">${pct(s.passed / Math.max(1, s.messages), 0)}</td></tr>`).join('')}</tbody></table></div></div>`).join('')
      : '<p class="faint" style="font-size:13px;margin:0">No reports yet. Add <span class="mono">rua=mailto:you@yourdomain</span> to your DMARC record and upload what arrives; sources that fail are usually forwarders, or a service you forgot signs as you.</p>';
  }).catch(() => {});

  // Once the session is known, fill the sidebar (real usage, real Copilot count).
  const fillShell = () => { if (!window.SL_ME) return setTimeout(fillShell, 300); S.refreshUsage(); S.refreshCopilotBadge(); };
  fillShell();
})();
