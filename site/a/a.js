(function () {
  'use strict';
  // A journey's "Human approval" link. Opening it only shows the request; a button press answers it, once.
  const token = new URLSearchParams(location.search).get('t') || '';
  const card = document.getElementById('card');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const xsrf = () => { const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=[^;]+/g); return m ? decodeURIComponent((c => c.slice(c.indexOf('=') + 1))(m.pop())) : ''; /* newest: a parent domain can add a stale one */ };
  let csrf = null;
  async function api(method, body) {
    if (method !== 'GET') { csrf = csrf || fetch('/api/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrf; }
    const r = await fetch('/api/v1/approve/' + encodeURIComponent(token), { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error((d.error && d.error.message) || 'Something went wrong. Please try again.');
    return d;
  }
  const foot = '<p class="foot">Sent with <span class="bn">MailVesper</span></p>';
  const when = (iso) => { try { return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); } catch (e) { return iso; } };
  function show(d) {
    const who = d.contact ? (d.contact.name ? `${esc(d.contact.name)} (${esc(d.contact.email)})` : esc(d.contact.email)) : 'a deleted contact';
    if (d.status !== 'waiting') {
      card.innerHTML = `<div class="mark">✓</div><h1>Already answered</h1><p>This request from “${esc(d.journey)}” was answered already, or the time to answer ran out.</p>${foot}`;
      return;
    }
    card.innerHTML = `<div class="mark">?</div><h1>Approve this?</h1><p>The journey “${esc(d.journey)}” is waiting for an answer about ${who}.</p>
      <div class="req">${esc(d.message || '')}</div>${d.until ? `<p class="meta">No answer by ${esc(when(d.until))} counts as not approved.</p>` : ''}
      <div class="row"><button class="reject" data-d="reject">Reject</button><button data-d="approve">Approve</button></div><div id="msg"></div>${foot}`;
    card.querySelectorAll('[data-d]').forEach((b) => { b.onclick = () => answer(b.dataset.d); });
  }
  function answer(decision) {
    card.querySelectorAll('[data-d]').forEach((b) => { b.disabled = true; });
    api('POST', { decision }).then(() => {
      const ok = decision === 'approve';
      card.innerHTML = `<div class="mark ${ok ? 'ok' : 'no'}">${ok ? '✓' : '✕'}</div><h1>${ok ? 'Approved' : 'Rejected'}</h1><p>Thanks. The journey goes on ${ok ? 'down the Approved path' : 'down the Not approved path'}.</p>${foot}`;
    }).catch((e) => { card.querySelectorAll('[data-d]').forEach((b) => { b.disabled = false; }); document.getElementById('msg').innerHTML = `<p class="err">${esc(e.message)}</p>`; });
  }
  if (!token) { card.innerHTML = `<h1>Link not complete</h1><p>Open the link from the email again.</p>${foot}`; return; }
  api('GET').then(show).catch((e) => { card.innerHTML = `<h1>Link not valid</h1><p>${esc(e.message)}</p>${foot}`; });

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
