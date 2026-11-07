(function () {
  'use strict';
  const token = new URLSearchParams(location.search).get('t') || '';
  const card = document.getElementById('card');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const xsrf = () => { const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=[^;]+/g); return m ? decodeURIComponent((c => c.slice(c.indexOf('=') + 1))(m.pop())) : ''; /* newest: a parent domain can add a stale one */ };
  let csrf = null;
  async function api(method, path, body) {
    if (method !== 'GET') { csrf = csrf || fetch('/api/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrf; }
    const r = await fetch('/api/v1/unsubscribe/' + encodeURIComponent(token) + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error((d.error && d.error.message) || 'Something went wrong. Please try again.');
    return d;
  }
  const foot = '<p class="foot">Sent with <span class="bn">MailVesper</span></p>';
  let info = null;
  function ask() {
    const who = esc(info.sender || 'this sender');
    if (info.topics && info.topics.length) return prefs();
    card.innerHTML = `<div class="mark">${esc((info.sender || 'S').charAt(0).toUpperCase())}</div><h1>Unsubscribe from ${who}?</h1><p>You’ll stop getting emails from ${who} at <span class="email">${esc(info.email)}</span>.</p><button id="go">Unsubscribe</button><div id="msg"></div>${foot}`;
    document.getElementById('go').onclick = (e) => run(e.currentTarget, 'POST', '', done);
  }
  // Topics: choose which kinds of email to keep. The email's own topic is offered first.
  function prefs(saved) {
    const who = esc(info.sender || 'this sender'), cur = info.topics.find((t) => t.name === info.topic);
    card.innerHTML = `<div class="mark">${esc((info.sender || 'S').charAt(0).toUpperCase())}</div><h1>${saved ? 'Preferences saved' : `Choose what ${who} sends you`}</h1><p>For <span class="email">${esc(info.email)}</span>. Untick anything you don’t want.</p>
      ${cur && cur.subscribed && !saved ? `<button id="one">Stop “${esc(cur.name)}” emails</button>` : ''}
      <div class="topics">${info.topics.map((t) => `<label class="topic"><input type="checkbox" data-t="${esc(t.id)}" ${t.subscribed ? 'checked' : ''}><span><b>${esc(t.name)}</b>${t.description ? `<small>${esc(t.description)}</small>` : ''}</span></label>`).join('')}</div>
      <button class="secondary" id="save">Save preferences</button><button class="link" id="all">Unsubscribe from everything</button><div id="msg"></div>${foot}`;
    const one = document.getElementById('one');
    if (one) one.onclick = (e) => run(e.currentTarget, 'POST', '', (d) => { info.topics = d.topics; prefs(true); }, { topic_id: cur.id });
    document.getElementById('save').onclick = (e) => { const topics = {}; card.querySelectorAll('[data-t]').forEach((i) => { topics[i.dataset.t] = i.checked; }); run(e.currentTarget, 'POST', '/preferences', (d) => { info.topics = d.topics; prefs(true); }, { topics }); };
    document.getElementById('all').onclick = (e) => run(e.currentTarget, 'POST', '', done);
  }
  function done() {
    const who = esc(info.sender || 'this sender');
    card.innerHTML = `<div class="mark ok">✓</div><h1>You’re unsubscribed</h1><p>${who} won’t email <span class="email">${esc(info.email)}</span> again. This takes effect immediately.</p><button class="link" id="undo">Unsubscribed by mistake? Resubscribe</button><div id="msg"></div>${foot}`;
    document.getElementById('undo').onclick = (e) => run(e.currentTarget, 'POST', '/resubscribe', (d) => { if (d.unsubscribed) { document.getElementById('msg').innerHTML = '<p class="err">This address can’t be resubscribed here. Contact the sender.</p>'; } else { info.topics = d.topics; d.topics && d.topics.length ? prefs() : subscribed(); } });
  }
  function subscribed() {
    const who = esc(info.sender || 'this sender');
    card.innerHTML = `<div class="mark ok">✓</div><h1>You’re subscribed again</h1><p>You’ll keep getting emails from ${who} at <span class="email">${esc(info.email)}</span>.</p><button class="link" id="go">Unsubscribe instead</button><div id="msg"></div>${foot}`;
    document.getElementById('go').onclick = (e) => run(e.currentTarget, 'POST', '', done);
  }
  function run(btn, method, path, then, body) {
    btn.disabled = true;
    api(method, path, body).then(then).catch((err) => { btn.disabled = false; document.getElementById('msg').innerHTML = `<p class="err">${esc(err.message)}</p>`; });
  }
  if (!/^[A-Za-z0-9]{40}$/.test(token)) { card.innerHTML = '<h1>This link isn’t complete</h1><p>Open the unsubscribe link from the email again.</p>' + foot; return; }
  api('GET', '').then((d) => { info = d; document.title = 'Unsubscribe from ' + (d.sender || 'emails'); d.unsubscribed ? done() : ask(); })
    .catch((err) => { card.innerHTML = `<h1>Link not found</h1><p>${esc(err.message)}</p>${foot}`; });

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
