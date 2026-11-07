/* MailVesper: design prototype, core.
   Identity: "The Line". Every email travels Sent → Delivered → Opened → Clicked.
   This file: shell, component system (modal/wizard/confirm, menu, command
   palette, drawer, toast), route visuals, and the delivery screens.
   More screens are in pages.js. All data is seeded sample data. */
(function () {
  'use strict';
  const SL = (window.SL = { pages: {} });

  // ── Seeded RNG ──
  let seed = 20261022;
  const rnd = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
  const pick = (a) => a[Math.floor(rnd() * a.length)];
  const between = (a, b) => a + rnd() * (b - a);
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Math.round(n).toLocaleString('en-US');
  const pct = (n, d = 1) => (n * 100).toFixed(d) + '%';

  // ── Icons ──
  const P = {
    overview: '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
    activity: '<circle cx="5" cy="12" r="2"/><circle cx="19" cy="12" r="2"/><path d="M7 12h10"/><circle cx="12" cy="12" r="2"/>',
    palette: '<circle cx="13.5" cy="6.5" r="1.2"/><circle cx="17.5" cy="10.5" r="1.2"/><circle cx="8.5" cy="7.5" r="1.2"/><circle cx="6.5" cy="12.5" r="1.2"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.3A5.7 5.7 0 0 0 22 9.8C22 5.5 17.5 2 12 2z"/>',
    sparkles: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    database: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.4 3.3-5.5 6.5-5.5s5.9 2.1 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
    key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L20 3"/><path d="M16 7l3 3"/><path d="M18 5l2 2"/>',
    layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    contacts: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    campaigns: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    automations: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M6 9v3a3 3 0 0 0 3 3h6"/>',
    templates: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
    forms: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8"/><rect x="8" y="15" width="5" height="2.5" rx="1"/>',
    domains: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
    suppressions: '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    keys: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
    webhooks: '<path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>',
    setup: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    integrations: '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    moon: '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    send: '<path d="m22 2-7 20-4-9-9-4 20-7z"/><path d="M22 2 11 13"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    chev: '<path d="m7 15 5 5 5-5M7 9l5-5 5 5"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    spark: '<path d="M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
    trash: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    alert: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    filter: '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
    zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    split: '<path d="M16 3h5v5M8 3H3v5M21 3l-7.5 7.5M3 3l7.5 7.5M12 12v9"/>',
    pause: '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    edit: '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    card: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    team: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
    code: '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    tag: '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/>',
    bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    copilot: '<path d="M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    ses: '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/>',
    migrate: '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  };
  const ic = (n, s = 17, w = 1.8) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round">${P[n] || ''}</svg>`;
  // The operator's brand (Platform → Branding): cached in the browser so it shows at once, refreshed on every load.
  const BRAND = (() => { try { return Object.assign({ name: 'MailVesper' }, JSON.parse(localStorage.getItem('sl-brand') || '{}')); } catch (e) { return { name: 'MailVesper' }; } })();
  const brandName = () => BRAND.name || 'MailVesper';
  const isDark = () => document.documentElement.dataset.theme === 'dark';
  const brandHtml = () => {
    const logo = (isDark() && BRAND.logo_dark) || BRAND.logo;
    if (logo) return `<img class="sb-logo" src="${esc(logo)}" alt="${esc(brandName())}">`;
    return `${BRAND.icon ? `<img class="mark" src="${esc(BRAND.icon)}" width="28" height="28" alt="">` : LOGO(28)}<span class="word">${esc(brandName())}</span>`;
  };
  const paintBrand = () => {
    const b = document.querySelector('.sidebar .brand'); if (b) b.innerHTML = brandHtml();
    const fav = document.querySelector('link[rel="icon"]'); if (fav && BRAND.icon) { fav.type = 'image/png'; fav.href = BRAND.icon; }
    const root = document.documentElement.style, c = BRAND.accent;
    if (c && /^#[0-9a-f]{6}$/i.test(c)) {
      root.setProperty('--accent', c); root.setProperty('--signal', c);
      root.setProperty('--accent-hover', `color-mix(in srgb, ${c} 86%, #000)`);
      root.setProperty('--accent-soft', `color-mix(in srgb, ${c} 14%, transparent)`); root.setProperty('--signal-soft', `color-mix(in srgb, ${c} 14%, transparent)`);
      root.setProperty('--accent-text', `color-mix(in srgb, ${c} 78%, var(--text))`); root.setProperty('--signal-text', `color-mix(in srgb, ${c} 78%, var(--text))`);
    } else ['--accent', '--signal', '--accent-hover', '--accent-soft', '--signal-soft', '--accent-text', '--signal-text'].forEach((v) => root.removeProperty(v));
    const t = document.title.replace(/ · [^·]+$/, ''); document.title = t && t !== document.title ? t + ' · ' + brandName() : brandName();
  };
  SL.brand = () => BRAND;
  SL.brandName = brandName;
  window.SL_BN = brandName;
  SL.refreshBrand = () => fetch('/api/v1/auth/setup', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json()).then((r) => {
    if (!r || !r.brand) return;
    Object.keys(BRAND).forEach((k) => delete BRAND[k]); Object.assign(BRAND, r.brand);
    try { localStorage.setItem('sl-brand', JSON.stringify(r.brand)); } catch (e) {}
    paintBrand();
  }).catch(() => {});

  const LOGO = (s = 28) => `<svg class="mark" width="${s}" height="${s}" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#17160F"/><path d="M7.5 22.5c5.5 0 5-8.5 10.5-8.5h4.5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M19.8 10.4 23.6 14l-3.8 3.6" fill="none" stroke="#FF5B1F" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7.5" cy="22.5" r="2.4" fill="#FF5B1F"/></svg>`;

  // ── Sample data ──
  const FIRST = ['Amara', 'Liam', 'Sofia', 'Noah', 'Chen', 'Fatima', 'Lucas', 'Maya', 'Tunde', 'Elena', 'Kofi', 'Hana', 'Jonas', 'Priya', 'Mateo', 'Aisha', 'Oliver', 'Zara', 'Ethan', 'Nia'];
  const LAST = ['Okafor', 'Schmidt', 'Rossi', 'Kim', 'Haddad', 'Nguyen', 'Silva', 'Brown', 'Adeyemi', 'Novak', 'Mensah', 'Tanaka', 'Weber', 'Patel', 'Garcia', 'Bello', 'Johnson', 'Ali', 'Martin', 'Owusu'];
  const DOMAINS = [['gmail.com', .46], ['outlook.com', .2], ['yahoo.com', .1], ['icloud.com', .14], ['hotmail.de', .04], ['protonmail.com', .02], ['acme-corp.io', .04]];
  const PROVIDER = { 'gmail.com': 'Gmail', 'outlook.com': 'Outlook', 'yahoo.com': 'Yahoo', 'icloud.com': 'iCloud', 'hotmail.de': 'Outlook', 'protonmail.com': 'Proton', 'acme-corp.io': 'Google WS' };
  const SUBJECTS = [['Your receipt from Acme #48213', 'transactional'], ['Reset your password', 'transactional'], ['Welcome to Acme 👋', 'transactional'], ['Your order has shipped', 'transactional'], ['Verify your email address', 'transactional'], ['Your weekly summary', 'broadcast'], ['New features in October', 'broadcast'], ['Your invoice for November', 'transactional'], ['Someone signed in to your account', 'transactional'], ['Last chance: 20% off annual plans', 'broadcast']];
  const BOUNCE = [['550 5.1.1 The email account that you tried to reach does not exist', 'Mailbox does not exist'], ['552 5.2.2 Mailbox full', 'Mailbox full'], ['554 5.7.1 Message rejected due to content policy', 'Blocked by content policy']];
  const COLORS = ['#5B5BF7', '#0EA5E9', '#16A34A', '#D97706', '#DB2777', '#7C3AED', '#0D9488', '#EA580C'];
  const wDomain = () => { let r = rnd(), a = 0; for (const [d, w] of DOMAINS) { a += w; if (r <= a) return d; } return 'gmail.com'; };
  const emailOf = (f, l, d, n) => (f + '.' + l).toLowerCase() + (n || '') + '@' + d;
  const HEX = '0123456789ABCDEF';
  const trackCode = () => 'SL-' + Array.from({ length: 4 }, () => HEX[Math.floor(rnd() * 16)]).join('') + '-' + Array.from({ length: 4 }, () => HEX[Math.floor(rnd() * 16)]).join('');
  const now = Date.now();

  function makeMessage(ts) {
    const f = pick(FIRST), l = pick(LAST), dom = wDomain();
    const [subject, stream] = pick(SUBJECTS);
    const r = rnd();
    let status = 'delivered';
    if (r < .028) status = 'bounced'; else if (r < .036) status = 'deferred'; else if (r < .039) status = 'complained'; else if (r < .046) status = 'dropped'; else if (r < .052) status = 'processing';
    let opens = 0, clicks = 0; const machine = dom === 'icloud.com' && rnd() < .85;
    if (status === 'delivered') {
      if (rnd() < .52 || machine) { opens = 1 + Math.floor(rnd() * 3); status = 'opened'; }
      if (status === 'opened' && rnd() < .22 && !(machine && rnd() < .6)) { clicks = 1 + Math.floor(rnd() * 2); status = 'clicked'; }
    }
    if (status === 'complained') opens = 1;
    return { id: trackCode(), to: emailOf(f, l, dom, rnd() < .3 ? Math.floor(rnd() * 90) : ''), name: f + ' ' + l, domain: dom, provider: PROVIDER[dom], subject, stream, status, opens, clicks, machine, ts, from: stream === 'broadcast' ? 'news@acme-corp.io' : 'hello@acme-corp.io', bounce: pick(BOUNCE), tags: stream === 'broadcast' ? ['newsletter', 'oct-2026'] : [pick(['receipts', 'auth', 'onboarding', 'shipping'])] };
  }
  const MESSAGES = [];
  { let t = now; for (let i = 0; i < 160; i++) { t -= between(25e3, 240e3); MESSAGES.push(makeMessage(t)); } }

  // ── Helpers ──
  const ago = (ts) => { const s = Math.floor((Date.now() - ts) / 1000); if (s < 60) return s + 's ago'; const m = Math.floor(s / 60); if (m < 60) return m + 'm ago'; const h = Math.floor(m / 60); if (h < 24) return h + 'h ago'; return Math.floor(h / 24) + 'd ago'; };
  const day = (ts) => new Date(ts).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  const LABEL = { delivered: 'Delivered', opened: 'Inbox · opened', clicked: 'Clicked', bounced: 'Bounced', deferred: 'Deferred', complained: 'Reported spam', dropped: 'Suppressed', processing: 'Sending', sent: 'Sent', unsubscribed: 'Unsubscribed', subscribed: 'Subscribed', draft: 'Draft', scheduled: 'Scheduled', sending: 'Sending', sent: 'Sent', paused: 'Paused', active: 'Active' };
  const SVAR = { sent: 'processing', delivered: 'delivered', opened: 'opened', clicked: 'clicked', bounced: 'bounced', deferred: 'deferred', complained: 'complained', dropped: 'dropped', processing: 'processing' };
  const chip = (s, txt) => `<span class="chip ${s}">${txt || LABEL[s] || s}</span>`;
  const initials = (n) => n.split(' ').map((x) => x[0]).join('').slice(0, 2).toUpperCase();
  const colorFor = (s) => COLORS[[...s].reduce((a, c) => a + c.charCodeAt(0), 0) % COLORS.length];
  const avatar = (name, size) => `<span class="avatar-c" style="background:${colorFor(name)}${size ? `;width:${size}px;height:${size}px` : ''}">${initials(name)}</span>`;
  // Email HTML shown inside the app (the builder canvas) can't run anything: no scripts, frames, plugins, forms,
  // event handlers or script URLs. Previews use sandboxed iframes; the saved and sent email are unchanged.
  const UNSAFE = new Set(['SCRIPT', 'IFRAME', 'FRAME', 'FRAMESET', 'OBJECT', 'EMBED', 'APPLET', 'LINK', 'META', 'BASE', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'SVG', 'MATH', 'NOSCRIPT', 'TEMPLATE', 'STYLE']);
  function safeHtml(html) {
    const t = document.createElement('template');
    t.innerHTML = String(html == null ? '' : html); // template content is inert: nothing loads or runs while parsing
    [...t.content.querySelectorAll('*')].forEach((el) => {
      if (UNSAFE.has(el.tagName.toUpperCase())) { el.remove(); return; }
      [...el.attributes].forEach((a) => {
        const n = a.name.toLowerCase(), v = a.value.replace(/[\s\u0000-\u001f]/g, '').toLowerCase();
        const url = ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster', 'srcset'].includes(n);
        if (n.startsWith('on') || n === 'srcdoc' || (url && (v.startsWith('javascript:') || v.startsWith('vbscript:') || (v.startsWith('data:') && !v.startsWith('data:image/'))))) el.removeAttribute(a.name);
      });
    });
    return t.innerHTML;
  }
  function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2000); }
  function bindCopy(root = document) { $$('[data-copy]', root).forEach((el) => el.onclick = (e) => { e.stopPropagation(); try { navigator.clipboard.writeText(el.dataset.copy); } catch (er) {} toast('Copied to clipboard'); }); }

  // ── The Line: route visuals ──
  /** How far along the line an email got, and how it ended. */
  function lineState(status) {
    // reached: number of stations lit (1..4). end: how the line terminates after the last lit station.
    switch (status) {
      case 'processing': return { reached: 1, end: 'pending' };
      case 'sent': return { reached: 1 };
      case 'deferred': return { reached: 1, end: 'dash' };
      case 'bounced': return { reached: 1, end: 'break' };
      case 'dropped': return { reached: 0, end: 'cut' };
      case 'delivered': return { reached: 2 };
      case 'opened': return { reached: 3 };
      case 'clicked': return { reached: 4 };
      case 'complained': return { reached: 3, end: 'spam' };
      default: return { reached: 1 };
    }
  }
  function miniRoute(status, label = true) {
    const { reached, end } = lineState(status);
    let h = '';
    for (let i = 0; i < 4; i++) {
      if (i) {
        let cls = i < reached ? 'on' : '';
        if (i === reached && end === 'dash') cls = 'dash';
        if (i === reached && end === 'break') cls = 'break';
        h += `<span class="tr ${cls}"></span>`;
      }
      let st = i < reached ? 'on' : '';
      if (i === 0 && end === 'cut') st = 'x';
      if (i === 1 && end === 'break') st = 'x';
      h += `<span class="st ${st}"></span>`;
    }
    return `<span class="route-cell" style="--c:var(--s-${SVAR[status] || 'dropped'})"><span class="route" title="${LABEL[status]}">${h}</span>${label ? `<span class="lbl">${LABEL[status]}</span>` : ''}</span>`;
  }
  function sparkline(vals, color, w = 46, h = 22) {
    const mx = Math.max(...vals), mn = Math.min(...vals), r = mx - mn || 1;
    const pts = vals.map((v, i) => `${(i / (vals.length - 1)) * w},${h - 2 - ((v - mn) / r) * (h - 4)}`).join(' ');
    return `<svg class="spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}"><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  }
  function lineChart(el, series, keys, opts = {}) {
    const W = el.clientWidth || 800, H = opts.h || 260, pl = 46, pr = 26, pt = 12, pb = 28;
    const on = keys.filter((k) => !k.off);
    const max = Math.max(1, ...series.flatMap((s) => on.map((k) => s[k.key])));
    const step = max > 4000 ? 1000 : max > 400 ? 100 : 10, nice = Math.ceil(max / step) * step;
    const x = (i) => pl + (i / (series.length - 1)) * (W - pl - pr), y = (v) => pt + (1 - v / nice) * (H - pt - pb);
    let g = '';
    for (let i = 0; i <= 4; i++) { const v = (nice / 4) * i; g += `<line x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}" stroke="var(--border)" stroke-dasharray="${i ? '3 4' : ''}"/><text x="${pl - 8}" y="${y(v) + 4}" text-anchor="end">${v >= 1000 ? (v / 1000).toFixed(v % 1000 ? 1 : 0) + 'k' : Math.round(v)}</text>`; }
    const xl = opts.xLabel || ((s) => s.date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }));
    const every = opts.every || 2;
    series.forEach((s, i) => { if (i % every === 0 || i === series.length - 1) g += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${xl(s, i)}</text>`; });
    if (opts.marker != null) { const mx = x(opts.marker); g += `<line x1="${mx}" x2="${mx}" y1="${pt}" y2="${H - pb}" stroke="var(--accent)" stroke-dasharray="4 4"/><rect x="${mx + 6}" y="${pt}" width="${opts.markerText.length * 6.4 + 14}" height="22" rx="6" fill="var(--accent)"/><text x="${mx + 13}" y="${pt + 15}" style="fill:#fff;font-weight:600">${opts.markerText}</text>`; }
    on.forEach((k) => { const d = series.map((s, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(s[k.key]).toFixed(1)).join(' '); g += `<path d="${d} L ${x(series.length - 1)} ${y(0)} L ${x(0)} ${y(0)} Z" fill="${k.color}" opacity=".06"/><path d="${d}" fill="none" stroke="${k.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`; });
    g += `<line class="hover-line" x1="0" x2="0" y1="${pt}" y2="${H - pb}" stroke="var(--border-strong)" opacity="0"/>`;
    el.innerHTML = `<svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">${g}<rect x="${pl}" y="${pt}" width="${W - pl - pr}" height="${H - pt - pb}" fill="transparent" class="hit"/></svg>`;
    const tt = $('#chart-tt'), svg = el.querySelector('svg'), hl = svg.querySelector('.hover-line'), hit = svg.querySelector('.hit');
    hit.addEventListener('mousemove', (e) => {
      const r = svg.getBoundingClientRect(), i = Math.max(0, Math.min(series.length - 1, Math.round(((e.clientX - r.left - pl) / (W - pl - pr)) * (series.length - 1)))), s = series[i];
      hl.setAttribute('x1', x(i)); hl.setAttribute('x2', x(i)); hl.setAttribute('opacity', 1);
      tt.innerHTML = `<div style="font-weight:600;margin-bottom:6px">${opts.ttLabel ? opts.ttLabel(s, i) : s.date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</div>` + on.map((k) => `<div class="row"><span><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${k.color};margin-right:6px"></i>${k.name}</span><b>${fmt(s[k.key])}</b></div>`).join('');
      tt.style.display = 'block'; tt.style.left = Math.min(e.clientX + 14, innerWidth - 200) + 'px'; tt.style.top = e.clientY - 20 + 'px';
    });
    hit.addEventListener('mouseleave', () => { tt.style.display = 'none'; hl.setAttribute('opacity', 0); });
  }

  // ═══════ Component system ═══════
  /** Modal. opts: {title, desc, icon, tone, size, body, actions:[{label, kind, onClick}], hint, steps:[{label, body, mount, validate, nextLabel, hint}], onFinish, finishLabel} */
  function modal(opts) {
    const root = $('#modal');
    const steps = opts.steps || null;
    let cur = 0;
    const stepActions = (st) => [
      cur === 0 ? { label: 'Cancel' } : { label: 'Back', onClick: () => { cur--; render(); return false; } },
      { label: st.nextLabel || (cur === steps.length - 1 ? (opts.finishLabel || 'Finish') : 'Continue'), kind: 'primary', onClick: () => { if (st.validate && st.validate(root) === false) return false; if (cur < steps.length - 1) { cur++; render(); return false; } if (opts.onFinish) return opts.onFinish(api); } },
    ];
    const render = () => {
      const st = steps ? steps[cur] : null;
      const body = st ? st.body : opts.body;
      const acts = st ? stepActions(st) : (opts.actions || [{ label: 'Close' }]);
      const hint = (st && st.hint) || opts.hint || '';
      root.innerHTML = `<div class="modal-card ${opts.size || 'md'}" role="dialog" aria-modal="true">
        <div class="m-head">${opts.icon ? `<span class="m-icon ${opts.tone || ''}">${ic(opts.icon, 19)}</span>` : ''}<div><div class="m-title">${esc(opts.title)}</div>${opts.desc ? `<div class="m-desc">${esc(opts.desc)}</div>` : ''}</div><button class="icon-btn" data-x aria-label="Close">${ic('x', 16)}</button></div>
        ${steps ? `<div class="stepper">${steps.map((s, i) => `${i ? '<span class="step-line"></span>' : ''}<span class="step ${i < cur ? 'done' : i === cur ? 'on' : ''}"><span class="n">${i < cur ? ic('check', 12, 3) : i + 1}</span><span class="t">${s.label}</span></span>`).join('')}</div>` : ''}
        <div class="m-body">${typeof body === 'function' ? body() : body}</div>
        <div class="m-foot"><span class="hint">${hint}</span>${acts.map((a, i) => `<button class="btn ${a.kind === 'primary' ? 'btn-primary' : a.kind === 'danger' ? 'btn-danger' : ''}" data-a="${i}">${a.label}</button>`).join('')}</div></div>`;
      $$('[data-a]', root).forEach((b) => b.onclick = () => { const a = acts[+b.dataset.a]; if (!a.onClick || a.onClick(api) !== false) close(); });
      $('[data-x]', root).onclick = close;
      const mount = st ? st.mount : opts.mount; if (mount) mount(root, api);
      const f = $('input:not([type=checkbox]):not([type=radio]),select,textarea', $('.m-body', root)); if (f && !opts.noFocus) setTimeout(() => f.focus(), 60);
    };
    const close = () => { root.classList.remove('open'); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const api = { close, root, render, goto: (i) => { cur = i; render(); } };
    render();
    root.onclick = (e) => { if (e.target === root) close(); };
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(() => root.classList.add('open'));
    return api;
  }
  function confirmDialog({ title, desc, label = 'Delete', onConfirm }) {
    modal({ title, desc, icon: 'alert', tone: 'danger', size: 'sm', body: '', noFocus: true, actions: [{ label: 'Cancel' }, { label, kind: 'danger', onClick: () => { onConfirm && onConfirm(); } }] });
  }
  function fieldError(root, sel, msg) { const inp = $(sel, root); const f = inp.closest('.field'); f.classList.add('error'); if (!$('.err', f)) f.insertAdjacentHTML('beforeend', `<span class="err">${msg}</span>`); inp.focus(); inp.oninput = () => { f.classList.remove('error'); const er = $('.err', f); if (er) er.remove(); }; return false; }
  function radioCards(root) { $$('.radio-cards', root).forEach((g) => $$('.radio-card', g).forEach((c) => c.onclick = () => { $$('.radio-card', g).forEach((x) => x.classList.remove('on')); c.classList.add('on'); })); }

  /** Dropdown menu. items: [{label, icon, meta, danger, onClick, html} | 'sep' | {header}] */
  function menu(anchor, items, align = 'left') {
    closeMenu();
    const r = anchor.getBoundingClientRect();
    const m = document.createElement('div'); m.className = 'menu'; m.id = 'menu-pop';
    m.innerHTML = items.map((it, i) => it === 'sep' ? '<div class="menu-sep"></div>' : it.header ? `<div class="menu-label">${it.header}</div>` : `<button class="menu-item ${it.danger ? 'danger' : ''}" data-i="${i}">${it.html || (it.icon ? ic(it.icon, 16) : '') + esc(it.label)}${it.meta ? `<span class="meta">${it.meta}</span>` : ''}</button>`).join('');
    document.body.appendChild(m);
    const w = m.offsetWidth, h = m.offsetHeight;
    let left = align === 'right' ? r.right - w : r.left, top = r.bottom + 6;
    if (top + h > innerHeight - 8) top = r.top - h - 6;
    m.style.left = Math.max(8, Math.min(left, innerWidth - w - 8)) + 'px'; m.style.top = Math.max(8, top) + 'px';
    $$('[data-i]', m).forEach((b) => b.onclick = () => { closeMenu(); const it = items[+b.dataset.i]; it.onClick && it.onClick(); });
    setTimeout(() => document.addEventListener('click', closeMenu, { once: true }), 0);
  }
  function closeMenu() { const m = $('#menu-pop'); if (m) m.remove(); }

  /** Command palette (⌘K): emails by tracking code or address, actions, pages. */
  function palette() {
    const root = $('#palette');
    const NAVS = Object.entries(TITLES).map(([k, t]) => ({ label: 'Go to ' + t, icon: k, run: () => (location.hash = '#/' + k) }));
    const ACTIONS = [
      { label: 'Send an email to someone', icon: 'send', run: () => (location.hash = '#/send') },
      { label: 'Create API key', icon: 'keys', run: () => { location.hash = '#/keys'; setTimeout(() => SL.createKey && SL.createKey(), 300); } },
      { label: 'Add sending domain', icon: 'domains', run: () => (location.hash = '#/domains/new') },
      { label: 'Import contacts', icon: 'upload', run: () => (location.hash = '#/contacts/import') },
      { label: 'New campaign', icon: 'campaigns', run: () => (location.hash = '#/campaigns/new') },
      { label: 'Connect Amazon SES', icon: 'ses', run: () => (location.hash = '#/ses/connect') },
      { label: 'Run an inbox placement test', icon: 'inbox', run: () => (location.hash = '#/inbox') },
      { label: 'Invite a teammate', icon: 'team', run: () => SL.inviteMember() },
      { label: 'Toggle dark mode', icon: 'moon', run: toggleTheme },
    ];
    let sel = 0, items = [], found = [], seq = 0, timer = null;
    // Emails: the workspace's real activity, searched by address, subject or tracking code.
    const search = (q) => {
      clearTimeout(timer); found = []; const n = ++seq, ws = window.SL_ME && window.SL_ME.current_workspace_id;
      if (q.length < 3 || !ws || !window.SL_API) return;
      timer = setTimeout(() => window.SL_API('GET', `/v1/workspaces/${ws}/activity?q=${encodeURIComponent(q)}`).then((r) => { if (n === seq) { found = (r.messages || []).slice(0, 5); draw(); } }).catch(() => {}), 250);
    };
    root.innerHTML = `<div class="palette-card"><div class="palette-input">${ic('search', 18)}<input id="pal-q" placeholder="Search a tracking code, email address, page or action…" autocomplete="off"><kbd class="badge-soft">ESC</kbd></div><div class="palette-list" id="pal-list"></div><div class="palette-foot"><span><kbd>↑↓</kbd> navigate</span><span><kbd>↵</kbd> open</span><span><kbd>esc</kbd> close</span></div></div>`;
    const draw = () => {
      const q = $('#pal-q').value.trim().toLowerCase();
      const mails = found.map((m) => ({ label: m.to, sub: m.tracking_code, meta: m.status, icon: 'mail', run: () => { location.hash = '#/activity'; setTimeout(() => SL.openActivity && SL.openActivity(m.id), 150); } }));
      const acts = ACTIONS.filter((a) => !q || a.label.toLowerCase().includes(q)), navs = NAVS.filter((a) => !q || a.label.toLowerCase().includes(q));
      const groups = [['Emails', mails], ['Actions', acts], ['Pages', navs]].filter((g) => g[1].length);
      items = groups.flatMap((g) => g[1]); sel = Math.min(sel, Math.max(0, items.length - 1));
      let i = 0;
      $('#pal-list').innerHTML = groups.map(([g, list]) => `<div class="menu-label">${g}</div>` + list.map((it) => `<button class="menu-item ${i === sel ? 'hl' : ''}" data-p="${i++}">${ic(it.icon, 16)}<span>${esc(it.label)}${it.sub ? `<span class="trk" style="margin-left:8px">${esc(it.sub)}</span>` : ''}</span>${it.meta ? `<span class="meta">${it.meta}</span>` : ''}</button>`).join('')).join('') || `<div class="empty" style="padding:30px"><h3>Nothing on the line</h3><p>Try a tracking code like SL-8F2A, an email address or a page name.</p></div>`;
      $$('[data-p]', root).forEach((b) => { b.onclick = () => run(+b.dataset.p); b.onmousemove = () => { if (sel !== +b.dataset.p) { sel = +b.dataset.p; draw(); } }; });
    };
    const run = (i) => { close(); items[i] && items[i].run(); };
    const close = () => { root.classList.remove('open'); document.removeEventListener('keydown', key); };
    const key = (e) => { if (e.key === 'Escape') close(); else if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, items.length - 1); draw(); e.preventDefault(); } else if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); draw(); e.preventDefault(); } else if (e.key === 'Enter') run(sel); };
    $('#pal-q').oninput = () => { sel = 0; search($('#pal-q').value.trim()); draw(); };
    root.onclick = (e) => { if (e.target === root) close(); };
    document.addEventListener('keydown', key);
    draw(); root.classList.add('open'); setTimeout(() => $('#pal-q').focus(), 30);
  }

  function drawer(html, mount) { const d = $('#drawer'); d.innerHTML = html; $$('[data-dclose]', d).forEach((b) => b.onclick = closeDrawer); d.classList.add('open'); $('#scrim').classList.add('open'); if (mount) mount(d); bindCopy(d); }
  function closeDrawer() { $('#drawer').classList.remove('open'); $('#scrim').classList.remove('open'); }
  function tabs(root, panes, after) { $$('[data-t]', root).forEach((b) => b.onclick = () => { $$('[data-t]', root).forEach((x) => x.classList.remove('on')); b.classList.add('on'); $('[data-pane]', root).innerHTML = panes[b.dataset.t](); bindCopy(root); after && after(b.dataset.t, root); }); }

  // ═══════ Shell ═══════
  const NAV = [
    { items: [['overview', 'Overview'], ['activity', 'Activity'], ['contacts', 'Contacts']] },
    { label: 'Engage', items: [['send', 'Quick send'], ['campaigns', 'Campaigns'], ['automations', 'Automations'], ['forms', 'Forms'], ['templates', 'Templates']] },
    { label: 'Deliverability', items: [['copilot', 'Copilot'], ['domains', 'Domains'], ['ses', 'Sending'], ['suppressions', 'Suppressions'], ['inbox', 'Inbox tests']] },
    { label: 'Developers', items: [['keys', 'API keys'], ['webhooks', 'Webhooks'], ['integrations', 'Integrations']] },
    { label: 'Get started', items: [['setup', 'Set up sending'], ['migrate', 'Migrate & save']] },
  ];
  const TITLES = { overview: 'Overview', activity: 'Activity', contacts: 'Contacts', send: 'Quick send', campaigns: 'Campaigns', automations: 'Automations', forms: 'Forms', templates: 'Templates', domains: 'Domains', suppressions: 'Suppressions', inbox: 'Inbox tests', keys: 'API keys', webhooks: 'Webhooks', integrations: 'Integrations', setup: 'Set up sending', settings: 'Settings', copilot: 'Copilot', ses: 'Sending', migrate: 'Migrate & save' };

  function shell() {
    document.body.innerHTML = `
    <div class="shell">
      <aside class="sidebar" id="sidebar">
        <a class="brand" href="#/overview">${brandHtml()}</a>
        <button class="workspace" id="ws"><span class="avatar">A</span><span style="text-align:left"><div class="ws-name">Acme Inc</div><div class="ws-plan">Growth plan</div></span><span class="chev">${ic('chev', 14)}</span></button>
        ${NAV.map((g) => `<div class="nav-group">${g.label ? `<div class="nav-label">${g.label}</div>` : ''}${g.items.map(([k, t]) => `<a class="nav-item" href="#/${k}" data-k="${k}">${ic(k)}${t}${k === 'activity' ? '<span class="count">● Live</span>' : k === 'copilot' ? '<span class="count" style="color:var(--accent-text);display:none"></span>' : ''}</a>`).join('')}</div>`).join('')}
        <div class="nav-group"><a class="nav-item" href="#/settings" data-k="settings">${ic('settings')}Settings</a></div>
        <div style="margin-top:auto">
          <div class="usage" id="usage"><div class="usage-top"><span>Emails this month</span><b>–</b></div><div class="meter"><span style="width:0%"></span></div><small>&nbsp;</small></div>
          <button class="user-btn" id="user">${avatar('Ayodele Akintomide')}<span><b>Ayodele A.</b><small>Owner</small></span><span class="chev" style="margin-left:auto;color:var(--text-3)">${ic('chev', 14)}</span></button>
        </div>
      </aside>
      <div class="main">
        <header class="topbar">
          <button class="icon-btn menu-btn" id="menu">${ic('menu')}</button>
          <div class="page-title" id="title"></div>
          <div class="spacer"></div>
          <button class="search" id="search">${ic('search', 15)}<span>Track an email or jump to…</span><kbd>⌘K</kbd></button>
          <div class="env mode-sw" title="Switch mode (⌘J)"><a class="on" href="#/overview">${ic('overview', 13)}Dashboard</a><a href="#/agent" class="ag-on">${ic('copilot', 13, 2)}Agent</a></div>
          <div class="env"><button class="on live" data-env="live">Live</button><button data-env="test">Test</button></div>
          <button class="icon-btn" id="bell" title="Alerts">${ic('bell')}</button>
          <button class="icon-btn" id="theme" title="Toggle theme"></button>
          <a class="btn btn-primary" id="send-test" href="#/send">${ic('send', 15)}<span>Send email</span></a>
        </header>
        <main class="content" id="view"></main>
      </div>
    </div>
    <div class="scrim" id="scrim"></div><aside class="drawer" id="drawer"></aside>
    <div class="modal" id="modal"></div><div class="palette" id="palette"></div>
    <div class="chart-tt" id="chart-tt" style="display:none"></div><div class="toast" id="toast"></div>`;
    $('#menu').onclick = () => $('#sidebar').classList.toggle('open');
    $('#theme').onclick = toggleTheme;
    $('#scrim').onclick = closeDrawer;
    $('#search').onclick = palette;
    $$('.env button').forEach((b) => b.onclick = () => { $$('.env button').forEach((x) => x.classList.remove('on')); b.classList.add('on'); toast(b.dataset.env === 'test' ? 'Test mode: emails are captured, never delivered' : 'Live mode'); });
    $('#ws').onclick = (e) => { e.stopPropagation(); menu($('#ws'), [{ header: 'Workspaces' }, { html: `<span class="ws-dot" style="background:#17160F">A</span>Acme Inc`, meta: '✓' }, { html: `<span class="ws-dot" style="background:#0E7C86">N</span>Northwind Labs`, onClick: () => toast('Switched to Northwind Labs (prototype)') }, { html: `<span class="ws-dot" style="background:#B4235F">B</span>Bloom Studio`, onClick: () => toast('Switched to Bloom Studio (prototype)') }, 'sep', { label: 'Create workspace', icon: 'plus', onClick: createWorkspace }, { label: 'Workspace settings', icon: 'settings', onClick: () => (location.hash = '#/settings') }]); };
    $('#user').onclick = (e) => { e.stopPropagation(); menu($('#user'), [{ header: esc((window.SL_ME && window.SL_ME.user.email) || '') }, { label: 'Your profile', icon: 'user', onClick: () => { SL.settingsTab = 'profile'; location.hash = '#/settings'; route(); } }, { label: 'Billing', icon: 'card', onClick: () => { SL.settingsTab = 'billing'; location.hash = '#/settings'; route(); } }, { label: 'API documentation', icon: 'book', meta: '↗', onClick: () => window.open('/docs/', '_blank', 'noopener') }, 'sep', { label: 'Sign out', icon: 'logout', danger: true, onClick: () => (window.SL_LOGOUT ? window.SL_LOGOUT() : (location.href = '/login/')) }]); };
    // Alerts are the workspace's real open Copilot findings (copilot.js provides SL.loadAlerts).
    $('#bell').onclick = (e) => {
      e.stopPropagation();
      const bell = $('#bell');
      (SL.loadAlerts ? SL.loadAlerts() : Promise.resolve([])).then((items) => menu(bell, [{ header: 'Alerts' },
        ...(items.length ? items : [{ html: `<span style="color:var(--s-delivered)">${ic('check', 16)}</span><span>Nothing needs your attention</span>` }]),
        'sep', { label: 'Open Copilot', icon: 'spark', onClick: () => (location.hash = '#/copilot') }], 'right'));
    };
    document.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette(); } if (e.key === 'Escape') { closeDrawer(); closeMenu(); } });
    setThemeIcon();
  }
  function toggleTheme() { const d = document.documentElement; const n = d.dataset.theme === 'dark' ? 'light' : 'dark'; d.dataset.theme = n; try { localStorage.setItem('sl-theme', n); } catch (e) {} setThemeIcon(); route(); }
  function setThemeIcon() { $('#theme').innerHTML = ic(document.documentElement.dataset.theme === 'dark' ? 'sun' : 'moon'); }

  function createWorkspace() {
    modal({ title: 'Create a workspace', desc: 'Separate brands, clients or environments, each with its own domains, contacts and billing.', icon: 'plus', body: `<div class="field"><label>Workspace name</label><input id="wsn" placeholder="e.g. Northwind Labs"></div><div class="field"><label>Data region</label><div class="radio-cards"><label class="radio-card on"><span class="dot"></span><div><b>European Union</b><span>Data and sending stay in the EU.</span></div></label><label class="radio-card"><span class="dot"></span><div><b>United States</b><span>Lowest latency for North America.</span></div></label></div></div>`, mount: radioCards, actions: [{ label: 'Cancel' }, { label: 'Create workspace', kind: 'primary', onClick: (m) => { if (!$('#wsn', m.root).value.trim()) return fieldError(m.root, '#wsn', 'Give the workspace a name.'); toast('Workspace created (prototype)'); } }] });
  }


  // ═══════ Delivery screens ═══════
  const pages = SL.pages;
  function emailHtml(subject, name) { return `<!doctype html><html><body style="margin:0;font-family:-apple-system,Segoe UI,sans-serif;background:#f6f5f1;padding:32px"><div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e7e4db"><div style="font-weight:700;font-size:18px;margin-bottom:16px">Acme</div><h1 style="font-size:20px;margin:0 0 12px">${esc(subject)}</h1><p style="color:#5c594f;line-height:1.6">Hi ${esc(name)},<br><br>This is the email exactly as it was sent. In the live product this shows the stored HTML.</p><a style="display:inline-block;margin-top:16px;background:#5B5BF7;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">View your account</a><p style="color:#8e8a7e;font-size:12px;margin-top:28px">You received this because you have an Acme account. <u>Unsubscribe</u></p></div></body></html>`; }

  // ═══════ Router ═══════
  function routeTo() {
    const k = (location.hash.replace('#/', '') || 'overview').split('?')[0];
    const page = pages[k] ? k : 'overview';
    if (!pages[page]) { $('#view').innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>'; return; } // its script hasn't loaded yet
    const top = page.split('/')[0];
    $$('.nav-item').forEach((a) => a.classList.toggle('active', a.dataset.k === top));
    const title = (SL.SUBTITLES && SL.SUBTITLES[page]) || TITLES[top];
    $('#title').textContent = title;
    document.title = title + ' · ' + brandName();
    window.onresize = null;
    // A fresh view element for every page: a slow request from the page we just left then writes into a detached
    // node instead of over this one (each module only guards against its own pages).
    const prev = $('#view'), view = prev.cloneNode(false); prev.replaceWith(view);
    SL.routeSeq = (SL.routeSeq || 0) + 1; // lets every module tell a late answer for a page that's gone
    SL.drawn = { fn: pages[page], withSession: !!window.SL_ME };
    pages[page](view);
    $('#sidebar').classList.remove('open');
    closeDrawer(); closeMenu();
    const demo = location.hash.match(/[?&]demo=(\w+)/); if (demo) setTimeout(() => (SL.demos[demo[1]] || (() => {}))(), 120);
  }
  const route = routeTo;
  /** Draws the page again only if it was drawn without the session, or a later script (Pro) replaced it. */
  SL.routeIfStale = () => {
    const k = (location.hash.replace('#/', '') || 'overview').split('?')[0], page = pages[k] ? k : 'overview';
    if (!SL.drawn || !SL.drawn.withSession || SL.drawn.fn !== pages[page]) routeTo();
  };

  SL.param = (n) => new URLSearchParams(location.hash.split('?')[1] || '').get(n);
  /**
   * A list screen, 50 rows at a time (the API's ?page=&q=). draw(r, ctl) gets the response with every row loaded
   * so far under `key`; then SL.pageBar(box, ctl) adds the search box and "Showing N of M · Load more".
   */
  SL.paged = (url, key, draw) => {
    const st = { page: 1, rows: [], meta: null, q: '', busy: false, focus: false };
    const m = url.match(/[?&]q=([^&]*)/); // a starting search in the url becomes the search, so it isn't sent twice
    if (m) { st.q = decodeURIComponent(m[1].replace(/\+/g, ' ')); url = url.replace(/([?&])q=[^&]*&?/, '$1').replace(/[?&]$/, ''); }
    const load = (reset) => {
      if (reset) st.page = 1;
      st.busy = true;
      return window.SL_API('GET', url + (url.includes('?') ? '&' : '?') + new URLSearchParams({ page: String(st.page), q: st.q }).toString()).then((r) => {
        st.rows = reset ? r[key] : st.rows.concat(r[key]); st.meta = r.meta || null; st.busy = false;
        draw(Object.assign({}, r, { [key]: st.rows }), ctl);
      }, (err) => { st.busy = false; throw err; });
    };
    const ctl = { st, more: () => (st.busy || !st.meta || !st.meta.has_more ? Promise.resolve() : (st.page++, load(false))), reload: () => load(true) };
    let t = null;
    ctl.search = (q) => { st.q = q; clearTimeout(t); t = setTimeout(() => load(true).catch((err) => SL.toast && SL.toast(err.message)), 250); };
    ctl.ready = load(true);
    return ctl;
  };
  /** Search box in the list's toolbar (place: an element) and the count + Load more under the table (foot: the card). */
  SL.pageBar = (ctl, place, foot, label, where = 'afterbegin') => {
    const st = ctl.st, esc = SL.esc;
    if (place) { const old = place.querySelector('.pg-search'); if (old) old.remove(); }
    if (foot) { const old = foot.querySelector('.pg-foot'); if (old) old.remove(); }
    if (place && (st.meta && (st.meta.total > 10 || st.q))) {
      place.insertAdjacentHTML(where, `<div class="pg-search">${SL.ic('search', 14)}<input placeholder="Search ${esc(label || '')}" value="${esc(st.q)}" aria-label="Search ${esc(label || '')}"></div>`);
      const inp = place.querySelector('.pg-search input');
      inp.oninput = () => { st.focus = true; ctl.search(inp.value.trim()); };
      inp.onblur = () => setTimeout(() => { if (inp.isConnected) st.focus = false; }); // a redraw removes the box: keep typing in the new one
      if (st.focus) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
    }
    if (foot && st.meta && (st.meta.total > st.rows.length || st.meta.total > st.meta.per_page)) {
      foot.insertAdjacentHTML('beforeend', `<div class="tfoot pg-foot"><span>Showing ${SL.fmt(st.rows.length)} of ${SL.fmt(st.meta.total)}</span>${st.meta.has_more ? '<button class="btn btn-sm">Load more</button>' : ''}</div>`);
      const b = foot.querySelector('.pg-foot button');
      if (b) b.onclick = () => { b.disabled = true; b.textContent = 'Loading…'; ctl.more().catch((err) => { b.disabled = false; b.textContent = 'Load more'; SL.toast && SL.toast(err.message); }); };
    }
  };
  // Markup-driven actions (no inline onclick: the Content-Security-Policy forbids inline script).
  document.addEventListener('click', (e) => {
    const t = e.target.closest && e.target.closest('[data-toast],[data-settings-tab]');
    if (!t) return;
    if (t.dataset.toast) toast(t.dataset.toast);
    if (t.dataset.settingsTab) SL.settingsTab = t.dataset.settingsTab;
  });

  Object.assign(SL, { safeHtml, addDomain: () => (location.hash = '#/domains/new'), $, $$, esc, fmt, pct, ic, chip, route: routeTo, miniRoute, avatar, toast, modal, confirmDialog, fieldError, radioCards, menu, drawer, closeDrawer, tabs, bindCopy, sparkline, lineChart, ago, day, rnd, pick, between, now, FIRST, LAST, DOMAINS, MESSAGES, LABEL, colorFor, initials, emailOf, emailHtml });

  SL.demos = { test: () => (location.hash = '#/send'), palette, workspace: createWorkspace };
  SL.boot = () => {
    try { const q = new URLSearchParams(location.search).get('theme'); if (q) localStorage.setItem('sl-theme', q); } catch (e) {}
    try { const t = localStorage.getItem('sl-theme'); if (t) document.documentElement.dataset.theme = t; else if (matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.theme = 'dark'; } catch (e) {}
    shell(); paintBrand(); addEventListener('hashchange', routeTo); routeTo();
    SL.refreshBrand();
  };
})();
