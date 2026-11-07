/* MailVesper app session guard. Loads before the app; the page stays hidden until the API confirms a session.
   No session → /login/?next=<this page>. Session but sign-up unfinished → back to /signup/ to finish it. */
(function () {
  'use strict';
  var root = document.documentElement;
  root.style.visibility = 'hidden';
  var reveal = function () { root.style.visibility = ''; };
  var here = location.pathname + location.search + location.hash;

  // The newest XSRF-TOKEN this page can see. A site on a parent domain (another app on *.example.com, say) can
  // leave its own beside ours, and the server refuses that one as an expired session, so those copies are cleared
  // on every read (it can appear while the app is open).
  window.SL_XSRF = xsrf;
  function xsrf() {
    var parts = location.hostname.split('.');
    for (var i = 1; i < parts.length - 1; i++) ['/', '/api', '/app'].forEach(function (p) { document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=' + p + '; domain=.' + parts.slice(i).join('.'); });
    var all = document.cookie.match(/(?:^|; )XSRF-TOKEN=[^;]+/g) || []; var c = all.pop() || '';
    return decodeURIComponent(c.slice(c.indexOf('=') + 1));
  }
  xsrf();

  // Shared JSON API helper for the app (cookie session + CSRF). Rejects with the API's { code, message, fields }.
  // The operator's brand name (Platform → Branding) for messages; app.js keeps it current once it has loaded.
  window.SL_BN = function () { try { return (JSON.parse(localStorage.getItem('sl-brand') || 'null') || {}).name || 'MailVesper'; } catch (e) { return 'MailVesper'; } };

  window.SL_API = function (method, path, body) {
    return fetch('/api/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(function () {
      var form = typeof FormData !== 'undefined' && body instanceof FormData; // file uploads: the browser sets the multipart type
      var headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' };
      if (!form) headers['Content-Type'] = 'application/json';
      return fetch('/api' + path, { method: method, credentials: 'same-origin', headers: headers, body: form ? body : body ? JSON.stringify(body) : undefined });
    }, function () { throw { code: 'network', message: 'Can’t reach ' + SL_BN() + ' right now. Check your connection.' }; }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (r.status === 401) { location.replace('/login/?next=' + encodeURIComponent(location.pathname + location.hash)); }
        if (!r.ok) throw Object.assign({ status: r.status, code: 'error', message: 'Something went wrong. Please try again.' }, data.error || {});
        return data;
      });
    });
  };

  window.SL_LOGOUT = function () {
    fetch('/api/sanctum/csrf-cookie', { credentials: 'same-origin' })
      .then(function () { return fetch('/api/v1/auth/logout', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' } }); })
      .catch(function () {})
      .then(function () { location.replace('/login/'); });
  };

  function initials(name) { var p = String(name).trim().split(/\s+/); return ((p[0] || '')[0] || '').toUpperCase() + ((p[1] || '')[0] || '').toUpperCase(); }
  function shortName(name) { var p = String(name).trim().split(/\s+/); return p.length > 1 ? p[0] + ' ' + p[p.length - 1][0] + '.' : p[0]; }

  // The app shell renders from static prototype data; put the real user and workspace into it. Settings calls it
  // again (window.SL_APPLY_ME) after a rename.
  window.SL_APPLY_ME = function (me) { applyMe(me); };
  function applyMe(me, tries) {
    var userBtn = document.getElementById('user');
    if (!userBtn) { if ((tries || 0) < 100) setTimeout(function () { applyMe(me, (tries || 0) + 1); }, 30); return; }
    var ws = (me.workspaces || []).filter(function (w) { return w.id === me.current_workspace_id; })[0] || (me.workspaces || [])[0];
    var b = userBtn.querySelector('b'), small = userBtn.querySelector('small'), av = userBtn.querySelector('.avatar-c');
    if (b) b.textContent = shortName(me.user.name);
    if (small && ws) small.textContent = ws.role.charAt(0).toUpperCase() + ws.role.slice(1);
    if (av) av.textContent = initials(me.user.name);
    var wsName = document.querySelector('#ws .ws-name'), wsAv = document.querySelector('#ws .avatar');
    if (ws && wsName) wsName.textContent = ws.name;
    if (ws && wsAv) wsAv.textContent = ws.name.charAt(0).toUpperCase();
    // The plan name in SaaS mode (Pro); nothing otherwise.
    var plan = document.querySelector('#ws .ws-plan'); if (plan) plan.textContent = me.billing && !me.billing.exempt && me.billing.plan ? me.billing.plan.name + (!me.billing.usable ? ' · paused' : me.billing.status === 'trialing' ? ' · trial' : '') : '';
  }

  fetch('/api/v1/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) {
      if (r.status === 401) { location.replace('/login/?next=' + encodeURIComponent(here)); return null; }
      if (!r.ok) { reveal(); return null; } // API trouble: don't lock the user out of the page
      return r.json();
    })
    .then(function (me) {
      if (!me) return;
      if (me.next !== 'app') { location.replace('/signup/'); return; }
      window.SL_ME = me;
      applyMe(me);
      // MailVesper Pro: its scripts come from the Pro package and load after the app's own (they replace locked pages).
      var scripts = (me.edition && me.edition.scripts) || [];
      var loadNext = function (i) {
        if (i >= scripts.length) {
          // The app booted before the session (and before every page script) was ready: draw the real page now.
          if (window.SL && window.SL.route) { try { (window.SL.routeIfStale || window.SL.route)(); } catch (e) { /* the page shows its own error */ } }
          reveal();
          return;
        }
        var el = document.createElement('script');
        el.src = scripts[i];
        el.onload = el.onerror = function () { loadNext(i + 1); };
        document.body.appendChild(el);
      };
      // Wait for the app's own scripts (this file runs in <head>, before them).
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { loadNext(0); }); else loadNext(0);
    })
    .catch(reveal);
})();
