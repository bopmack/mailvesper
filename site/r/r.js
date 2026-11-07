(function () {
  'use strict';
  const token = new URLSearchParams(location.search).get('t') || '';
  const root = document.getElementById('root');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Number(n || 0).toLocaleString('en-US');
  const pct = (a, b) => (b ? ((a / b) * 100).toFixed(1) + '%' : '—');
  const PROV = { google: 'Gmail', microsoft: 'Outlook', yahoo: 'Yahoo', apple: 'iCloud', proton: 'Proton' };
  if (!/^[A-Za-z0-9]{32}$/.test(token)) { root.innerHTML = '<p class="msg">This report link isn’t valid.</p>'; return; }
  fetch('/api/v1/reports/' + token, { headers: { Accept: 'application/json' } })
    .then((r) => r.json().then((j) => (r.ok ? j : Promise.reject(new Error((j.error && j.error.message) || 'This report isn’t available.')))))
    .then(({ campaign: c, report: r }) => {
      const T = r.totals;
      document.title = c.name + ' · Campaign report';
      const series = r.timeline || [];
      const max = Math.max(1, ...series.map((h) => Math.max(h.opens, h.clicks)));
      const line = (k) => series.map((h, i) => `${i ? 'L' : 'M'}${(i / Math.max(1, series.length - 1)) * 1000},${170 - (h[k] / max) * 160}`).join(' ');
      const maxP = Math.max(1, ...r.links.map((l) => l.people));
      root.innerHTML = `<div class="eyebrow">Campaign report · ${esc(c.sender || '')}</div><h1>${esc(c.name)}</h1>
        <p class="sub">${esc(c.subject || '')}${c.started_at ? ' · sent ' + esc(new Date(c.started_at).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })) : ''}${c.status === 'sending' ? ' · still sending' : ''}</p>
        <div class="card stats">${[['Sent', fmt(T.sent), ''], ['Delivered', pct(T.delivered, T.sent), fmt(T.delivered)], ['Real opens', pct(T.opened, T.delivered), fmt(T.opened) + ' people'], ['Clicks', pct(T.clicked, T.delivered), fmt(T.clicked) + ' people']].map(([a, b, s]) => `<div><small>${a}</small><b>${b}</b><span>${s}</span></div>`).join('')}</div>
        <div class="card"><h2>Links</h2><p class="hint">People who clicked each link. Security-scanner clicks are left out.</p>${r.links.length ? r.links.map((l) => `<div class="row"><span class="u">${esc(l.url.replace(/^https?:\/\//, ''))}</span><span class="bar"><i style="width:${(l.people / maxP) * 100}%;background:var(--clicked)"></i></span><span class="n">${fmt(l.people)}</span></div>`).join('') : '<p class="empty">No clicks yet.</p>'}</div>
        <div class="card"><h2>Engagement over time</h2><p class="hint">Real opens and clicks per hour after sending (UTC).</p><div class="legend"><span><i style="background:var(--opened)"></i>Real opens</span><span><i style="background:var(--clicked)"></i>Clicks</span></div>
          <div class="chart">${series.length > 1 ? `<svg viewBox="0 0 1000 175" preserveAspectRatio="none"><path d="${line('opens')}" fill="none" stroke="var(--opened)" stroke-width="2.5" vector-effect="non-scaling-stroke"/><path d="${line('clicks')}" fill="none" stroke="var(--clicked)" stroke-width="2.5" vector-effect="non-scaling-stroke"/></svg>` : '<p class="empty" style="padding:4px">The chart fills in during the first hours after sending.</p>'}</div></div>
        <div class="card"><h2>By mailbox provider</h2><p class="hint">Real open rate per provider.</p>${r.providers.length ? r.providers.map((p) => `<div class="row"><span class="u">${esc(PROV[p.provider] || 'Other')}</span><span class="bar"><i style="width:${p.delivered ? (p.opened / p.delivered) * 100 : 0}%;background:var(--opened)"></i></span><span class="n">${pct(p.opened, p.delivered)}</span></div>`).join('') : '<p class="empty">No emails sent yet.</p>'}</div>
        <p class="foot">Read-only report. Apple Mail Privacy and scanner opens aren’t counted as real opens. Made with <span class="bn">MailVesper</span>.</p>`;
    })
    .catch((err) => { root.innerHTML = `<p class="msg">${esc(err.message)}</p>`; });

  // The operator's brand name in the footer (Platform → Branding): from the browser's cache, then the server, and
  // again after every re-render (only when it differs, so it never loops).
  (function () {
    let n = null;
    try { n = (JSON.parse(localStorage.getItem('sl-brand') || 'null') || {}).name || null; } catch (e) {}
    const set = () => { if (n) document.querySelectorAll('.bn').forEach((el) => { if (el.textContent !== n) el.textContent = n; }); };
    set();
    new MutationObserver(set).observe(document.body, { childList: true, subtree: true });
    fetch('/api/v1/auth/setup', { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((r) => {
      if (r && r.brand && r.brand.name) { n = r.brand.name; set(); try { localStorage.setItem('sl-brand', JSON.stringify(r.brand)); } catch (e) {} }
    }).catch(() => {});
  })();
})();
