(function () {
  'use strict';
  // The hosted signup page (?k=<form key>) and the double opt-in confirmation page (?c=<token>).
  const q = new URLSearchParams(location.search), key = q.get('k') || '', token = q.get('c') || '';
  const card = document.getElementById('card');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const xsrf = () => { const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=[^;]+/g); return m ? decodeURIComponent((c => c.slice(c.indexOf('=') + 1))(m.pop())) : ''; /* newest: a parent domain can add a stale one */ };
  let csrf = null;
  async function api(method, path, body) {
    if (method !== 'GET') { csrf = csrf || fetch('/api/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrf; }
    const r = await fetch('/api/v1/f/' + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error((d.error && d.error.message) || 'Something went wrong. Please try again.'); e.fields = (d.error && d.error.fields) || {}; throw e; }
    return d;
  }
  const foot = '<p class="foot">Sent with <span class="bn">MailVesper</span></p>';
  const mark = (s, ok) => `<div class="mark ${ok ? 'ok' : ''}">${ok ? '✓' : esc((s || 'S').charAt(0).toUpperCase())}</div>`;
  const message = (title, text, ok) => { card.innerHTML = `${mark('', ok)}<h1>${esc(title)}</h1><p>${esc(text)}</p>${foot}`; };

  // The form itself is drawn by the shared renderer (embed.js), as on customers' sites and in the editor's preview.
  function form(f, error) {
    document.title = f.heading || 'Sign up to ' + (f.sender || f.name);
    if (!f.open) return message('Sign-ups are closed', 'This form isn’t taking sign-ups right now.');
    const d = f.design || {}, host = document.createElement('div');
    host.style.cssText = 'width:100%;max-width:' + (d.image && d.layout === 'image_left' ? 780 : 440) + 'px';
    document.body.style.background = d.background || '';
    card.replaceWith(host);
    window.SendlineForms.render(host, f, { error, follow: true, onSubmit: (body) => api('POST', encodeURIComponent(key), body) });
  }

  function confirmPage() {
    card.innerHTML = `${mark('', false)}<h1>Confirm your subscription</h1><p>One click and you’re on the list.</p><button id="go">Yes, subscribe me</button><div id="msg"></div>${foot}`;
    document.getElementById('go').onclick = (e) => {
      e.currentTarget.disabled = true;
      api('POST', 'confirm/' + encodeURIComponent(token)).then((d) => (d.redirect ? (location.href = d.redirect) : message('You’re subscribed', d.message, true)))
        .catch((err) => message('That didn’t work', err.message));
    };
  }

  if (token) { if (/^[A-Za-z0-9]{48}$/.test(token)) confirmPage(); else message('This link isn’t complete', 'Open the link from the email again.'); return; }
  if (!/^[a-z0-9]{20}$/.test(key)) { message('Form not found', 'Check the link you were given.'); return; }
  const done = q.get('done');
  if (done) { message(done === 'pending' ? 'Check your inbox' : 'You’re signed up', done === 'pending' ? 'Almost done: check your inbox and confirm your email address.' : 'Thanks for signing up!', true); return; }
  api('GET', encodeURIComponent(key)).then((d) => form(d.form, q.get('error'))).catch((err) => message('Form not found', err.message));

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
