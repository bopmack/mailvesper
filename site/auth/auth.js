/* MailVesper auth pages, wired to the MailVesper API at /api (Laravel, Sanctum cookie sessions + CSRF).
   Endpoints: /v1/auth/{lookup,login,code/send,code/verify,signup,email/verify,email/resend,password/forgot,password/reset,logout}, /v1/me, /v1/workspaces[/slug-check].
   Research basis: email-first sign-in (Linear/Vercel/Slack); 2–3 primary options (Authgear); Google button at least as
   prominent as other providers (Google branding guidelines); NIST 800-63B rev 4 password rules. */
(function () {
  'use strict';
  // ─── API client (same origin: /api, Sanctum cookie session + CSRF) ───
  const API = '/api';
  let csrfReady = null;
  // Every XSRF-TOKEN the browser shows this page, newest last. A site on a parent domain (another app on
  // *.example.com, say) can leave its own beside ours; the server refuses that one as an expired session.
  const xsrfAll = () => (document.cookie.match(/(?:^|; )XSRF-TOKEN=[^;]+/g) || []).map((c) => decodeURIComponent(c.slice(c.indexOf('=') + 1)));
  const xsrf = () => { dropStaleXsrf(); return xsrfAll().pop() || ''; };
  const dropStaleXsrf = () => {
    const parts = location.hostname.split('.');
    for (let i = 1; i < parts.length - 1; i++) for (const p of ['/', '/api', '/app']) document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=' + p + '; domain=.' + parts.slice(i).join('.');
  };
  const csrf = () => (csrfReady = csrfReady || fetch(API + '/sanctum/csrf-cookie', { credentials: 'same-origin' }).catch((e) => { csrfReady = null; throw e; }));
  async function api(method, path, body) {
    const send = (token) => fetch(API + path, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
    let r;
    try {
      await csrf();
      r = await send(xsrf());
      if (r.status === 419) { // stale token: clear other sites' copies, get a fresh one, then try each token still here
        dropStaleXsrf(); csrfReady = null; await csrf();
        for (const t of [...new Set(xsrfAll())].reverse()) { r = await send(t); if (r.status !== 419) break; }
      }
    } catch (_) { throw { code: 'network', message: 'Can’t reach ' + B() + ' right now. Check your connection and try again.' }; }
    let data = {}; try { data = await r.json(); } catch (_) {}
    if (!r.ok) throw Object.assign({ status: r.status, code: 'error', message: 'Something went wrong. Please try again.' }, data.error || {});
    return data;
  }
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const APP = '/app/';
  const mode = document.body.dataset.mode;
  const qs = new URLSearchParams(location.search);
  const card = $('#card');
  let st = { email: '', name: '', step: '' };
  // The operator's brand (Platform → Branding): painted at once from the browser's cache, then from the server. On a
  // first visit the logo and name stay hidden until the real brand arrives, so a renamed site never flashes "MailVesper".
  const BRAND0 = (() => { try { return JSON.parse(localStorage.getItem('sl-brand') || 'null'); } catch (e) { return null; } })();
  const brand = () => (st.setup && st.setup.brand) || BRAND0 || { name: 'MailVesper' };
  const B = () => brand().name || 'MailVesper';
  function paintBrand() {
    const b = brand(), logo = document.querySelector('.au-logo');
    if (logo) logo.innerHTML = b.logo ? `<img src="${esc(b.logo)}" alt="${esc(B())}" class="au-logo-img">` : `<img src="${esc(b.icon || '/auth/logo-mark.svg')}" alt="" width="28" height="28">${esc(B())}`;
    const sw = document.querySelector('.au-switch'); if (sw && mode === 'login') sw.firstChild.textContent = `New to ${B()}? `;
    const foot = document.querySelector('.au-foot span'); if (foot) foot.textContent = `© ${new Date().getFullYear()} ${(b.company || B())}`;
    const fav = document.querySelector('link[rel="icon"]'); if (fav && b.icon) { fav.type = 'image/png'; fav.href = b.icon; }
    const c = b.accent, root = document.documentElement.style;
    if (c && /^#[0-9a-f]{6}$/i.test(c)) { root.setProperty('--accent', c); root.setProperty('--accent-hover', `color-mix(in srgb, ${c} 86%, #000)`); root.setProperty('--accent-soft', `color-mix(in srgb, ${c} 14%, transparent)`); root.setProperty('--accent-text', `color-mix(in srgb, ${c} 78%, var(--text))`); }
    const meta = document.querySelector('meta[name="description"]'); if (meta && b.custom) meta.content = B();
    document.title = document.title.replace(/ · .*$/, ' · ' + B());
    document.body.classList.add('branded');
  }

  try { st.email = localStorage.getItem('sl-last-email') || ''; } catch (_) {}
  let lastProvider = null; try { lastProvider = localStorage.getItem('sl-last-provider'); } catch (_) {}

  // ─── Icons ───
  const I = {
    google: '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>',
    microsoft: '<svg width="17" height="17" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/><rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/></svg>',
    github: '<svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.2.8-.6v-2c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.7 1.3 3.4 1 .1-.8.4-1.3.7-1.5-2.6-.3-5.2-1.3-5.2-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3 0 0 1-.3 3.2 1.2a11 11 0 0 1 5.8 0C17.3 4.4 18.3 4.7 18.3 4.7c.6 1.6.2 2.8.1 3 .7.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.1v3.2c0 .3.2.7.8.6A11.5 11.5 0 0 0 23.5 12C23.5 5.7 18.3.5 12 .5z"/></svg>',
    apple: '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.4 1.4c0 1.1-.5 2.3-1.2 3.1-.7.9-2 1.6-3 1.6-.1 0-.2 0-.3-.1v-.4c0-1.1.6-2.3 1.2-3 .8-.9 2.1-1.6 3.2-1.7.1.2.1.3.1.5zm4.5 15.7c0 .1-.5 1.6-1.5 3.1-.9 1.3-1.9 2.7-3.4 2.7-1.5 0-1.9-.9-3.6-.9-1.7 0-2.3.9-3.7.9-1.4 0-2.3-1.3-3.4-2.8C4 18.3 3 15.5 3 12.9c0-4.3 2.8-6.6 5.6-6.6 1.4 0 2.7 1 3.6 1 .9 0 2.2-1 3.9-1 .6 0 2.9.1 4.4 2.2-.1.1-2.4 1.4-2.4 4.2 0 3.3 2.9 4.4 3 4.4z"/></svg>',
    key: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14.5 8.5l2 2"/></svg>',
    mail: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
    eye: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    eyeOff: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-2.4 3.3M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.5 4.5-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2M3 3l18 18"/></svg>',
    alert: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>',
    info: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>',
    ses: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H8a5 5 0 1 1 1-9.9A6 6 0 0 1 20.6 11 4 4 0 0 1 17.5 19z"/></svg>',
    spark: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/></svg>',
    swap: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4 3 8l4 4M3 8h13M17 20l4-4-4-4M21 16H8"/></svg>',
    users: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/></svg>',
    back: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>',
  };
  const PROV = { google: 'Google', microsoft: 'Microsoft', github: 'GitHub', apple: 'Apple' };
  const FREE = /@(gmail|googlemail|yahoo|ymail|outlook|hotmail|live|msn|icloud|me|aol|proton|protonmail|gmx|yandex|mail)\.[a-z.]+$/i;
  const isEmail = (e) => /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(e);
  const BREACHED = ['password', 'password1', '123456', '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123', '111111', 'iloveyou', 'admin', 'welcome', 'letmein', 'monkey', 'football', 'sendline', 'password123', 'abc123', 'passw0rd', 'qwertyuiop'];

  // ─── Shared bits ───
  // Only the providers this server has keys for (GET /v1/auth/providers); none configured: no buttons and no divider.
  const social = (verb, or) => {
    const on = (p) => !!(st.providers && st.providers[p]);
    const big = ['google', 'microsoft'].filter(on), small = ['github', 'apple'].filter(on);
    if (!big.length && !small.length) return '';
    return `<div class="soc">${big.map((p) => `<button class="sbtn" data-oauth="${p}">${I[p]}${verb} with ${PROV[p]}${lastProvider === p ? '<span class="last">Last used</span>' : ''}</button>`).join('')}${small.length ? `<div class="soc-row">${small.map((p) => `<button class="sbtn sm" data-oauth="${p}" title="${verb} with ${PROV[p]}">${I[p]}${PROV[p]}</button>`).join('')}</div>` : ''}</div><div class="or">${or}</div>`;
  };
  const field = (id, label, type, o = {}) => `<div class="fld" id="f-${id}">${o.labelHtml || `<label for="${id}">${label}</label>`}<div class="inp ${type === 'password' ? 'has-eye' : ''}"><input id="${id}" name="${id}" type="${type}" ${o.ac ? `autocomplete="${o.ac}"` : ''} ${o.ph ? `placeholder="${esc(o.ph)}"` : ''} value="${esc(o.val || '')}" ${o.ro ? 'readonly' : ''} ${type === 'email' ? 'inputmode="email" spellcheck="false" autocapitalize="off"' : ''} ${o.req === false ? '' : 'required'}>${type === 'password' ? `<button type="button" class="eye" data-eye="${id}" aria-label="Show password">${I.eye}</button>` : ''}</div><div class="msg-slot"></div></div>`;
  const setMsg = (id, kind, text) => { const f = $('#f-' + id); if (!f) return; f.classList.toggle('err', kind === 'bad'); $('.msg-slot', f).innerHTML = text ? `<div class="msg ${kind}">${kind === 'info' ? I.info : I.alert}<span>${text}</span></div>` : ''; };
  const busy = (btn, on, label) => { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.l = btn.innerHTML; btn.innerHTML = `<span class="spin"></span>${label || ''}`; } else if (btn.dataset.l) btn.innerHTML = btn.dataset.l; };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const head = (h, sub) => `<h1 class="au-h">${h}</h1>${sub ? `<p class="au-sub">${sub}</p>` : ''}`;
  // The operator's own links (Platform → Settings → Your site), from GET /v1/auth/setup; unset ones aren't shown.
  const links = () => (st.setup && st.setup.links) || {};
  const legal = () => {
    const l = links(), t = l.terms && `<a href="${esc(l.terms)}" target="_blank" rel="noopener">Terms of Service</a>`, p = l.privacy && `<a href="${esc(l.privacy)}" target="_blank" rel="noopener">Privacy Policy</a>`;
    return t || p ? `<p class="legal">By continuing you agree to the ${[t, p].filter(Boolean).join(' and ')}.</p>` : '';
  };
  const footLinks = () => {
    const foot = document.querySelector('.au-foot'), l = links(); if (!foot) return;
    foot.querySelectorAll('a').forEach((a) => a.remove());
    if (l.privacy) foot.insertAdjacentHTML('beforeend', `<a href="${esc(l.privacy)}" target="_blank" rel="noopener">Privacy</a>`);
    if (l.terms) foot.insertAdjacentHTML('beforeend', `<a href="${esc(l.terms)}" target="_blank" rel="noopener">Terms</a>`);
    if (l.support_email) foot.insertAdjacentHTML('beforeend', `<a href="mailto:${esc(l.support_email)}">Help</a>`);
  };
  const who = () => `<div class="who"><span class="av">${esc((st.email[0] || '?').toUpperCase())}</span><span>${esc(st.email)}</span><button type="button" data-view="${mode === 'signup' ? 'signup' : 'email'}">Change</button></div>`;
  const otp = () => `<div class="otp" id="otp">${Array.from({ length: 6 }, (_, i) => `<input inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit ${i + 1}" ${i ? '' : 'autocomplete="one-time-code"'}>`).join('')}</div>`;
  const mailApps = () => { const d = (st.email.split('@')[1] || '').toLowerCase(); const g = /gmail|googlemail/.test(d) || !FREE.test(st.email), o = /outlook|hotmail|live|msn/.test(d) || !FREE.test(st.email); return `<div class="mail-apps">${g ? `<a class="btn ghost" href="https://mail.google.com/mail/u/0/#search/from%3Asendline" target="_blank" rel="noopener">${I.google}Open Gmail</a>` : ''}${o ? `<a class="btn ghost" href="https://outlook.live.com/mail/0/" target="_blank" rel="noopener">${I.microsoft}Open Outlook</a>` : ''}</div>`; };

  // ─── Error display ───
  const banner = (msg, kind = 'bad') => { const g = $('#gmsg'); if (g) g.innerHTML = msg ? `<div class="note ${kind}" role="alert">${kind === 'info' ? I.info : I.alert}<div>${msg}</div></div>` : ''; };
  // Map API field names to this page's input ids, show field messages, otherwise a banner.
  function showErr(e, map = {}) {
    let shown = false;
    if (e && e.fields) Object.entries(e.fields).forEach(([k, m]) => { const id = map[k]; if (id && $('#f-' + id)) { setMsg(id, 'bad', esc(m)); shown = true; } });
    if (!shown) banner(esc((e && e.message) || 'Something went wrong. Please try again.'));
  }
  const nextFrom = (me) => (me.next === 'verify_email' ? 'verify' : me.next === 'create_workspace' ? 'workspace' : me.next === 'accept_invite' ? 'invites' : 'done');
  // Where to go after any successful sign-in step. A pending invite link is accepted automatically.
  async function afterAuth(me) {
    st.me = me;
    if (me.next === 'verify_email') return go('verify');
    if (st.invite && st.invite.valid) {
      try { const r = await api('POST', '/v1/invitations/accept', { token: st.invite.token }); st.invite = null; st.me = r; return go('done'); }
      catch (err) { st.invite = null; banner(esc(err.message)); }
    }
    go(nextFrom(me));
  }
  const inviteBanner = () => st.invite ? `<div class="note info" style="margin-bottom:4px">${I.users}<div><b>${esc(st.invite.invited_by || 'Someone')}</b> invited you to join <b>${esc(st.invite.workspace)}</b> as ${st.invite.role === 'admin' ? 'an' : 'a'} ${esc(st.invite.role)}.${st.invite.valid ? '' : ' <b>This invitation has expired.</b> Ask for a new one.'}</div></div>` : '';
  const gmsg = '<div id="gmsg"></div>';

  // ─── Views ───
  const V = {
    email: () => ({
      html: `${head(st.invite ? 'Log in to accept' : 'Log in to ' + B(), st.invite ? 'Use the account for the invited email.' : 'Welcome back. Use your work email or a connected account.')}<div class="au-body">${gmsg}${inviteBanner()}${social('Continue', 'or')}<form id="frm" novalidate>${field('email', 'Email', 'email', { ac: 'username webauthn', ph: 'you@company.com', val: st.email })}<button class="btn primary" style="margin-top:12px">Continue</button></form>
        <div class="alt"><button class="btn link" type="button" data-passkey>${I.key} Sign in with a passkey</button></div></div>`,
      submit: async (f, btn) => {
        const e = $('#email').value.trim(); if (!isEmail(e)) return setMsg('email', 'bad', 'Enter a valid email address, like you@company.com.');
        st.email = e.toLowerCase(); remember(); busy(btn, true);
        try { const r = await api('POST', '/v1/auth/lookup', { email: e }); st.methods = r.methods || []; if (!r.exists) return go('nouser'); if (st.methods.includes('password')) return go('password'); await sendCode(); go('code'); }
        catch (err) { busy(btn, false); showErr(err, { email: 'email' }); }
      },
    }),
    nouser: () => ({ html: `${head('No account for that email', `We couldn't find a ${esc(B())} account for <b>${esc(st.email)}</b>.${st.setup && !st.setup.signup_open ? ' This ' + esc(B()) + ' is private: ask its admin to give you access.' : ''}`)}<div class="au-body">${st.setup && !st.setup.signup_open ? '' : `<a class="btn primary" href="/signup/?email=${encodeURIComponent(st.email)}">Create an account</a>`}<button class="btn ghost" type="button" data-view="email">Try another email</button></div>` }),
    password: () => ({
      html: `${head('Enter your password')}<div class="au-body">${gmsg}${who()}<form id="frm" novalidate>${field('password', '', 'password', { ac: 'current-password', labelHtml: `<div class="lbl-row"><label for="password">Password</label><a href="#" data-view="forgot">Forgot password?</a></div>` })}<div id="caps"></div><button class="btn primary" style="margin-top:12px">Log in</button></form>
        <div class="or">or</div><button class="btn ghost" type="button" id="usecode">${I.mail}Email me a sign-in code</button><button class="btn ghost" type="button" data-passkey>${I.key}Use a passkey</button></div>`,
      mount: () => { setTimeout(() => $('#password').focus(), 30); $('#usecode').onclick = async (ev) => { const b = ev.currentTarget; busy(b, true, 'Sending…'); try { await sendCode(); go('code'); } catch (err) { busy(b, false); showErr(err); } }; },
      submit: async (f, btn) => {
        const p = $('#password').value; if (!p) return setMsg('password', 'bad', 'Enter your password.');
        busy(btn, true, 'Logging in…');
        try { const me = await api('POST', '/v1/auth/login', { email: st.email, password: p, remember: true }); if (me.mfa_required) return go('mfa'); afterAuth(me); }
        catch (err) { busy(btn, false); if (err.code === 'login_locked') { banner(esc(err.message) + ' <a href="#" id="lockcode">Email me a code</a>'); const l = $('#lockcode'); if (l) l.onclick = async (ev) => { ev.preventDefault(); try { await sendCode(); go('code'); } catch (e2) { showErr(e2); } }; } else showErr(err, { password: 'password', email: 'password' }); }
      },
    }),
    code: () => ({
      html: `${head('Check your email', `We sent a 6-digit sign-in code to <b>${esc(st.email)}</b>. It expires in 10 minutes.`)}<div class="au-body">${gmsg}${otp()}<div class="msg-slot" id="otpmsg"></div>${mailApps()}<p class="resend">Didn’t get it? Check spam, or <button type="button" id="resend">resend code</button></p><button class="btn ghost" type="button" data-view="${(st.methods || []).includes('password') ? 'password' : 'email'}">${I.back}Back</button></div>`,
      mount: () => {
        otpBind(async (code) => { try { const me = await api('POST', '/v1/auth/code/verify', { email: st.email, code }); otpOk(); await wait(350); if (me.mfa_required) return go('mfa'); afterAuth(me); } catch (err) { otpErr(esc(err.message)); } });
        cooldown(() => sendCode());
      },
    }),
    mfa: () => ({
      html: `${head('Two-step verification', 'Enter the 6-digit code from your authenticator app.')}<div class="au-body">${gmsg}<div id="mfabox">${otp()}</div><div class="msg-slot" id="otpmsg"></div><label class="legal" style="text-align:left;display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" id="trust" checked style="accent-color:var(--accent)"> Trust this device for 30 days</label><div class="alt"><button class="btn link" type="button" id="recov">Use a recovery code instead</button></div></div>`,
      mount: () => {
        const done = (me) => afterAuth(me);
        const expired = (err) => { if (err.code === 'mfa_expired' || err.code === 'mfa_locked') { flash(err.message); setTimeout(() => go(st.email ? 'password' : 'email'), 900); return true; } return false; };
        otpBind(async (code) => { try { const me = await api('POST', '/v1/auth/two-factor', { code, trust_device: $('#trust').checked }); otpOk(); await wait(300); done(me); } catch (err) { if (!expired(err)) otpErr(esc(err.message)); } });
        $('#recov').onclick = () => {
          $('#mfabox').innerHTML = `<form id="rcf" novalidate>${field('rc', 'Recovery code', 'text', { ph: 'xxxxx-xxxxx', ac: 'one-time-code' })}<button class="btn primary" style="margin-top:12px">Verify</button></form>`;
          $('#recov').remove(); setTimeout(() => $('#rc').focus(), 30);
          $('#rcf').onsubmit = async (e) => { e.preventDefault(); const btn = $('#rcf button.primary'); const v = $('#rc').value.trim(); if (!v) return setMsg('rc', 'bad', 'Enter one of your recovery codes.'); busy(btn, true); try { done(await api('POST', '/v1/auth/two-factor', { recovery_code: v, trust_device: $('#trust').checked })); } catch (err) { busy(btn, false); if (!expired(err)) showErr(err, { recovery_code: 'rc' }); } };
        };
      },
    }),
    forgot: () => ({
      html: `${head('Reset your password', 'Enter your email and we’ll send you a link to choose a new password.')}<div class="au-body">${gmsg}<form id="frm" novalidate>${field('femail', 'Email', 'email', { ac: 'username', ph: 'you@company.com', val: st.email })}<button class="btn primary" style="margin-top:12px">Send reset link</button></form><button class="btn ghost" type="button" data-view="email">${I.back}Back to log in</button></div>`,
      submit: async (f, btn) => { const e = $('#femail').value.trim(); if (!isEmail(e)) return setMsg('femail', 'bad', 'Enter a valid email address.'); st.email = e.toLowerCase(); busy(btn, true); try { await api('POST', '/v1/auth/password/forgot', { email: e }); go('forgot-sent'); } catch (err) { busy(btn, false); showErr(err, { email: 'femail' }); } },
    }),
    'forgot-sent': () => ({ html: `${head('Check your email', `If an account exists for <b>${esc(st.email)}</b>, a reset link is on its way. It works for 30 minutes.`)}<div class="au-body">${gmsg}${mailApps()}<p class="resend">Nothing after a few minutes? <button type="button" id="resend">Send again</button></p><button class="btn ghost" type="button" data-view="email">${I.back}Back to log in</button></div>`, mount: () => cooldown(() => api('POST', '/v1/auth/password/forgot', { email: st.email })) }),
    reset: () => ({
      html: `${head('Choose a new password', 'You’ll be signed out on all other devices.')}<div class="au-body">${gmsg}<form id="frm" novalidate>${pwField('npw', 'New password')}<button class="btn primary" style="margin-top:12px">Update password</button></form></div>`,
      mount: () => pwBind('npw'),
      submit: async (f, btn) => { if (!pwOk('npw')) return; busy(btn, true); try { await api('POST', '/v1/auth/password/reset', { email: qs.get('email') || st.email, token: qs.get('reset'), password: $('#npw').value }); go('reset-done'); } catch (err) { busy(btn, false); if (err.code === 'reset_invalid') banner(esc(err.message) + ' <a href="#" data-view="forgot">Request a new link</a>'); else showErr(err, { password: 'npw' }); $$('#gmsg [data-view]').forEach((el) => el.addEventListener('click', (ev) => { ev.preventDefault(); go(el.dataset.view); })); } },
    }),
    'reset-done': () => ({ html: `${head('Password updated', 'Log in with your new password.')}<div class="au-body"><a class="btn primary" href="/login/">Log in</a></div>` }),

    // signup
    closed: () => ({ html: `${head('Sign-up is closed', 'This ' + esc(B()) + ' is private. Ask its admin to give you access.')}<div class="au-body">${gmsg}<a class="btn primary" href="/login/">Log in</a></div>` }),
    signup: () => (st.setup && !st.setup.signup_open && !st.invite ? V.closed() : {
      html: `${head(st.invite ? 'Create your account to join' : st.setup && st.setup.first_run ? 'Create the admin account' : 'Create your ' + esc(B()) + ' account', st.invite ? `You’ll join ${esc(st.invite.workspace)} as soon as your email is verified.` : st.setup && st.setup.first_run ? 'This is the first account on this ' + esc(B()) + '. It runs every workspace you create.' : 'Free for 14 days. No card needed.')}<div class="au-body">${gmsg}${inviteBanner()}${social('Sign up', 'or with email')}<form id="frm" novalidate>${field('name', 'Full name', 'text', { ac: 'name', ph: 'Ayodele Akintomide', val: st.name })}<div style="height:12px"></div>${field('semail', 'Work email', 'email', { ac: 'email', ph: 'you@company.com', val: st.email })}<div style="height:12px"></div>${pwField('spw', 'Password')}<button class="btn primary" style="margin-top:14px">Create account</button></form>${legal()}</div>`,
      mount: () => { pwBind('spw'); $('#semail').addEventListener('blur', () => { const e = $('#semail').value.trim(); if (!e) return setMsg('semail'); if (!isEmail(e)) return setMsg('semail', 'bad', 'That doesn’t look like an email address.'); const fix = e.replace(/gmial|gmal/i, 'gmail').replace(/yaho\b/i, 'yahoo').replace(/hotmial/i, 'hotmail').replace(/outlok/i, 'outlook'); if (fix !== e) return setMsg('semail', 'warn', `Did you mean <a href="#" data-fix="${esc(fix)}">${esc(fix)}</a>?`); if (FREE.test(e)) return setMsg('semail', 'info', 'You can sign up with this. To send campaigns you’ll need a domain you own, like you@yourcompany.com.'); setMsg('semail'); }); },
      submit: async (f, btn) => {
        const n = $('#name').value.trim(), e = $('#semail').value.trim(); let ok = true;
        if (n.length < 2) { setMsg('name', 'bad', 'Tell us your name.'); ok = false; } else setMsg('name');
        if (!isEmail(e)) { setMsg('semail', 'bad', 'Enter a valid email address.'); ok = false; }
        if (!pwOk('spw')) ok = false; if (!ok) return;
        st.name = n; st.email = e.toLowerCase(); remember(); busy(btn, true, 'Creating account…'); banner('');
        let tz = 'UTC'; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) {}
        try { const me = await api('POST', '/v1/auth/signup', { name: n, email: e, password: $('#spw').value, timezone: tz }); if (me.user && me.user.email_verified) return afterAuth(me); st.delivered = me.code_delivered !== false; go('verify'); }
        catch (err) { busy(btn, false); if (err.code === 'signup_closed') { st.setup = Object.assign({}, st.setup, { signup_open: false }); return go('signup'); } if (err.code === 'email_taken') setMsg('semail', 'bad', 'There’s already an account with this email. <a href="/login/">Log in instead</a>'); else showErr(err, { name: 'name', email: 'semail', password: 'spw', timezone: 'name' }); }
      },
    }),
    verify: () => ({
      html: `<div class="steps-dots"><i class="on"></i><i></i><i></i></div>${head('Verify your email', `Enter the 6-digit code we sent to <b>${esc(st.email)}</b>.`)}<div class="au-body">${gmsg}${otp()}<div class="msg-slot" id="otpmsg"></div>${mailApps()}<p class="resend">Didn’t get it? Check spam, or <button type="button" id="resend">resend code</button></p><button class="btn ghost" type="button" id="diffemail">${I.back}Use a different email</button></div>`,
      mount: () => {
        if (st.delivered === false) banner('We couldn’t deliver the code to this address. Check that it’s correct, or resend.', 'warn');
        otpBind(async (code) => { try { const me = await api('POST', '/v1/auth/email/verify', { code }); otpOk(); await wait(350); afterAuth(me); } catch (err) { if (err.status === 401) return go('signup'); otpErr(esc(err.message)); } });
        cooldown(() => api('POST', '/v1/auth/email/resend'));
        $('#diffemail').onclick = async () => { try { await api('POST', '/v1/auth/logout'); } catch (_) {} st.email = ''; go('signup'); };
      },
    }),
    workspace: () => {
      const dom = (st.email.split('@')[1] || '').split('.')[0], company = FREE.test(st.email) ? '' : dom.charAt(0).toUpperCase() + dom.slice(1);
      return {
        html: `<div class="steps-dots"><i class="on"></i><i class="on"></i><i></i></div>${head('Name your workspace', 'Usually your company or brand. You can invite your team next.')}<div class="au-body">${gmsg}<form id="frm" novalidate>${field('ws', 'Workspace name', 'text', { ph: 'Acme Inc', val: st.ws || company, ac: 'organization' })}<div style="height:12px"></div><div class="fld" id="f-slug"><label for="slug">Workspace URL</label><div class="slug"><span>${esc(location.host)}/</span><input id="slug" value="${esc(st.slug || company.toLowerCase().replace(/[^a-z0-9]+/g, '-'))}" spellcheck="false" autocapitalize="off"></div><div class="msg-slot" id="slugmsg"></div></div><button class="btn primary" style="margin-top:14px">Continue</button></form></div>`,
        mount: () => {
          const ws = $('#ws'), sl = $('#slug'); let touched = !!st.slug, t = null, seq = 0;
          const check = () => { clearTimeout(t); const v = sl.value; st.slugOk = false; if (!v) { $('#slugmsg').innerHTML = ''; return; } $('#slugmsg').innerHTML = `<div class="msg info">${I.info}<span>Checking…</span></div>`; const my = ++seq; t = setTimeout(async () => { try { const r = await api('GET', '/v1/workspaces/slug-check?slug=' + encodeURIComponent(v)); if (my !== seq) return; st.slugOk = r.available; $('#slugmsg').innerHTML = r.available ? `<div class="msg ok">${I.check}<span>Available</span></div>` : `<div class="msg bad">${I.alert}<span>${esc(r.reason)}</span></div>`; } catch (err) { if (my === seq) $('#slugmsg').innerHTML = `<div class="msg bad">${I.alert}<span>${esc(err.message)}</span></div>`; } }, 350); };
          ws.oninput = () => { if (!touched) sl.value = ws.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); check(); };
          sl.oninput = () => { touched = true; sl.value = sl.value.toLowerCase().replace(/[^a-z0-9-]/g, ''); check(); };
          check(); setTimeout(() => ws.focus(), 30);
        },
        submit: async (f, btn) => { const w = $('#ws').value.trim(); if (w.length < 2) return setMsg('ws', 'bad', 'Give your workspace a name.'); if (!st.slugOk) { $('#slug').focus(); return; } st.ws = w; st.slug = $('#slug').value; go('source'); },
      };
    },
    source: () => ({
      html: `<div class="steps-dots"><i class="on"></i><i class="on"></i><i class="on"></i></div>${head('How will you send?', 'We’ll set up the right things first. You can change this later.')}<div class="au-body">${gmsg}<div class="choice">${[['ses', I.ses, 'My own Amazon SES', 'Bring your AWS account and pay AWS prices'], ['smtp', I.mail || I.ses, 'SMTP or another provider', 'SendGrid, Mailgun, Brevo, Postmark, Gmail, Microsoft 365…'], ['migrate', I.swap, 'Move from another provider', 'Bring contacts and lists from Mailchimp, Klaviyo…'], ['explore', I.spark, 'Just exploring', 'Show me around with sample data']].map(([k, ic, a, b], i) => `<button type="button" class="ch ${i ? '' : 'on'}" data-src="${k}"><span class="ci">${ic}</span><span><b>${a}</b><small>${b}</small></span></button>`).join('')}</div><button class="btn primary" id="finish" style="margin-top:6px">Open ${esc(B())}</button><button class="btn ghost" type="button" data-view="workspace">${I.back}Back</button></div>`,
      mount: () => {
        $$('[data-src]').forEach((b) => b.onclick = () => $$('[data-src]').forEach((x) => x.classList.toggle('on', x === b)));
        $('#finish').onclick = async (e) => { const btn = e.currentTarget, s = $('.ch.on').dataset.src; busy(btn, true, 'Setting up your workspace…'); try { await api('POST', '/v1/workspaces', { name: st.ws, slug: st.slug, send_source: s }); location.href = APP + ({ ses: '#/setup?p=ses', smtp: '#/setup?p=smtp', migrate: '#/migrate', explore: '#/overview' })[s]; } catch (err) { busy(btn, false); if (err.code === 'slug_unavailable') { st.slugOk = false; go('workspace'); setTimeout(() => setMsg('slug', 'bad', esc(err.message)), 50); } else showErr(err); } };
      },
    }),
    invites: () => {
      const inv = (st.me && st.me.invitations) || [];
      return {
        html: `${head('Join a workspace', 'You’ve been invited. Join one, or create your own.')}<div class="au-body">${gmsg}<div class="choice">${inv.map((i) => `<button type="button" class="ch" data-join="${esc(i.id)}"><span class="ci">${I.users}</span><span><b>${esc(i.workspace)}</b><small>${esc(i.invited_by || 'Someone')} invited you as ${esc(i.role)}</small></span></button>`).join('')}</div><button class="btn ghost" type="button" data-view="workspace">Create my own workspace instead</button></div>`,
        mount: () => $$('[data-join]').forEach((b) => b.onclick = async () => { busy(b, true, 'Joining…'); try { await api('POST', '/v1/invitations/accept', { id: b.dataset.join }); go('done'); } catch (err) { busy(b, false); showErr(err); } }),
      };
    },
    done: () => ({ html: `${head('You’re in', 'Taking you to your workspace…')}<div class="au-body"><button class="btn primary" disabled><span class="spin"></span>Opening ${esc(B())}</button></div>`, mount: async () => { await wait(300); const n = qs.get('next'); location.href = n && /^\/app\//.test(n) ? n : APP; } }),
  };
  let tries = 0;
  const sendCode = () => api('POST', '/v1/auth/code/send', { email: st.email });

  // password field + NIST-style strength (length first, breached list, no composition rules)
  const pwField = (id, label) => field(id, label, 'password', { ac: 'new-password', ph: 'At least 12 characters' }).replace('<div class="msg-slot"></div>', `<div class="pw-meter" id="m-${id}"><i></i><i></i><i></i><i></i></div><div class="msg-slot"></div>`);
  const score = (p, email) => { if (!p) return [0, '']; const lower = p.toLowerCase(); if (BREACHED.includes(lower) || (email && lower.includes(email.split('@')[0].toLowerCase()) && email.split('@')[0].length > 3)) return [1, 'bad|This password appears in known data breaches or contains your email. Choose another.']; if (p.length < 12) return [1, `info|${12 - p.length} more character${12 - p.length > 1 ? 's' : ''}. Long phrases are easier to remember than symbols.`]; const v = new Set(p).size; const s = p.length >= 20 || (p.length >= 15 && v >= 8) ? 4 : p.length >= 15 || v >= 9 ? 3 : 2; return [s, s >= 3 ? 'ok|Strong password.' : 'info|Good. A few more characters make it much stronger.']; };
  function pwBind(id) { const inp = $('#' + id); inp.addEventListener('input', () => { const [s, m] = score(inp.value, st.email || ($('#semail') || {}).value); $('#m-' + id).className = 'pw-meter s' + s; const [k, t] = m.split('|'); setMsg(id, k === 'bad' ? 'warn' : k, t); }); }
  function pwOk(id) { const v = $('#' + id).value, [s, m] = score(v, st.email || ($('#semail') || {}).value); if (v.length < 12) { setMsg(id, 'bad', 'Use at least 12 characters.'); return false; } if (s <= 1) { setMsg(id, 'bad', m.split('|')[1]); return false; } if (v.length > 128) { setMsg(id, 'bad', 'Use 128 characters or fewer.'); return false; } return true; }

  // OTP behaviour: auto-advance, backspace, paste, auto-submit
  let otpDone = null;
  function otpBind(cb) {
    otpDone = cb; const boxes = $$('#otp input'); boxes[0].focus();
    const val = () => boxes.map((b) => b.value).join('');
    const check = () => { const v = val(); if (v.length === 6) otpDone(v); };
    boxes.forEach((b, i) => {
      b.addEventListener('input', () => { b.value = b.value.replace(/\D/g, '').slice(-1); $('#otp').classList.remove('err'); if (b.value && boxes[i + 1]) boxes[i + 1].focus(); check(); });
      b.addEventListener('keydown', (e) => { if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].focus(); boxes[i - 1].value = ''; } if (e.key === 'ArrowLeft' && boxes[i - 1]) boxes[i - 1].focus(); if (e.key === 'ArrowRight' && boxes[i + 1]) boxes[i + 1].focus(); });
      b.addEventListener('paste', (e) => { const t = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6); if (!t) return; e.preventDefault(); t.split('').forEach((c, k) => boxes[k] && (boxes[k].value = c)); (boxes[t.length] || boxes[5]).focus(); check(); });
    });
  }
  const otpErr = (t) => { const o = $('#otp'); o.classList.add('err'); $('#otpmsg').innerHTML = `<div class="msg bad">${I.alert}<span>${t}</span></div>`; $$('#otp input').forEach((b) => (b.value = '')); $$('#otp input')[0].focus(); };
  const otpOk = () => { $('#otp').classList.add('ok'); $('#otpmsg').innerHTML = `<div class="msg ok">${I.check}<span>Verified</span></div>`; };
  function cooldown(resendFn) {
    const b = $('#resend'); if (!b) return; let s = 30; b.dataset.t = b.textContent;
    const tick = () => { if (!document.body.contains(b)) return; if (s <= 0) { b.disabled = false; b.textContent = b.dataset.t; return; } b.disabled = true; b.textContent = `resend in ${s}s`; s--; setTimeout(tick, 1000); };
    tick();
    b.onclick = async () => { b.disabled = true; try { if (resendFn) await resendFn(); flash('Sent. Check your inbox'); s = 30; } catch (err) { s = err.retry_after || 30; if (err.code !== 'code_cooldown') flash(err.message || 'Couldn’t send. Try again'); } tick(); };
  }
  const remember = () => { try { localStorage.setItem('sl-last-email', st.email); } catch (_) {} };
  const flash = (t) => { let el = $('#flash'); if (!el) { el = document.createElement('div'); el.id = 'flash'; el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#18181B;color:#fff;padding:10px 16px;border-radius:10px;font-size:13.5px;font-weight:600;z-index:9;box-shadow:0 10px 30px rgba(0,0,0,.25)'; document.body.appendChild(el); } el.textContent = t; el.style.opacity = 1; clearTimeout(el._t); el._t = setTimeout(() => (el.style.opacity = 0), 2200); };

  // ─── Passkeys (WebAuthn). The API speaks base64url; the browser wants ArrayBuffers. ───
  const b2u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const u2b = (str) => Uint8Array.from(atob(str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4)), (c) => c.charCodeAt(0)).buffer;
  const passkeyOK = () => !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get);
  let pkAbort = null;
  async function passkeyLogin(conditional, btn) {
    if (!passkeyOK()) return conditional ? null : banner('This browser doesn’t support passkeys. Use your password or an email code.', 'info');
    if (pkAbort) pkAbort.abort();
    const ctl = (pkAbort = new AbortController());
    if (btn) busy(btn, true, 'Waiting for your device…');
    try {
      const pk = (await api('POST', '/v1/auth/passkey/options')).publicKey;
      pk.challenge = u2b(pk.challenge);
      (pk.allowCredentials || []).forEach((c) => { c.id = u2b(c.id); });
      const cred = await navigator.credentials.get(Object.assign({ publicKey: pk, signal: ctl.signal }, conditional ? { mediation: 'conditional' } : {}));
      const r = cred.response;
      const me = await api('POST', '/v1/auth/passkey', { id: b2u(cred.rawId), client_data_json: b2u(r.clientDataJSON), authenticator_data: b2u(r.authenticatorData), signature: b2u(r.signature) });
      if (pkAbort === ctl) pkAbort = null;
      st.email = me.user.email; remember();
      afterAuth(me);
    } catch (err) {
      if (pkAbort === ctl) pkAbort = null;
      busy(btn, false);
      if (ctl.signal.aborted || (conditional && err && err.name)) return; // replaced by another attempt, or autofill dismissed
      if (err && err.name === 'NotAllowedError') return banner('Passkey sign-in was cancelled or timed out. Try again, or use another way.', 'info');
      if (err && err.name) return banner('Your device couldn’t use a passkey here. Use your password or an email code.', 'info');
      showErr(err);
    }
  }

  // ─── Router ───
  function go(view) {
    if (pkAbort) { pkAbort.abort(); pkAbort = null; }
    st.view = view; const v = V[view](); card.innerHTML = `<div class="fade">${v.html}</div>`;
    const frm = $('#frm'); if (frm) frm.onsubmit = (e) => { e.preventDefault(); v.submit && v.submit(frm, $('button.primary', frm)); };
    $$('[data-view]', card).forEach((el) => el.addEventListener('click', (e) => { e.preventDefault(); go(el.dataset.view); }));
    $$('[data-oauth]', card).forEach((b) => b.onclick = () => {
      const p = b.dataset.oauth;
      if (st.providers && !st.providers[p]) return banner(`${PROV[p]} sign-in isn’t switched on yet. Use your email for now.`, 'info');
      try { localStorage.setItem('sl-last-provider', p); } catch (_) {}
      busy(b, true, `Opening ${PROV[p]}…`);
      const q = new URLSearchParams({ intent: mode === 'signup' ? 'signup' : 'login' });
      if (st.invite && st.invite.token) q.set('invite', st.invite.token);
      if (qs.get('next')) q.set('next', qs.get('next'));
      location.href = `/api/v1/auth/oauth/${p}/redirect?${q}`;
    });
    $$('[data-passkey]', card).forEach((b) => b.onclick = () => passkeyLogin(false, b));
    // Passkey autofill: the browser offers saved passkeys right in the email field (WebAuthn conditional mediation).
    if (view === 'email' && mode !== 'signup' && passkeyOK() && PublicKeyCredential.isConditionalMediationAvailable) PublicKeyCredential.isConditionalMediationAvailable().then((ok) => ok && st.view === 'email' && passkeyLogin(true)).catch(() => {});
    $$('[data-eye]', card).forEach((b) => b.onclick = () => { const i = $('#' + b.dataset.eye); const show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.innerHTML = show ? I.eyeOff : I.eye; b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); i.focus(); });
    $$('input[type=password]', card).forEach((i) => i.addEventListener('keyup', (e) => { const c = $('#caps') || i.closest('.fld').querySelector('.msg-slot'); if (e.getModifierState && e.getModifierState('CapsLock')) { if ($('#caps')) $('#caps').innerHTML = `<div class="msg warn">${I.alert}<span>Caps Lock is on</span></div>`; } else if ($('#caps')) $('#caps').innerHTML = ''; void c; }));
    card.addEventListener('click', (e) => { const fx = e.target.closest('[data-fix]'); if (fx) { e.preventDefault(); $('#semail').value = fx.dataset.fix; setMsg('semail'); } }, { once: true });
    const first = $('input:not([readonly])', card); if (first && !v.mount) setTimeout(() => first.focus(), 30);
    v.mount && v.mount();
    document.title = (mode === 'signup' ? 'Sign up' : 'Log in') + ' · ' + B();
  }

  // ─── Right panel: the product, live ───
  function art() {
    const box = $('#art'); if (!box) return;
    const b = brand();
    if (b.custom) { // a renamed installation: its own name, not MailVesper's marketing
      box.innerHTML = `<div class="art-brand">${b.logo_dark || b.logo ? `<img src="${esc(b.logo_dark || b.logo)}" alt="">` : `<b>${esc(B())}</b>`}<p>Email campaigns, automations and delivery insights, in one place.</p></div>`;
      return;
    }
    if (mode === 'signup') {
      box.innerHTML = `<div class="art-head"><h2>Your email, <em>handled</em>.<br>Your SES, your prices.</h2><p>Connect your own Amazon SES and get campaigns, automations, a CRM and an AI agent that does the work for you.</p></div>
        <div class="art-stage"><div class="vp">${[[I.ses, 'Bring your own Amazon SES', 'Pay AWS about $0.10 per 1,000 emails. No markup on sending.'], [I.spark, 'An agent that does the work', '“Send a sale email to last month’s buyers.” Done, with your approval.'], [I.check, 'See every email’s journey', 'Delivered, opened, bounced, and why. In plain English.']].map(([ic, a, b]) => `<div><span>${ic}</span><div><b>${a}</b><small>${b}</small></div></div>`).join('')}</div></div>
        <div class="art-proof"><div><b>$255/mo</b>average saved vs Mailchimp</div><div><b>2 min</b>to connect SES</div><div><b>39</b>ready-made templates</div></div>`;
      return;
    }
    box.innerHTML = `<div class="art-head"><h2>Tell it what you need.<br><em>MailVesper does the rest.</em></h2><p>Campaigns, automations and deliverability, run by an agent on your own Amazon SES.</p></div>
      <div class="art-stage"><div class="bub" id="bub"></div><div class="ans" id="ans"><img src="/auth/logo-mark.svg" alt=""><div class="ans-card"><div class="tt"><i>✓</i>Campaign scheduled</div><div class="row"><span>Audience</span><b>Bought in last 90 days · 2,140</b></div><div class="row"><span>Sends</span><b>Tue 3 Nov, 09:30</b></div><div class="row"><span>Checks</span><b>7 of 7 pass</b></div></div></div><div class="feed" id="feed"></div></div>`;
    const prompt = 'Send a Black Friday email to everyone who bought in the last 90 days', bub = $('#bub');
    let i = 0; const type = () => { if (!document.body.contains(bub)) return; bub.innerHTML = esc(prompt.slice(0, i)) + '<span class="cur"></span>'; if (i++ < prompt.length) setTimeout(type, 28); else setTimeout(() => $('#ans').classList.add('on'), 400); }; setTimeout(type, 600);
    const C = { delivered: '#4ADE80', opened: '#60A5FA', clicked: '#C084FC', deferred: '#FBBF24' };
    const people = ['amara.okafor@gmail.com', 'liam.s@outlook.com', 'sofia@rossi.it', 'kwame.m@yahoo.com', 'mei.t@icloud.com', 'tunde@lumen.co', 'zara.b@proton.me', 'nia.n@gmail.com'];
    const sts = [['delivered', 2], ['opened', 3], ['clicked', 4], ['delivered', 2], ['opened', 3], ['deferred', 1]];
    let k = 0; const feed = $('#feed');
    const add = () => { if (!document.body.contains(feed)) return; const [s, n] = sts[k % sts.length], code = 'SL-' + Math.random().toString(16).slice(2, 6).toUpperCase() + '-' + Math.random().toString(16).slice(2, 6).toUpperCase(); const row = document.createElement('div'); row.className = 'fr'; row.style.setProperty('--c', C[s]); row.innerHTML = `<span class="route">${[0, 1, 2, 3].map((x) => `<i class="${x < n ? 'on' : ''}"></i>`).join('')}</span><span class="to">${people[k % people.length]}<br><span class="code">${code}</span></span><span class="st">${s[0].toUpperCase() + s.slice(1)}</span>`; feed.prepend(row); while (feed.children.length > 3) feed.lastChild.remove(); k++; setTimeout(add, 2200); };
    setTimeout(add, 3800);
  }

  // ─── Boot: resume an existing session where it left off ───
  if (qs.get('email')) st.email = qs.get('email');
  if (BRAND0) paintBrand();
  const setupP = api('GET', '/v1/auth/setup').catch(() => null);
  setupP.then((r) => {
    if (r) { st.setup = st.setup || r; try { localStorage.setItem('sl-brand', JSON.stringify(r.brand || { name: 'MailVesper' })); } catch (e) {} }
    // "Create an account" only where sign-up is open (Pro SaaS). Community: the installer creates the admin, nobody signs up.
    const sw = document.querySelector('.au-switch'); if (sw && mode === 'login' && r && r.signup_open) sw.hidden = false;
    paintBrand(); art();
  });
  setTimeout(() => document.body.classList.add('branded'), 1500); // never hide the logo for long if the server is slow
  (async () => {
    if (qs.get('reset')) return go('reset');
    [st.providers, st.setup] = await Promise.all([api('GET', '/v1/auth/providers').catch(() => ({})), setupP]);
    footLinks();
    if (st.setup && st.setup.first_run && !st.setup.signup_open) { // no admin yet: the installer makes it, not this page
      card.innerHTML = `${head('Finish installing ' + esc(B()), 'This installation has no admin account yet. Open <b>/install/</b> on this site to use the browser installer, or run <code>php artisan mailvesper:install</code> on the server.')}`;
      return;
    }
    if (qs.get('step') === 'mfa') { if (qs.get('invite')) { try { const inv = await api('GET', '/v1/invitations/' + encodeURIComponent(qs.get('invite'))); st.invite = Object.assign({ token: qs.get('invite') }, inv); } catch (_) {} } return go('mfa'); }
    card.innerHTML = '<div style="display:grid;place-items:center;min-height:200px"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span></div>';
    if (qs.get('invite')) {
      try { const inv = await api('GET', '/v1/invitations/' + encodeURIComponent(qs.get('invite'))); st.invite = Object.assign({ token: qs.get('invite') }, inv); st.email = inv.email; }
      catch (_) { st.invite = null; }
    }
    try {
      const me = await api('GET', '/v1/me');
      st.email = me.user.email; st.name = me.user.name; st.me = me;
      if (qs.get('new') === 'workspace' && me.user.email_verified) return go('workspace');
      return afterAuth(me);
    } catch (_) { /* not signed in */ }
    if (st.invite && st.invite.account_exists) go('email'); // they already have an account: log in to accept
    else if (mode === 'signup' && st.setup && !st.setup.signup_open && !st.invite) return location.replace('/login/' + (st.email ? '?email=' + encodeURIComponent(st.email) : '')); // no sign-up here
    else go(mode === 'signup' ? 'signup' : 'email');
    const oe = qs.get('oauth_error'), pv = PROV[qs.get('provider')] || 'That provider';
    const OAUTH_ERRORS = {
      not_configured: [`${pv} sign-in isn’t switched on yet. Use your email for now.`, 'info'],
      cancelled: [`Sign-in with ${pv} was cancelled.`, 'info'],
      invalid_state: ['That sign-in attempt expired. Please try again.', 'warn'],
      provider_error: [`${pv} didn’t respond correctly. Try again, or use your email.`, 'bad'],
      no_email: [`${pv} didn’t share your email address. Use your email to continue.`, 'bad'],
      link_requires_login: [`There’s already a ${B()} account with that email. Log in with your password or an email code first; you can connect ${pv} afterwards.`, 'warn'],
      invitation_wrong_account: ['That invitation was sent to a different email. Log in with the invited email to accept it.', 'warn'],
      invitation_invalid: ['That invitation has expired or was already used. Ask for a new one.', 'warn'],
      signup_closed: ['Sign-up is closed on this ' + B() + '. Ask its admin to give you access.', 'warn'],
    };
    if (oe && OAUTH_ERRORS[oe]) banner(...OAUTH_ERRORS[oe]);
  })();
})();
