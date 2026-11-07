/* MailVesper: "Connect to MailVesper" (#/connect). A website with the MailVesper package sends its owner here with
   redirect_uri, state, code_challenge (S256) and name. The owner picks a workspace that can send and approves;
   MailVesper returns to the site with a one-time code. Also: Connected sites, shown on the API keys page. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast, confirmDialog } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { connect: 'Connect a site' });

  const ROLE_OK = ['owner', 'admin'];
  const card = (inner) => `<div class="cn-wrap"><div class="card cn-card"><div class="card-body">${inner}</div></div></div>`;

  pages.connect = (v) => {
    const seq = S.routeSeq;
    const p = { redirect_uri: S.param('redirect_uri') || '', state: S.param('state') || '', code_challenge: S.param('code_challenge') || '', code_challenge_method: S.param('code_challenge_method') || '', name: S.param('name') || '' };
    if (!p.redirect_uri || !p.state || !p.code_challenge || p.code_challenge_method !== 'S256') {
      v.innerHTML = card(`<h2 class="cn-h">This connection link isn’t complete</h2><p class="faint">Go back to your site’s admin and click <b>Connect to ${SL_BN()}</b> again.</p>`);
      return;
    }
    v.innerHTML = card('<p class="faint">Checking the request…</p>');
    API('GET', '/v1/connect/check?' + new URLSearchParams({ redirect_uri: p.redirect_uri, state: p.state }).toString()).then((chk) => {
      if (seq !== S.routeSeq) return;
      const wss = (me().workspaces || []).filter((w) => ROLE_OK.includes(w.role));
      const siteName = p.name.trim() || chk.host;
      let wsSel = (wss.find((w) => w.id === me().current_workspace_id) || wss[0] || {}).id;
      const head = `<div class="cn-logos"><span class="cn-site">${esc(siteName.charAt(0).toUpperCase())}</span><span class="cn-dots">···</span><span class="cn-sl">${ic('send', 18)}</span></div>
        <h2 class="cn-h">Connect ${esc(siteName)} to ${SL_BN()}</h2>
        <p class="cn-host">${ic('lock', 13)}<span class="mono">${esc(chk.host)}</span></p>`;
      if (!wss.length) {
        v.innerHTML = card(`${head}<div class="callout warn">${ic('alert', 15)}<div>Only a workspace’s owners and admins can connect sites, and you aren’t one in any workspace. Ask an owner, or create your own workspace.</div></div><div class="cn-acts"><a class="btn" href="${esc(chk.cancel_url)}">Back to the site</a></div>`);
        return;
      }
      const draw = (rd) => {
        const ok = rd && rd.ready;
        v.innerHTML = card(`${head}
          <p class="cn-lead">Only connect sites you run. ${esc(siteName)} will be able to:</p>
          <ul class="cn-list"><li>${ic('contacts', 15)}<span><b>Let this workspace read its users live</b> to count, browse and email them. They’re read from the site when needed and never copied into ${SL_BN()}</span></li><li>${ic('send', 15)}<span><b>Send its emails</b> through this workspace’s sending setup, tracked in Activity</span></li>
            <li>${ic('webhooks', 15)}<span><b>Get notified</b> of its emails’ delivery, bounces, opens and unsubscribes</span></li></ul>
          <div class="field"><label>Workspace</label><select class="select" id="cnws">${wss.map((w) => `<option value="${esc(w.id)}" ${w.id === wsSel ? 'selected' : ''}>${esc(w.name)}</option>`).join('')}</select></div>
          <div id="cnrd">${!rd ? '<p class="faint" style="font-size:13px">Checking this workspace…</p>' : ok ? `<div class="callout ok">${ic('check', 15)}<div>Ready to send through <b>${rd.method === 'smtp' ? 'your SMTP server' : 'Amazon SES'}</b>${rd.domains.length ? ' from ' + rd.domains.map(esc).join(', ') : rd.emails.length ? ' as ' + rd.emails.map(esc).join(', ') : ''}.</div></div>`
            : `<div class="callout warn">${ic('alert', 15)}<div><b>This workspace can’t send email yet.</b> ${esc(rd.reason || '')}<div class="cn-fix"><a class="btn btn-sm btn-primary" href="#/setup" target="_blank" rel="noopener" id="cnsetup">Set up sending</a><button class="btn btn-sm" id="cnagain">Check again</button></div></div></div>`}</div>
          <div class="cn-acts"><a class="btn" href="${esc(chk.cancel_url)}" id="cncancel">Cancel</a><button class="btn btn-primary" id="cnok" ${ok ? '' : 'disabled'}>Approve and connect</button></div>
          <p class="cn-note">The site gets its own key, listed under API keys → Connected sites. Disconnect it there at any time.</p>`);
        $('#cnws').onchange = (e) => { wsSel = e.target.value; check(); };
        const again = $('#cnagain'); if (again) again.onclick = () => check();
        const setup = $('#cnsetup'); if (setup) setup.onclick = () => { if (wsSel !== me().current_workspace_id) API('POST', `/v1/workspaces/${wsSel}/switch`).catch(() => {}); };
        $('#cnok').onclick = () => {
          const b = $('#cnok'); b.disabled = true; b.textContent = 'Connecting…';
          API('POST', `/v1/workspaces/${wsSel}/connect/approve`, p).then((r) => { location.href = r.redirect; })
            .catch((err) => { b.disabled = false; b.textContent = 'Approve and connect'; toast(err.message); if (err.code === 'not_ready') check(); });
        };
      };
      const check = () => { draw(null); API('GET', `/v1/workspaces/${wsSel}/connect/readiness`).then((r) => { if (seq === S.routeSeq) draw(r.readiness); }).catch((err) => { toast(err.message); }); };
      check();
    }).catch((err) => {
      if (seq !== S.routeSeq) return;
      v.innerHTML = card(`<h2 class="cn-h">This connection request isn’t valid</h2><p class="faint">${esc(err.message)}</p>`);
    });
  };

  // Connected sites, under the API keys page.
  S.connectedSites = (box) => API('GET', `/v1/workspaces/${me().current_workspace_id}/connections`).then((r) => {
    if (!document.body.contains(box)) return;
    const when = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : 'Never');
    box.innerHTML = `<div class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>Connected sites</h3><div class="sub">Websites connected with “Connect to ${SL_BN()}” (the ${SL_BN()} package for Laravel). Each has its own key.</div></div></div>
      ${r.sites.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Site</th><th>Key</th><th>Connected</th><th>Last used</th><th></th></tr></thead><tbody>${r.sites.map((x) => `<tr style="cursor:default"><td><div class="cell-main">${esc(x.name)}${x.live ? ' <span class="tag tag-live">Live audience</span>' : ''}</div><div class="cell-sub mono">${esc(x.site_url)}</div>${x.audience_error ? `<div class="cell-sub" style="color:var(--s-deferred)">${esc(x.audience_error)}</div>` : ''}</td><td><span class="trk">${esc(x.key_prefix || '')}••••</span></td><td class="faint">${when(x.connected_at)}${x.connected_by ? '<div class="cell-sub">by ' + esc(x.connected_by) + '</div>' : ''}</td><td class="faint">${when(x.last_used_at)}</td><td style="text-align:right"><button class="btn btn-sm btn-ghost" data-dc="${esc(x.id)}" data-n="${esc(x.name)}">Disconnect</button></td></tr>`).join('')}</tbody></table></div>`
        : '<div class="card-body faint" style="font-size:13px">No sites yet. In your site’s admin, open the ' + SL_BN() + ' settings and click <b>Connect to ' + SL_BN() + '</b>.</div>'}</div>`;
    $$('[data-dc]', box).forEach((b) => b.onclick = () => confirmDialog({ title: `Disconnect “${b.dataset.n}”?`, desc: 'Its key stops working at once: ' + SL_BN() + ' can no longer read its users (its live segments stop working) and its emails go back to its own mail settings.', label: 'Disconnect',
      onConfirm: () => API('DELETE', `/v1/workspaces/${me().current_workspace_id}/connections/${b.dataset.dc}`).then(() => { toast('Site disconnected'); S.connectedSites(box); }).catch((err) => toast(err.message)) }));
  }).catch(() => { box.innerHTML = ''; });
})();
