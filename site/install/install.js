/* MailVesper browser installer: server check → database → address & email → admin → done. Talks to /api/v1/install. */
(function () {
  'use strict';
  const API = '/api/v1/install';
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const I = {
    ok: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>',
    bad: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    warn: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>',
  };
  const card = $('#card');
  const st = { step: 'check', status: null, token: '', db: { driver: 'mysql', host: 'localhost', port: '3306', name: '', user: '', password: '' }, site: { url: '' }, mail: { mailer: 'log', from: '', host: '', port: '587', user: '', password: '' }, admin: { name: '', email: '', password: '' } };

  async function api(method, path, body) {
    const h = { Accept: 'application/json' };
    if (body) h['Content-Type'] = 'application/json';
    if (st.token) h['X-Install-Token'] = st.token;
    let r;
    try { r = await fetch(API + path, { method, headers: h, body: body ? JSON.stringify(body) : undefined }); } catch (e) { throw new Error('Couldn’t reach the server. Check your connection and try again.'); }
    let data = {};
    try { data = await r.json(); } catch (e) { /* not JSON: PHP error page or wrong folder */ }
    if (r.status === 404 && path === '') return { installed: true };
    if (!r.ok || data.ok === false) {
      const err = new Error(data.message || data.error?.message || (r.status === 404 ? 'MailVesper is already installed here.' : `The server answered ${r.status}. Check that the domain points at the zip’s public_html folder.`));
      err.step = data.step; err.fields = data.fields; throw err;
    }
    return data;
  }

  const dots = (n) => `<div class="steps-dots">${[1, 2, 3, 4].map((i) => `<i class="${i <= n ? 'on' : ''}"></i>`).join('')}</div>`;
  const head = (h, sub) => `<h1 class="au-h">${h}</h1>${sub ? `<p class="au-sub">${sub}</p>` : ''}`;
  const field = (id, label, type, val, o = {}) => `<div class="fld" id="f-${id}"><label for="${id}">${label}</label><div class="inp"><input id="${id}" type="${type}" value="${esc(val)}" ${o.ph ? `placeholder="${esc(o.ph)}"` : ''} ${o.ac ? `autocomplete="${o.ac}"` : 'autocomplete="off"'} spellcheck="false" autocapitalize="off"></div>${o.hint ? `<div class="msg info"><span>${o.hint}</span></div>` : ''}<div class="msg-slot"></div></div>`;
  const note = (kind, text) => `<div class="note ${kind}">${I[kind === 'bad' ? 'bad' : 'warn']}<span>${text}</span></div>`;
  const val = (id) => ($('#' + id)?.value ?? '').trim();
  const busy = (btn, on, label) => { btn.disabled = on; if (on) { btn.dataset.l = btn.innerHTML; btn.innerHTML = `<span class="spin"></span>${label}`; } else if (btn.dataset.l) btn.innerHTML = btn.dataset.l; };
  const showErr = (text) => { $('#err').innerHTML = text ? note('bad', esc(text)) : ''; };
  const choice = (name, cur, opts) => `<div class="choice" data-choice="${name}">${opts.map(([k, a, b]) => `<button type="button" class="ch ${k === cur ? 'on' : ''}" data-k="${k}"><span><b>${a}</b><small>${b}</small></span></button>`).join('')}</div>`;
  const bindChoice = (name, fn) => card.querySelectorAll(`[data-choice="${name}"] .ch`).forEach((b) => { b.onclick = () => fn(b.dataset.k); });

  const views = {
    async check() {
      card.innerHTML = `${head('Install MailVesper', 'Checking this server…')}`;
      let s;
      try { s = await api('GET', ''); } catch (e) { card.innerHTML = `${head('Install MailVesper')}<div class="au-body">${note('bad', esc(e.message))}<button class="btn primary" id="again">Try again</button></div>`; $('#again').onclick = () => views.check(); return; }
      if (s.installed) { card.innerHTML = `${head('MailVesper is installed', 'This installer has closed. Log in with the admin account you created.')}<div class="au-body"><a class="btn primary" href="/login/">Log in</a></div>`; return; }
      st.status = s;
      st.site.url = st.site.url || s.defaults.url; st.mail.from = st.mail.from || s.defaults.mail_from;
      const mark = (c) => c.ok ? ['ok', I.ok] : c.required ? ['bad', I.bad] : ['warn', I.warn];
      const row = (c) => { const [k, ic] = mark(c); return `<li><span class="mk ${k}">${ic}</span><span><b>${esc(c.name)}</b><small>${esc(c.detail)}</small></span></li>`; };
      const blocking = s.checks.filter((c) => !c.ok && c.required), optional = s.checks.filter((c) => !c.ok && !c.required);
      if (s.ready) {
        card.innerHTML = `${dots(1)}<div class="ready">${I.ok}</div>${head('Your server is ready', 'Everything MailVesper needs is here.')}
          <div class="au-body">${optional.map((c) => note('warn', `<b>${esc(c.name)}:</b> ${esc(c.detail)}`)).join('')}
          <details class="more"><summary>What was checked</summary><ul class="checks">${s.checks.map(row).join('')}</ul></details>
          <button class="btn primary" id="next">Continue</button></div>`;
        $('#next').onclick = () => go('database');
        return;
      }
      const forHost = `Hello, I’m installing a PHP app (MailVesper) on ${location.host}. Could you please fix this for me?\n\n${blocking.map((c) => '- ' + c.detail).join('\n')}\n\nThank you!`;
      card.innerHTML = `${dots(1)}${head(blocking.length === 1 ? 'One thing to fix first' : 'A few things to fix first', 'Your hosting company’s support can fix this for you, usually in minutes. Send them the message below, then check again.')}
        <div class="au-body"><ul class="checks">${blocking.map(row).join('')}</ul>
        <div class="in-sec">Message for your hosting company</div><div class="cron" id="hostmsg">${esc(forHost).replace(/\n/g, '<br>')}</div>
        <div class="btn-row"><button type="button" class="btn ghost" id="copy">Copy the message</button><button class="btn primary" id="again">Check again</button></div></div>`;
      $('#again').onclick = () => views.check();
      $('#copy').onclick = async () => { try { await navigator.clipboard.writeText(forHost); $('#copy').textContent = 'Copied'; } catch (e) { $('#copy').textContent = 'Select the text and copy it'; } };
    },

    database() {
      const d = st.db;
      d.driver = 'mysql';
      card.innerHTML = `${dots(2)}${head('Connect a database', 'MailVesper keeps your contacts and emails in a MySQL database. Creating one takes a minute:')}
        <div class="au-body"><ol class="how" style="margin:0 0 14px"><li>In cPanel, open <b>MySQL® Database Wizard</b>.</li><li>Type a database name (for example <b>mailvesper</b>) and click <b>Next Step</b>.</li><li>Type a username and a password, and click <b>Create User</b>. Keep the password: you need it below.</li><li>Tick <b>ALL PRIVILEGES</b> and click <b>Next Step</b>.</li></ol>
        <form id="frm" novalidate>${field('dbname', 'Database name', 'text', d.name, { ph: 'e.g. cpaneluser_mailvesper', hint: 'cPanel puts your account name and _ in front of it. Type the full name it shows.' })}${field('dbuser', 'Database username', 'text', d.user, { ph: 'e.g. cpaneluser_mailvesper' })}${field('dbpass', 'Database password', 'password', d.password, { ac: 'new-password' })}
          <details class="more"><summary>Database on another server?</summary><div class="row2">${field('dbhost', 'Host', 'text', d.host)}${field('dbport', 'Port', 'text', d.port)}</div><p class="faint-note">Leave these as they are on cPanel hosting.</p></details>
          <div id="err" style="margin-top:12px"></div><div class="btn-row"><button type="button" class="btn ghost" id="back">Back</button><button class="btn primary" id="next">Continue</button></div></form></div>`;
      $('#back').onclick = () => views.check();
      const save = () => Object.assign(st.db, { name: val('dbname'), user: val('dbuser'), password: $('#dbpass').value, host: val('dbhost') || 'localhost', port: val('dbport') || '3306' });
      $('#frm').onsubmit = async (e) => {
        e.preventDefault(); save(); showErr('');
        if (!st.db.name || !st.db.user) return showErr('Enter the database name and username from cPanel.');
        const btn = $('#next'); busy(btn, true, 'Connecting…');
        try { await api('POST', '/database', { db: st.db }); go('site'); } catch (err) { busy(btn, false); showErr(err.message); }
      };
    },

    site() {
      const m = st.mail, smtp = m.mailer === 'smtp';
      card.innerHTML = `${dots(3)}${head('Address and email', 'Where people open MailVesper, and how it sends its own emails: sign-in codes, password resets and team invites. Your campaigns are set up later, in the app.')}
        <div class="au-body"><form id="frm" novalidate>${field('url', 'Website address', 'url', st.site.url, { hint: 'Filled in for you. Change it only if it’s wrong.' })}
          <div class="in-sec">MailVesper’s own emails</div>${choice('mailer', smtp ? 'smtp' : 'log', [['smtp', 'An SMTP server', 'Your host’s mail server, or any email provider'], ['log', 'Set it up later', 'Codes and resets go to the log file until then']])}
          ${smtp ? `<div style="margin-top:12px">${field('mfrom', 'From address', 'email', m.from)}<div class="row2">${field('mhost', 'SMTP server', 'text', m.host, { ph: 'mail.example.com' })}${field('mport', 'Port', 'text', m.port)}</div>${field('muser', 'SMTP username', 'text', m.user)}${field('mpass', 'SMTP password', 'password', m.password, { ac: 'new-password' })}</div>` : ''}
          <div id="err" style="margin-top:12px"></div><div class="btn-row"><button type="button" class="btn ghost" id="back">Back</button><button class="btn primary" id="next">Continue</button></div></form></div>`;
      const save = () => { st.site.url = val('url').replace(/\/+$/, ''); if (st.mail.mailer === 'smtp') Object.assign(st.mail, { from: val('mfrom'), host: val('mhost'), port: val('mport') || '587', user: val('muser'), password: $('#mpass').value }); };
      bindChoice('mailer', (k) => { save(); st.mail.mailer = k; views.site(); });
      $('#back').onclick = () => { save(); go('database'); };
      $('#frm').onsubmit = (e) => {
        e.preventDefault(); save(); showErr('');
        if (!/^https?:\/\/[^/\s]+$/i.test(st.site.url)) return showErr('Enter the full address, like https://mail.example.com (no path after the domain).');
        if (st.mail.mailer === 'smtp' && (!st.mail.host || !st.mail.from)) return showErr('Enter the SMTP server and the From address, or choose “Set it up later”.');
        go('admin');
      };
    },

    admin() {
      const a = st.admin;
      card.innerHTML = `${dots(4)}${head('Your admin account', 'The one login that runs this MailVesper. You can add as many workspaces (companies) as you like after you log in.')}
        <div class="au-body"><form id="frm" novalidate>${field('aname', 'Your name', 'text', a.name, { ac: 'name' })}${field('aemail', 'Email', 'email', a.email, { ac: 'username' })}${field('apass', 'Password', 'password', a.password, { ac: 'new-password', hint: 'At least 12 characters.' })}
          <div id="err" style="margin-top:12px"></div><div class="btn-row"><button type="button" class="btn ghost" id="back">Back</button><button class="btn primary" id="next">Install</button></div></form></div>`;
      const save = () => Object.assign(st.admin, { name: val('aname'), email: val('aemail'), password: $('#apass').value });
      $('#back').onclick = () => { save(); go('site'); };
      $('#frm').onsubmit = (e) => {
        e.preventDefault(); save(); showErr('');
        if (st.admin.name.length < 2) return showErr('Enter your name.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(st.admin.email)) return showErr('Enter a valid email address.');
        if (st.admin.password.length < 12) return showErr('Use a password of at least 12 characters.');
        go('run');
      };
    },

    async run() {
      const steps = [['settings', 'Saving the settings'], ['tables', 'Creating the database tables'], ['admin', 'Creating your admin account']];
      card.innerHTML = `${head('Installing MailVesper', 'This takes up to a minute. Keep this page open.')}<div class="au-body"><ul class="prog">${steps.map(([k, t]) => `<li id="p-${k}"><span class="mk"></span>${t}</li>`).join('')}</ul><div id="err"></div></div>`;
      const mark = (k, cls, ic) => { const li = $('#p-' + k); li.className = cls; $('.mk', li).innerHTML = ic; };
      const back = { db: 'database', url: 'site', mail: 'site', admin: 'admin' };
      let done;
      for (const [k] of steps) {
        mark(k, 'now', '<span class="spin"></span>');
        try {
          if (k === 'settings') { const r = await api('POST', '/settings', { url: st.site.url, db: st.db, mail: st.mail }); st.token = r.token; }
          if (k === 'tables') await api('POST', '/tables', {});
          if (k === 'admin') done = await api('POST', '/admin', { name: st.admin.name, email: st.admin.email, password: st.admin.password, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' });
          mark(k, 'done', I.ok);
        } catch (err) {
          mark(k, 'bad', I.bad);
          const to = back[err.step];
          $('#err').innerHTML = `${note('bad', esc(err.message))}<div class="btn-row"><button class="btn primary" id="fix">${to ? 'Go back and fix it' : 'Try again'}</button></div>`;
          $('#fix').onclick = () => { st.token = ''; go(to || 'run'); };
          return;
        }
      }
      st.admin.password = ''; st.db.password = ''; st.mail.password = '';
      views.done(done);
    },

    done(r) {
      const cmd = r.cron_command || String(r.cron || '').replace(/^(\S+\s+){5}/, '');
      card.innerHTML = `${dots(4)}${head('Last step: the cron job', 'MailVesper is installed. Now give it a cron job: it’s what sends your scheduled campaigns and runs your automations every minute. It takes two minutes in cPanel.')}
        <div class="au-body"><ol class="how cron-steps">
          <li>In cPanel, open <b>Cron Jobs</b> (in the <b>Advanced</b> section, or type “cron” in cPanel’s search box).</li>
          <li>Scroll down to <b>Add New Cron Job</b>. In <b>Common Settings</b>, choose <b>Once Per Minute (* * * * *)</b>.</li>
          <li>Copy this command and paste it into the <b>Command</b> box:
            <div class="cron" id="cron">${esc(cmd)}</div><div class="btn-row" style="margin-top:8px"><button type="button" class="btn ghost" id="copy">Copy the command</button></div></li>
          <li>Click <b>Add New Cron Job</b>. Leave the “Cron Email” at the top as it is.</li>
          <li>Come back here and click <b>Check it’s working</b>.</li></ol>
        <div id="cronres" style="margin-top:14px"></div>
        <div class="btn-row"><button type="button" class="btn primary" id="check">Check it’s working</button></div>
        <p class="faint-note" style="margin-top:14px">Stuck? Your hosting company can add it for you: send them the command above and ask for it to run “once per minute”. <a href="${esc(r.login)}">Log in now and do this later</a></p></div>`;
      $('#copy').onclick = async () => { try { await navigator.clipboard.writeText(cmd); $('#copy').textContent = 'Copied'; } catch (e) { $('#copy').textContent = 'Select the command and copy it'; } };
      let polling = false;
      $('#check').onclick = async () => {
        if (polling) return;
        polling = true;
        const btn = $('#check'); busy(btn, true, 'Waiting for the cron job… (up to a minute)');
        $('#cronres').innerHTML = '';
        const until = Date.now() + 150000;
        while (Date.now() < until) {
          try { const x = await (await fetch('/api/v1/auth/cron', { headers: { Accept: 'application/json' } })).json(); if (x.running) break; } catch (e) { /* keep trying */ }
          await new Promise((ok) => setTimeout(ok, 5000));
        }
        let running = false;
        try { running = !!(await (await fetch('/api/v1/auth/cron', { headers: { Accept: 'application/json' } })).json()).running; } catch (e) { /* no */ }
        polling = false; busy(btn, false);
        if (running) {
          card.innerHTML = `<div class="ready">${I.ok}</div>${head('All done: the cron job is working', 'MailVesper is installed and ready. Log in with the email and password you chose.')}<div class="au-body"><a class="btn primary" href="${esc(r.login)}">Log in to MailVesper</a></div>`;
        } else {
          $('#cronres').innerHTML = note('bad', 'The cron job hasn’t run yet. Check that <b>Common Settings</b> says <b>Once Per Minute</b> and that the whole command was pasted (it starts with <b>cd</b> and ends with <b>2&gt;&amp;1</b>), then click Check again.');
          btn.textContent = 'Check again';
        }
      };
    },
  };

  function go(step) { st.step = step; views[step](); const f = card.querySelector('input'); if (f) f.focus(); }
  views.check();
})();
