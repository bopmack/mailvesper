/* MailVesper: design prototype, CRM and engagement screens.
   Contacts, Campaigns, Templates, Automations, Inbox tests, Settings. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, chip, avatar, toast, modal, confirmDialog, fieldError, radioCards, menu, drawer, closeDrawer, tabs, bindCopy, miniRoute, ago, day, rnd, pick, between, now, FIRST, LAST, DOMAINS, MESSAGES, colorFor, emailOf, emailHtml } = S;
  const pages = S.pages;

  // ═══════ Campaigns ═══════
  const CAMPAIGNS = [
    { n: 'October product newsletter', st: 'sent', aud: 'Newsletter · 12,408', d: now - 2 * 864e5, del: .978, op: .412, cl: .071 },
    { n: 'Black Friday early access', st: 'scheduled', aud: 'Customers · 6,212', d: now + 26 * 864e5 },
    { n: 'Trial day-7 nudge (A/B)', st: 'sending', aud: 'Trials · 1,840', d: now - 3600e3, prog: .64, del: .981, op: .22, cl: .04 },
    { n: 'Win-back: 90 days inactive', st: 'draft', aud: 'Segment · 2,931', d: now - 5 * 864e5 },
    { n: 'September product newsletter', st: 'sent', aud: 'Newsletter · 11,970', d: now - 33 * 864e5, del: .974, op: .396, cl: .066 },
    { n: 'Pricing change notice', st: 'sent', aud: 'All customers · 7,004', d: now - 48 * 864e5, del: .983, op: .588, cl: .124 },
    { n: 'Webinar invite: deliverability 101', st: 'paused', aud: 'Leads · 3,310', d: now - 9 * 864e5, prog: .31 },
  ];
  // ═══════ Templates ═══════
  const TEMPLATES = [
    { n: 'Welcome email', c: 'Onboarding', col: '#1F8A4C', img: false, used: '2,410 sends · 7d' },
    { n: 'Password reset', c: 'Transactional', col: '#17160F', img: false, used: '1,982 sends · 7d' },
    { n: 'Monthly newsletter', c: 'Marketing', col: '#FF5B1F', img: true, used: 'Last used 2d ago' },
    { n: 'Receipt', c: 'Transactional', col: '#1E6FD9', img: false, used: '6,104 sends · 7d' },
    { n: 'Product launch', c: 'Marketing', col: '#6D46D1', img: true, used: 'Last used 33d ago' },
    { n: 'Trial ending', c: 'Lifecycle', col: '#A86A0C', img: false, used: '312 sends · 7d' },
    { n: 'Event invitation', c: 'Marketing', col: '#B4235F', img: true, used: 'Draft' },
    { n: 'Shipping update', c: 'Transactional', col: '#0E7C86', img: false, used: '904 sends · 7d' },
  ];
  // ═══════ Settings ═══════
  // Team invites live in Settings → Team (MailVesper Pro; Community shows what it adds).
  S.inviteMember = () => { S.settingsTab = 'team'; location.hash = '#/settings?tab=team'; };
  pages.settings = (v) => {
    const tab = S.settingsTab || 'general'; S.settingsTab = null;
    const T = { general: 'General', profile: 'Profile', team: 'Team', billing: 'Billing', security: 'Security' };
    // Each tab is drawn by the module that owns it: account.js (General, Profile, Security), edition.js / Pro (Team, Billing).
    const loading = () => '<div class="card"><div class="card-body faint">Loading…</div></div>';
    v.innerHTML = `<div class="page-head"><div><h1>Settings</h1><p>Workspace, team, billing and security.</p></div></div><div class="ptabs">${Object.entries(T).map(([k, t]) => `<button class="ptab ${k === tab ? 'on' : ''}" data-st="${k}">${t}</button>`).join('')}</div><div id="spane">${loading()}</div>`;
    $$('[data-st]').forEach((b) => b.onclick = () => { $$('[data-st]').forEach((x) => x.classList.remove('on')); b.classList.add('on'); $('#spane').innerHTML = loading(); });
  };

  // ═══════ Connect Amazon SES ═══════
  const SES_LETTER = `Hello AWS team,

We'd like to move our account out of the SES sandbox in eu-west-1.

Who we are: Acme Inc (acme-corp.io), a SaaS company with about 12,800 customers.

What we send:
• Transactional email (receipts, password resets, sign-in alerts), about 40,000 per month.
• A monthly product newsletter to customers who opted in, about 12,000 per month.

How recipients join: they create an account on acme-corp.io and confirm their address; newsletter subscribers opt in explicitly.

How we handle bounces and complaints: bounce and complaint events are published from our configuration set to our platform (${SL_BN()}), which suppresses hard bounces and complaints automatically and stops all sending to those addresses. Every marketing email includes a one-click unsubscribe header and a visible unsubscribe link.

Our current metrics with our previous provider: bounce rate 1.6%, complaint rate 0.08%.

Expected volume: about 55,000 emails per month, peaking at 3,000 per hour.

Thank you.`;
  function connectSes() {
    modal({
      title: 'Connect your Amazon SES', desc: 'You keep paying AWS directly, about $0.10 per 1,000 emails. ' + SL_BN() + ' adds the dashboard, CRM and Copilot.', icon: 'ses', size: 'lg', finishLabel: 'Done',
      steps: [
        { label: 'Connect', body: `<div class="radio-cards"><label class="radio-card on"><span class="dot"></span><div><b>One-click with CloudFormation (recommended)</b><span>Opens AWS and creates a limited role for ${SL_BN()}. No keys to copy, and you can revoke it any time.</span></div></label><label class="radio-card"><span class="dot"></span><div><b>Paste access keys</b><span>For accounts that can't use CloudFormation. Create an IAM user with the policy we provide.</span></div></label></div><div class="row2" style="margin-top:14px"><div class="field"><label>AWS region</label><select><option>EU (Ireland) · eu-west-1</option><option>US East (N. Virginia) · us-east-1</option><option>EU (Frankfurt) · eu-central-1</option></select></div><div class="field"><label>Permissions requested</label><input value="Send email, manage identities & events" disabled></div></div><div class="callout info">${ic('shield', 16)}<div>${SL_BN()} never sees your AWS password and can't touch anything outside SES and its event topic.</div></div>`, mount: radioCards, nextLabel: 'Open AWS and connect' },
        { label: 'Autopilot setup', body: `<p class="muted" style="margin-bottom:10px">Connected to AWS account <span class="mono">4821-****-7730</span>. Setting everything up for you…</p><div class="checklist" id="ck">${[['Verify the connection', 'eu-west-1'], ['Create configuration set "sendline-events"', 'Tracks every email station by station'], ['Publish SES events to ' + SL_BN(), 'Sends, deliveries, bounces, complaints, opens, clicks'], ['Import your verified domains', '2 found: acme-corp.io, acme.dev'], ['Check DKIM and SPF', 'acme.dev still pending DNS'], ['Check sending limits', 'Sandbox: 200 emails a day']].map(([a, b], i) => `<div class="ck" data-i="${i}"><span class="st"></span><div>${a}<small>${b}</small></div><span class="r"></span></div>`).join('')}</div>`,
          mount: (r) => { const items = $$('.ck', r); let i = 0; const next = () => { if (!items[i] || !document.body.contains(items[i])) return; items[i].classList.add('run'); setTimeout(() => { if (!items[i]) return; items[i].classList.remove('run'); const warn = i >= 4; items[i].classList.add(warn ? 'warn' : 'done'); $('.st', items[i]).innerHTML = warn ? '!' : ic('check', 12, 3); $('.r', items[i]).innerHTML = warn ? '<span class="pill-warn">Needs attention</span>' : '<span class="pill-ok">Done</span>'; i++; next(); }, 650); }; next(); },
          hint: 'Takes about 10 seconds', nextLabel: 'Leave the sandbox' },
        { label: 'Production access', body: `<div class="callout warn" style="margin-top:0">${ic('alert', 16)}<div><b>Your account is in the SES sandbox.</b> You can only send 200 emails a day, and only to verified addresses, until AWS approves production access.</div></div><div class="cp-label" style="margin-top:16px">${ic('copilot', 13)} Copilot drafted your request</div><p class="faint" style="font-size:12.5px;margin-bottom:8px">Based on your domains, volumes and bounce handling. AWS approves clear, specific requests much faster. Edit anything that isn't accurate.</p><textarea class="letter" id="letter" style="width:100%;min-height:240px;font-family:inherit">${SES_LETTER}</textarea><div style="display:flex;gap:8px;margin-top:10px"><button class="btn btn-sm" data-copy="${esc(SES_LETTER)}">${ic('copy', 14)}Copy request</button><button class="btn btn-sm">${ic('link', 14)}Open AWS support case</button></div>`, mount: (r) => bindCopy(r) },
      ],
      onFinish: () => toast('Amazon SES connected. We\'ll notify you when AWS approves production access'),
    });
  }
  // ═══════ Migrate & save ═══════
  const PROVIDERS = [['Mailchimp', '#FFE01B', '#241C15'], ['SendGrid', '#1A82E2', '#fff'], ['Brevo', '#0B996E', '#fff'], ['Klaviyo', '#232426', '#fff'], ['Mailgun', '#F06B66', '#fff'], ['Kit', '#FB6970', '#fff'], ['CSV file', '#71717A', '#fff']];
  const planFor = (contacts) => contacts <= 25000 ? ['Starter', 29] : contacts <= 100000 ? ['Growth', 79] : ['Scale', 199];
  function migrateWizard(prov) {
    modal({
      title: 'Import from ' + prov, desc: 'Bring everything over without losing your sender reputation.', icon: 'migrate', size: 'lg', finishLabel: 'Done',
      steps: [
        { label: 'Connect', body: prov === 'CSV file' ? `<div class="dropzone"><div class="ic">${ic('upload', 22)}</div><b>Drop your export here</b><p class="faint" style="font-size:12.5px;margin-top:4px">Contacts, unsubscribes and bounces as CSV</p></div>` : `<div class="field"><label>${prov} API key</label><input id="pk" placeholder="Paste your ${prov} API key"><span class="help">Find it in ${prov} under Account → API keys. Read-only access is enough; we never send through ${prov}.</span></div>`, validate: (r) => { const k = $('#pk', r); if (k && k.value.trim().length < 6) return fieldError(r, '#pk', 'Paste a valid API key.'); } },
        { label: 'Choose data', body: `<p class="muted" style="margin-bottom:12px">We found this in your ${prov} account:</p><div class="checklist">${[['Contacts', '12,840 contacts with custom fields', true, false], ['Unsubscribes, bounces and spam complaints', '1,203 addresses. Always imported, so you never email someone who opted out', true, true], ['Tags and segments', '18 segments, 26 tags', true, false], ['Templates', '24 templates, converted to ' + SL_BN() + ' format', true, false], ['Automations', '3 journeys, recreated as paused drafts for you to review', false, false]].map(([a, b, on, lock]) => `<label class="ck" style="cursor:pointer"><input type="checkbox" ${on ? 'checked' : ''} ${lock ? 'disabled' : ''} style="accent-color:var(--accent);width:16px;height:16px"><div>${a}<small>${b}</small></div>${lock ? '<span class="r tag">Required</span>' : ''}</label>`).join('')}</div>` },
        { label: 'Review', body: `<div class="copilot" style="margin-bottom:0"><div class="cp-head">${ic('copilot', 15, 2)}<span>Copilot checked your data</span></div><ul class="cp-fix" style="margin-top:10px">${['14 templates use ' + prov + ' merge tags like <span class="mono">*|FNAME|*</span>. Converted to <span class="mono">{{first_name}}</span>.', 'Segment "VIP buyers" uses purchase data that isn\'t in ' + prov + '\'s export. Created as an empty segment for you to reconnect through the API.', '312 contacts haven\'t opened anything in 12 months. Imported and tagged <span class="mono">dormant</span>; we suggest excluding them from your first broadcast.', 'Your first broadcast from a new setup should go to your most engaged contacts first. We\'ll warm up automatically.'].map((t) => `<li>${ic('check', 14, 2.4)}<span>${t}</span></li>`).join('')}</ul></div>`, nextLabel: 'Start import' },
        { label: 'Import', body: `<div id="imp" style="display:grid;gap:14px">${[['Unsubscribes & suppressions', 1203], ['Contacts', 12840], ['Segments & tags', 44], ['Templates', 24]].map(([a, n]) => `<div><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span>${a}</span><span class="faint" data-n="${n}">0 / ${fmt(n)}</span></div><div class="progress"><span style="width:0%"></span></div></div>`).join('')}</div><div id="impdone"></div>`,
          mount: (r) => { const rows = $$('#imp > div', r); let p = 0; const t = setInterval(() => { if (!document.body.contains(r) || !$('#imp', r)) return clearInterval(t); p += 7; rows.forEach((row, i) => { const v2 = Math.max(0, Math.min(100, p - i * 12)); $('.progress span', row).style.width = v2 + '%'; const n = +$('[data-n]', row).dataset.n; $('[data-n]', row).textContent = fmt(n * v2 / 100) + ' / ' + fmt(n); }); if (p >= 140) { clearInterval(t); $('#impdone', r).innerHTML = `<div class="callout ok">${ic('check', 16, 2.5)}<div><b>Migration complete.</b> Everything from ${prov} is in ${SL_BN()}, and your unsubscribes are protected.</div></div>`; } }, 120); } },
      ],
      onFinish: () => toast('Imported from ' + prov),
    });
  }
  Object.assign(S.demos, { invite: S.inviteMember, ses: connectSes, migrate: () => migrateWizard('Mailchimp') });
  S.CAMPAIGNS = CAMPAIGNS; S.TEMPLATES = TEMPLATES; S.PROVIDERS = PROVIDERS; S.SES_LETTER = SES_LETTER; S.planFor = planFor;
})();
