/* MailVesper: real Contacts and CRM (Phase 5).
   Replaces the prototype #/contacts and #/contacts/import, adds #/contacts/segment (rule builder).
   Talks to /api/v1/workspaces/{id}/{contacts,lists,segments,fields,topics,imports}. Loaded after activity.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, modal, confirmDialog, fieldError, drawer, closeDrawer, tabs, menu, avatar, miniRoute } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const canEdit = () => myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}`;
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { 'contacts/segment': 'Segment', 'contacts/import': 'Import contacts', 'forms/edit': 'Signup form' });
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const ago = (iso) => { if (!iso) return '—'; const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000)); return s < 3600 ? Math.max(1, Math.floor(s / 60)) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : s < 86400 * 60 ? Math.floor(s / 86400) + 'd ago' : new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); };
  const day = (iso) => (iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
  const nameOf = (c) => c.name || c.email.split('@')[0];
  const STATUS = { subscribed: ['delivered', 'Subscribed'], unsubscribed: ['dropped', 'Unsubscribed'], bounced: ['bounced', 'Bounced'], complained: ['complained', 'Spam report'] };
  const statusChip = (s) => `<span class="chip ${STATUS[s][0]}">${STATUS[s][1]}</span>`;
  const scoreColor = (s) => (s > 60 ? 'var(--s-delivered)' : s > 30 ? 'var(--s-deferred)' : 'var(--s-dropped)');
  const score = (c) => c.engagement == null ? '<span class="faint" style="font-size:12.5px">New</span>' : `<span class="score"><span class="bar"><span style="width:${c.engagement}%;background:${scoreColor(c.engagement)}"></span></span><span class="faint">${c.engagement}</span></span>${c.dormant ? ' <span class="tag" title="No real opens or clicks in 120 days">dormant</span>' : ''}`;

  // ═══════ #/contacts (tabs: contacts, lists, segments, fields, topics) ═══════
  const view = { tab: 'contacts', q: '', status: '', list: '', tag: '', segment: '' };
  pages.contacts = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    const tab = S.param('tab') || view.tab;
    view.tab = tab;
    v.innerHTML = `<div class="page-head"><div><h1>Contacts</h1><p>Everyone you email, with their history and engagement.</p></div><div style="display:flex;gap:8px">${canEdit() ? `<a class="btn" href="#/contacts/import">${ic('upload', 15)}Import</a><button class="btn btn-primary" id="addc">${ic('plus', 15)}Add contact</button>` : ''}</div></div>
      <div class="ptabs" id="ctabs">${tabBar(tab, liveSites.list || [])}</div><div id="ctab"></div>`;
    const add = $('#addc'); if (add) add.onclick = () => addContact(() => S.route());
    // A connected site's users show up in their own tab, read live (they're never stored as contacts).
    sitesNow().then((sites) => { const bar = $('#ctabs'); if (alive(g) && bar && sites.length) bar.innerHTML = tabBar(tab, sites); });
    if (tab === 'forms') { view.tab = 'contacts'; location.replace('#/forms'); return; } // Forms has its own page now
    (({ contacts: contactsTab, lists: listsTab, segments: segmentsTab, fields: fieldsTab, topics: topicsTab, people: peopleTab })[tab] || contactsTab)($('#ctab'), g);
  };
  const tabBar = (tab, sites) => [['contacts', 'Contacts'], ['lists', 'Lists'], ['segments', 'Segments'], ...(sites.length ? [['people', sites.length === 1 ? `${esc(sites[0].name)} people` : 'Site people']] : []), ['fields', 'Custom fields'], ['topics', 'Topics']]
    .map(([k, l]) => `<a class="ptab ${tab === k ? 'on' : ''}" href="#/contacts?tab=${k}">${k === 'people' ? ic('link', 13) + ' ' : ''}${l}</a>`).join('');
  // Connected sites whose users MailVesper can read live (from the segment catalog, which every member can see).
  const liveSites = { ws: null, list: null, p: null };
  const sitesNow = () => {
    const ws = base();
    if (liveSites.ws !== ws) Object.assign(liveSites, { ws, list: null, p: null });
    if (liveSites.list) return Promise.resolve(liveSites.list);
    return liveSites.p || (liveSites.p = API('GET', ws + '/segments/catalog').then((c) => (liveSites.list = c.sites || []), () => { liveSites.p = null; return []; }));
  };

  // ── A connected site's people, read live ──
  function peopleTab(box, g) {
    box.innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>';
    Promise.all([sitesNow(), API('GET', base() + '/segments')]).then(([sites, segs]) => {
      if (!alive(g)) return;
      if (!sites.length) { box.innerHTML = `<div class="card"><div class="card-body" style="text-align:center;padding:32px 20px"><b>No connected sites</b><p class="faint" style="margin:6px 0 0">Connect a site from its admin (Settings → ${SL_BN()}) and its users show up here, read live.</p></div></div>`; return; }
      const site = sites.find((x) => x.id === S.param('site')) || sites[0];
      const seg = S.param('segment') || '';
      const mine = segs.segments.filter((x) => x.site_connection_id === site.id);
      const go = (o) => { location.hash = '#/contacts?' + new URLSearchParams(Object.assign({ tab: 'people', site: site.id }, seg ? { segment: seg } : {}, o)).toString(); };
      S.paged(`${base()}/connections/${site.id}/people${seg ? '?segment=' + encodeURIComponent(seg) : ''}`, 'people', (r, ctl) => {
        if (!alive(g)) return;
        const f = (p, k) => (p.fields || {})[k];
        box.innerHTML = `<div class="ppl-bar">${sites.length > 1 ? `<select class="select" id="pps">${sites.map((x) => `<option value="${x.id}" ${x.id === site.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>` : ''}
            <select class="select" id="ppg"><option value="">Everyone</option>${mine.map((x) => `<option value="${x.id}" ${x.id === seg ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></div>
          <div class="callout" style="margin-bottom:12px">${ic('link', 15)}<div>Read live from <b>${esc(site.name)}</b> each time you open this, never copied into ${SL_BN()}. Only people who agreed to marketing get campaigns; unsubscribes and bounces are kept here. Click a person to see the placeholders you can put in an email, filled in for them.</div></div>
          <div class="card">${r.people.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Last login</th><th class="num">Spent</th><th>Marketing</th></tr></thead><tbody>${r.people.map((p, i) => `<tr data-pi="${i}" title="See this person's placeholders"><td><div class="cell-main">${esc([p.first_name, p.last_name].filter(Boolean).join(' ') || p.email)}</div><div class="cell-sub">${esc(p.email)}</div></td>
            <td>${f(p, 'role') != null ? `<span class="tag">${esc(f(p, 'role'))}</span>` : '<span class="faint">–</span>'}${f(p, 'creator_verified') ? ` <span class="tag">verified</span>` : ''}</td>
            <td class="faint">${esc(f(p, 'account_status') || '–')}</td><td class="faint">${f(p, 'last_login_at') ? ago(String(f(p, 'last_login_at')).replace(' ', 'T') + 'Z') : '–'}</td>
            <td class="num">${f(p, 'total_spent') != null ? fmt(f(p, 'total_spent')) : '–'}</td><td>${p.subscribed ? S.chip('delivered', 'Agreed') : '<span class="faint">No</span>'}</td></tr>`).join('')}</tbody></table></div>`
          : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>Nobody matches</b></div>`}</div>`;
        const ss = $('#pps'); if (ss) ss.onchange = () => { location.hash = '#/contacts?tab=people&site=' + ss.value; };
        $('#ppg').onchange = (e) => go({ segment: e.target.value || '' });
        box.querySelectorAll('tr[data-pi]').forEach((tr) => { tr.onclick = () => personTags(r.people[+tr.dataset.pi], site); });
        S.pageBar(ctl, box.firstElementChild, box.querySelector('.card:last-child'), 'people', 'beforeend');
      }).ready.catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body"><div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div></div></div>`));
    }).catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }

  // One site person's placeholders, with their values: what {{…}} becomes in an email to them. back: reopen the list.
  function personTags(p, site, back, campaignId) {
    const vars = Object.assign({ first_name: p.first_name, last_name: p.last_name, email: p.email }, p.fields || {});
    const show = (v) => v === null || v === undefined || v === '' ? '<span class="faint">empty: the fallback is used</span>' : esc(typeof v === 'boolean' ? (v ? 'yes' : 'no') : String(v));
    modal({ title: [p.first_name, p.last_name].filter(Boolean).join(' ') || p.email, desc: `${p.email} · read live from ${site.name}`, icon: 'user', size: 'md',
      body: `${campaignId ? `<div class="callout" style="margin:0 0 14px;display:block"><b>Send ${esc(p.first_name || 'this person')}’s version</b><p class="faint" style="margin:4px 0 8px;font-size:12.5px">Exactly what they’ll get, with their details filled in, sent only to the address below (to yourself, or to them to try it on one person). It goes out exactly as they’d get it (same subject and reply-to). Nobody else gets anything.</p>
          <div style="display:flex;gap:8px"><input class="input" id="pt-to" type="email" value="${esc((window.SL_ME && SL_ME.user && SL_ME.user.email) || '')}" style="flex:1;padding-left:10px;background-image:none"><button class="btn btn-primary btn-sm" id="pt-send">Send</button><button class="btn btn-sm" id="pt-them" title="${esc(p.email)}">Use their address</button></div><div id="pt-res" style="margin-top:8px;font-size:12.5px"></div></div>` : ''}<p class="faint" style="margin:0 0 10px;font-size:13px">Put a placeholder in the subject or the email. Add fallbacks after a bar, tried in order: <code>{{real_first_name | first_name | there}}</code> uses the verified name, else the profile name, else “there”.</p>
        <div class="table-wrap"><table class="tbl"><thead><tr><th>Placeholder</th><th>For this person</th></tr></thead><tbody>${Object.keys(vars).map((k) => `<tr style="cursor:pointer" data-tag="${esc(k)}" title="Copy"><td><code>{{${esc(k)}}}</code></td><td>${show(vars[k])}</td></tr>`).join('')}</tbody></table></div>`,
      actions: back ? [{ label: 'Back to the list', onClick: () => { back(); return false; } }, { label: 'Close' }] : [{ label: 'Close' }],
      mount: (root) => {
        root.querySelectorAll('tr[data-tag]').forEach((tr) => { tr.onclick = () => { navigator.clipboard && navigator.clipboard.writeText(`{{${tr.dataset.tag}}}`); toast(`Copied {{${tr.dataset.tag}}}`); }; });
        const go = root.querySelector('#pt-send'); if (!go) return;
        root.querySelector('#pt-them').onclick = () => { root.querySelector('#pt-to').value = p.email; };
        go.onclick = () => {
          const to = root.querySelector('#pt-to').value.trim(), res = root.querySelector('#pt-res');
          if (!/^\S+@\S+\.\S+$/.test(to)) { res.innerHTML = '<span style="color:var(--s-bounced)">Enter a valid email address.</span>'; return; }
          go.disabled = true; go.textContent = 'Sending…';
          API('POST', `${base()}/campaigns/${campaignId}/test`, { to, as: p.email })
            .then(() => { res.innerHTML = `<span style="display:inline-flex;vertical-align:-2px">${ic('check', 13, 2.5)}</span> Sent ${esc(p.first_name || p.email)}’s version to <b>${esc(to)}</b>. Check that inbox (and spam) in a minute.`; toast('Test sent'); })
            .catch((e) => { res.innerHTML = `<span style="color:var(--s-bounced)">${esc(e.message)}</span>`; })
            .finally(() => { go.disabled = false; go.textContent = 'Send'; });
        };
      } });
  }

  // A live segment's people (from a campaign's audience): read from the site, only who agreed to marketing, as the send would.
  S.sitePeople = (segId, campaignId) => Promise.all([API('GET', base() + '/segments'), sitesNow()]).then(([sg, sites]) => {
    const seg = sg.segments.find((x) => x.id === segId);
    if (!seg || !seg.site_connection_id) return toast('That audience isn’t read from a site.');
    const site = sites.find((x) => x.id === seg.site_connection_id) || { id: seg.site_connection_id, name: seg.group || 'the site' };
    const st = { page: 1, q: '', r: null, err: '' };
    let box = null, qT = null;
    const load = () => {
      st.r = null; draw();
      API('GET', `${base()}/connections/${site.id}/people?` + new URLSearchParams({ segment: seg.id, subscribed: '1', page: String(st.page), q: st.q }).toString())
        .then((r) => { st.r = r; st.err = ''; draw(); }, (e) => { st.err = e.message; draw(); });
    };
    const draw = () => {
      if (!box || !box.isConnected) return;
      const r = st.r, f = (p, k) => (p.fields || {})[k];
      const real = r && r.people.some((p) => 'real_first_name' in (p.fields || {}));
      const rows = !r ? `<tr><td colspan="4" class="faint">${st.err ? esc(st.err) : 'Reading live from ' + esc(site.name) + '…'}</td></tr>`
        : r.people.length ? r.people.map((p, i) => `<tr data-pi="${i}" style="cursor:pointer" title="See this person's placeholders"><td><div class="cell-main">${esc([p.first_name, p.last_name].filter(Boolean).join(' ') || p.email)}</div><div class="cell-sub">${esc(p.email)}</div></td>
          ${real ? `<td>${f(p, 'real_first_name') ? esc(f(p, 'real_first_name')) + ' <span class="faint">(verified)</span>' : p.first_name ? esc(p.first_name) + ' <span class="faint">(profile)</span>' : '<span class="faint">there (fallback)</span>'}</td>` : ''}<td>${f(p, 'role') != null ? `<span class="tag">${esc(f(p, 'role'))}</span>` : '–'}</td><td class="faint">${f(p, 'last_login_at') ? ago(String(f(p, 'last_login_at')).replace(' ', 'T') + 'Z') : '–'}</td></tr>`).join('')
        : '<tr><td colspan="4" class="faint">Nobody matches</td></tr>';
      const pages = r ? Math.max(1, Math.ceil(r.meta.total / 50)) : 1;
      box.querySelector('#spt').innerHTML = `<thead><tr><th>Person</th>${real ? '<th title="{{real_first_name | first_name | there}}">Name in the email</th>' : ''}<th>Role</th><th>Last login</th></tr></thead><tbody>${rows}</tbody>`;
      box.querySelector('#spf').innerHTML = r ? `<span class="faint" style="font-size:12.5px">${fmt(r.meta.total)} ${r.meta.total === 1 ? 'person' : 'people'} · page ${st.page} of ${pages} · click a person to see their placeholders${campaignId ? ' and send yourself their version' : ''}</span>
        <span><button class="btn btn-sm" id="spp" ${st.page < 2 ? 'disabled' : ''}>Previous</button> <button class="btn btn-sm" id="spn" ${r.meta.has_more ? '' : 'disabled'}>Next</button></span>` : '';
      const pv = box.querySelector('#spp'), nx = box.querySelector('#spn');
      if (pv) pv.onclick = () => { st.page--; load(); };
      if (nx) nx.onclick = () => { st.page++; load(); };
      box.querySelectorAll('tr[data-pi]').forEach((tr) => { tr.onclick = () => personTags(r.people[+tr.dataset.pi], site, open, campaignId); });
    };
    const open = () => modal({ title: seg.name, desc: `Read live from ${site.name}: exactly who this campaign goes to (only people who agreed to marketing).`, icon: 'users', size: 'lg',
      body: `<input class="input" id="spq" placeholder="Search name or email" value="${esc(st.q)}" style="width:100%;margin-bottom:10px">
        <div class="table-wrap" style="max-height:52vh;overflow:auto"><table class="tbl" id="spt"></table></div>
        <div id="spf" style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap"></div>`,
      mount: (root) => {
        box = root;
        root.querySelector('#spq').oninput = (e) => { clearTimeout(qT); qT = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 350); };
        st.r ? draw() : load();
      } });
    open();
  }).catch((e) => toast(e.message));

  function contactsTab(box, g) {
    const st = { rows: [], next: null, total: 0 };
    box.innerHTML = `<div class="grid kpis" id="ckpi" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px"></div>
      <div class="card"><div class="toolbar"><input class="input" id="cq" placeholder="Search name or email" value="${esc(view.q)}">
        <select class="select" id="cst"><option value="">All statuses</option>${Object.keys(STATUS).map((k) => `<option value="${k}" ${view.status === k ? 'selected' : ''}>${STATUS[k][1]}</option>`).join('')}</select>
        <select class="select" id="clist"><option value="">All lists</option></select><select class="select" id="cseg"><option value="">All contacts</option></select><select class="select" id="ctag"><option value="">Any tag</option></select>
        <a class="btn btn-sm" id="cexp" style="margin-left:auto">${ic('download', 14)}Export</a></div>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Contact</th><th>Status</th><th>Engagement</th><th>Last opened</th><th>Tags</th><th>Added</th></tr></thead><tbody id="crows"><tr><td colspan="6" class="faint">Loading…</td></tr></tbody></table></div>
      <div class="tfoot"><span id="ccount"></span><button class="btn btn-sm" id="cmore" style="display:none">Load more</button></div></div>`;
    API('GET', base() + '/contacts/summary').then((s) => {
      if (!alive(g)) return;
      $('#ckpi').innerHTML = [['Total contacts', fmt(s.total), `+${fmt(s.new_7d)} this week`], ['Subscribed', fmt(s.subscribed), s.total ? Math.round((s.subscribed / s.total) * 100) + '%' : '—'], ['Engaged (30 days)', fmt(s.engaged_30d), 'real opens or clicks'], ['Dormant', fmt(s.dormant), 'no engagement in 120 days']].map(([a, b, c]) => `<div class="card kpi"><div class="kpi-label">${a}</div><div class="kpi-value">${b}</div><div class="kpi-foot"><span class="note" style="margin:0;text-align:left">${c}</span></div></div>`).join('');
      $('#ctag').innerHTML = '<option value="">Any tag</option>' + Object.entries(s.tags || {}).map(([t, n]) => `<option value="${esc(t)}" ${view.tag === t ? 'selected' : ''}>${esc(t)} (${fmt(n)})</option>`).join('');
    }).catch(() => {});
    Promise.all([API('GET', base() + '/lists'), API('GET', base() + '/segments')]).then(([l, sg]) => {
      if (!alive(g)) return;
      $('#clist').innerHTML = '<option value="">All lists</option>' + l.lists.map((x) => `<option value="${x.id}" ${view.list === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
      $('#cseg').innerHTML = '<option value="">All contacts</option>' + sg.segments.map((x) => `<option value="${x.id}" ${view.segment === x.id ? 'selected' : ''}>Segment: ${esc(x.name)}</option>`).join('');
    }).catch(() => {});
    const qs = (more) => { const q = new URLSearchParams(); ['q', 'status', 'list', 'tag', 'segment'].forEach((k) => view[k] && q.set(k, view[k])); if (more && st.next) q.set('before', st.next); return q; };
    const load = (more) => API('GET', `${base()}/contacts?${qs(more)}`).then((r) => {
      if (!alive(g)) return;
      st.rows = more ? st.rows.concat(r.contacts) : r.contacts; st.next = r.next_before; st.total = r.total;
      $('#crows').innerHTML = st.rows.map((c) => `<tr data-id="${c.id}"><td><div class="who">${avatar(nameOf(c))}<div><div class="cell-main">${esc(c.name || '—')}</div><div class="cell-sub">${esc(c.email)}</div></div></div></td><td>${statusChip(c.status)}</td><td>${score(c)}</td><td class="faint">${ago(c.last_opened_at)}</td><td>${(c.tags || []).slice(0, 3).map((t) => `<span class="tag">${esc(t)}</span>`).join(' ')}${c.tags.length > 3 ? ` <span class="faint">+${c.tags.length - 3}</span>` : ''}</td><td class="faint">${day(c.created_at)}</td></tr>`).join('')
        || `<tr style="cursor:default"><td colspan="6"><div class="empty"><div class="ic">${ic('contacts', 22)}</div><h3>${view.q || view.status || view.list || view.tag || view.segment ? 'No contacts match' : 'No contacts yet'}</h3><p>${view.q || view.status || view.list || view.tag || view.segment ? 'Try another search or filter.' : 'Import a CSV, add someone by hand, or create contacts from your app with the API.'}</p>${canEdit() && !view.q ? `<div style="display:flex;gap:8px;justify-content:center;margin-top:12px"><a class="btn btn-primary btn-sm" href="#/contacts/import">${ic('upload', 14)}Import a CSV</a></div>` : ''}</div></td></tr>`;
      $('#ccount').textContent = st.total ? `Showing ${fmt(st.rows.length)} of ${fmt(st.total)}` : '';
      $('#cmore').style.display = st.next ? '' : 'none';
      $('#cexp').href = `/api${base()}/contacts/export?${qs(false)}`;
      $$('#crows tr[data-id]').forEach((tr) => tr.onclick = () => openContact(tr.dataset.id, () => load(false)));
    }).catch((err) => alive(g) && ($('#crows').innerHTML = `<tr><td colspan="6">${esc(err.message)}</td></tr>`));
    let t; $('#cq').oninput = (e) => { clearTimeout(t); t = setTimeout(() => { view.q = e.target.value.trim(); load(false); }, 250); };
    [['#cst', 'status'], ['#clist', 'list'], ['#cseg', 'segment'], ['#ctag', 'tag']].forEach(([sel, k]) => { $(sel).onchange = (e) => { view[k] = e.target.value; load(false); }; });
    $('#cmore').onclick = (e) => { const b = e.currentTarget; b.disabled = true; load(true).then(() => { b.disabled = false; }); };
    load(false);
  }

  function addContact(done) {
    API('GET', base() + '/lists').then((l) => modal({ title: 'Add a contact', icon: 'contacts', size: 'sm',
      body: `<div class="field"><label>Email</label><input id="ce" type="email" placeholder="name@example.com"></div><div class="row2"><div class="field"><label>First name</label><input id="cf"></div><div class="field"><label>Last name</label><input id="cl"></div></div>
        ${l.lists.length ? `<div class="field"><label>Add to list <span class="faint">(optional)</span></label><select id="cli" class="select"><option value="">None</option>${l.lists.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select></div>` : ''}
        <p class="faint" style="font-size:12.5px">Only add people who agreed to get email from you.</p>`,
      actions: [{ label: 'Cancel' }, { label: 'Add contact', kind: 'primary', onClick: (m) => {
        const email = $('#ce', m.root).value.trim(); if (!/^\S+@\S+\.\S+$/.test(email)) return fieldError(m.root, '#ce', 'Enter a valid email address.');
        const list = $('#cli', m.root) && $('#cli', m.root).value;
        API('POST', base() + '/contacts', { email, first_name: $('#cf', m.root).value.trim() || null, last_name: $('#cl', m.root).value.trim() || null, lists: list ? [list] : undefined })
          .then(() => { m.close(); toast('Contact added'); done(); }).catch((err) => fieldError(m.root, '#ce', err.message));
        return false;
      } }] }));
  }

  // ── Contact drawer ──
  function openContact(id, onChange) {
    Promise.all([API('GET', `${base()}/contacts/${id}`), API('GET', base() + '/lists'), API('GET', base() + '/fields')]).then(([r, lists, fields]) => {
      const c = r.contact;
      const save = (body, msg) => API('PATCH', `${base()}/contacts/${c.id}`, body).then((x) => { Object.assign(c, x.contact); toast(msg || 'Saved'); onChange && onChange(); return x; }).catch((err) => { toast(err.message); throw err; });
      const panes = {
        emails: () => c.messages.length ? `<div class="mail-list">${c.messages.map((m) => `<div class="mi" data-m="${m.id}" style="cursor:pointer">${miniRoute({ processing: 'processing', suppressed: 'dropped', failed: 'dropped' }[m.status] || m.status, false)}<div style="flex:1;min-width:0"><div class="trunc" style="font-weight:550">${esc(m.subject)}</div><div class="faint" style="font-size:12px"><span class="trk">${esc(m.tracking_code)}</span> · ${ago(m.created_at)}</div></div><span class="tag">${esc(m.type)}</span></div>`).join('')}</div>` : '<p class="faint">No emails sent to this address yet.</p>',
        details: () => `<div class="row2"><div class="field"><label>First name</label><input id="dfn" value="${esc(c.first_name || '')}"></div><div class="field"><label>Last name</label><input id="dln" value="${esc(c.last_name || '')}"></div></div>
          ${fields.fields.map((f) => `<div class="field"><label>${esc(f.label)} <span class="faint mono" style="font-size:11px">{{ ${esc(f.key)} }}</span></label><input data-fk="${esc(f.key)}" value="${esc(c.fields[f.key] ?? '')}" ${f.type === 'number' ? 'inputmode="decimal"' : ''}></div>`).join('')}
          ${Object.keys(c.fields).filter((k) => !fields.fields.some((f) => f.key === k)).map((k) => `<div class="field"><label>${esc(k)} <span class="faint mono" style="font-size:11px">{{ ${esc(k)} }}</span></label><input data-fk="${esc(k)}" value="${esc(c.fields[k])}"></div>`).join('')}
          ${canEdit() ? '<button class="btn btn-primary btn-sm" id="dsave">Save</button>' : ''}
          <dl class="kv" style="margin-top:18px"><dt>Source</dt><dd>${esc(c.source)}</dd><dt>Added</dt><dd>${day(c.created_at)}</dd><dt>Last emailed</dt><dd>${ago(c.last_sent_at)}</dd><dt>Last real open</dt><dd>${ago(c.last_opened_at)}</dd><dt>Last real click</dt><dd>${ago(c.last_clicked_at)}</dd></dl>`,
        lists: () => `<div class="cp-label" style="margin-top:0">Lists</div><div style="display:flex;flex-wrap:wrap;gap:6px">${c.lists.map((l) => `<span class="tag">${esc(l.name)}${canEdit() ? ` <a href="#" data-rl="${l.id}" title="Remove from list">×</a>` : ''}</span>`).join('') || '<span class="faint">Not on any list.</span>'}</div>
          ${canEdit() && lists.lists.length ? `<div style="display:flex;gap:8px;margin-top:10px"><select class="select" id="dal"><option value="">Add to a list…</option>${lists.lists.filter((l) => !c.lists.some((x) => x.id === l.id)).map((l) => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select></div>` : ''}
          <div class="cp-label">Tags</div><div style="display:flex;flex-wrap:wrap;gap:6px">${c.tags.map((t) => `<span class="tag">${esc(t)}${canEdit() ? ` <a href="#" data-rt="${esc(t)}" title="Remove tag">×</a>` : ''}</span>`).join('') || '<span class="faint">No tags.</span>'}</div>
          ${canEdit() ? `<form id="dtf" style="display:flex;gap:8px;margin-top:10px"><input id="dtag" placeholder="Add a tag" maxlength="60" style="flex:1"><button class="btn btn-sm">Add</button></form>` : ''}`,
        subs: () => `<div class="setting-row"><div><b>Subscribed</b><span>${c.status === 'subscribed' ? 'Gets your email.' : c.suppression ? esc(c.suppression.detail || STATUS[c.status][1]) : STATUS[c.status][1]}</span></div>${canEdit() ? `<label class="switch"><input type="checkbox" id="dsub" ${c.status === 'subscribed' ? 'checked' : ''}><span></span></label>` : statusChip(c.status)}</div>
          ${c.topics.length ? `<div class="cp-label">Topics</div>${c.topics.map((t) => `<div class="setting-row"><div><b>${esc(t.name)}</b><span>${t.subscribed ? 'Subscribed' : 'Opted out'}</span></div>${canEdit() ? `<label class="switch"><input type="checkbox" data-tp="${t.id}" ${t.subscribed ? 'checked' : ''}><span></span></label>` : ''}</div>`).join('')}` : '<p class="faint" style="font-size:12.5px;margin-top:12px">Create topics (Contacts → Topics) to let people choose which kinds of email they get.</p>'}`,
      };
      const bind = (t, d) => {
        if (t === 'emails') $$('[data-m]', d).forEach((el) => el.onclick = () => S.openActivity && S.openActivity(el.dataset.m));
        if (t === 'details' && $('#dsave', d)) $('#dsave', d).onclick = () => { const f = {}; $$('[data-fk]', d).forEach((i) => { const val = i.value.trim(); f[i.dataset.fk] = val === '' ? null : val; }); save({ first_name: $('#dfn', d).value.trim() || null, last_name: $('#dln', d).value.trim() || null, fields: f }); };
        if (t === 'lists') {
          $$('[data-rl]', d).forEach((a) => a.onclick = (e) => { e.preventDefault(); save({ remove_lists: [a.dataset.rl] }, 'Removed from list').then(() => rerender(d, 'lists')); });
          $$('[data-rt]', d).forEach((a) => a.onclick = (e) => { e.preventDefault(); save({ remove_tags: [a.dataset.rt] }, 'Tag removed').then(() => rerender(d, 'lists')); });
          const al = $('#dal', d); if (al) al.onchange = () => al.value && save({ add_lists: [al.value] }, 'Added to list').then(() => rerender(d, 'lists'));
          const tf = $('#dtf', d); if (tf) tf.onsubmit = (e) => { e.preventDefault(); const v2 = $('#dtag', d).value.trim(); if (v2) save({ add_tags: [v2] }, 'Tag added').then(() => rerender(d, 'lists')); };
        }
        if (t === 'subs') {
          const sb = $('#dsub', d); if (sb) sb.onchange = () => save({ subscribed: sb.checked }, sb.checked ? 'Subscribed again' : 'Unsubscribed').then(() => rerender(d, 'subs')).catch(() => { sb.checked = !sb.checked; });
          $$('[data-tp]', d).forEach((i) => i.onchange = () => save({ topics: { [i.dataset.tp]: i.checked } }).then(() => rerender(d, 'subs')));
        }
      };
      const rerender = (d, t) => { $('[data-pane]', d).innerHTML = panes[t](); bind(t, d); $('#dhead-status', d).innerHTML = statusChip(c.status); };
      drawer(`<div class="drawer-head"><div class="drawer-top"><div class="profile-head">${avatar(nameOf(c))}<div><h2>${esc(c.name || c.email)}</h2><div class="faint" style="font-size:13px">${esc(c.email)} <span id="dhead-status">${statusChip(c.status)}</span></div></div></div><div style="margin-left:auto;display:flex;gap:6px"><button class="icon-btn" id="cmore">${ic('more', 16)}</button><button class="icon-btn" data-dclose>${ic('x')}</button></div></div>
        <div class="stat-row"><div><small>Emails</small><b>${fmt(c.stats.sent)}</b></div><div><small>Real opens</small><b>${fmt(c.stats.opened)}</b></div><div><small>Clicks</small><b>${fmt(c.stats.clicked)}</b></div><div><small>Engagement</small><b style="color:${c.engagement == null ? 'var(--text-3)' : scoreColor(c.engagement)}">${c.engagement == null ? 'New' : c.engagement}</b></div></div>
        <div class="tabs"><button class="tab on" data-t="emails">Emails</button><button class="tab" data-t="details">Details</button><button class="tab" data-t="lists">Lists & tags</button><button class="tab" data-t="subs">Subscriptions</button></div></div>
        <div class="drawer-body" data-pane>${panes.emails()}</div>`, (d) => {
        tabs(d, panes, (t) => bind(t, d)); bind('emails', d);
        const items = canManage() ? [
          { label: 'Download their data (GDPR)', icon: 'download', onClick: () => API('GET', `${base()}/contacts/${c.id}/export`).then((data) => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })); a.download = `contact-${c.email}.json`; a.click(); }).catch((err) => toast(err.message)) },
          'sep',
          { label: 'Erase contact', icon: 'trash', danger: true, onClick: () => confirmDialog({ title: `Erase ${c.email}?`, desc: 'Deletes the contact and removes their personal data from your email history. The address stays on your suppression list so it’s never emailed again by accident. This can’t be undone.', label: 'Erase', onConfirm: () => API('DELETE', `${base()}/contacts/${c.id}`).then(() => { closeDrawer(); toast('Contact erased'); onChange && onChange(); }).catch((err) => toast(err.message)) }) },
        ] : [{ label: 'Only owners and admins can export or erase contacts', icon: 'info' }];
        $('#cmore', d).onclick = (e) => { e.stopPropagation(); menu($('#cmore', d), items, 'right'); };
      });
    }).catch((err) => toast(err.message));
  }
  S.openContact = (name, email) => openContact(email);

  // ── Lists tab ──
  function listsTab(box, g) {
    box.innerHTML = '<div class="card"><div class="card-body faint">Loading lists…</div></div>';
    S.paged(base() + '/lists', 'lists', (r, ctl) => {
      if (!alive(g)) return;
      box.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:12px">${canEdit() ? `<button class="btn btn-primary btn-sm" id="nl">${ic('plus', 14)}New list</button>` : ''}</div>
        <div class="card">${r.lists.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>List</th><th class="num">Subscribed</th><th class="num">Total</th><th></th></tr></thead><tbody>${r.lists.map((l) => `<tr data-l="${l.id}"><td><div class="cell-main">${esc(l.name)}</div>${l.description ? `<div class="cell-sub">${esc(l.description)}</div>` : ''}</td><td class="num">${fmt(l.subscribed)}</td><td class="num">${fmt(l.total)}</td><td style="text-align:right">${canEdit() ? `<button class="btn btn-sm btn-ghost lm" data-l="${l.id}" data-n="${esc(l.name)}">${ic('more', 15)}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No lists yet</b><p class="faint" style="margin:6px 0 0">Lists group contacts you email together, like Newsletter or Customers. Create one here or when you import.</p></div>`}</div>`;
      const nl = $('#nl'); if (nl) nl.onclick = () => listModal(null);
      $$('tr[data-l]', box).forEach((tr) => tr.onclick = (e) => { if (e.target.closest('.lm')) return; Object.assign(view, { list: tr.dataset.l, q: '', status: '', tag: '', segment: '' }); location.hash = '#/contacts?tab=contacts'; });
      $$('.lm', box).forEach((b) => b.onclick = (e) => { e.stopPropagation(); menu(b, [{ label: 'Rename', icon: 'edit', onClick: () => listModal(r.lists.find((l) => l.id === b.dataset.l)) }, ...(canManage() ? ['sep', { label: 'Delete list', icon: 'trash', danger: true, onClick: () => confirmDialog({ title: `Delete “${b.dataset.n}”?`, desc: 'The list is removed. Its contacts stay in your contacts.', onConfirm: () => API('DELETE', `${base()}/lists/${b.dataset.l}`).then(() => { toast('List deleted'); S.route(); }) }) }] : [])], 'right'); });
      S.pageBar(ctl, box.firstElementChild, box.querySelector('.card'), 'lists');
    }).ready.catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }
  function listModal(l) {
    modal({ title: l ? 'Rename list' : 'New list', icon: 'contacts', size: 'sm', body: `<div class="field"><label>Name</label><input id="ln" maxlength="100" value="${esc(l ? l.name : '')}" placeholder="e.g. Newsletter"></div><div class="field"><label>Description <span class="faint">(optional)</span></label><input id="ld" maxlength="255" value="${esc(l && l.description ? l.description : '')}"></div>`,
      actions: [{ label: 'Cancel' }, { label: l ? 'Save' : 'Create list', kind: 'primary', onClick: (m) => {
        const name = $('#ln', m.root).value.trim(); if (!name) return fieldError(m.root, '#ln', 'Name the list.');
        (l ? API('PATCH', `${base()}/lists/${l.id}`, { name, description: $('#ld', m.root).value.trim() || null }) : API('POST', base() + '/lists', { name, description: $('#ld', m.root).value.trim() || null }))
          .then(() => { m.close(); toast(l ? 'List renamed' : 'List created'); S.route(); }).catch((err) => fieldError(m.root, '#ln', err.message));
        return false;
      } }] });
  }

  // ── Segments tab ──
  function segmentsTab(box, g) {
    box.innerHTML = '<div class="card"><div class="card-body faint">Loading segments…</div></div>';
    S.paged(base() + '/segments', 'segments', (r, ctl) => {
      if (!alive(g)) return;
      box.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:12px">${canEdit() ? `<button class="btn btn-primary btn-sm" id="nseg">${ic('plus', 14)}New segment</button>` : ''}</div>
        <div class="card">${r.segments.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Segment</th><th>Rules</th><th class="num">Contacts now</th><th></th></tr></thead><tbody>${r.segments.slice().sort((a, b) => (a.group || '').localeCompare(b.group || '') || a.name.localeCompare(b.name)).map((s, i, all) => `${s.group && (i === 0 || all[i - 1].group !== s.group) ? `<tr class="seg-grp" style="cursor:default"><td colspan="4">${ic('link', 13)}${esc(s.group)} <span class="faint">· ${s.live ? 'read live from the site, never copied' : 'ready-made segments from the connected site'}</span></td></tr>` : ''}<tr data-s="${s.id}" ${s.live ? `data-site="${s.site_connection_id}"` : ''}><td class="cell-main">${esc(s.name)}${s.live ? ' <span class="tag tag-live">Live</span>' : ''}</td><td class="faint" style="font-size:12.5px">${s.rules.conditions.length} condition${s.rules.conditions.length === 1 ? '' : 's'} · match ${s.rules.match}</td><td class="num">${s.live_error ? `<span class="faint" title="${esc(s.live_error)}">${fmt(s.count || 0)} ${ic('alert', 13)}</span>` : fmt(s.count || 0)}</td><td style="text-align:right"><a class="btn btn-sm" href="#/contacts/segment?id=${s.id}">${canEdit() ? 'Edit' : 'View'}</a></td></tr>`).join('')}</tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No segments yet</b><p class="faint" style="margin:6px 0 0">Segments are saved rules, like “on the Growth plan and opened in the last 30 days”. They update automatically.</p></div>`}</div>`;
      $$('tr[data-s]', box).forEach((tr) => tr.onclick = (e) => {
        if (e.target.closest('a')) return;
        if (tr.dataset.site) { location.hash = `#/contacts?tab=people&site=${tr.dataset.site}&segment=${tr.dataset.s}`; return; } // a site's people, live
        Object.assign(view, { segment: tr.dataset.s, list: '', q: '', status: '', tag: '' }); location.hash = '#/contacts?tab=contacts';
      });
      const ns = $('#nseg'); if (ns) ns.onclick = () => sitesNow().then((sites) => {
        if (!sites.length) { location.hash = '#/contacts/segment'; return; }
        menu(ns, [{ label: 'Of your contacts', icon: 'contacts', onClick: () => { location.hash = '#/contacts/segment'; } }, 'sep',
          ...sites.map((x) => ({ label: `Of ${x.name} people (live)`, icon: 'link', onClick: () => { location.hash = '#/contacts/segment?site=' + x.id; } }))], 'right');
      });
      S.pageBar(ctl, box.firstElementChild, box.querySelector('.card'), 'segments');
    }).ready.catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }

  // ── Custom fields tab ──
  function fieldsTab(box, g) {
    API('GET', base() + '/fields').then((r) => {
      if (!alive(g)) return;
      box.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:12px">${canEdit() ? `<button class="btn btn-primary btn-sm" id="nf">${ic('plus', 14)}Add field</button>` : ''}</div>
        <div class="card">${r.fields.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Field</th><th>Merge tag</th><th>Type</th><th></th></tr></thead><tbody>${r.fields.map((f) => `<tr style="cursor:default"><td class="cell-main">${esc(f.label)}</td><td><span class="mono" style="font-size:12.5px">{{ ${esc(f.key)} | fallback }}</span></td><td><span class="tag">${f.type}</span></td><td style="text-align:right">${canManage() ? `<button class="btn btn-sm btn-ghost fd" data-id="${f.id}" data-n="${esc(f.label)}">${ic('trash', 14)}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No custom fields yet</b><p class="faint" style="margin:6px 0 0">Store anything about a contact, like plan or city, then use it in segments and as merge tags. Imports create fields for you.</p></div>`}</div>
        <p class="faint" style="font-size:12.5px;margin-top:10px">Built-in fields: <span class="mono">email</span>, <span class="mono">first_name</span>, <span class="mono">last_name</span>.</p>`;
      const nf = $('#nf'); if (nf) nf.onclick = () => modal({ title: 'Add a custom field', icon: 'contacts', size: 'sm',
        body: `<div class="field"><label>Label</label><input id="fl" placeholder="e.g. Signup date" maxlength="80"></div><div class="field"><label>Key <span class="faint">(used in merge tags and the API)</span></label><input id="fk" class="mono" placeholder="signup_date" maxlength="40"></div><div class="field"><label>Type</label><select id="ft" class="select"><option value="text">Text</option><option value="number">Number</option><option value="date">Date</option><option value="boolean">Yes / no</option></select></div>`,
        mount: (root) => { $('#fl', root).oninput = (e) => { $('#fk', root).value = e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[^a-z]+/, '').slice(0, 40); }; },
        actions: [{ label: 'Cancel' }, { label: 'Add field', kind: 'primary', onClick: (m) => { API('POST', base() + '/fields', { label: $('#fl', m.root).value.trim(), key: $('#fk', m.root).value.trim(), type: $('#ft', m.root).value }).then(() => { m.close(); toast('Field added'); S.route(); }).catch((err) => fieldError(m.root, '#fk', err.message)); return false; } }] });
      $$('.fd', box).forEach((b) => b.onclick = () => confirmDialog({ title: `Delete “${b.dataset.n}”?`, desc: 'The field disappears from forms and segments. Values already stored on contacts are kept.', onConfirm: () => API('DELETE', `${base()}/fields/${b.dataset.id}`).then(() => { toast('Field deleted'); S.route(); }) }));
    }).catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }

  // ═══════ #/forms (its own page; the builder is #/forms/edit in forms.js) ═══════
  pages.forms = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = `<div class="page-head"><div><h1>Forms</h1><p>Sign-up forms for your website: a page to share, a popup, a slide-in or a bar. Edit a draft, then publish it.</p></div><div>${canEdit() ? `<button class="btn btn-primary" id="nf">${ic('plus', 15)}New form</button>` : ''}</div></div><div id="ftab"></div>`;
    const nf = $('#nf'); if (nf) nf.onclick = () => { nf.disabled = true; API('POST', base() + '/forms', { name: 'Newsletter sign-up', heading: 'Join our newsletter', double_opt_in: true }).then((x) => { location.hash = '#/forms/edit?id=' + x.form.id; }).catch((err) => { nf.disabled = false; toast(err.message); }); };
    formsTab($('#ftab'), g);
  };
  /** A form's state as a chip: Draft, Live, Live with unpublished changes, or Closed. */
  S.formState = (f) => !f.enabled && f.state !== 'draft' ? '<span class="tag">Closed</span>'
    : f.state === 'draft' ? '<span class="tag">Draft</span>'
      : `<span class="tag fs-live"><i></i>Live</span>${f.state === 'changed' ? ' <span class="tag fs-changed">Unpublished changes</span>' : ''}`;
  function formsTab(box, g) {
    API('GET', base() + '/lists').then((ls) => S.paged(base() + '/forms', 'forms', (r, ctl) => {
      if (!alive(g)) return;
      const listName = (id) => (ls.lists.find((l) => l.id === id) || {}).name;
      box.innerHTML = `<div class="card">${r.forms.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Form</th><th>Status</th><th>Adds to</th><th>Confirmation</th><th class="num">Sign-ups</th><th class="num">Confirmed</th><th></th></tr></thead><tbody>${r.forms.map((f) => `<tr data-f="${f.id}"><td><div class="cell-main">${esc(f.name)}</div></td><td>${S.formState(f)}</td><td>${f.list_id ? esc(listName(f.list_id) || 'list') : '<span class="faint">Contacts only</span>'}${f.tags.length ? ` <span class="faint">+ ${f.tags.length} tag${f.tags.length > 1 ? 's' : ''}</span>` : ''}</td><td>${f.double_opt_in ? 'Double opt-in' : 'Added at once'}</td><td class="num">${fmt(f.submissions)}</td><td class="num">${f.double_opt_in ? fmt(f.confirmed) : '–'}</td><td style="text-align:right">${f.state === 'draft' ? '' : `<button class="btn btn-sm btn-ghost fl" data-copy="${esc(f.page_url)}" title="Copy the form’s link">${ic('copy', 14)}</button>`}</td></tr>`).join('')}</tbody></table></div>`
          : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No signup forms yet</b><p class="faint" style="margin:6px 0 0;max-width:540px;margin-inline:auto">A form gives you a sign-up page to share and code to put on your website. With double opt-in, people confirm by email before they’re added, which keeps fake and mistyped addresses off your list.</p></div>`}</div>`;
      S.bindCopy(box);
      $$('tr[data-f]', box).forEach((tr) => tr.onclick = (e) => { if (e.target.closest('.fl')) return; location.hash = '#/forms/edit?id=' + tr.dataset.f; });
      S.pageBar(ctl, box, box.querySelector('.card'), 'forms');
    }).ready).catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }


  // ── Topics tab ──
  function topicsTab(box, g) {
    API('GET', base() + '/topics').then((r) => {
      if (!alive(g)) return;
      box.innerHTML = `<div style="display:flex;justify-content:flex-end;margin-bottom:12px">${canManage() ? `<button class="btn btn-primary btn-sm" id="nt">${ic('plus', 14)}New topic</button>` : ''}</div>
        <div class="card">${r.topics.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Topic</th><th>For new contacts</th><th class="num">Opted out</th><th>ID for the API</th><th></th></tr></thead><tbody>${r.topics.map((t) => `<tr style="cursor:default"><td><div class="cell-main">${esc(t.name)}</div>${t.description ? `<div class="cell-sub">${esc(t.description)}</div>` : ''}</td><td>${t.default_subscribed ? 'Subscribed' : 'Opt-in only'}</td><td class="num">${fmt(t.opted_out)}</td><td><span class="copy mono" data-copy="${t.id}" style="font-size:12px">${t.id} ${ic('copy', 12)}</span></td><td style="text-align:right">${canManage() ? `<button class="btn btn-sm btn-ghost tm" data-id="${t.id}">${ic('more', 15)}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : `<div class="card-body" style="text-align:center;padding:32px 20px"><b>No topics yet</b><p class="faint" style="margin:6px 0 0;max-width:520px;margin-inline:auto">Topics are kinds of email, like Newsletter or Product updates. People can opt out of one topic instead of everything, which keeps more of them subscribed.</p></div>`}</div>
        <p class="faint" style="font-size:12.5px;margin-top:10px">Send with <span class="mono">"topic": "&lt;id&gt;"</span> in the API. People who opted out are skipped, and the unsubscribe page lets them choose topics.</p>`;
      S.bindCopy(box);
      const nt = $('#nt'); if (nt) nt.onclick = () => topicModal(null);
      $$('.tm', box).forEach((b) => b.onclick = (e) => { e.stopPropagation(); const t = r.topics.find((x) => x.id === b.dataset.id); menu(b, [{ label: 'Edit', icon: 'edit', onClick: () => topicModal(t) }, 'sep', { label: 'Delete topic', icon: 'trash', danger: true, onClick: () => confirmDialog({ title: `Delete “${t.name}”?`, desc: 'People’s choices for this topic are deleted. Emails sent with it can no longer be filtered by it.', onConfirm: () => API('DELETE', `${base()}/topics/${t.id}`).then(() => { toast('Topic deleted'); S.route(); }) }) }], 'right'); });
    }).catch((err) => alive(g) && (box.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  }
  function topicModal(t) {
    modal({ title: t ? 'Edit topic' : 'New topic', icon: 'contacts', size: 'sm',
      body: `<div class="field"><label>Name</label><input id="tn" maxlength="80" value="${esc(t ? t.name : '')}" placeholder="e.g. Product updates"></div><div class="field"><label>Description <span class="faint">(shown on the preferences page)</span></label><input id="td" maxlength="255" value="${esc(t && t.description ? t.description : '')}"></div>
        <div class="field"><label>New contacts</label><select id="tds" class="select"><option value="1" ${!t || t.default_subscribed ? 'selected' : ''}>Subscribed until they opt out</option><option value="0" ${t && !t.default_subscribed ? 'selected' : ''}>Only if they opt in</option></select></div>`,
      actions: [{ label: 'Cancel' }, { label: t ? 'Save' : 'Create topic', kind: 'primary', onClick: (m) => {
        const body = { name: $('#tn', m.root).value.trim(), description: $('#td', m.root).value.trim() || null, default_subscribed: $('#tds', m.root).value === '1' };
        if (!body.name) return fieldError(m.root, '#tn', 'Name the topic.');
        (t ? API('PATCH', `${base()}/topics/${t.id}`, body) : API('POST', base() + '/topics', body)).then(() => { m.close(); toast(t ? 'Topic saved' : 'Topic created'); S.route(); }).catch((err) => fieldError(m.root, '#tn', err.message));
        return false;
      } }] });
  }

  // ═══════ #/contacts/segment?id= (rule builder) ═══════
  const FIELD_LABEL = { email: 'Email', first_name: 'First name', last_name: 'Last name', status: 'Status', source: 'Source', created_at: 'Added', last_sent_at: 'Last emailed', last_opened_at: 'Last opened (real)', last_clicked_at: 'Last clicked', engagement: 'Engagement score', dormant: 'Dormant', tag: 'Tag', list: 'List' };
  const OP_LABEL = { is: 'is', is_not: 'is not', contains: 'contains', not_contains: 'doesn’t contain', starts_with: 'starts with', ends_with: 'ends with', is_set: 'has a value', is_not_set: 'is empty', eq: '=', neq: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', before: 'is before', after: 'is after', within_days: 'is in the last', not_within_days: 'is not in the last', in_next_days: 'is in the next', is_true: 'is yes', is_false: 'is no', has: 'has', has_not: 'doesn’t have', in: 'is in', not_in: 'is not in' };
  pages['contacts/segment'] = (v) => {
    const g = (seq = S.routeSeq, ++gen), id = S.param('id');
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>';
    (id ? API('GET', base() + '/segments') : Promise.resolve(null)).then((segs) => {
      const seg0 = id && segs ? segs.segments.find((s) => s.id === id) : null;
      const siteId = seg0 ? seg0.site_connection_id : S.param('site'); // a site's segment: rules run on the site, live
      return API('GET', base() + '/segments/catalog' + (siteId ? '?site=' + encodeURIComponent(siteId) : '')).then((cat) => [cat, segs, siteId]);
    }).then(([cat, segs, siteId]) => {
      if (!alive(g)) return;
      const seg = id && segs ? segs.segments.find((s) => s.id === id) : null;
      const live = !!siteId, siteName = live && cat.site ? cat.site.name : '';
      const firstSiteField = (cat.fields.find((f) => f.field === 'field:last_login_at') || cat.fields.find((f) => f.field.startsWith('field:')) || { field: 'email', type: 'text' });
      const st = { name: seg ? seg.name : '', match: seg ? seg.rules.match : 'all', conds: seg ? seg.rules.conditions.map((c) => Object.assign({}, c))
        : [live ? { field: firstSiteField.field, op: firstSiteField.type === 'date' ? 'not_within_days' : (cat.ops[firstSiteField.type] || ['is'])[0], value: firstSiteField.type === 'date' ? 7 : '' } : { field: 'last_opened_at', op: 'within_days', value: 30 }] };
      const typeOf = (f) => (cat.fields.find((x) => x.field === f) || {}).type || 'text';
      const label = (f) => (live && f === 'created_at' ? 'Signed up' : FIELD_LABEL[f]) || (cat.fields.find((x) => x.field === f) || {}).label || f;
      const noValue = (op) => ['is_set', 'is_not_set', 'is_true', 'is_false'].includes(op);
      const valueInput = (c, i) => {
        if (noValue(c.op)) return '';
        const t = typeOf(c.field), val = c.value == null ? '' : c.value;
        if (c.field === 'status') return `<select class="select sv" data-i="${i}">${cat.statuses.map((s) => `<option ${val === s ? 'selected' : ''}>${s}</option>`).join('')}</select>`;
        if (c.field === 'source') return `<select class="select sv" data-i="${i}">${cat.sources.map((s) => `<option ${val === s ? 'selected' : ''}>${s}</option>`).join('')}</select>`;
        if (c.field === 'list') return `<select class="select sv" data-i="${i}"><option value="">Choose a list</option>${cat.lists.map((l) => `<option value="${l.id}" ${val === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>`;
        if (c.field === 'tag') return `<input class="sv" data-i="${i}" list="tagl" value="${esc(val)}" placeholder="tag"><datalist id="tagl">${cat.tags.map((x) => `<option value="${esc(x)}">`).join('')}</datalist>`;
        if (t === 'date' && ['within_days', 'not_within_days', 'in_next_days'].includes(c.op)) return `<span class="seg-days"><input class="sv" data-i="${i}" type="number" min="1" max="3650" value="${esc(val)}" style="width:80px"> days</span>`;
        if (t === 'date') return `<input class="sv" data-i="${i}" type="date" value="${esc(val)}">`;
        return `<input class="sv" data-i="${i}" ${t === 'number' ? 'type="number" step="any"' : ''} value="${esc(val)}" placeholder="value">`;
      };
      const fieldOptions = (sel) => (live ? [['Person', ['email', 'first_name', 'last_name', 'status', 'created_at']]] : [['Contact', ['email', 'first_name', 'last_name', 'status', 'source', 'created_at']], ['Engagement', ['last_opened_at', 'last_clicked_at', 'last_sent_at', 'engagement', 'dormant']], ['Lists & tags', ['list', 'tag']]])
        .map(([grp, fs]) => `<optgroup label="${grp}">${fs.map((f) => `<option value="${f}" ${sel === f ? 'selected' : ''}>${label(f)}</option>`).join('')}</optgroup>`).join('')
        + (cat.fields.some((f) => f.field.startsWith('field:')) ? `<optgroup label="${live ? esc(siteName) : 'Custom fields'}">${cat.fields.filter((f) => f.field.startsWith('field:')).map((f) => `<option value="${esc(f.field)}" ${sel === f.field ? 'selected' : ''}>${esc(f.label)}</option>`).join('')}</optgroup>` : '');
      const render = () => {
        v.innerHTML = `<div class="flow-top"><a class="back" href="#/contacts?tab=segments">← Segments</a></div>
          <div class="page-head" style="margin-bottom:16px"><div><h1>${seg ? 'Edit segment' : 'New segment'}${live ? ` <span class="tag tag-live">Live</span>` : ''}</h1><p>${live ? `People on ${esc(siteName)}, read live from the site when you count, preview or send. Nobody is copied into ${SL_BN()}.` : 'Segments update automatically as contacts change.'}</p></div></div>
          <div class="seg-layout"><section class="card"><div class="card-body">
            <div class="field"><label>Segment name</label><input id="sn" maxlength="100" value="${esc(st.name)}" placeholder="e.g. Engaged Growth customers"></div>
            <div class="seg-match">${live ? 'People' : 'Contacts'} who match <select class="select" id="sm"><option value="all" ${st.match === 'all' ? 'selected' : ''}>all</option><option value="any" ${st.match === 'any' ? 'selected' : ''}>any</option></select> of these conditions</div>
            <div id="conds">${st.conds.map((c, i) => `<div class="seg-row"><select class="select sf" data-i="${i}">${fieldOptions(c.field)}</select><select class="select so" data-i="${i}">${(cat.ops[typeOf(c.field)] || []).map((o) => `<option value="${o}" ${c.op === o ? 'selected' : ''}>${OP_LABEL[o]}</option>`).join('')}</select>${valueInput(c, i)}<button class="icon-btn sx" data-i="${i}" title="Remove condition">${ic('x', 15)}</button></div>`).join('') || '<p class="faint">No conditions: the segment includes every contact.</p>'}</div>
            <button class="btn btn-sm" id="sadd">${ic('plus', 14)}Add condition</button>
            <div id="serr"></div>
          </div><div class="panel-foot"><span class="hint">${live ? 'Campaigns only go to people who agreed to marketing; unsubscribed and bounced addresses are never emailed.' : 'Unsubscribed and bounced contacts are never emailed, even if they match.'}</span>${seg && canEdit() ? '<button class="btn" id="sdel">Delete</button>' : ''}${canEdit() ? `<button class="btn btn-primary" id="ssave">${seg ? 'Save segment' : 'Create segment'}</button>` : ''}</div></section>
          <aside class="card seg-preview"><div class="card-body"><div class="cp-label" style="margin-top:0">Right now</div><div class="seg-count" id="pc">…</div><div class="faint" id="psub" style="font-size:12.5px"></div><div class="cp-label">Sample</div><div id="ps" class="faint" style="font-size:13px">…</div></div></aside></div>`;
        bind(); preview();
      };
      let pt;
      const preview = () => { clearTimeout(pt); pt = setTimeout(() => API('POST', base() + '/segments/preview', Object.assign({ rules: { match: st.match, conditions: st.conds } }, live ? { site_connection_id: siteId } : {})).then((r) => { if (!$('#pc')) return; $('#pc').textContent = fmt(r.count); $('#psub').textContent = live ? `${fmt(r.subscribed)} agreed to marketing` : `${fmt(r.subscribed)} subscribed`; $('#ps').innerHTML = r.sample.map((c) => `<div class="seg-sample">${esc(c.name || c.email)}<span>${esc(c.email)}</span></div>`).join('') || 'Nobody matches yet.'; $('#serr').innerHTML = ''; }).catch((err) => { if (!$('#pc')) return; $('#pc').textContent = '—'; $('#psub').textContent = ''; $('#ps').textContent = ''; $('#serr').innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; }), 300); };
      const bind = () => {
        $('#sn').oninput = (e) => { st.name = e.target.value; };
        $('#sm').onchange = (e) => { st.match = e.target.value; preview(); };
        $$('.sf').forEach((s) => s.onchange = () => { const c = st.conds[s.dataset.i]; c.field = s.value; c.op = (cat.ops[typeOf(c.field)] || [])[0]; c.value = c.field === 'status' ? 'subscribed' : ''; render(); });
        $$('.so').forEach((s) => s.onchange = () => { const c = st.conds[s.dataset.i]; c.op = s.value; if (noValue(c.op)) c.value = null; render(); });
        $$('.sv').forEach((s) => s.oninput = s.onchange = () => { const c = st.conds[s.dataset.i]; c.value = s.type === 'number' ? (s.value === '' ? '' : +s.value) : s.value; preview(); });
        $$('.sx').forEach((b) => b.onclick = () => { st.conds.splice(+b.dataset.i, 1); render(); });
        $('#sadd').onclick = () => { st.conds.push(live ? { field: firstSiteField.field, op: (cat.ops[firstSiteField.type] || ['is'])[0], value: '' } : { field: 'tag', op: 'has', value: '' }); render(); };
        const save = $('#ssave'); if (save) save.onclick = () => {
          if (!st.name.trim()) return fieldError(v, '#sn', 'Name the segment.');
          save.disabled = true;
          (seg ? API('PATCH', `${base()}/segments/${seg.id}`, { name: st.name.trim(), rules: { match: st.match, conditions: st.conds } }) : API('POST', base() + '/segments', Object.assign({ name: st.name.trim(), rules: { match: st.match, conditions: st.conds } }, live ? { site_connection_id: siteId } : {})))
            .then((r) => { toast(`${seg ? 'Saved' : 'Created'}: ${fmt(r.segment.count)} ${live ? 'people' : 'contacts'}`); location.hash = '#/contacts?tab=segments'; })
            .catch((err) => { save.disabled = false; $('#serr').innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
        };
        const del = $('#sdel'); if (del) del.onclick = () => confirmDialog({ title: `Delete “${seg.name}”?`, desc: live ? 'Nobody on the site is affected.' : 'Contacts aren’t affected.', onConfirm: () => API('DELETE', `${base()}/segments/${seg.id}`).then(() => { toast('Segment deleted'); location.hash = '#/contacts?tab=segments'; }) });
      };
      render();
    }).catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  // ═══════ #/contacts/import (full-page flow) ═══════
  const TARGETS = [['email', 'Email (required)'], ['first_name', 'First name'], ['last_name', 'Last name'], ['full_name', 'Full name (split in two)'], ['tags', 'Tags (separated by ; or ,)'], ['unsubscribed', 'Unsubscribed (yes/no)'], ['skip', 'Don’t import']];
  const STAT_LABEL = [['created', 'New contacts', 'var(--s-delivered)'], ['updated', 'Updated', ''], ['duplicates', 'Duplicates merged', ''], ['suppressed', 'Already suppressed (kept that way)', ''], ['marked_unsubscribed', 'Marked unsubscribed', ''], ['invalid', 'Not valid', 'var(--s-bounced)'], ['typo', 'Typos (e.g. gmial.com)', 'var(--s-bounced)'], ['role', 'Role addresses (info@…)', 'var(--s-deferred)'], ['no_mail_server', 'Domain can’t get email', 'var(--s-bounced)'], ['unchanged', 'Existing, left as they were', ''], ['over_limit', 'Over your plan’s contact limit', 'var(--s-bounced)']];
  pages['contacts/import'] = (v) => {
    gen++;
    if (!canEdit()) { v.innerHTML = '<div class="card"><div class="card-body">Viewers can’t import contacts.</div></div>'; return; }
    const ctx = { imp: null, lists: [], fields: [], listMode: 'none', listId: '', newList: '', tags: '', update: true, roles: false, poll: null };
    Promise.all([API('GET', base() + '/lists'), API('GET', base() + '/fields')]).then(([l, f]) => { ctx.lists = l.lists; ctx.fields = f.fields; flow(); }).catch(() => flow());
    const stop = () => { if (ctx.poll) { clearInterval(ctx.poll); ctx.poll = null; } };
    const flow = () => S.flowPage(v, {
      back: ['#/contacts', 'Contacts'], title: 'Import contacts', sub: 'Every address is checked before it touches your list. Anyone who unsubscribed or bounced stays that way.', ctx,
      railFoot: `${ic('shield', 13)} Only import people who agreed to get email from you. Bought or scraped lists hurt your deliverability and break AWS’s rules.`,
      steps: [
        { label: 'Upload', railSub: (x) => (x.imp ? esc(x.imp.filename) : 'CSV, up to 100,000 rows'), status: (x) => (x.imp ? 'done' : ''), title: 'Upload your CSV', desc: 'First row is the header. Comma, semicolon or tab separated.',
          render: (x) => `<div class="dropzone" id="dz"><div class="ic">${ic('upload', 22)}</div><b>Drop a CSV here, or click to choose</b><p class="faint" style="font-size:12.5px;margin-top:4px">Up to 100,000 rows and 25 MB</p><input type="file" id="csv" accept=".csv,text/csv,text/plain" hidden></div><div id="fp" style="margin-top:12px">${x.imp ? `<div class="file-pill">${ic('file', 18)}<div style="flex:1"><b style="font-size:13px">${esc(x.imp.filename)}</b><div class="faint" style="font-size:12px">${fmt(x.imp.total_rows)} rows · ${x.imp.columns.length} columns</div></div><span class="pill-ok">${ic('check', 15, 2.5)}</span></div>` : ''}</div>
            <label class="check" style="margin-top:14px"><input type="checkbox" id="consent" ${x.consent ? 'checked' : ''}> These people agreed to get email from us</label>`,
          mount: (v2, x, f) => {
            const pick = (file) => { if (!file) return; if (file.size > 25 * 1024 * 1024) return toast('Files can be up to 25 MB.'); const fd = new FormData(); fd.append('file', file); $('#fp', v2).innerHTML = `<div class="file-pill"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span><div style="flex:1"><b style="font-size:13px">${esc(file.name)}</b><div class="faint" style="font-size:12px">Uploading and reading the file…</div></div></div>`; upload(fd).then((r) => { x.imp = r.import; f.render(); }).catch((err) => { $('#fp', v2).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; }); };
            $('#dz', v2).onclick = () => $('#csv', v2).click(); $('#csv', v2).onchange = (e) => pick(e.target.files[0]);
            $('#dz', v2).ondragover = (e) => { e.preventDefault(); $('#dz', v2).classList.add('over'); }; $('#dz', v2).ondragleave = () => $('#dz', v2).classList.remove('over'); $('#dz', v2).ondrop = (e) => { e.preventDefault(); $('#dz', v2).classList.remove('over'); pick(e.dataTransfer.files[0]); };
            $('#consent', v2).onchange = (e) => { x.consent = e.target.checked; };
          },
          validate: (v2, x) => { if (!x.imp) { toast('Choose a CSV file first.'); return false; } if (!$('#consent', v2).checked) { toast('Confirm these people agreed to get email from you.'); return false; } x.consent = true; } },
        { label: 'Match columns', railSub: (x) => (x.imp ? `${x.imp.mapping.filter((t) => t !== 'skip').length} of ${x.imp.columns.length} used` : ''), title: 'Check the column matching', desc: 'We matched your columns from their names. Unknown columns become custom fields, so nothing is lost.',
          render: (x) => `<div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table class="tbl"><thead><tr><th>Your column</th><th>Sample values</th><th>Imports as</th></tr></thead><tbody>${x.imp.columns.map((col, i) => { const t = x.imp.mapping[i]; const samples = (x.imp.sample || []).map((r) => r[i]).filter(Boolean).slice(0, 3); return `<tr style="cursor:default"><td class="cell-main">${esc(col || `Column ${i + 1}`)}</td><td class="faint" style="font-size:12.5px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(samples.join(', ') || '—')}</td><td><select class="select mp" data-i="${i}">${TARGETS.map(([k, l]) => `<option value="${k}" ${t === k ? 'selected' : ''}>${l}</option>`).join('')}<optgroup label="Custom field">${x.fields.map((fd) => `<option value="field:${esc(fd.key)}" ${t === 'field:' + fd.key ? 'selected' : ''}>${esc(fd.label)}</option>`).join('')}${t.startsWith('field:') && !x.fields.some((fd) => 'field:' + fd.key === t) ? `<option value="${esc(t)}" selected>New field: ${esc(t.slice(6))}</option>` : ''}</optgroup></select></td></tr>`; }).join('')}</tbody></table></div><div id="merr"></div>`,
          mount: (v2, x) => $$('.mp', v2).forEach((s) => s.onchange = () => { x.imp.mapping[+s.dataset.i] = s.value; }),
          validate: (v2, x) => { const n = x.imp.mapping.filter((t) => t === 'email').length; if (n !== 1) { $('#merr', v2).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>Choose exactly one column as Email.</div></div>`; return false; } } },
        { label: 'Options', railSub: 'Lists, tags, updates', title: 'Where should they go?', desc: '',
          render: (x) => `<div class="field"><label>Add everyone to a list</label><div class="radio-cards" id="lm">${[['none', 'Don’t add to a list'], ['existing', 'An existing list'], ['new', 'A new list']].filter(([k]) => k !== 'existing' || x.lists.length).map(([k, l]) => `<label class="radio-card ${x.listMode === k ? 'on' : ''}" data-v="${k}"><span class="dot"></span><div><b>${l}</b></div></label>`).join('')}</div></div>
            ${x.listMode === 'existing' ? `<div class="field"><select class="select" id="lid">${x.lists.map((l) => `<option value="${l.id}" ${x.listId === l.id ? 'selected' : ''}>${esc(l.name)} (${fmt(l.total)})</option>`).join('')}</select></div>` : ''}
            ${x.listMode === 'new' ? `<div class="field"><input id="lnew" placeholder="e.g. Webinar signups, October" value="${esc(x.newList)}" maxlength="100"></div>` : ''}
            <div class="field"><label>Tag everyone <span class="faint">(optional, comma separated)</span></label><input id="itags" value="${esc(x.tags)}" placeholder="e.g. import-oct-2026"></div>
            <label class="check"><input type="checkbox" id="iupd" ${x.update ? 'checked' : ''}> Update contacts that already exist (names and fields from the file)</label>
            <label class="check" style="margin-top:6px"><input type="checkbox" id="irole" ${x.roles ? 'checked' : ''}> Include role addresses like info@ and support@ <span class="faint">(not recommended: shared inboxes complain more)</span></label>`,
          mount: (v2, x, f) => { $$('#lm .radio-card', v2).forEach((c) => c.onclick = () => { x.listMode = c.dataset.v; if (x.listMode === 'existing' && !x.listId && x.lists[0]) x.listId = x.lists[0].id; f.render(); }); const lid = $('#lid', v2); if (lid) lid.onchange = (e) => { x.listId = e.target.value; }; const ln = $('#lnew', v2); if (ln) ln.oninput = (e) => { x.newList = e.target.value; }; $('#itags', v2).oninput = (e) => { x.tags = e.target.value; }; $('#iupd', v2).onchange = (e) => { x.update = e.target.checked; }; $('#irole', v2).onchange = (e) => { x.roles = e.target.checked; }; },
          validate: (v2, x) => { if (x.listMode === 'new' && !x.newList.trim()) { toast('Name the new list.'); return false; } },
          next: 'Start import' },
        { label: 'Import', railSub: (x) => (x.imp && x.imp.status === 'done' ? 'Finished' : x.imp && x.imp.status === 'processing' ? 'Importing…' : 'Results'), status: (x) => (x.imp && x.imp.status === 'done' ? 'done' : ''), title: 'Importing', desc: 'You can leave this page; the import keeps going.',
          render: (x) => resultView(x.imp),
          mount: (v2, x, f) => {
            const foot = $('.panel-foot', v2); if (foot) foot.style.display = x.imp.status === 'done' || x.imp.status === 'failed' ? '' : 'none';
            const nb = $('#pnext', v2); if (nb) nb.textContent = 'Go to contacts';
            const pb = $('#pback', v2); if (pb) pb.style.display = 'none';
            if (x.imp.status === 'uploaded') {
              const opts = { consent: true, update_existing: x.update, include_roles: x.roles, tags: x.tags.split(',').map((t) => t.trim()).filter(Boolean) };
              if (x.listMode === 'existing') opts.list_id = x.listId; if (x.listMode === 'new') opts.new_list = x.newList.trim();
              API('POST', `${base()}/imports/${x.imp.id}/start`, { mapping: x.imp.mapping, options: opts }).then((r) => { x.imp = r.import; f.render(); }).catch((err) => { $('#ires', v2).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; if (foot) foot.style.display = ''; if (pb) pb.style.display = ''; });
              return;
            }
            stop();
            if (x.imp.status === 'processing') ctx.poll = setInterval(() => { if (!document.body.contains(v2)) return stop(); API('GET', `${base()}/imports/${x.imp.id}`).then((r) => { const was = x.imp.status; x.imp = r.import; if (x.imp.status !== was) { stop(); f.render(); } else { const box = $('#ires', v2); if (box) box.outerHTML = resultView(x.imp); } }).catch(() => {}); }, 1500);
          } },
      ],
      onFinish: () => { stop(); Object.assign(view, { list: '', segment: '', tag: '', q: '', status: '' }); location.hash = '#/contacts?tab=contacts'; },
    });
  };
  function resultView(imp) {
    if (!imp || imp.status === 'uploaded') return '<div id="ires"><div class="tf-res"><span class="spin" style="border-color:var(--border-strong);border-top-color:var(--accent)"></span>Starting…</div></div>';
    const s = imp.stats || {}, pctDone = imp.total_rows ? Math.round((imp.processed_rows / imp.total_rows) * 100) : 100;
    const skipped = (s.invalid || 0) + (s.typo || 0) + (s.role || 0) + (s.no_mail_server || 0) + (s.over_limit || 0);
    return `<div id="ires">${imp.status === 'processing' ? `<div class="imp-prog"><div class="lm-top" style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><b>${fmt(imp.processed_rows)} of ${fmt(imp.total_rows)} rows</b><span class="faint">${pctDone}%</span></div><div class="lm-bar"><span style="width:${pctDone}%"></span></div></div>` : imp.status === 'failed' ? `<div class="callout warn" style="margin-top:0">${ic('alert', 15)}<div>${esc(imp.error || 'The import stopped.')}</div></div>` : `<div class="callout ok" style="margin-top:0">${ic('check', 15, 2.5)}<div><b>Import finished.</b> ${fmt((s.created || 0) + (s.updated || 0))} contacts imported from ${fmt(imp.total_rows)} rows.</div></div>`}
      <div class="imp-stats">${STAT_LABEL.filter(([k]) => s[k]).map(([k, l, col]) => `<div><small>${l}</small><b ${col ? `style="color:${col}"` : ''}>${fmt(s[k])}</b></div>`).join('')}</div>
      ${skipped && imp.has_skipped ? `<a class="btn btn-sm" href="/api${base()}/imports/${imp.id}/skipped" style="margin-top:12px">${ic('download', 14)}Download the ${fmt(skipped)} skipped rows, with reasons</a>` : ''}</div>`;
  }
  // Multipart upload needs its own request: SL_API sends JSON.
  async function upload(fd) {
    const xsrf = window.SL_XSRF();
    const r = await fetch('/api' + base() + '/imports', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf) : '', 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw Object.assign({ message: 'Upload failed. Try again.' }, d.error || {}, d.error && d.error.fields && d.error.fields.file ? { message: d.error.fields.file } : {});
    return d;
  }
})();
