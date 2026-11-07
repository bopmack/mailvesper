/* MailVesper: editions. Community (the free edition) shows MailVesper Pro's features as locked pages with a real preview and
   a way in; Pro's own scripts (served by the Pro package, loaded by auth-guard after /me) replace these pages.
   Also: the edition card in Settings → General, where the installation admin adds a license key. Loaded last. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, esc, ic, toast, modal } = S;
  const pages = S.pages;
  const ed = () => (window.SL_ME && window.SL_ME.edition) || { name: 'community', features: [], upgrade_url: '#' };
  S.isPro = (feature) => ed().name === 'pro' && (!feature || (ed().features || []).includes(feature));

  const LOCKS = {
    agent: {
      kicker: 'Agent', title: 'Say what you want sent. The agent builds it.',
      lead: 'Describe a campaign in one sentence. The agent finds the audience in your own data, designs the email in your brand, and schedules it. You approve every send before anything leaves.',
      points: [['contacts', 'Audiences from your data', 'Segments from purchases, opens, events and fields, explained before you use them.'], ['templates', 'On-brand designs', 'Real emails from your brand kit and media library, editable in the builder.'], ['shield', 'You stay in control', 'Every action waits for your approval. Viewers can only ask questions.']],
      preview: () => `<div class="pl-chat"><div class="pl-msg me">Send a Black Friday offer to everyone who bought in the last 90 days</div>
        <div class="pl-msg ai"><ol class="pl-steps"><li>${ic('check', 12, 3)}Found <b>1,284</b> buyers in the last 90 days</li><li>${ic('check', 12, 3)}Designed “Black Friday · 30% off everything”</li><li>${ic('check', 12, 3)}Picked Friday 09:00 in each contact’s time zone</li></ol>
          <div class="pl-card"><div><small>Waiting for your approval</small><b>Schedule campaign to 1,284 people</b></div><span class="btn btn-primary btn-sm" aria-hidden="true">Approve</span></div></div></div>`,
    },
    inbox: {
      feature: 'inbox_tests', kicker: 'Inbox tests', title: 'See where your email really lands.',
      lead: 'Send the real email to your own seed mailboxes at Gmail, Outlook, Yahoo and iCloud, and see whether each copy reached the inbox, another tab or spam, before your customers do.',
      points: [['inbox', 'Real mailboxes', 'Your own test accounts, read over encrypted IMAP. No shared seed lists.'], ['copilot', 'Per-provider verdicts', 'Inbox, other folder or spam, for every provider, with the SPF, DKIM and DMARC results.'], ['activity', 'History over time', 'A heatmap of every test, so a drop shows up the day it happens.']],
      preview: () => `<div class="pl-seeds">${[['Gmail', ['in', 'in', 'in', 'tab']], ['Outlook', ['in', 'spam', 'in']], ['Yahoo', ['in', 'in']], ['iCloud', ['in', 'in', 'in']]].map(([p, cells]) => `<div class="pl-seed"><b>${p}</b><span>${cells.map((c) => `<i class="sd k-${c}"></i>`).join('')}</span><small>${Math.round(cells.filter((c) => c === 'in').length / cells.length * 100)}% inbox</small></div>`).join('')}</div>`,
    },
    team: {
      feature: 'team', kicker: 'Teams', title: 'Bring your team in.',
      lead: 'Community has one admin login, which can run as many companies as you like. Pro adds teammates with their own logins and roles, for each workspace.',
      points: [['team', 'Invite by email', 'Links that expire after 7 days. Social sign-in works too.'], ['shield', 'Four roles', 'Owner, admin, member and viewer. Viewers can see reports but can’t send.'], ['user', 'Ownership transfer', 'Hand a workspace to someone else, confirmed with a password.']],
      preview: () => `<div class="pl-team">${[['Ada Okafor', 'Owner'], ['Musa Bello', 'Admin'], ['Chidi Eze', 'Member'], ['Ngozi Ade', 'Viewer']].map(([n, r]) => `<div class="pl-mem">${S.avatar(n, 28)}<b>${n}</b><span class="tag">${r}</span></div>`).join('')}</div>`,
    },
    platform: {
      kicker: 'Platform admin', title: 'Run the whole installation from one place.',
      lead: 'See at a glance whether the scheduler, queue, backups and disk are healthy, read the errors, and put your own name and logo on everything, all from the browser.',
      points: [['activity', 'System health', 'Scheduler, queue, failed jobs, errors, slow queries and disk, with an email when something breaks.'], ['palette', 'Your brand', 'Your name, logo and colour on the login pages, the app and every email it sends.'], ['database', 'Backups and admins', 'Daily backups with restore drills and off-site copies, more admins, and sign-in with Google, Microsoft, GitHub or Apple.']],
      preview: () => `<div class="pl-sys">${[['Scheduler', 'Running', 'Last ran just now'], ['Queue', '0 waiting', 'Nothing waiting'], ['Backups', '6 h ago', '182 MB'], ['Restore drill', 'Passed', '2 d ago'], ['Disk', '64 GB free', 'of 100 GB'], ['Errors', '0 open', '0 seen in 24 hours']].map(([l, v, s]) => `<div class="pl-tile"><small><i></i>${l}</small><b>${v}</b><span>${s}</span></div>`).join('')}</div>`,
    },
  };

  function lockHtml(key, compact) {
    const L = LOCKS[key];
    return `<section class="pro-lock ${compact ? 'compact' : ''}"><div class="pl-copy"><span class="pro-chip">${ic('zap', 12, 2.4)}MailVesper Pro</span>
        <div class="pl-kicker">${esc(L.kicker)}</div><h1>${esc(L.title)}</h1><p class="pl-lead">${esc(L.lead)}</p>
        <ul class="pl-points">${L.points.map(([i, t, d]) => `<li><span class="pl-ic">${ic(i, 15)}</span><div><b>${esc(t)}</b><span>${esc(d)}</span></div></li>`).join('')}</ul>
        <div class="pl-actions"><a class="btn btn-primary" href="${esc(ed().upgrade_url)}" target="_blank" rel="noopener">See MailVesper Pro</a><button class="btn" data-license>I have a license key</button></div></div>
      <div class="pl-preview" aria-hidden="true"><div class="pl-frame"><div class="pl-bar"><i></i><i></i><i></i><span>Preview</span></div>${L.preview()}</div></div></section>`;
  }
  const bindLock = (root) => root.querySelectorAll('[data-license]').forEach((b) => (b.onclick = licenseModal));
  /** Draws a Pro feature's locked page into el (platform.js uses it for the admin dashboard). */
  S.proLock = (key, el) => { el.innerHTML = lockHtml(key); bindLock(el); };

  // Locked pages (Pro's scripts load afterwards and replace them).
  S.SUBTITLES = Object.assign({ agent: 'Agent' }, S.SUBTITLES || {});
  pages.agent = (v) => { if (!window.SL_ME) { v.innerHTML = ''; return; } v.innerHTML = lockHtml('agent'); bindLock(v); };
  pages.inbox = (v) => { if (!window.SL_ME) { v.innerHTML = ''; return; } v.innerHTML = lockHtml('inbox'); bindLock(v); };
  pages['ses/seeds'] = (v) => { if (!window.SL_ME) { v.innerHTML = ''; return; } S.sendingPage(v, 'seeds', (body) => { body.innerHTML = lockHtml('inbox', true); bindLock(body); }); };
  // Settings → Team (account.js calls this; Pro's team.js replaces it with the real members page).
  S.renderTeamPane = (pane) => { pane.innerHTML = lockHtml('team', true); bindLock(pane); };
  // Copilot's chat panel (copilot.js asks for this when the chat isn't part of this edition).
  S.copilotChatTeaser = () => `<aside class="ask ask-lock"><div class="ask-head">${ic('copilot', 16, 2)}<div><b>Ask Copilot</b><small>MailVesper Pro</small></div></div>
      <div class="chat"><div class="bubble user">Why did Outlook opens drop this week?</div><div class="bubble ai">Outlook deferred 31% of your Tuesday campaign <sup class="cite">F2</sup>, while Gmail delivered normally <sup class="cite">S3</sup>. Turn on gentle Outlook pacing, then…</div></div>
      <div class="ask-lock-foot"><p>Copilot explains your findings in plain words, citing your own data, and answers questions about any email by its tracking code.</p><a class="btn btn-primary btn-sm" href="${esc(ed().upgrade_url)}" target="_blank" rel="noopener">See MailVesper Pro</a></div></aside>`;

  // Sidebar + top bar: a small Pro mark on what Community doesn't include.
  const markNav = () => {
    if (!window.SL_ME) return setTimeout(markNav, 300);
    if (S.isPro()) return;
    const inbox = document.querySelector('.nav-item[data-k="inbox"]');
    if (inbox && !inbox.querySelector('.pro-mark')) inbox.insertAdjacentHTML('beforeend', '<span class="pro-mark">Pro</span>');
    const ag = document.querySelector('.mode-sw a[href="#/agent"]');
    if (ag && !ag.querySelector('.pro-mark')) ag.insertAdjacentHTML('beforeend', '<span class="pro-mark">Pro</span>');
  };
  markNav();

  // A workspace whose sending the platform paused: say so everywhere, with the reason.
  const pausedBar = () => {
    if (!window.SL_ME) return setTimeout(pausedBar, 300);
    const why = window.SL_ME.workspace_suspended; const view = document.getElementById('view');
    if (!why || !view || document.getElementById('pausebar')) return;
    view.insertAdjacentHTML('beforebegin', `<div id="pausebar" class="billbar warn">${ic('alert', 14)}<span><b>Sending is paused for this workspace by the platform.</b> ${esc(why)}</span></div>`);
  };
  pausedBar(); window.addEventListener('hashchange', () => setTimeout(pausedBar, 0));

  // ═══════ License (Settings → General, and "I have a license key") ═══════
  function licenseModal() {
    window.SL_API('GET', '/v1/license').then((st) => {
      if (!st.can_manage) { toast('Only this installation’s admin can add a license key'); return; }
      modal({ title: 'Add your MailVesper Pro license', desc: st.pro_installed ? 'Paste the key from your MailVesper account. Pro switches on as soon as it’s checked.' : 'MailVesper Pro isn’t installed on this server yet: download it from your MailVesper account and put it in the app’s pro/ folder first.', icon: 'keys', size: 'sm',
        body: `<div class="field"><label>License key</label><textarea id="lk" class="mono" rows="4" placeholder="SL1.…" style="width:100%;resize:vertical" ${st.pro_installed ? '' : 'disabled'}></textarea></div><div id="lkerr"></div>`,
        actions: [{ label: 'Cancel' }, { label: 'Activate', kind: 'primary', onClick: (m) => {
          const key = $('#lk', m.root).value.trim(); if (!key) return S.fieldError(m.root, '#lk', 'Paste your license key.'), false;
          const b = m.root.querySelector('.m-foot .btn-primary'); b.disabled = true; b.textContent = 'Checking…';
          window.SL_API('PUT', '/v1/license', { key }).then(() => { toast('MailVesper Pro is on'); setTimeout(() => location.reload(), 600); })
            .catch((err) => { b.disabled = false; b.textContent = 'Activate'; $('#lkerr', m.root).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
          return false;
        } }] });
    }).catch((err) => toast(err.message));
  }
  S.licenseModal = licenseModal;

  function editionCard() {
    const e = ed();
    if (e.name === 'pro') {
      const lic = e.license || {};
      return `<div class="card ed-card pro" id="ed-card"><div class="card-body"><div class="setting-row"><div><b><span class="pro-chip">${ic('zap', 12, 2.4)}MailVesper Pro</span></b><span>Licensed to ${esc(lic.licensee || '—')}${lic.expires_at ? ' · renews ' + esc(String(lic.expires_at).slice(0, 10)) : ''} · version ${esc(e.version || '')}</span></div></div></div></div>`;
    }
    return `<div class="card ed-card" id="ed-card"><div class="card-body"><div class="setting-row"><div><b>MailVesper Community</b><span>${e.license_problem ? esc(e.license_problem) : 'The free edition: one admin login, as many workspaces as you need.'} Pro adds the agent, teams, Copilot chat and inbox tests.</span></div>
      <div style="display:flex;gap:8px;flex-shrink:0"><a class="btn btn-sm" href="${esc(e.upgrade_url)}" target="_blank" rel="noopener">See Pro</a><button class="btn btn-sm btn-primary" data-license>Add license key</button></div></div></div></div>`;
  }
  // ═══════ Plan limits (Pro SaaS mode): one clear prompt wherever a limit stops an action ═══════
  const rawApi = window.SL_API;
  let limitShownAt = 0;
  window.SL_API = (m, p, b) => rawApi(m, p, b).catch((err) => {
    if (err && err.code === 'plan_limit' && Date.now() - limitShownAt > 3000) {
      limitShownAt = Date.now();
      modal({ title: 'You’ve reached your plan’s limit', desc: err.message, icon: 'zap', size: 'sm', body: '',
        actions: [{ label: 'Not now' }, { label: 'See plans', kind: 'primary', onClick: () => { S.settingsTab = 'billing'; location.hash = '#/settings'; if (location.hash === '#/settings' && S.route) S.route(); } }] });
    }
    throw err;
  });

  // Settings → Billing: Pro SaaS mode draws the real billing (saas.js); otherwise there is no billing here.
  S.renderBillingPane = S.renderBillingPane || ((pane) => {
    pane.innerHTML = `<div class="card"><div class="card-body"><div class="setting-row" style="border:0;padding:0"><div><b>No billing on this ${SL_BN()}</b><span>${S.isPro() ? 'Workspaces here aren’t charged. Selling plans to your own customers is part of MailVesper Pro SaaS mode.' : 'MailVesper Community is free: workspaces here are never charged. Selling plans to your own customers is part of MailVesper Pro.'}</span></div>${S.isPro() ? '' : `<a class="btn btn-sm" href="${esc(ed().upgrade_url)}" target="_blank" rel="noopener">See Pro</a>`}</div></div></div>`;
  });

  const orig = pages.settings;
  pages.settings = (v) => {
    orig(v);
    const mountEd = () => {
      const pane = $('#spane'); if (!pane || !document.querySelector('[data-st="general"].on') || $('#ed-card')) return;
      window.SL_API('GET', '/v1/license').then((st) => { if (!st.can_manage || $('#ed-card') || !document.querySelector('[data-st="general"].on')) return; pane.insertAdjacentHTML('afterbegin', editionCard()); bindLock(pane); }).catch(() => {});
    };
    const mountBilling = () => { const pane = $('#spane'); if (pane && document.querySelector('[data-st="billing"].on')) S.renderBillingPane(pane); };
    mountEd(); mountBilling();
    document.querySelectorAll('[data-st]').forEach((b) => b.addEventListener('click', () => setTimeout(() => { mountEd(); mountBilling(); }, 0)));
  };
})();
