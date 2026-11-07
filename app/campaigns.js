/* MailVesper: real campaigns (Phase 7).
   Replaces the prototype #/campaigns, #/campaigns/new (?id=) and #/campaigns/report (?id=); the builder edits a
   campaign's design with #/campaigns/design?campaign=<id> and autosaves it. Talks to /api/v1/workspaces/{id}/campaigns*.
   Loaded after design.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, chip, toast, modal, confirmDialog, fieldError, menu, lineChart, miniRoute, bindCopy } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}/campaigns`;
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { 'campaigns/new': 'Campaign', 'campaigns/report': 'Campaign report' });
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const day = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
  const rate = (a, b) => (b ? a / b : 0);
  const myTz = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (_) { return 'UTC'; } })();
  const ZONES = (() => { try { return Intl.supportedValuesOf('timeZone'); } catch (_) { return [myTz, 'UTC', 'Africa/Lagos', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Singapore']; } })();
  const preview = (html) => String(html || '').replace(/\{\{\s*first_name[^}]*\}\}/g, 'Amara').replace(/\{\{\s*last_name[^}]*\}\}/g, 'Okafor').replace(/\{\{\s*email\s*\}\}/g, 'amara@example.com').replace(/\{\{[^}]+\}\}/g, '#');
  const STATUS_LABEL = { draft: 'Draft', scheduled: 'Scheduled', sending: 'Sending', paused: 'Paused', sent: 'Sent', cancelled: 'Cancelled' };
  const statusChip = (s) => chip({ cancelled: 'dropped' }[s] || s, STATUS_LABEL[s] || s);

  // ═══════ #/campaigns ═══════
  let listTab = 'all';
  pages.campaigns = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = `<div class="page-head"><div><h1>Campaigns</h1><p>One-off emails to a list or segment, tracked from send to click.</p></div>${canEdit() ? `<button class="btn btn-primary" id="newc">${ic('plus', 15)}New campaign</button>` : ''}</div>
      <div class="ptabs" id="ctabs">${[['all', 'All'], ['draft', 'Drafts'], ['scheduled', 'Scheduled'], ['sending,paused', 'Sending'], ['sent', 'Sent']].map(([k, l]) => `<button class="ptab ${listTab === k ? 'on' : ''}" data-k="${k}">${l}</button>`).join('')}</div>
      <div class="card"><div class="table-wrap"><table class="tbl"><thead><tr><th>Campaign</th><th>Status</th><th>Delivered</th><th>Real opens</th><th>Clicks</th><th></th></tr></thead><tbody id="crows"><tr><td colspan="6" class="faint">Loading…</td></tr></tbody></table></div></div>`;
    const nc = $('#newc'); if (nc) nc.onclick = () => newCampaign();
    $$('#ctabs .ptab').forEach((b) => b.onclick = () => { listTab = b.dataset.k; pages.campaigns(v); });
    S.paged(base() + (listTab === 'all' ? '' : '?status=' + listTab), 'campaigns', (r, ctl) => {
      if (!alive(g)) return;
      $('#crows').innerHTML = r.campaigns.length ? r.campaigns.map((c) => {
        const s = c.stats, when = c.status === 'scheduled' ? `sends ${day(c.scheduled_at)}` : c.started_at ? day(c.started_at) : `edited ${day(c.updated_at)}`;
        const prog = (c.status === 'sending' || c.status === 'paused') && c.recipients_count ? `<div class="camp-meta" style="margin-top:4px"><span class="mini-bar"><span style="width:${Math.min(100, ((s ? s.sent : 0) / c.recipients_count) * 100)}%;background:var(--signal)"></span></span>${fmt(s ? s.sent : 0)} of ${fmt(c.recipients_count)} sent</div>` : '';
        return `<tr data-id="${c.id}" data-st="${c.status}"><td><div class="camp-name">${esc(c.name)}</div><div class="camp-meta">${esc(c.audience_name || 'No audience yet')} · ${when}${c.ab.enabled ? ' · A/B' : ''}</div></td><td>${statusChip(c.status)}${prog}</td>
          <td>${s && s.sent ? pct(rate(s.delivered, s.sent)) : '<span class="faint">–</span>'}</td><td>${s && s.delivered ? `<span class="mini-bar"><span style="width:${Math.min(100, rate(s.opened, s.delivered) * 140)}%;background:var(--s-opened)"></span></span>${pct(rate(s.opened, s.delivered))}` : '<span class="faint">–</span>'}</td>
          <td>${s && s.delivered ? pct(rate(s.clicked, s.delivered)) : '<span class="faint">–</span>'}</td><td style="text-align:right">${canEdit() ? `<button class="btn btn-sm btn-ghost cm" data-id="${c.id}">${ic('more', 15)}</button>` : ''}</td></tr>`;
      }).join('') : `<tr style="cursor:default"><td colspan="6"><div class="empty"><div class="ic">${ic('campaigns', 22)}</div><h3>${listTab === 'all' ? 'No campaigns yet' : 'Nothing here'}</h3><p>${listTab === 'all' ? 'Pick an audience, design the email, and send now or schedule it.' : 'Try another tab.'}</p>${listTab === 'all' && canEdit() ? '<div style="margin-top:12px"><button class="btn btn-primary btn-sm" id="newc2">New campaign</button></div>' : ''}</div></td></tr>`;
      const n2 = $('#newc2'); if (n2) n2.onclick = () => newCampaign();
      $$('#crows tr[data-id]').forEach((tr) => tr.onclick = (e) => { if (e.target.closest('.cm')) return; location.hash = (['draft', 'scheduled'].includes(tr.dataset.st) ? '#/campaigns/new?id=' : '#/campaigns/report?id=') + tr.dataset.id; });
      $$('.cm', v).forEach((b) => b.onclick = (e) => { e.stopPropagation(); const c = r.campaigns.find((x) => x.id === b.dataset.id); menu(b, campaignMenu(c, () => pages.campaigns(v)), 'right'); });
      S.pageBar(ctl, $('#ctabs'), $('#crows').closest('.card'), 'campaigns', 'beforeend');
    }).ready.catch((err) => { const rows = $('#crows'); if (alive(g) && rows) rows.innerHTML = `<tr><td colspan="6">${esc(err.message)}</td></tr>`; }); // the page may be gone already
  };
  function campaignMenu(c, refresh) {
    const act = (a, msg) => API('POST', `${base()}/${c.id}/${a}`).then(() => { toast(msg); refresh(); }).catch((err) => toast(err.message));
    return [
      ...(['draft', 'scheduled'].includes(c.status) ? [{ label: 'Edit', icon: 'edit', onClick: () => (location.hash = '#/campaigns/new?id=' + c.id) }] : [{ label: 'View report', icon: 'overview', onClick: () => (location.hash = '#/campaigns/report?id=' + c.id) }]),
      { label: 'Duplicate', icon: 'copy', onClick: () => API('POST', `${base()}/${c.id}/duplicate`).then((r) => { toast('Duplicated as a draft'); location.hash = '#/campaigns/new?id=' + r.campaign.id; }).catch((err) => toast(err.message)) },
      ...(c.status === 'sending' ? [{ label: 'Pause sending', icon: 'pause', onClick: () => act('pause', 'Campaign paused') }] : []),
      ...(c.status === 'paused' ? [{ label: 'Resume sending', icon: 'send', onClick: () => act('resume', 'Sending again') }] : []),
      ...(['scheduled', 'sending', 'paused'].includes(c.status) ? ['sep', { label: 'Cancel campaign', icon: 'x', danger: true, onClick: () => confirmDialog({ title: `Cancel “${c.name}”?`, desc: 'Nobody else gets it. Emails already sent stay sent.', label: 'Cancel campaign', onConfirm: () => act('cancel', 'Campaign cancelled') }) }] : []),
      ...(['draft', 'sent', 'cancelled'].includes(c.status) ? ['sep', { label: 'Delete', icon: 'trash', danger: true, onClick: () => confirmDialog({ title: `Delete “${c.name}”?`, desc: c.status === 'sent' ? 'The report is deleted. Sent emails stay in Activity.' : 'This draft will be removed.', onConfirm: () => API('DELETE', `${base()}/${c.id}`).then(() => { toast('Campaign deleted'); refresh(); }).catch((err) => toast(err.message)) }) }] : []),
    ];
  }
  function newCampaign(templateId) {
    API('POST', base(), { name: 'Untitled campaign', template_id: templateId || undefined }).then((r) => { location.hash = '#/campaigns/new?id=' + r.campaign.id; }).catch((err) => toast(err.message));
  }
  S.newCampaignFromTemplate = newCampaign;

  // ═══════ #/campaigns/new?id= (checklist editor) ═══════
  pages['campaigns/new'] = (v) => {
    const g = (seq = S.routeSeq, ++gen), id = S.param('id');
    if (!id) { if (canEdit()) newCampaign(); else location.hash = '#/campaigns'; return; }
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading the campaign…</div></div>';
    Promise.all([API('GET', `${base()}/${id}`), API('GET', `${base()}/audiences`), S.senders(), API('GET', `/v1/workspaces/${wsId()}/templates`).catch(() => ({ templates: [] }))])
      .then(([r, aud, dom, tpls]) => { if (alive(g)) editor(v, g, r.campaign, aud, dom, tpls.templates); })
      .catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)} <a href="#/campaigns">Back to campaigns</a></div></div>`));
  };

  function editor(v, g, c, aud, snd, templates) {
    const domains = S.hasSenders(snd) ? [S.firstFrom(snd)] : []; // only its length and first entry are used
    let open = S.campaignOpen || (c.audience_type ? (c.subject ? (c.has_design ? 'when' : 'content') : 'subj') : 'aud'), device = 'desktop', saveT = null, savingState = 'Saved';
    const when = { mode: c.send_mode === 'scheduled' ? 'scheduled' : c.send_mode === 'local' ? 'local' : (c.status === 'scheduled' ? c.send_mode : null), date: '', time: '09:30', tz: c.timezone || myTz };
    if (c.scheduled_at && c.send_mode === 'scheduled') { const d = new Date(c.scheduled_at); when.date = d.toISOString().slice(0, 10); when.time = d.toTimeString().slice(0, 5); when.tz = myTz; }
    if (c.send_mode === 'local') { when.date = c.local_date || ''; when.time = c.local_time || '09:30'; }
    if (!when.date) { const t = new Date(Date.now() + 864e5); when.date = t.toISOString().slice(0, 10); }
    const ro = !canEdit() || !['draft', 'scheduled'].includes(c.status);
    // Debounced saves merge, so a quick edit to another field never drops an earlier one. After saving, the page
    // re-renders only when nobody is typing (a re-render mid-word would steal the caret); otherwise on focus leaving.
    let pendingBody = {}, renderLater = false;
    const typing = () => { const a = document.activeElement; return !!a && v.contains(a) && /^(INPUT|TEXTAREA)$/.test(a.tagName) && a.type !== 'checkbox'; };
    const save = (body, now) => {
      if (ro) return Promise.resolve();
      Object.assign(pendingBody, body); clearTimeout(saveT); savingState = 'Saving…'; paintSaved();
      const run = () => { const b = pendingBody; pendingBody = {}; return API('PATCH', `${base()}/${c.id}`, b).then((r) => { Object.assign(c, r.campaign); savingState = 'Saved just now'; paintSaved(); if (!now && typing()) renderLater = true; else { renderLater = false; render(); } }).catch((err) => { savingState = 'Not saved: ' + err.message; paintSaved(); toast(err.message); }); };
      return now ? run() : new Promise((res) => { saveT = setTimeout(() => run().then(res), 600); });
    };
    v.addEventListener('focusout', () => setTimeout(() => { if (renderLater && alive(g) && !typing()) { renderLater = false; render(); } }, 0));
    const paintSaved = () => { const el = $('#csaved'); if (el) el.innerHTML = `${savingState.startsWith('Saved') ? ic('check', 13, 2.5) : ''}${esc(savingState)}`; };
    const audLabel = () => { if (!c.audience_type) return ''; const e = c.estimate || { total: 0 }; if (e.total == null) return `${c.audience_name || ''} · ${e.error || 'couldn’t be counted right now'}`; return `${c.audience_name || ''} · ${fmt(e.total)} recipient${e.total === 1 ? '' : 's'}${c.exclude_dormant && e.dormant ? ` (${fmt(e.dormant)} dormant left out)` : ''}`; };
    const issues = () => (c.checks || []).filter((x) => x.status !== 'ok').length;
    const problemFor = (k) => (c.problems || []).find((p) => p.key === k);
    const peopleOpt = () => { const on = c.audience_type === 'people'; return `<label class="aud ${on ? 'on' : ''}" data-at="people" data-aid=""><span class="dot" style="width:16px;height:16px;border-radius:50%;border:${on ? '5px solid var(--accent)' : '2px solid var(--border-strong)'}"></span><div><b>Specific people</b><br><span>Pick contacts one by one, or type email addresses</span></div><span class="n">${on ? fmt((c.people || []).length) : ''}</span></label>`; };
    const secs = () => [
      { k: 'aud', t: 'To', s: audLabel(), done: !!c.audience_type && !problemFor('audience'), warn: !!c.audience_type && !!problemFor('audience'),
        body: () => `<div class="aud-list">${[['all', null, 'All subscribed contacts', 'Everyone who can be emailed', aud.all]].concat(aud.lists.map((l) => ['list', l.id, l.name, 'List', l]), aud.segments.slice().sort((a, b) => (a.group || '').localeCompare(b.group || '') || a.name.localeCompare(b.name)).map((s) => ['segment', s.id, s.name, s.live ? (s.error ? esc(s.error) : 'Read live from ' + esc(s.group || 'the site') + ' when it starts · only who agreed to marketing') : s.group ? 'From ' + esc(s.group) : 'Segment · updates until sending starts', s])).map(([type, aid, name, sub, est], i, all) => { const on = c.audience_type === type && (type === 'all' || c.audience_id === aid); const grp = type === 'segment' && est.group && (!all[i - 1] || all[i - 1][4].group !== est.group) ? `<div class="aud-grp">${esc(est.group)}</div>` : ''; return `${grp}<label class="aud ${on ? 'on' : ''}" data-at="${type}" data-aid="${aid || ''}"><span class="dot" style="width:16px;height:16px;border-radius:50%;border:${on ? '5px solid var(--accent)' : '2px solid var(--border-strong)'}"></span><div><b>${esc(name)}</b>${est.live ? ' <span class="tag tag-live">Live</span>' : ''}<br><span>${sub}</span></div><span class="n">${est.total == null ? '–' : fmt(est.total)}</span></label>`; }).join('')}${peopleOpt()}</div>
          ${c.audience_type === 'people' ? `<div class="aud-people"><div id="aud-pp">${ro ? `<p style="font-size:13px">${(c.people || []).map((p) => esc(p.name ? `${p.name} <${p.email}>` : p.email)).join(', ') || 'Nobody yet'}</p>` : ''}</div><span class="help">Search your contacts, or type or paste email addresses. New addresses are added to your contacts. Unsubscribed people are skipped. Up to 500.</span></div>` : ''}
          ${aud.lists.length || aud.segments.length ? '' : '<p class="faint" style="font-size:12.5px;margin-top:8px">Create lists or segments on the Contacts page to target part of your audience.</p>'}
          <label class="check" style="margin-top:10px"><input type="checkbox" id="xd" ${c.exclude_dormant ? 'checked' : ''} ${ro ? 'disabled' : ''}> Leave out dormant contacts (no real opens or clicks in 120 days)${c.estimate && c.estimate.dormant ? ` · ${fmt(c.estimate.dormant)} in this audience` : ''}. <span class="faint">Recommended: it protects your reputation.</span></label>
          ${aud.topics.length ? `<div class="field" style="margin-top:12px"><label>Topic <span class="faint">(people who opted out of it are skipped)</span></label><select class="select" id="ctopic" ${ro ? 'disabled' : ''}><option value="">No topic</option>${aud.topics.map((t) => `<option value="${t.id}" ${c.topic_id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>` : ''}` },
      { k: 'from', t: 'From', s: c.from_email ? `${c.from_name ? c.from_name + ' ' : ''}<${c.from_email}>` : '', done: !!c.from_email && !problemFor('from'), warn: !!problemFor('from'),
        body: () => domains.length ? `<div class="row2"><div class="field"><label>Sender name</label><input id="fn" value="${esc(c.from_name || '')}" placeholder="e.g. Acme" maxlength="120" ${ro ? 'disabled' : ''}></div><div class="field"><label>Sender address</label>${S.fromPick('fp', snd, c.from_email, 'news', ro)}</div></div>
          <div class="field"><label>Reply-to <span class="faint">(optional)</span></label><input id="rt" type="email" value="${esc(c.reply_to || '')}" placeholder="Where replies go, if not the sender" ${ro ? 'disabled' : ''}></div>` : `<div class="callout warn" style="margin-top:0">${ic('alert', 15)}<div>You need a verified sender to send campaigns. <a href="${snd.method === 'smtp' ? '#/ses/smtp' : '#/domains'}">Verify one</a>.</div></div>` },
      { k: 'subj', t: 'Subject', s: c.subject ? `${c.subject}${c.ab.enabled && c.subject_b ? ` / B: ${c.subject_b}` : ''}${c.preheader ? ' — ' + c.preheader : ''}` : '', done: !!c.subject && !problemFor('subject'), warn: (c.subject || '').length > 60 || !!problemFor('subject'),
        body: () => `<div class="field"><label>Subject line ${c.ab.enabled ? '<span class="tag">A</span>' : ''}</label><input id="sj" maxlength="255" value="${esc(c.subject || '')}" placeholder="What’s new this month, {{ first_name | there }}" ${ro ? 'disabled' : ''}><div class="counter" id="sjc">${(c.subject || '').length} / 60 recommended</div></div>
          <label class="check"><input type="checkbox" id="abon" ${c.ab.enabled ? 'checked' : ''} ${ro ? 'disabled' : ''}> A/B test the subject line</label>
          ${c.ab.enabled ? `<div class="ab-box"><div class="field"><label>Subject line <span class="tag">B</span></label><input id="sjb" maxlength="255" value="${esc(c.subject_b || '')}" placeholder="Try a different angle" ${ro ? 'disabled' : ''}></div>
            <div class="row3"><div class="field"><label>Test on</label><select class="select" id="abp" ${ro ? 'disabled' : ''}>${[10, 20, 30, 50].map((n) => `<option value="${n}" ${c.ab.test_pct === n ? 'selected' : ''}>${n}% of the audience</option>`).join('')}</select></div><div class="field"><label>Winner has more</label><select class="select" id="abm" ${ro ? 'disabled' : ''}><option value="opens" ${c.ab.metric === 'opens' ? 'selected' : ''}>Real opens</option><option value="clicks" ${c.ab.metric === 'clicks' ? 'selected' : ''}>Clicks</option></select></div><div class="field"><label>Pick the winner after</label><select class="select" id="abw" ${ro ? 'disabled' : ''}>${[1, 2, 4, 8, 12, 24].map((n) => `<option value="${n}" ${c.ab.wait_hours === n ? 'selected' : ''}>${n} hour${n > 1 ? 's' : ''}</option>`).join('')}</select></div></div>
            <p class="faint" style="font-size:12.5px">Half of the test group gets A, half gets B. Everyone else gets the winner. Apple’s automatic opens don’t count.</p></div>` : ''}
          <div class="field" style="margin-top:12px"><label>Preview text</label><input id="pr" maxlength="300" value="${esc(c.preheader || '')}" placeholder="Shown after the subject in most inboxes" ${ro ? 'disabled' : ''}></div>` },
      { k: 'content', t: 'Content', s: c.has_design ? `Designed email · ${c.size_kb} KB${issues() ? ` · ${issues()} ${issues() === 1 ? 'check' : 'checks'} to review` : ''}` : '', done: c.has_design && !problemFor('content'), warn: !!problemFor('content') || (c.checks || []).some((x) => x.status === 'bad'),
        body: () => c.has_design ? `<div class="ct-design"><div class="ct-thumb" id="ctopen" title="${ro ? 'Preview' : 'Open the builder'}"><iframe id="ctf" sandbox tabindex="-1"></iframe>${ro ? '' : `<span class="ct-hover">${ic('edit', 15)}Edit design</span>`}</div><div class="ct-side"><b>Your design</b><small>${c.size_kb} KB of HTML · Gmail clips at 102 KB</small>
            <ul class="ct-checks">${(c.checks || []).map((x) => `<li class="${x.status}"><span>${x.status === 'ok' ? ic('check', 12, 3) : '!'}</span>${x.status === 'ok' ? esc(x.name) : esc(x.detail)}</li>`).join('')}</ul>
            ${ro ? '' : `<div class="ct-acts"><button class="btn btn-primary btn-sm" id="editd">${ic('edit', 14)}Edit design</button><button class="btn btn-sm" id="ctnew">Start over</button></div>`}</div></div>`
          : `<div class="ct-start">${[['blank', 'edit', 'Design from scratch', 'Drag-and-drop builder, your brand applied'], ['gallery', 'templates', 'Pick from the gallery', '39 ready-made designs']].map(([k, i2, a, b]) => `<button class="ct-opt" data-start="${k}" ${ro ? 'disabled' : ''}><span>${ic(i2, 18)}</span><b>${a}</b><small>${b}</small></button>`).join('')}</div>
            ${templates.length ? `<div class="ins-label" style="margin:16px 0 8px">Or start from one of your templates</div><div class="tpl-pick">${templates.slice(0, 12).map((t) => `<button class="tpl-p" data-tpl="${t.id}" ${ro ? 'disabled' : ''}><b>${esc(t.name)}</b><small>${t.size_kb} KB</small></button>`).join('')}</div>` : ''}` },
      { k: 'when', t: 'Send time', s: c.status === 'scheduled' ? (c.send_mode === 'local' ? `${c.local_date} at ${c.local_time} in each recipient’s time zone` : day(c.scheduled_at)) : when.mode === 'now' ? 'Send now' : when.mode ? 'Not scheduled yet' : '', done: c.status === 'scheduled' || when.mode === 'now',
        body: () => `<div class="radio-cards" id="wh">${[['now', 'Send now', 'Starts within a minute.'], ['scheduled', 'Schedule', 'Pick a date and time.'], ['local', 'Recipient’s time zone', 'Arrives at the same local time for everyone.']].map(([k, a, b]) => `<label class="radio-card ${when.mode === k ? 'on' : ''}" data-w="${k}"><span class="dot"></span><div><b>${a}</b><span>${b}</span></div></label>`).join('')}</div>
          ${when.mode === 'scheduled' || when.mode === 'local' ? `<div class="row3" style="margin-top:12px"><div class="field"><label>Date</label><input type="date" id="wd" value="${esc(when.date)}" ${ro ? 'disabled' : ''}></div><div class="field"><label>Time</label><input type="time" id="wt" value="${esc(when.time)}" ${ro ? 'disabled' : ''}></div>
            <div class="field"><label>${when.mode === 'local' ? 'If a contact has no time zone' : 'Time zone'}</label><select class="select" id="wz" ${ro ? 'disabled' : ''}>${ZONES.map((z) => `<option ${z === when.tz ? 'selected' : ''}>${esc(z)}</option>`).join('')}</select></div></div>
            ${when.mode === 'local' ? '<p class="faint" style="font-size:12.5px">Uses each contact’s <span class="mono">timezone</span> field (e.g. Africa/Lagos). Sending starts when the earliest time zone reaches this time.</p>' : ''}` : ''}` },
    ];
    const subjectChecks = () => [
      ['Subject length', !c.subject ? '—' : c.subject.length <= 60 ? 'Good' : 'Too long for phones', !!c.subject && c.subject.length <= 60],
      ['Spam-trigger words', /\bfree\b|!!!|guarantee|act now|100% /i.test((c.subject || '') + (c.subject_b || '')) ? 'Found' : 'None', !/\bfree\b|!!!|guarantee|act now|100% /i.test((c.subject || '') + (c.subject_b || ''))],
      ...(c.checks || []).filter((x) => ['Unsubscribe link & address', 'Gmail clipping', 'Alt text on images', 'Links'].includes(x.name)).map((x) => [x.name, x.status === 'ok' ? 'OK' : x.detail, x.status === 'ok']),
      ['Dormant contacts left out', c.exclude_dormant ? 'Yes' : 'No, adds spam risk', c.exclude_dormant],
    ];
    const render = () => {
      if (!alive(g)) return;
      const S2 = secs(), doneN = S2.filter((s) => s.done && !s.warn).length, ready = !(c.problems || []).length && !!when.mode;
      const primary = when.mode === 'now' ? 'Send now' : 'Schedule';
      v.innerHTML = `<div class="flow-top"><a class="back" href="#/campaigns">← Campaigns</a></div>
        <div class="flow-top" style="margin-top:-8px"><input class="flow-title" id="cname" value="${esc(c.name)}" ${ro ? 'disabled' : ''}>${statusChip(c.status)}<span class="saved" id="csaved"></span><div class="spacer"></div>
          ${c.status === 'scheduled' ? `<button class="btn" id="unsch">Back to draft</button>` : ''}${c.has_design && canEdit() ? `<button class="btn" id="tst">${ic('send', 15)}Send test</button>` : ''}${ro ? '' : `<button class="btn btn-primary" id="go" ${ready ? '' : 'disabled'} title="${ready ? '' : esc(((c.problems || [])[0] || { message: 'Choose a send time' }).message)}">${c.status === 'scheduled' ? 'Update schedule' : primary}</button>`}</div>
        <div class="builder"><div>
          <div class="ready"><b>${ready ? 'Ready to send' : `${doneN} of ${S2.length} ready`}</b><div class="bars">${S2.map((s) => `<span class="${s.done && !s.warn ? 'on' : ''}"></span>`).join('')}</div></div>
          ${S2.map((s) => `<div class="sec ${open === s.k ? 'open' : ''} ${s.warn ? 'warn' : s.done ? 'done' : ''}" data-k="${s.k}"><div class="sec-head"><span class="sec-st">${s.warn ? '!' : s.done ? ic('check', 13, 3) : ''}</span><div style="min-width:0"><div class="t">${s.t}</div><div class="s ${s.s ? '' : 'unset'}">${s.s ? esc(s.s) : 'Not set yet'}</div>${s.warn && problemFor(s.k === 'subj' ? 'subject' : s.k === 'aud' ? 'audience' : s.k) ? `<div class="s" style="color:var(--s-deferred)">${esc(problemFor(s.k === 'subj' ? 'subject' : s.k === 'aud' ? 'audience' : s.k).message)}</div>` : ''}</div><button class="btn btn-sm">${open === s.k ? 'Done' : s.done ? 'Edit' : 'Add'}</button></div><div class="sec-body">${open === s.k ? s.body() : ''}</div></div>`).join('')}
          <div class="copilot" style="margin-top:14px"><div class="cp-head">${ic('copilot', 15, 2)}<span>Pre-send check</span><span class="cp-badge">${subjectChecks().filter((x) => x[2]).length} of ${subjectChecks().length} passing</span></div><ul class="cp-fix precheck" style="margin-top:10px">${subjectChecks().map(([a, b, ok]) => `<li><span style="display:flex;gap:8px">${ok ? ic('check', 14, 2.4) : `<span style="color:var(--s-deferred)">${ic('alert', 14, 2.2)}</span>`}${esc(a)}</span><span class="r" style="color:${ok ? 'var(--s-delivered)' : 'var(--s-deferred)'}">${esc(b)}</span></li>`).join('')}</ul></div>
        </div>
        <aside class="preview-pane"><div class="pp-head"><span>Preview</span><div class="seg" id="dev"><button data-d="desktop" class="${device === 'desktop' ? 'on' : ''}">Desktop</button><button data-d="mobile" class="${device === 'mobile' ? 'on' : ''}">Mobile</button></div></div>
          <div class="inbox-mock"><div class="cap">How it looks in the inbox</div><div class="im-row"><span class="dot"></span><span class="fr">${esc(c.from_name || (c.from_email || 'Your name'))}</span><span class="sj"><b>${esc(preview(c.subject) || 'Your subject line')}</b> <span>— ${esc(preview(c.preheader) || 'Preview text appears here')}</span></span><span class="tm">09:30</span></div><div class="im-row ghost"><span></span><span class="fr">Stripe</span><span class="sj">Your receipt from Stripe <span>— Thanks for your payment</span></span><span class="tm">08:12</span></div></div>
          <iframe class="pp-frame ${device}" id="ppf" sandbox></iframe></aside></div>`;
      paintSaved();
      const paint = () => { $('#ppf').srcdoc = c.html ? preview(c.html).replace('</style>', device === 'mobile' ? '.col{display:block!important;width:100%!important}</style>' : '</style>') : '<html><body style="font-family:-apple-system,sans-serif;display:grid;place-items:center;height:90vh;color:#a1a1aa;margin:0;background:#f4f4f5">Design your email to preview it</body></html>'; };
      paint();
      const ctf = $('#ctf'); if (ctf) ctf.srcdoc = preview(c.html);
      bind(paint);
    };
    const bind = (paint) => {
      $$('.sec-head', v).forEach((h) => h.onclick = () => { const k = h.parentElement.dataset.k; open = open === k ? null : k; S.campaignOpen = open; render(); });
      $$('#dev button', v).forEach((b) => b.onclick = () => { device = b.dataset.d; $('#ppf').className = 'pp-frame ' + device; $$('#dev button').forEach((x) => x.classList.toggle('on', x === b)); paint(); });
      if (ro) { const co = $('#ctopen'); if (co) co.onclick = null; return; }
      $('#cname').oninput = (e) => { c.name = e.target.value; save({ name: c.name.trim() || 'Untitled campaign' }); };
      $$('.aud', v).forEach((a) => a.onclick = (e) => { e.preventDefault(); save({ audience_type: a.dataset.at, audience_id: a.dataset.aid || null }, true); });
      const pp = $('#aud-pp'); if (pp && S.peoplePicker) S.peoplePicker(pp, { multi: true, label: 'Recipients', value: c.people || [], placeholder: 'Search contacts or type an email', onChange: (l) => save({ people: l.map((p) => p.id || p.email) }) });
      const xd = $('#xd'); if (xd) xd.onchange = () => save({ exclude_dormant: xd.checked }, true);
      const tp = $('#ctopic'); if (tp) tp.onchange = () => save({ topic_id: tp.value || null }, true);
      const fromSave = () => save({ from_name: $('#fn').value.trim() || null, from_email: S.fromPickValue(document, 'fp') });
      const fn = $('#fn'); if (fn) fn.oninput = fromSave; const fl = $('#fp-l'); if (fl) fl.oninput = fromSave; S.fromPickBind(document, 'fp', fromSave);
      const rt = $('#rt'); if (rt) rt.onchange = () => { const val = rt.value.trim(); if (val && !/^\S+@\S+\.\S+$/.test(val)) return fieldError(v, '#rt', 'Enter a valid address.'); save({ reply_to: val || null }); };
      const sj = $('#sj'); if (sj) sj.oninput = () => { c.subject = sj.value; $('#sjc').textContent = sj.value.length + ' / 60 recommended'; $('#sjc').style.color = sj.value.length > 60 ? 'var(--s-deferred)' : ''; $('.im-row .sj b').textContent = preview(sj.value) || 'Your subject line'; save({ subject: sj.value }); };
      const sjb = $('#sjb'); if (sjb) sjb.oninput = () => save({ subject_b: sjb.value });
      const abon = $('#abon'); if (abon) abon.onchange = () => save({ ab_enabled: abon.checked }, true);
      [['#abp', 'ab_test_pct'], ['#abm', 'ab_metric'], ['#abw', 'ab_wait_hours']].forEach(([s, k]) => { const el = $(s); if (el) el.onchange = () => save({ [k]: k === 'ab_metric' ? el.value : +el.value }, true); });
      const pr = $('#pr'); if (pr) pr.oninput = () => { $('.im-row .sj span').textContent = '— ' + (preview(pr.value) || 'Preview text appears here'); save({ preheader: pr.value }); };
      const goDesign = () => { S.campaignOpen = 'content'; location.hash = '#/campaigns/design?campaign=' + c.id; };
      const co = $('#ctopen'); if (co) co.onclick = goDesign; const ed = $('#editd'); if (ed) ed.onclick = goDesign;
      const cn = $('#ctnew'); if (cn) cn.onclick = () => confirmDialog({ title: 'Start over?', desc: 'This removes the current design from the campaign.', label: 'Start over', onConfirm: () => save({ doc: null }, true) });
      $$('[data-start]', v).forEach((b) => b.onclick = () => {
        if (b.dataset.start === 'gallery') { S.pickForCampaign = c.id; location.hash = '#/templates'; toast('Pick a design, then choose “Use template”'); return; }
        b.disabled = true;
        Promise.resolve(S.applyBrand ? API('GET', `/v1/workspaces/${wsId()}/brand`).then((r) => S.applyBrand(S.blankEmailDoc(), r.brand)) : S.blankEmailDoc())
          .then((doc) => save({ doc }, true)).then(goDesign);
      });
      $$('[data-tpl]', v).forEach((b) => b.onclick = () => { b.disabled = true; API('GET', `/v1/workspaces/${wsId()}/templates/${b.dataset.tpl}`).then((r) => save({ doc: r.template.doc, subject: c.subject || r.template.subject || undefined }, true)).then(() => { open = 'content'; }).catch((err) => toast(err.message)); });
      $$('#wh .radio-card', v).forEach((r) => r.onclick = () => { when.mode = r.dataset.w; render(); });
      const wd = $('#wd'); if (wd) wd.onchange = () => { when.date = wd.value; };
      const wt = $('#wt'); if (wt) wt.onchange = () => { when.time = wt.value; };
      const wz = $('#wz'); if (wz) wz.onchange = () => { when.tz = wz.value; };
      const tst = $('#tst'); if (tst) tst.onclick = () => testCampaign(c, domains);
      const un = $('#unsch'); if (un) un.onclick = () => API('POST', `${base()}/${c.id}/unschedule`).then((r) => { Object.assign(c, r.campaign); toast('Back to draft'); render(); }).catch((err) => toast(err.message));
      const go = $('#go'); if (go) go.onclick = () => {
        if (!when.mode) { open = 'when'; return render(); }
        const body = when.mode === 'now' ? { mode: 'now' } : when.mode === 'scheduled' ? { mode: 'scheduled', at: `${when.date} ${when.time}`, timezone: when.tz } : { mode: 'local', date: when.date, time: when.time, timezone: when.tz };
        const sendIt = () => {
          go.disabled = true; go.innerHTML = '<span class="spin"></span>' + (when.mode === 'now' ? 'Starting…' : 'Scheduling…');
          const run = c.status === 'scheduled' ? API('POST', `${base()}/${c.id}/unschedule`).then(() => API('POST', `${base()}/${c.id}/send`, body)) : API('POST', `${base()}/${c.id}/send`, body);
          run.then((r) => { toast(r.campaign.status === 'sending' ? 'Sending started' : 'Campaign scheduled'); location.hash = (r.campaign.status === 'sending' ? '#/campaigns/report?id=' : '#/campaigns?') + (r.campaign.status === 'sending' ? c.id : ''); })
            .catch((err) => { go.disabled = false; go.textContent = when.mode === 'now' ? 'Send now' : 'Schedule'; toast(err.message); });
        };
        const n = (c.estimate || { total: 0 }).total;
        modal({ title: when.mode === 'now' ? (n == null ? 'Send now?' : `Send to ${fmt(n)} people now?`) : 'Schedule this campaign?', desc: when.mode === 'now' ? 'It starts within a minute. You can pause it while it sends.' : when.mode === 'local' ? `At ${when.time} on ${when.date} in each recipient’s time zone.` : `On ${when.date} at ${when.time} (${when.tz}).`, icon: 'send', size: 'sm', body: `<p class="faint" style="font-size:13px">${esc(c.subject || '')}${c.ab.enabled ? ` · A/B test on ${c.ab.test_pct}%` : ''}</p>`, actions: [{ label: 'Cancel' }, { label: when.mode === 'now' ? 'Send now' : 'Schedule', kind: 'primary', onClick: () => sendIt() }] });
      };
    };
    render();
  }
  function testCampaign(c, domains) {
    modal({ title: 'Send a test', desc: 'Exactly as your recipients will get it, through your Amazon SES.', icon: 'send',
      body: `<div class="field"><label>Send to</label><input id="tto" type="email" value="${esc(me().user.email || '')}"></div>${c.ab.enabled ? '<div class="field"><label>Subject</label><select class="select" id="tvar"><option value="a">A</option><option value="b">B</option></select></div>' : ''}${!c.from_email && domains.length ? `<p class="faint" style="font-size:12.5px">Sent from hello@${esc(domains[0])} until you choose a sender.</p>` : ''}<div id="ttr"></div>`,
      actions: [{ label: 'Close' }, { label: 'Send test', kind: 'primary', onClick: (m) => {
        const to = $('#tto', m.root).value.trim(); if (!/^\S+@\S+\.\S+$/.test(to)) return fieldError(m.root, '#tto', 'Enter a valid email address.');
        const b = m.root.querySelector('.m-foot .btn-primary'); b.disabled = true;
        API('POST', `${base()}/${c.id}/test`, { to, variant: $('#tvar', m.root) ? $('#tvar', m.root).value : 'a', from: c.from_email ? undefined : domains[0] })
          .then((x) => { b.disabled = false; $('#ttr', m.root).innerHTML = `<div class="tf-res"><span>Sent: <span class="trk">${esc(x.tracking_code)}</span></span><a class="btn btn-sm" href="#/activity">Follow it in Activity</a></div>`; })
          .catch((err) => { b.disabled = false; $('#ttr', m.root).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
        return false;
      } }] });
  }

  // ── Builder: editing a campaign's design (autosaves to the campaign) ──
  S.editingCampaign = null;
  let cSaveT = null, cPending = null, cSaving = null;
  const cStatus = (t) => { const el = $('#ebsaved'); if (el) el.textContent = t; };
  const cPush = () => { if (!S.editingCampaign || !cPending) return Promise.resolve(); const doc = cPending; cPending = null; cStatus('Saving…'); cSaving = API('PATCH', `${base()}/${S.editingCampaign.id}`, { doc }).then((r) => cStatus(`Campaign design · saved · ${r.campaign.size_kb} KB`)).catch((err) => { cStatus('Couldn’t save: ' + err.message); toast(err.message); }).finally(() => { cSaving = null; }); return cSaving; };
  const prevSave = S.saveEmailDoc;
  S.saveEmailDoc = (d) => { if (!S.editingCampaign) return prevSave(d); S.emailDoc = d; cPending = JSON.parse(JSON.stringify(d)); clearTimeout(cSaveT); cSaveT = setTimeout(cPush, 1200); cStatus('Unsaved changes…'); };
  const prevBuilder = pages['campaigns/design'];
  pages['campaigns/design'] = (v) => {
    const id = S.param('campaign');
    if (!id) { S.editingCampaign = null; return prevBuilder(v); }
    v.innerHTML = '<div class="card"><div class="card-body faint">Opening the design…</div></div>';
    API('GET', `${base()}/${id}`).then((r) => {
      const c = r.campaign;
      if (!c.doc) { location.hash = '#/campaigns/new?id=' + id; return; }
      S.editingTemplate = null; S.editingCampaign = { id: c.id, name: c.name }; S.emailDoc = c.doc;
      S.campaignDraft = { name: c.name };
      prevBuilder(v);
      const back = $('.jx-back'); if (back) { back.href = '#/campaigns/new?id=' + c.id; back.title = 'Back to the campaign'; }
      cStatus(`Campaign design · saved · ${c.size_kb} KB`);
      const done = $('#done'); if (done) { done.textContent = 'Save & back'; done.onclick = () => { clearTimeout(cSaveT); cPending = cPending || JSON.parse(JSON.stringify(S.emailDoc)); (cSaving || Promise.resolve()).then(cPush).then(() => { location.hash = '#/campaigns/new?id=' + c.id; }); }; }
    }).catch((err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`; });
  };
  window.addEventListener('hashchange', () => { if (S.editingCampaign && !location.hash.startsWith('#/campaigns/design')) { clearTimeout(cSaveT); cPush(); S.editingCampaign = null; S.emailDoc = null; } });
  // Gallery → campaign: when a campaign asked for a gallery design, "Use template" fills that campaign.
  const prevUse = S.useTemplateDoc;
  S.useTemplateDoc = (name, doc, category) => {
    if (!S.pickForCampaign) return prevUse(name, doc, category);
    const cid = S.pickForCampaign; S.pickForCampaign = null;
    toast('Adding the design to your campaign…');
    API('GET', `/v1/workspaces/${wsId()}/brand`).then((r) => (S.applyBrand ? S.applyBrand(doc, r.brand) : doc)).then((d) => API('PATCH', `${base()}/${cid}`, { doc: d }))
      .then(() => { S.campaignOpen = 'content'; location.hash = '#/campaigns/design?campaign=' + cid; }).catch((err) => toast(err.message));
  };

  // ═══════ #/campaigns/report?id= ═══════
  pages['campaigns/report'] = (v) => {
    const g = (seq = S.routeSeq, ++gen), id = S.param('id');
    let poll = null, recFilter = '', recQ = '';
    const load = () => Promise.all([API('GET', `${base()}/${id}/report`), API('GET', `${base()}/${id}`)]).then(([r, full]) => { if (alive(g)) draw(r.campaign, r.report, full.campaign); });
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading the report…</div></div>';
    const draw = (c, r, full) => {
      const T = r.totals, A = r.average, live = c.status === 'sending' || c.status === 'paused';
      const delta = (val, avg, good) => { if (avg == null) return ''; const d = (val - avg) * 100; return `<em style="color:${Math.abs(d) < 0.05 ? 'var(--text-3)' : (d > 0) === good ? 'var(--s-delivered)' : 'var(--s-bounced)'}">${Math.abs(d) < 0.05 ? 'same' : (d > 0 ? '+' : '−') + Math.abs(d).toFixed(1) + ' pts'}</em><span class="sub2"> vs avg</span>`; };
      const stations = [['Sent', T.sent, null], ['Delivered', T.delivered, T.sent], ['Opened', T.opened, T.delivered], ['Clicked', T.clicked, T.delivered]];
      const maxLink = Math.max(1, ...r.links.map((l) => l.people)), totLink = r.links.reduce((a, l) => a + l.people, 0) || 1;
      v.innerHTML = `<div class="flow-top"><a class="back" href="#/campaigns">← Campaigns</a></div>
        <div class="page-head"><div><div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">${statusChip(c.status)}<span class="faint" style="font-size:13px">${c.started_at ? 'Started ' + day(c.started_at) : ''}${c.finished_at ? ' · finished ' + day(c.finished_at) : ''} · ${esc(c.audience_name || '')}</span></div><h1 style="margin-top:6px">${esc(c.name)}</h1><p class="faint" style="margin-top:2px">${esc(c.subject || '')}${c.ab.enabled && c.subject_b ? ` <span class="faint">/ B: ${esc(c.subject_b)}</span>` : ''}</p></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">${canEdit() && c.status === 'sending' ? `<button class="btn" id="rpause">${ic('pause', 15)}Pause</button>` : ''}${canEdit() && c.status === 'paused' ? `<button class="btn btn-primary" id="rresume">${ic('send', 15)}Resume</button>` : ''}${canEdit() && live ? '<button class="btn" id="rcancel">Cancel</button>' : ''}${canEdit() ? `<button class="btn" id="rdup">${ic('copy', 15)}Duplicate</button><button class="btn" id="rshare">${ic('link', 15)}${c.shared ? 'Shared' : 'Share report'}</button>` : ''}</div></div>
        ${live ? `<div class="callout ${c.status === 'paused' ? 'warn' : 'info'}" style="margin:0 0 16px">${ic(c.status === 'paused' ? 'pause' : 'send', 15)}<div><b>${c.status === 'paused' ? 'Paused.' : 'Sending…'}</b> ${fmt(T.sent)} of ${fmt(T.recipients)} handed to SES${T.pending ? `, ${fmt(T.pending)} waiting${c.ab.enabled && !c.ab.winner ? ' (most wait for the A/B winner)' : c.send_mode === 'local' ? ' for their local time' : ''}` : ''}. ${c.status === 'sending' ? 'Updates every few seconds.' : c.status_note ? esc(c.status_note) + ' <a href="#/settings" data-settings-tab="billing">See plans</a>' : 'Resume to send the rest.'}</div></div>` : ''}
        <div class="card" style="margin-bottom:16px"><div class="linemap" style="padding:22px 6px 12px"><div class="lm-track">${stations.map(([n, val, of], i) => `<div class="lm-stn"><span class="lm-dot"></span>${i ? `<span class="lm-rate">${pct(rate(val, of))}</span>` : ''}<div class="lm-name">${n}</div><div class="lm-val" style="font-size:24px">${fmt(val)}</div></div>`).join('')}</div></div></div>
        <div class="stat-strip">${[['Delivered', rate(T.delivered, T.sent), T.delivered, A && A.delivered_rate, true], ['Real opens', rate(T.opened, T.delivered), T.opened, A && A.open_rate, true], ['Clicks', rate(T.clicked, T.delivered), T.clicked, A && A.click_rate, true], ['Unsubscribed', rate(T.unsubscribed, T.delivered), T.unsubscribed, null, false], ['Spam reports', rate(T.complained, T.delivered), T.complained, null, false], ['Bounced', rate(T.bounced, T.sent), T.bounced, null, false]].map(([a, rt, n, avg, good]) => `<div><small>${a}</small><b>${T.sent ? pct(rt, a === 'Spam reports' ? 2 : 1) : '—'}</b><span class="sub2">${fmt(n)}${avg != null ? ' · ' : ''}</span>${avg != null && T.sent ? delta(rt, avg, good) : ''}</div>`).join('')}</div>
        ${T.machine_only ? `<p class="faint" style="font-size:12.5px;margin:-6px 0 16px">${fmt(T.machine_only)} more emails were only opened by machines (Apple Mail Privacy, security scanners), so they aren’t counted as real opens.</p>` : ''}
        ${c.ab.enabled && r.variants ? abBox(c, r.variants) : ''}
        <div class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>Click map</h3><div class="sub">Where people clicked, on the email itself. Scanner clicks are left out.</div></div></div>
          <div class="clickmap-wrap" style="margin-top:14px;border-top:1px solid var(--border)"><div class="clickmap" style="padding:0"><iframe id="cmf" sandbox="allow-same-origin" style="width:100%;height:640px;border:0;display:block;background:#fff"></iframe></div>
          <div class="links-side"><div class="cp-label" style="margin-top:0">Links, by people who clicked</div>${r.links.length ? r.links.map((l, i) => `<div class="lk" data-l="${i}"><span class="rk">${i + 1}</span><div style="min-width:0"><div class="u" style="font-weight:600">${esc(l.url.replace(/^https?:\/\//, ''))}</div><div class="u faint">${fmt(l.clicks)} click${l.clicks === 1 ? '' : 's'}</div></div><div class="c">${fmt(l.people)}<small>${pct(l.people / totLink, 0)}</small></div></div>`).join('') : '<p class="faint" style="font-size:13px">No clicks yet.</p>'}</div></div></div>
        <div class="grid two"><div class="card"><div class="card-head"><div><h3>Engagement over time</h3><div class="sub">Real opens and clicks per hour (UTC), first 72 hours</div></div></div><div class="chart-wrap" id="eng"></div></div>
          <div class="card"><div class="card-head"><div><h3>By mailbox provider</h3><div class="sub">Real opens / clicks</div></div></div><div class="card-body">${r.providers.length ? r.providers.map((p) => `<div class="health-row"><span style="min-width:80px">${esc({ google: 'Gmail', microsoft: 'Outlook', yahoo: 'Yahoo', apple: 'iCloud', proton: 'Proton' }[p.provider] || 'Other')}</span><span class="mini-bar" style="flex:1;width:auto;margin:0 12px"><span style="width:${rate(p.opened, p.delivered) * 100}%;background:var(--s-opened)"></span></span><span style="min-width:110px;text-align:right" class="faint">${p.delivered ? pct(rate(p.opened, p.delivered), 0) + ' · ' + pct(rate(p.clicked, p.delivered)) : '—'} · ${fmt(p.sent)}</span></div>`).join('') : '<p class="faint">No emails sent yet.</p>'}</div></div></div>
        <div class="card" style="margin-top:16px"><div class="toolbar"><input class="input" id="rq" placeholder="Search recipients" value="${esc(recQ)}"><div class="filter-chips" id="rf">${[['', 'All ' + fmt(T.recipients)], ['opened', 'Opened ' + fmt(T.opened)], ['clicked', 'Clicked ' + fmt(T.clicked)], ['not_opened', 'Not opened'], ['bounced', 'Bounced ' + fmt(T.bounced)], ['unsubscribed', 'Unsubscribed ' + fmt(T.unsubscribed)], ['complained', 'Spam ' + fmt(T.complained)], ['skipped', 'Skipped ' + fmt(T.skipped)], ...(T.pending ? [['pending', 'Waiting ' + fmt(T.pending)]] : [])].map(([k, l]) => `<button class="fchip ${recFilter === k ? 'on' : ''}" data-f="${k}">${l}</button>`).join('')}</div></div>
          <div class="table-wrap"><table class="tbl"><thead><tr><th>Route</th><th>Recipient</th>${c.ab.enabled ? '<th>Variant</th>' : ''}<th>Tracking</th><th class="num">Clicks</th></tr></thead><tbody id="rrows"><tr><td colspan="5" class="faint">Loading…</td></tr></tbody></table></div></div>`;
      // Click map: the real email, with each link's clicks pinned to it.
      const f = $('#cmf');
      f.onload = () => {
        const d = f.contentDocument; if (!d) return;
        const st = d.createElement('style'); st.textContent = '.sl-hot{outline:2px solid rgba(91,91,247,var(--o));outline-offset:3px;border-radius:6px;background:rgba(91,91,247,var(--b));position:relative}.sl-hot.hl{outline-color:#f59e0b}.sl-hb{position:absolute;top:-12px;right:-12px;font:700 10.5px/1 -apple-system,sans-serif;padding:3px 7px;border-radius:99px;background:#5B5BF7;color:#fff;white-space:nowrap;z-index:2}.sl-hot.hl .sl-hb{background:#f59e0b}'; d.head.appendChild(st);
        const norm = (u) => String(u || '').replace(/\/$/, '');
        r.links.forEach((l, i) => d.querySelectorAll('a[href]').forEach((a) => { if (norm(a.getAttribute('href')) !== norm(l.url)) return; a.classList.add('sl-hot'); a.dataset.l = i; a.style.setProperty('--o', (0.25 + 0.75 * l.people / maxLink).toFixed(2)); a.style.setProperty('--b', (0.2 * l.people / maxLink).toFixed(2)); if (!a.querySelector('.sl-hb')) { const b = d.createElement('em'); b.className = 'sl-hb'; b.textContent = `${l.people} · ${Math.round((l.people / totLink) * 100)}%`; a.appendChild(b); } a.onclick = (e) => e.preventDefault(); }));
        const sync = (i, on) => { $$(`.lk[data-l="${i}"]`, v).forEach((el) => el.classList.toggle('hl', on)); d.querySelectorAll(`.sl-hot[data-l="${i}"]`).forEach((el) => el.classList.toggle('hl', on)); };
        $$('.lk[data-l]', v).forEach((el) => { el.onmouseenter = () => sync(el.dataset.l, true); el.onmouseleave = () => sync(el.dataset.l, false); });
        d.querySelectorAll('.sl-hot').forEach((el) => { el.onmouseenter = () => sync(el.dataset.l, true); el.onmouseleave = () => sync(el.dataset.l, false); });
      };
      f.srcdoc = preview(full.html || '<p style="font-family:sans-serif;padding:20px;color:#a1a1aa">No design.</p>');
      const series = r.timeline.map((h, i) => ({ date: new Date(h.hour), h: i, opens: h.opens, clicks: h.clicks }));
      const chart = () => { if ($('#eng') && series.length > 1) lineChart($('#eng'), series, [{ key: 'opens', name: 'Real opens', color: '#2563EB' }, { key: 'clicks', name: 'Clicks', color: '#7C3AED' }], { h: 230, every: Math.max(1, Math.ceil(series.length / 8)), xLabel: (s) => s.h + 'h' }); else if ($('#eng')) $('#eng').innerHTML = '<p class="faint" style="padding:16px">The chart fills in during the first hours after sending.</p>'; };
      chart(); window.onresize = chart;
      loadRecipients(c);
      $$('#rf .fchip', v).forEach((b) => b.onclick = () => { recFilter = b.dataset.f; $$('#rf .fchip', v).forEach((x) => x.classList.toggle('on', x === b)); loadRecipients(c); });
      let tq; $('#rq').oninput = (e) => { clearTimeout(tq); tq = setTimeout(() => { recQ = e.target.value.trim(); loadRecipients(c); }, 250); };
      const act = (a, msg) => API('POST', `${base()}/${c.id}/${a}`).then(() => { toast(msg); load(); }).catch((err) => toast(err.message));
      const rp = $('#rpause'); if (rp) rp.onclick = () => act('pause', 'Campaign paused');
      const rr = $('#rresume'); if (rr) rr.onclick = () => act('resume', 'Sending again');
      const rc = $('#rcancel'); if (rc) rc.onclick = () => confirmDialog({ title: `Cancel “${c.name}”?`, desc: 'Nobody else gets it. Emails already sent stay sent.', label: 'Cancel campaign', onConfirm: () => act('cancel', 'Campaign cancelled') });
      const rd = $('#rdup'); if (rd) rd.onclick = () => API('POST', `${base()}/${c.id}/duplicate`).then((x) => { toast('Duplicated as a draft'); location.hash = '#/campaigns/new?id=' + x.campaign.id; });
      const rs = $('#rshare'); if (rs) rs.onclick = () => shareModal(c, load);
      clearInterval(poll);
      if (c.status === 'sending') poll = setInterval(() => { if (!alive(g) || !location.hash.startsWith('#/campaigns/report')) return clearInterval(poll); if (!document.hidden) load(); }, 6000);
    };
    const loadRecipients = (c) => {
      const q = new URLSearchParams(); if (recFilter) q.set('filter', recFilter); if (recQ) q.set('q', recQ);
      API('GET', `${base()}/${c.id}/recipients?${q}`).then((x) => {
        const b = $('#rrows'); if (!b) return;
        b.innerHTML = x.recipients.length ? x.recipients.map((r) => { const m = r.message; const st = m ? ({ processing: 'processing', suppressed: 'dropped', failed: 'dropped' }[m.status] || m.status) : r.status === 'skipped' ? 'dropped' : 'processing'; return `<tr data-m="${m ? m.id : ''}" style="${m ? '' : 'cursor:default'}"><td>${miniRoute(st)}</td><td class="cell-main">${esc(r.email)}${r.skip_reason ? `<div class="cell-sub">${esc(r.skip_reason)}</div>` : r.status === 'pending' ? '<div class="cell-sub">Waiting to send</div>' : ''}</td>${c.ab.enabled ? `<td><span class="tag">${(r.variant || '–').toUpperCase()}</span></td>` : ''}<td>${m ? `<span class="trk">${esc(m.tracking_code)}</span>` : ''}</td><td class="num">${m && m.clicks ? m.clicks : '–'}</td></tr>`; }).join('') : '<tr><td colspan="5" class="faint">Nobody matches.</td></tr>';
        $$('#rrows tr[data-m]').forEach((tr) => { if (tr.dataset.m) tr.onclick = () => S.openActivity && S.openActivity(tr.dataset.m); });
      }).catch(() => {});
    };
    load().catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)} <a href="#/campaigns">Back to campaigns</a></div></div>`));
  };
  function abBox(c, V) {
    const metric = c.ab.metric === 'clicks' ? 'clicked' : 'opened';
    const row = (k) => { const t = V[k]; const rt = rate(t[metric], t.delivered); return `<div class="ab-col ${c.ab.winner === k ? 'win' : ''}"><div class="ab-k">${k.toUpperCase()}${c.ab.winner === k ? ' · winner' : ''}</div><div class="ab-s">${esc(k === 'b' ? c.subject_b : c.subject)}</div><div class="ab-n"><b>${t.delivered ? pct(rt) : '—'}</b> ${c.ab.metric === 'clicks' ? 'click rate' : 'real opens'}</div><small>${fmt(t.sent)} sent · ${fmt(t.opened)} opened · ${fmt(t.clicked)} clicked</small></div>`; };
    const decide = c.ab.winner ? `Winner picked ${day(c.ab.decided_at)}; everyone else got ${c.ab.winner.toUpperCase()}.` : c.started_at ? `The winner is picked ${day(new Date(new Date(c.started_at).getTime() + c.ab.wait_hours * 3600e3).toISOString())}.` : '';
    return `<div class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>A/B subject test</h3><div class="sub">${c.ab.test_pct}% of the audience · winner by ${c.ab.metric === 'clicks' ? 'clicks' : 'real opens'}${decide ? ' · ' + decide : ''}</div></div></div><div class="ab-grid">${row('a')}${row('b')}</div></div>`;
  }
  function shareModal(c, after) {
    modal({ title: 'Share this report', desc: 'Anyone with the link sees the results, without any email addresses.', icon: 'link', size: 'sm',
      body: c.share_url ? `<div class="cs-cmd" style="padding-left:12px"><code style="white-space:normal;word-break:break-all">${esc(c.share_url)}</code><button class="btn btn-sm" data-copy="${esc(c.share_url)}">${ic('copy', 13)}Copy</button></div>` : '<p class="faint" style="font-size:13px">Turn on a read-only link you can send to your team or a client.</p>',
      mount: (root) => bindCopy(root),
      actions: c.share_url ? [{ label: 'Turn off link', onClick: () => API('POST', `${base()}/${c.id}/share`, { enabled: false }).then(() => { toast('Link turned off'); after(); }) }, { label: 'Done', kind: 'primary' }]
        : [{ label: 'Cancel' }, { label: 'Create link', kind: 'primary', onClick: () => API('POST', `${base()}/${c.id}/share`, { enabled: true }).then((r) => { toast('Link created'); c.share_url = r.share_url; after(); shareModal(c, after); }) }] });
  }
})();
