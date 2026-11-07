/* MailVesper: real account security in Settings → Security (two-step verification).
   Talks to /api/v1/account/two-factor/*. Loaded after pages.js; wraps the settings page. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast, modal, fieldError, confirmDialog, menu, avatar, ago } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {} };

  const card = () => {
    const u = me().user, on = !!u.two_factor;
    return `<div class="card" id="acct-sec" style="margin-bottom:16px"><div class="card-head"><div><h3>Your account</h3><div class="sub">${esc(u.email || '')}</div></div></div><div class="card-body">
      <div class="setting-row"><div><b>Two-step verification ${on ? '<span class="pill-ok" style="margin-left:6px">On</span>' : '<span class="faint" style="margin-left:6px;font-weight:500">Off</span>'}</b><span>${on ? `An authenticator app code is required at sign-in. ${u.recovery_codes_left} recovery code${u.recovery_codes_left === 1 ? '' : 's'} left.` : 'Protect your account with a code from an authenticator app (Google Authenticator, 1Password, Authy, Microsoft Authenticator).'}</span></div>
      <div style="display:flex;gap:8px">${on ? `<button class="btn btn-sm" id="tf-regen">New recovery codes</button><button class="btn btn-sm" id="tf-off">Turn off</button>` : `<button class="btn btn-primary btn-sm" id="tf-on">${ic('shield', 14)}Turn on</button>`}</div></div>
      <div class="setting-row" style="border-bottom:0;padding-bottom:4px"><div><b>Passkeys</b><span>Sign in with your fingerprint, face or device PIN. No password or code needed.</span></div><button class="btn btn-sm" id="pk-add">${ic('plus', 14)}Add a passkey</button></div>
      <div id="pk-box"><div class="faint" style="font-size:13px;padding:10px 0">Loading…</div></div></div></div>`;
  };

  const refreshMe = async () => { try { window.SL_ME = await API('GET', '/v1/me'); } catch (_) {} };
  const showErr = (m, sel, err) => { if (err && err.fields && sel) return fieldError(m.root, sel, Object.values(err.fields)[0]); toast((err && err.message) || 'Something went wrong'); return false; };

  const recoveryView = (codes) => `<p class="faint" style="font-size:13px;margin-bottom:12px">Keep these somewhere safe, like a password manager. Each code works once if you lose your phone. <b>You won't see them again.</b></p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:14px;border-radius:10px;background:var(--surface-2);font-family:var(--mono);font-size:14px">${codes.map((c) => `<span>${esc(c)}</span>`).join('')}</div>
    <div style="display:flex;gap:8px;margin-top:12px"><button class="btn btn-sm" id="rc-copy">${ic('copy', 13)}Copy</button><button class="btn btn-sm" id="rc-dl">${ic('download', 13)}Download .txt</button></div>`;
  const bindRecovery = (root, codes) => {
    $('#rc-copy', root).onclick = () => { navigator.clipboard && navigator.clipboard.writeText(codes.join('\n')); toast('Recovery codes copied'); };
    $('#rc-dl', root).onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([`${SL_BN()} recovery codes for ${me().user.email}\nEach code works once.\n\n${codes.join('\n')}\n`], { type: 'text/plain' })); a.download = 'sendline-recovery-codes.txt'; a.click(); };
  };

  function turnOn(rerender) {
    const needsPw = me().user.has_password !== false;
    modal({
      title: 'Turn on two-step verification', desc: needsPw ? 'First, confirm your password.' : 'Scan a QR code with your authenticator app.', icon: 'shield', size: 'sm',
      body: needsPw ? `<div class="field"><label>Password</label><input type="password" id="tf-pw" autocomplete="current-password"></div>` : '<p class="faint">Continue to get your QR code.</p>',
      actions: [{ label: 'Cancel' }, { label: 'Continue', kind: 'primary', onClick: (m) => {
        const btn = m.root.querySelector('.m-foot .btn-primary'); btn.disabled = true;
        API('POST', '/v1/account/two-factor/setup', needsPw ? { password: $('#tf-pw', m.root).value } : {})
          .then((r) => { m.close(); scan(r, rerender); })
          .catch((err) => { btn.disabled = false; showErr(m, '#tf-pw', err); });
        return false;
      } }],
    });
  }

  function scan(setup, rerender) {
    modal({
      title: 'Scan this QR code', desc: 'Open your authenticator app, add an account, and scan the code.', icon: 'shield', size: 'md',
      body: `<div style="display:grid;grid-template-columns:200px 1fr;gap:18px;align-items:start"><div style="background:#fff;border-radius:12px;padding:8px;border:1px solid var(--border)">${setup.qr_svg}</div>
        <div><div class="faint" style="font-size:12.5px;margin-bottom:6px">Can't scan? Enter this key manually:</div><div class="mono" style="font-size:13px;word-break:break-all;padding:10px;border-radius:8px;background:var(--surface-2)">${esc(setup.secret.replace(/(.{4})/g, '$1 ').trim())}</div>
        <div class="field" style="margin-top:16px"><label>Enter the 6-digit code from the app</label><input id="tf-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="mono" style="font-size:18px;letter-spacing:.3em"></div></div></div>`,
      actions: [{ label: 'Cancel' }, { label: 'Turn on', kind: 'primary', onClick: (m) => {
        const code = $('#tf-code', m.root).value.replace(/\D/g, '');
        if (code.length !== 6) return fieldError(m.root, '#tf-code', 'Enter the 6-digit code.');
        const btn = m.root.querySelector('.m-foot .btn-primary'); btn.disabled = true;
        API('POST', '/v1/account/two-factor/confirm', { code })
          .then((r) => { m.close(); showCodes(r.recovery_codes, 'Two-step verification is on', rerender); })
          .catch((err) => { btn.disabled = false; showErr(m, '#tf-code', err); });
        return false;
      } }],
    });
  }

  function showCodes(codes, title, rerender) {
    modal({ title, desc: 'Save your recovery codes now.', icon: 'check', size: 'sm', body: recoveryView(codes), mount: (root) => bindRecovery(root, codes),
      actions: [{ label: 'I’ve saved them', kind: 'primary', onClick: () => { refreshMe().then(rerender); } }] });
  }

  function withCode(title, desc, label, path, done, rerender) {
    modal({ title, desc, icon: 'shield', size: 'sm', body: `<div class="field"><label>Current code from your authenticator app</label><input id="tf-c" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="mono" style="font-size:18px;letter-spacing:.3em"></div>`,
      actions: [{ label: 'Cancel' }, { label, kind: label === 'Turn off' ? 'danger' : 'primary', onClick: (m) => {
        const code = $('#tf-c', m.root).value.replace(/\D/g, '');
        if (code.length !== 6) return fieldError(m.root, '#tf-c', 'Enter the 6-digit code.');
        API('POST', path, { code }).then((r) => { m.close(); done(r, rerender); }).catch((err) => showErr(m, '#tf-c', err));
        return false;
      } }] });
  }

  function mount() {
    const pane = $('#spane'); if (!pane || !$('[data-st="security"].on')) return;
    if ($('#acct-sec')) $('#acct-sec').remove();
    pane.insertAdjacentHTML('afterbegin', card());
    const rerender = () => mount();
    const on = $('#tf-on'); if (on) on.onclick = () => turnOn(rerender);
    const off = $('#tf-off'); if (off) off.onclick = () => withCode('Turn off two-step verification?', 'Your account will only be protected by your password.', 'Turn off', '/v1/account/two-factor/disable', () => { toast('Two-step verification is off'); refreshMe().then(rerender); }, rerender);
    $('#pk-add').onclick = addPasskey;
    renderPasskeys(); mountAudit();
    const rg = $('#tf-regen'); if (rg) rg.onclick = () => withCode('New recovery codes', 'Your old recovery codes stop working.', 'Create new codes', '/v1/account/two-factor/recovery-codes', (r) => showCodes(r.recovery_codes, 'New recovery codes', rerender), rerender);
  }


  // ═══════ Passkeys (Settings → Security). WebAuthn, base64url on the wire. ═══════
  const b2u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const u2b = (str) => Uint8Array.from(atob(str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4)), (c) => c.charCodeAt(0)).buffer;
  const passkeyOK = () => !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

  async function renderPasskeys() {
    const box = $('#pk-box'); if (!box) return;
    let d; try { d = await API('GET', '/v1/account/passkeys'); } catch (err) { box.innerHTML = `<div class="faint" style="font-size:13px">${esc(err.message)}</div>`; return; }
    if (!$('#pk-box')) return;
    box.innerHTML = d.passkeys.length ? d.passkeys.map((k) => `<div class="pk-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--border)">
        <span style="width:32px;height:32px;border-radius:8px;background:var(--surface-2);display:grid;place-items:center;flex:none">${ic('keys', 15)}</span>
        <div style="flex:1;min-width:0"><div class="cell-main">${esc(k.name)}</div><div class="cell-sub">Added ${esc(when(k.created_at))} · ${k.last_used_at ? 'Last used ' + esc(when(k.last_used_at)) : 'Not used yet'}</div></div>
        <button class="btn btn-sm btn-ghost" data-pk-rename="${esc(k.id)}" data-name="${esc(k.name)}" title="Rename">${ic('edit', 14)}</button><button class="btn btn-sm btn-ghost" data-pk-del="${esc(k.id)}" data-name="${esc(k.name)}" title="Remove">${ic('trash', 14)}</button></div>`).join('')
      : '<div class="faint" style="font-size:13px;padding:10px 0;border-top:1px solid var(--border)">No passkeys yet.</div>';
    $$('[data-pk-rename]', box).forEach((b) => b.onclick = () => modal({ title: 'Rename passkey', icon: 'keys', size: 'sm', body: `<div class="field"><label>Name</label><input id="pk-name" maxlength="80" value="${esc(b.dataset.name)}"></div>`,
      actions: [{ label: 'Cancel' }, { label: 'Save', kind: 'primary', onClick: (m) => {
        const name = $('#pk-name', m.root).value.trim(); if (!name) return fieldError(m.root, '#pk-name', 'Give it a name.');
        API('PATCH', `/v1/account/passkeys/${b.dataset.pkRename}`, { name }).then(() => { m.close(); renderPasskeys(); }).catch((err) => showErr(m, '#pk-name', err));
        return false;
      } }] }));
    $$('[data-pk-del]', box).forEach((b) => b.onclick = () => confirmDialog({ title: `Remove “${b.dataset.name}”?`, desc: 'You won’t be able to sign in with this passkey any more. Also delete it from the device or password manager.', label: 'Remove',
      onConfirm: () => API('DELETE', `/v1/account/passkeys/${b.dataset.pkDel}`).then(() => { toast('Passkey removed'); renderPasskeys(); }).catch((err) => toast(err.message)) }));
  }

  function addPasskey() {
    if (!passkeyOK()) return toast('This browser doesn’t support passkeys.');
    const needsPw = me().user.has_password !== false;
    modal({ title: 'Add a passkey', desc: 'Sign in with your fingerprint, face or device PIN instead of a password.', icon: 'keys', size: 'sm',
      body: `${needsPw ? '<div class="field"><label>Confirm your password</label><input type="password" id="pk-pw" autocomplete="current-password"></div>' : ''}<div class="field"><label>Name <span class="faint">(optional)</span></label><input id="pk-nm" maxlength="80" placeholder="e.g. Work MacBook"></div>`,
      actions: [{ label: 'Cancel' }, { label: 'Continue', kind: 'primary', onClick: (m) => {
        const btn = m.root.querySelector('.m-foot .btn-primary'); btn.disabled = true;
        (async () => {
          const o = await API('POST', '/v1/account/passkeys/options', needsPw ? { password: $('#pk-pw', m.root).value } : {});
          const pk = o.publicKey;
          pk.challenge = u2b(pk.challenge); pk.user.id = u2b(pk.user.id);
          (pk.excludeCredentials || []).forEach((c) => { c.id = u2b(c.id); });
          let cred;
          try { cred = await navigator.credentials.create({ publicKey: pk }); }
          catch (e) { throw { message: e.name === 'InvalidStateError' ? 'This device already has a ' + SL_BN() + ' passkey for your account.' : e.name === 'NotAllowedError' ? 'Cancelled, or the device didn’t respond in time.' : 'Your device couldn’t create a passkey.' }; }
          const r = cred.response;
          await API('POST', '/v1/account/passkeys', { name: $('#pk-nm', m.root).value.trim() || null, client_data_json: b2u(r.clientDataJSON), attestation_object: b2u(r.attestationObject), transports: r.getTransports ? r.getTransports() : [] });
        })().then(() => { m.close(); toast('Passkey added'); renderPasskeys(); })
          .catch((err) => { btn.disabled = false; showErr(m, err && err.fields ? '#pk-pw' : null, err); });
        return false;
      } }] });
  }

  // ═══════ Audit log (Settings → Security), owners and admins ═══════
  const AUDIT = {
    'auth.signup': 'Created their account', 'auth.login': 'Signed in', 'auth.login_failed': 'Failed sign-in attempt', 'auth.logout': 'Signed out', 'auth.code_sent': 'Requested a sign-in code',
    'auth.email_verified': 'Verified their email', 'auth.password_reset': 'Reset their password', 'auth.two_factor_failed': 'Entered a wrong two-step code',
    'security.two_factor_enabled': 'Turned on two-step verification', 'security.two_factor_disabled': 'Turned off two-step verification', 'security.recovery_codes_regenerated': 'Made new recovery codes',
    'security.recovery_code_used': 'Signed in with a recovery code', 'security.social_linked': 'Connected a sign-in provider', 'security.passkey_added': 'Added a passkey', 'security.passkey_removed': 'Removed a passkey',
    'team.invited': 'Invited a teammate', 'team.invitation_revoked': 'Revoked an invitation', 'team.joined': 'Joined the workspace', 'team.role_changed': 'Changed a role', 'team.member_removed': 'Removed a member',
    'team.left': 'Left the workspace', 'team.ownership_transferred': 'Transferred ownership', 'workspace.created': 'Created the workspace',
  };
  const METHOD = { password: 'password', code: 'email code', passkey: 'passkey', two_factor: 'two-step code', recovery_code: 'recovery code' };
  const detail = (e) => {
    const m = e.meta || {};
    if (e.action === 'auth.login') { const via = String(m.method || ''); return via.startsWith('oauth:') ? `with ${cap(via.slice(6))}` : METHOD[via] ? `with ${METHOD[via]}` : ''; }
    if (e.action === 'team.role_changed') return `${m.member || ''}: ${m.from || '?'} → ${m.to || '?'}`;
    if (e.action === 'team.invited') return `${m.email || ''} as ${m.role || 'member'}`;
    if (e.action === 'team.ownership_transferred') return `to ${m.to || ''}`;
    if (e.action === 'security.social_linked') return cap(String(m.provider || ''));
    if (e.action === 'security.recovery_code_used') return `${m.left} left`;
    return m.email || m.member || m.name || m.slug || '';
  };
  const device = (ua) => { ua = ua || ''; const b = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : ''; const o = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac OS/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : ''; return [b, o].filter(Boolean).join(' on ') || ''; };
  const audit = { rows: [], next: null, cat: '' };

  function auditCard() {
    return `<div class="card" id="audit" style="margin-top:16px"><div class="card-head"><div><h3>Audit log</h3><div class="sub">Sign-ins, security and team changes in this workspace.</div></div>
      <div style="display:flex;gap:8px"><select class="select" id="au-cat" style="height:32px"><option value="">All events</option><option value="auth">Sign-ins</option><option value="security">Security</option><option value="team">Team</option><option value="workspace">Workspace</option></select><button class="btn btn-sm" id="au-csv">${ic('download', 14)}Export CSV</button></div></div>
      <div class="table-wrap" style="margin-top:8px"><table class="tbl"><thead><tr><th>Who</th><th>What</th><th>When</th><th>IP · device</th></tr></thead><tbody id="au-body"><tr><td colspan="4" class="faint">Loading…</td></tr></tbody></table></div>
      <div id="au-more" style="padding:10px 16px;display:none"><button class="btn btn-sm">Load more</button></div></div>`;
  }
  const auditRow = (e) => `<tr style="cursor:default"><td><div class="who">${e.actor ? avatar(e.actor.name) : ''}<div><div class="cell-main">${esc(e.actor ? e.actor.name : 'Unknown')}</div><div class="cell-sub">${esc(e.actor ? e.actor.email : '')}</div></div></div></td>
    <td><div class="${/failed/.test(e.action) ? 'cell-main" style="color:var(--danger,#DC2626)' : 'cell-main'}">${esc(AUDIT[e.action] || e.action)}</div><div class="cell-sub">${esc(detail(e))}</div></td>
    <td class="faint" title="${esc(new Date(e.at).toLocaleString())}">${esc(when(e.at))}</td><td><div class="mono faint" style="font-size:12.5px">${esc(e.ip || '')}</div><div class="cell-sub">${esc(device(e.user_agent))}</div></td></tr>`;

  async function loadAudit(more) {
    const body = $('#au-body'); if (!body) return;
    const q = new URLSearchParams(); if (audit.cat) q.set('category', audit.cat); if (more && audit.next) q.set('before', audit.next);
    let d; try { d = await API('GET', `/v1/workspaces/${wsId()}/audit-logs${q.toString() ? '?' + q : ''}`); } catch (err) { body.innerHTML = `<tr><td colspan="4" class="faint">${esc(err.message)}</td></tr>`; return; }
    if (!$('#au-body')) return;
    audit.rows = more ? audit.rows.concat(d.events) : d.events; audit.next = d.next_before;
    body.innerHTML = audit.rows.length ? audit.rows.map(auditRow).join('') : '<tr><td colspan="4" class="faint">No events yet.</td></tr>';
    $('#au-more').style.display = audit.next ? '' : 'none';
  }

  function exportAudit() {
    if (!audit.rows.length) return toast('Nothing to export yet');
    const cell = (v) => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`;
    const lines = [['Time (UTC)', 'Who', 'Email', 'Event', 'Details', 'IP', 'Device', 'Code'].map(cell).join(',')].concat(audit.rows.map((e) => [e.at, e.actor && e.actor.name, e.actor && e.actor.email, AUDIT[e.action] || e.action, detail(e), e.ip, device(e.user_agent), e.action].map(cell).join(',')));
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([lines.join('\r\n')], { type: 'text/csv' })); a.download = `mailvesper-audit-log-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
    toast(`Exported ${audit.rows.length} event${audit.rows.length === 1 ? '' : 's'}${audit.next ? ' (load more to include older ones)' : ''}`);
  }

  function mountAudit() {
    const pane = $('#spane'); if (!pane) return;
    const old = [...pane.querySelectorAll('.card')].find((c) => c.id !== 'audit' && /Audit log/.test((c.querySelector('h3') || {}).textContent || ''));
    if (old) old.remove();
    if ($('#audit')) $('#audit').remove();
    const cur = (me().workspaces || []).find((w) => w.id === wsId());
    if (!cur || !(cur.role === 'owner' || cur.role === 'admin')) return;
    pane.insertAdjacentHTML('beforeend', auditCard());
    audit.cat = ''; audit.rows = []; audit.next = null;
    $('#au-cat').onchange = (e) => { audit.cat = e.target.value; loadAudit(false); };
    $('#au-csv').onclick = exportAudit;
    $('#au-more button').onclick = (e) => { const b = e.currentTarget; b.disabled = true; loadAudit(true).then(() => { b.disabled = false; }); };
    loadAudit(false);
  }

  // ═══════ General (the workspace) and Profile (the signed-in person) ═══════
  const curWs = () => (me().workspaces || []).find((w) => w.id === me().current_workspace_id);
  const applyMe = (r) => { window.SL_ME = Object.assign({}, window.SL_ME, r); delete window.SL_ME.workspace; if (window.SL_APPLY_ME) window.SL_APPLY_ME(window.SL_ME); };
  const clearLoading = (pane) => pane.querySelectorAll('.card:not([id])').forEach((c) => c.remove());
  const inlineErr = (root, err, map) => {
    if (err && err.fields) { let shown = false; Object.entries(err.fields).forEach(([k, msg]) => { if (map[k]) { fieldError(root, map[k], msg); shown = true; } }); if (shown) return; }
    toast((err && err.message) || 'Something went wrong. Please try again.');
  };
  const clearErr = (root) => $$('.field.error', root).forEach((f) => { f.classList.remove('error'); const e = $('.err', f); if (e) e.remove(); });

  function mountGeneral() {
    const pane = $('#spane'); if (!pane || !$('[data-st="general"].on') || $('#ws-gen')) return;
    clearLoading(pane);
    const w = curWs();
    if (!w) { pane.insertAdjacentHTML('beforeend', '<div class="card" id="ws-gen"><div class="card-body">Create a workspace first.</div></div>'); return; }
    const ro = !(w.role === 'owner' || w.role === 'admin');
    pane.insertAdjacentHTML('beforeend', `<div class="card" id="ws-gen"><div class="card-head"><div><h3>Workspace</h3><div class="sub">${ro ? 'Only owners and admins can change these.' : 'Its name shows in the workspace switcher and in team invitations.'}</div></div></div><div class="card-body">
      <div class="row2"><div class="field"><label>Workspace name</label><input id="wsn" maxlength="80" value="${esc(w.name)}" ${ro ? 'disabled' : ''}></div>
      <div class="field"><label>Workspace URL</label><input id="wss" class="mono" maxlength="40" value="${esc(w.slug)}" ${ro ? 'disabled' : ''}><span class="help">Lowercase letters, numbers and single hyphens.</span></div></div>
      ${ro ? '' : '<div style="display:flex;justify-content:flex-end;margin-top:6px"><button class="btn btn-primary" id="wssave">Save changes</button></div>'}</div></div>`);
    if (w.role === 'owner') {
      pane.insertAdjacentHTML('beforeend', `<div class="card" id="ws-del" style="margin-top:16px;border-color:color-mix(in srgb,var(--s-bounced) 40%,var(--border))"><div class="card-body"><div class="setting-row" style="border:0;padding:0"><div><b style="color:var(--s-bounced)">Delete workspace</b><span>Removes its contacts, emails, campaigns, automations, templates, keys and sending settings for everyone. This can’t be undone.</span></div><button class="btn btn-danger btn-sm" id="wsdel">Delete workspace</button></div></div></div>`);
      $('#wsdel').onclick = () => deleteWorkspace(w);
    }
    const save = $('#wssave'); if (!save) return;
    save.onclick = async () => {
      const card = $('#ws-gen'); clearErr(card); save.disabled = true;
      try {
        const r = await API('PATCH', `/v1/workspaces/${w.id}`, { name: $('#wsn').value.trim(), slug: $('#wss').value.trim().toLowerCase() });
        applyMe(r);
        $('#wsn').value = r.workspace.name; $('#wss').value = r.workspace.slug;
        toast('Workspace saved');
      } catch (err) { inlineErr(card, err, { name: '#wsn', slug: '#wss' }); } finally { save.disabled = false; }
    };
  }

  function deleteWorkspace(w) {
    const pw = me().user.has_password !== false;
    modal({
      title: `Delete ${w.name}?`, desc: 'Everyone loses access right away, and its data is removed. Your Amazon SES account and DNS records aren’t touched.', icon: 'alert', tone: 'danger', size: 'sm',
      body: `<div class="field"><label>Type <b>${esc(w.name)}</b> to confirm</label><input id="dl-name" autocomplete="off"></div>
        ${pw ? '<div class="field"><label>Your password</label><input id="dl-pw" type="password" autocomplete="current-password"></div>'
          : '<div class="field"><label>Code from your email</label><div style="display:flex;gap:8px"><input id="dl-code" inputmode="numeric" maxlength="6" class="mono" style="flex:1"><button class="btn" type="button" id="dl-send">Email me a code</button></div></div>'}`,
      mount: (root) => {
        const send = $('#dl-send', root);
        if (send) send.onclick = () => { send.disabled = true; API('POST', `/v1/workspaces/${w.id}/delete-code`).then(() => { send.textContent = 'Code sent'; toast('Check your email for the code'); }).catch((err) => { send.disabled = false; toast(err.message); }); };
      },
      actions: [{ label: 'Cancel' }, { label: 'Delete workspace', kind: 'danger', onClick: (m) => {
        clearErr(m.root);
        const name = $('#dl-name', m.root).value;
        if (name.trim().toLowerCase() !== w.name.trim().toLowerCase()) { fieldError(m.root, '#dl-name', 'Type the workspace’s name exactly.'); return false; }
        const btn = m.root.querySelector('.m-foot .btn-danger'); btn.disabled = true;
        API('DELETE', `/v1/workspaces/${w.id}`, pw ? { confirm_name: name, password: $('#dl-pw', m.root).value } : { confirm_name: name, code: $('#dl-code', m.root).value })
          .then((r) => {
            m.close(); applyMe(r); toast(`${w.name} was deleted`);
            if (r.current_workspace_id) { location.hash = '#/overview'; location.reload(); } else location.href = '/signup/?new=workspace';
          })
          .catch((err) => { btn.disabled = false; inlineErr(m.root, err, { confirm_name: '#dl-name', password: '#dl-pw', code: '#dl-code' }); });
        return false;
      } }],
    });
  }

  function mountProfile() {
    const pane = $('#spane'); if (!pane || !$('[data-st="profile"].on') || $('#acct-prof')) return;
    clearLoading(pane);
    const u = me().user || {};
    let zones = []; try { zones = Intl.supportedValuesOf('timeZone'); } catch (_) {}
    if (u.timezone && !zones.includes(u.timezone)) zones.unshift(u.timezone);
    if (!zones.includes('UTC')) zones.unshift('UTC');
    pane.innerHTML = `<div class="card" id="acct-prof"><div class="card-head"><div><h3>Profile</h3><div class="sub">How you appear to your team.</div></div></div><div class="card-body">
      <div class="row2"><div class="field"><label>Name</label><input id="pfn" maxlength="100" value="${esc(u.name || '')}"></div>
      <div class="field"><label>Email</label><input value="${esc(u.email || '')}" disabled><span class="help">Your sign-in email.</span></div></div>
      <div class="field"><label>Time zone</label><select id="pftz" class="select">${zones.map((z) => `<option ${z === (u.timezone || 'UTC') ? 'selected' : ''}>${esc(z)}</option>`).join('')}</select><span class="help">Used for dates and times you see in ${SL_BN()}.</span></div>
      <div style="display:flex;justify-content:flex-end;margin-top:6px"><button class="btn btn-primary" id="pfsave">Save changes</button></div></div></div>
      <div class="card" id="acct-pw" style="margin-top:16px"><div class="card-head"><div><h3>Password</h3><div class="sub">Changing it signs you out on every other device.</div></div></div><div class="card-body">
      <div class="field"><label>Current password</label><input id="pwc" type="password" autocomplete="current-password"></div>
      <div class="row2"><div class="field"><label>New password</label><input id="pwn" type="password" autocomplete="new-password" minlength="12"><span class="help">At least 12 characters.</span></div>
      <div class="field"><label>Repeat new password</label><input id="pwr" type="password" autocomplete="new-password"></div></div>
      <div style="display:flex;justify-content:flex-end;margin-top:6px"><button class="btn btn-primary" id="pwsave">Change password</button></div></div></div>`;
    const ps = $('#pfsave');
    ps.onclick = async () => {
      const card = $('#acct-prof'); clearErr(card); ps.disabled = true;
      try { applyMe(await API('PATCH', '/v1/account', { name: $('#pfn').value.trim(), timezone: $('#pftz').value })); toast('Profile saved'); }
      catch (err) { inlineErr(card, err, { name: '#pfn', timezone: '#pftz' }); } finally { ps.disabled = false; }
    };
    const pw = $('#pwsave');
    pw.onclick = async () => {
      const card = $('#acct-pw'); clearErr(card);
      if ($('#pwn').value !== $('#pwr').value) return fieldError(card, '#pwr', 'The two new passwords don’t match.');
      pw.disabled = true;
      try {
        const r = await API('POST', '/v1/account/password', { current_password: $('#pwc').value, password: $('#pwn').value });
        ['#pwc', '#pwn', '#pwr'].forEach((s) => ($(s).value = ''));
        toast(r.other_sessions_ended ? `Password changed. Signed out of ${r.other_sessions_ended} other session${r.other_sessions_ended === 1 ? '' : 's'}.` : 'Password changed');
      } catch (err) { inlineErr(card, err, { current_password: '#pwc', password: '#pwn' }); } finally { pw.disabled = false; }
    };
  }

  function mountSecurity() {
    const pane = $('#spane'); if (!pane || !$('[data-st="security"].on')) return;
    clearLoading(pane); mount();
  }

  // ═══════ Team (Settings → Team) ═══════
  const cap = (r) => r.charAt(0).toUpperCase() + r.slice(1);
  const wsId = () => me().current_workspace_id;
  const when = (iso) => { if (!iso) return 'Never'; const t = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('Z') || String(iso).includes('+') ? '' : 'Z')).getTime(); return ago ? ago(t) : new Date(t).toLocaleDateString(); };

  // The members page is MailVesper Pro (team.js); Community shows what it adds (edition.js).
  function renderTeam() {
    const pane = $('#spane'); if (!pane || !$('[data-st="team"].on')) return;
    if (!wsId()) { pane.innerHTML = '<div class="card"><div class="card-body">Create a workspace first.</div></div>'; return; }
    if (S.renderTeamPane) S.renderTeamPane(pane, { wsId: wsId(), refreshMe });
  }

  // ═══════ Workspace switcher (sidebar) ═══════
  function bindSwitcher(tries) {
    const btn = document.getElementById('ws');
    if (!btn || !window.SL_ME) { if ((tries || 0) < 100) setTimeout(() => bindSwitcher((tries || 0) + 1), 50); return; }
    btn.onclick = (e) => {
      e.stopPropagation();
      const list = me().workspaces || [];
      menu(btn, [{ header: 'Workspaces' }, ...list.map((w) => ({ html: `<span class="ws-dot" style="background:#17160F">${esc(w.name.charAt(0).toUpperCase())}</span>${esc(w.name)}`, meta: w.id === me().current_workspace_id ? '✓' : cap(w.role), onClick: () => {
        if (w.id === me().current_workspace_id) return;
        API('POST', `/v1/workspaces/${w.id}/switch`).then(() => { location.reload(); }).catch((err) => toast(err.message));
      } })), 'sep', { label: 'Create workspace', icon: 'plus', onClick: () => (location.href = '/signup/?new=workspace') }, { label: 'Team & settings', icon: 'settings', onClick: () => { S.settingsTab = 'team'; location.hash = '#/settings'; } }]);
    };
  }
  bindSwitcher();

  const original = pages.settings;
  pages.settings = (v) => {
    original(v);
    const all = () => { mountGeneral(); mountProfile(); mountSecurity(); renderTeam(); };
    all();
    document.querySelectorAll('[data-st]').forEach((b) => b.addEventListener('click', () => setTimeout(all, 0)));
  };
})();
