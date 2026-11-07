/* MailVesper: Quick send (#/send). One real email to one person: pick a template (yours or the gallery) and edit it
   in the full email builder for this send only (Edit design → #/campaigns/design?quick=1 → Done comes back here,
   the saved template unchanged), or write a short message. Merge fields fill from the contact; anything else is
   asked for. Talks to /api/v1/workspaces/{id}/emails/quick, /templates and /senders. ?template=<id> starts from a
   saved template, ?to=<email> fills the recipient. Loaded after templates.js, smtp.js and people.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast, fieldError } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const base = () => `/v1/workspaces/${wsId()}`;
  const SYSTEM = ['email', 'tracking_code', 'unsubscribe_url', 'preferences_url', 'view_url', 'web_url'];
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const VAR = /\{\{\s*([a-z_][a-z0-9_.]*)\s*(?:\|\s*("?)([^}"]*)\2\s*)?\}\}/gi;

  pages.send = (v) => {
    if (myRole() === 'viewer') { v.innerHTML = `<div class="card"><div class="card-body faint">Viewers can’t send emails. Ask an owner or admin for editor access.</div></div>`; return; }
    // The form lives on S.qs, so it's all still here after a trip to the builder (Edit design → Done).
    const fresh = () => ({ ws: wsId(), mode: 'template', pick: '', doc: null, saved: null, subject: '', message: '', vars: {}, to: [], from: '', fromName: '', marketing: false, busy: false });
    if (S.editingQuickSend && S.emailDoc && S.qs) S.qs.doc = clone(S.emailDoc); // back from the builder (this draws before the hashchange below)
    if (!S.qs || S.qs.ws !== wsId()) S.qs = fresh();
    const st = S.qs;
    st.busy = false;
    let snd = null, mine = [], previewT = null;
    const gallery = S.galleryTemplates ? S.galleryTemplates() : [];

    // Merge fields used by the subject and the content, with what the contact already has.
    const usedVars = () => {
      const src = st.subject + ' ' + (st.mode === 'template' ? JSON.stringify(st.doc || {}) : st.message);
      const out = new Map();
      for (const m of src.matchAll(VAR)) if (!SYSTEM.includes(m[1]) && !out.has(m[1])) out.set(m[1], m[3] || '');
      return [...out];
    };
    const contactVal = (k) => { const c = st.to[0]; if (!c) return ''; if (k === 'first_name' || k === 'last_name') return c[k] || ''; if (k === 'name') return c.name || ''; return (c.fields || {})[k] != null ? String(c.fields[k]) : ''; };
    const fill = (s) => String(s || '').replace(VAR, (m, k, q, fb) => {
      if (k === 'email') return esc((st.to[0] || {}).email || 'their@email.com');
      if (SYSTEM.includes(k)) return '#';
      const val = st.vars[k] || contactVal(k) || fb;
      return val ? esc(val) : `[${k.replace(/_/g, ' ')}]`;
    });
    const docHtml = () => st.doc && S.emailToHtml ? S.emailToHtml(st.doc, { preview: true, subject: st.subject }) : '';
    const msgHtml = () => `<!doctype html><html><body style="margin:0;padding:28px;background:#fff"><div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#18181b">${st.message.trim() ? st.message.trim().split(/\n{2,}/).map((p) => `<p style="margin:0 0 14px">${esc(p).replace(/\n/g, '<br>')}</p>`).join('') : '<p style="color:#a1a1aa">Your message appears here.</p>'}</div></body></html>`;
    const edited = () => !!(st.doc && st.saved && JSON.stringify(st.saved) !== JSON.stringify(st.doc));
    const tplName = () => { if (st.pick.startsWith('g:')) return (gallery[+st.pick.slice(2)] || {}).name || 'Gallery design'; const t = mine.find((x) => 't:' + x.id === st.pick); return t ? t.name : 'Template'; };
    const editDesign = () => { S.editingQuickSend = { name: tplName() }; S.emailDoc = clone(st.doc); location.hash = '#/campaigns/design?quick=1'; };

    const paintPreview = () => {
      clearTimeout(previewT);
      previewT = setTimeout(() => {
        const f = $('#qs-frame'); if (!f) return;
        f.srcdoc = st.mode === 'template' ? (st.doc ? fill(docHtml()) : '<!doctype html><html><body style="font:14px -apple-system,Segoe UI,sans-serif;color:#a1a1aa;display:grid;place-items:center;height:90vh;margin:0">Pick a template to see it here.</body></html>') : fill(msgHtml());
        const sj = $('#qs-psubj'); if (sj) sj.innerHTML = st.subject ? fill(st.subject) : '<span class="faint">No subject yet</span>';
        const to = $('#qs-pto'); if (to) to.textContent = st.to[0] ? (st.to[0].name ? `${st.to[0].name} <${st.to[0].email}>` : st.to[0].email) : 'Nobody yet';
      }, 90);
    };
    const paintVars = () => {
      const el = $('#qs-vars'); if (!el) return;
      const vars = usedVars();
      el.innerHTML = vars.length ? `<div class="qs-label">Personal details <span class="faint">Blank uses what the contact has${st.to[0] && st.to[0].id ? '' : ', or the fallback'}.</span></div><div class="qs-vars">${vars.map(([k, fb]) => { const has = contactVal(k); return `<div class="field"><label for="qv-${esc(k)}">${esc(k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()))}</label><input id="qv-${esc(k)}" data-var="${esc(k)}" value="${esc(st.vars[k] || '')}" placeholder="${esc(has || fb || 'Empty')}"></div>`; }).join('')}</div>` : '';
      $$('[data-var]', el).forEach((i) => i.oninput = () => { st.vars[i.dataset.var] = i.value; paintPreview(); });
    };
    const paintEditor = () => {
      const el = $('#qs-content'); if (!el) return;
      if (st.mode === 'message') {
        el.innerHTML = `<div class="field"><label for="qs-msg">Message</label><textarea id="qs-msg" rows="9" maxlength="20000" placeholder="Hi {{ first_name | there }},&#10;&#10;…">${esc(st.message)}</textarea><span class="help">A blank line starts a new paragraph. {{ first_name }} fills in the contact’s first name.</span></div>`;
        $('#qs-msg').addEventListener('input', (e) => { st.message = e.target.value; paintPreview(); paintVars(); });
        return;
      }
      const opts = `<option value="">Choose a template…</option>${mine.length ? `<optgroup label="Your templates">${mine.map((t) => `<option value="t:${t.id}" ${st.pick === 't:' + t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</optgroup>` : ''}<optgroup label="Gallery">${gallery.map((t, i) => `<option value="g:${i}" ${st.pick === 'g:' + i ? 'selected' : ''}>${esc(t.name)} · ${esc(t.cat)}</option>`).join('')}</optgroup>`;
      el.innerHTML = `<div class="field"><label for="qs-tpl">Template</label><select class="select" id="qs-tpl">${opts}</select></div>
        ${st.doc ? `<div class="qs-design"><span class="qs-design-ic">${ic('templates', 18)}</span><div><b>${esc(tplName())}</b><small>${edited() ? 'Edited for this email. The saved template is unchanged.' : 'Open it in the email builder to change anything for this person.'}</small></div>
          <div class="qs-design-acts">${edited() ? '<button type="button" class="btn btn-ghost btn-sm" id="qs-reset">Undo my edits</button>' : ''}<button type="button" class="btn btn-primary btn-sm" id="qs-edit">${ic('edit', 14)}Edit design</button></div></div>
          ${st.pick.startsWith('t:') ? `<p class="faint" style="font-size:12.5px;margin:6px 0 0">To change the template for everyone, <a href="#/campaigns/design?template=${esc(st.pick.slice(2))}">edit the template itself</a>.</p>` : ''}` : ''}`;
      $('#qs-tpl').onchange = (e) => { if (edited() && !window.confirm('Switch templates? Your edits to this one will be lost.')) { e.target.value = st.pick; return; } choose(e.target.value); };
      const ed = $('#qs-edit'); if (ed) ed.onclick = editDesign;
      const rs = $('#qs-reset'); if (rs) rs.onclick = () => { st.doc = clone(st.saved); paintEditor(); paintPreview(); paintVars(); };
    };
    const choose = (val) => {
      st.pick = val; st.doc = null; st.saved = null;
      const ready = (doc, subject) => { st.doc = doc; st.saved = clone(doc); if (!st.subjectTouched) { st.subject = subject || ''; const sj = $('#qs-subj'); if (sj) sj.value = st.subject; } paintEditor(); paintPreview(); paintVars(); };
      if (!val) { paintEditor(); paintPreview(); paintVars(); return; }
      if (val.startsWith('g:')) { const g = gallery[+val.slice(2)]; ready(g.doc(), g.name); return; }
      $('#qs-content').insertAdjacentHTML('beforeend', '<p class="faint" id="qs-load" style="font-size:13px">Loading the template…</p>');
      API('GET', `${base()}/templates/${val.slice(2)}`).then((r) => {
        if (st.pick !== val) return;
        if (r.template.doc) ready(r.template.doc, r.template.subject || r.template.name);
        else { st.doc = null; st.htmlOnly = r.template.html; paintEditor(); $('#qs-content').insertAdjacentHTML('beforeend', '<p class="faint" style="font-size:13px">This template was imported as HTML, so it’s sent exactly as saved.</p>'); if (!st.subjectTouched) { st.subject = r.template.subject || r.template.name; $('#qs-subj').value = st.subject; } const f = $('#qs-frame'); if (f) f.srcdoc = fill(r.template.html || ''); }
      }).catch((e) => { toast(e.message || 'Couldn’t load that template.'); st.pick = ''; paintEditor(); });
    };

    const send = () => {
      if (st.busy) return;
      $$('.field.error', v).forEach((f) => { f.classList.remove('error'); const er = $('.err', f); if (er) er.remove(); });
      const to = st.to[0];
      let bad = false;
      if (!to) { fieldError(v, '#qs-to .pp-in', 'Choose who gets it: search your contacts or type an email.'); bad = true; }
      if (st.mode === 'template' && !st.pick) { fieldError(v, '#qs-tpl', 'Choose a template, or switch to a plain message.'); bad = true; }
      if (st.mode === 'message' && !st.message.trim()) { fieldError(v, '#qs-msg', 'Write your message.'); bad = true; }
      if (!st.subject.trim()) { fieldError(v, '#qs-subj', 'Add a subject line.'); bad = true; }
      if (bad) return;
      const addr = S.fromPickValue(v, 'qsf'), name = (($('#qs-fn') || {}).value || '').trim();
      const body = { to: to.email, from: name ? `${name} <${addr}>` : addr, subject: st.subject.trim(), variables: st.vars, marketing: st.marketing };
      if (st.mode === 'template') { if (st.pick.startsWith('t:')) body.template = st.pick.slice(2); if (st.doc) body.doc = st.doc; } else body.message = st.message;
      st.busy = true; const btn = $('#qs-send'); btn.disabled = true; btn.innerHTML = `${ic('send', 15)}Sending…`;
      API('POST', `${base()}/emails/quick`, body).then((m) => {
        st.sent = m; toast(m.status === 'suppressed' ? `Not sent: ${to.email} is on your suppression list.` : `Sent to ${to.email}`);
        const done = $('#qs-done'); if (done) { done.hidden = false; done.innerHTML = `${ic('check', 15, 2.5)}<div><b>${m.status === 'suppressed' ? 'Not sent' : 'On its way'}</b> to ${esc(to.email)}. <a href="#/activity">Follow it in Activity</a> · <a href="#/send" id="qs-new">Write another email</a></div>`; const nw = $('#qs-new'); if (nw) nw.onclick = (ev) => { ev.preventDefault(); S.qs = fresh(); pages.send(v); }; }
      }).catch((e) => {
        const map = { to: '#qs-to .pp-in', from: '#qsf-l', subject: '#qs-subj', message: '#qs-msg', template: '#qs-tpl' };
        if (e.fields) Object.entries(e.fields).forEach(([k, msg]) => map[k] && fieldError(v, map[k], msg));
        toast(e.message || 'Couldn’t send it.');
      }).finally(() => { st.busy = false; const b = $('#qs-send'); if (b) { b.disabled = false; b.innerHTML = `${ic('send', 15)}Send`; } });
    };

    v.innerHTML = `<div class="page-head"><div><h1>Quick send</h1><p>One email to one person, from a template or a short message. For many people, use a <a href="#/campaigns">campaign</a>.</p></div></div><div class="card"><div class="card-body faint">Loading…</div></div>`;
    Promise.all([S.senders(), API('GET', `${base()}/templates`).catch(() => ({ templates: [] }))]).then(([s, t]) => {
      snd = s; mine = t.templates || [];
      const hasSender = S.hasSenders(snd);
      v.innerHTML = `<div class="page-head"><div><h1>Quick send</h1><p>One email to one person, from a template or a short message. For many people, use a <a href="#/campaigns">campaign</a>.</p></div></div>
        <div class="qs-grid">
          <div class="card"><div class="card-body qs-form">
            <div class="field" id="qs-to-f"><label>To</label><div id="qs-to"></div></div>
            ${hasSender ? `<div class="row2"><div class="field"><label for="qs-fn">From name <span class="faint">(optional)</span></label><input id="qs-fn" maxlength="120" placeholder="e.g. Acme" value="${esc(st.fromName)}"></div><div class="field"><label>From address</label>${S.fromPick('qsf', snd, st.from || S.firstFrom(snd, 'hello'))}</div></div>`
              : `<div class="callout warn" style="margin-top:0">${ic('alert', 15)}<div>You need a verified sender first. <a href="${snd.method === 'smtp' ? '#/ses/smtp' : '#/domains'}">Verify one</a>.</div></div>`}
            <div class="field"><label for="qs-subj">Subject</label><input id="qs-subj" maxlength="998" placeholder="What the email is about" value="${esc(st.subject)}"></div>
            <div class="qs-mode"><div class="seg" id="qs-mode"><button type="button" data-m="template" class="${st.mode === 'template' ? 'on' : ''}">From a template</button><button type="button" data-m="message" class="${st.mode === 'message' ? 'on' : ''}">Plain message</button></div></div>
            <div id="qs-content"></div>
            <div id="qs-vars"></div>
            <label class="check" style="margin-top:4px"><input type="checkbox" id="qs-mkt" ${st.marketing ? 'checked' : ''}> It’s marketing <span class="faint">(adds an unsubscribe link, and respects unsubscribes)</span></label>
            <div class="callout ok" id="qs-done" hidden></div>
            <div class="qs-actions"><button class="btn btn-primary" id="qs-send" ${hasSender ? '' : 'disabled'}>${ic('send', 15)}Send</button></div>
          </div></div>
          <div class="card qs-preview"><div class="qs-env"><div><span class="faint">To</span> <b id="qs-pto">Nobody yet</b></div><div><span class="faint">Subject</span> <b id="qs-psubj"></b></div></div><iframe id="qs-frame" title="Email preview" sandbox></iframe></div>
        </div>`;
      const pre = S.param('to');
      if (pre) st.to = [{ email: pre }];
      S.peoplePicker($('#qs-to'), { value: st.to, placeholder: 'Search contacts or type an email', onChange: (l) => { st.to = l; const f = $('#qs-to-f'); f.classList.remove('error'); const er = $('.err', f); if (er) er.remove(); paintVars(); paintPreview(); } });
      if (hasSender) { const keepFrom = () => { st.from = S.fromPickValue(v, 'qsf'); }; S.fromPickBind(v, 'qsf', keepFrom); keepFrom(); $('#qs-fn').addEventListener('input', (e) => { st.fromName = e.target.value; }); }
      $('#qs-subj').addEventListener('input', (e) => { st.subject = e.target.value; st.subjectTouched = true; paintPreview(); paintVars(); });
      $$('#qs-mode button').forEach((b) => b.onclick = () => { st.mode = b.dataset.m; $$('#qs-mode button').forEach((x) => x.classList.toggle('on', x === b)); paintEditor(); paintPreview(); paintVars(); });
      $('#qs-mkt').onchange = (e) => { st.marketing = e.target.checked; };
      $('#qs-send').onclick = send;
      paintEditor(); paintPreview(); paintVars();
      const tp = S.param('template'); if (tp && mine.some((x) => x.id === tp) && st.pick !== 't:' + tp) { choose('t:' + tp); $('#qs-tpl').value = 't:' + tp; }
    }).catch((e) => { v.innerHTML = `<div class="card"><div class="card-body faint">${esc(e.message || 'Couldn’t load Quick send.')}</div></div>`; });
  };

  // ── The email builder, for one Quick send: edits stay in S.qs.doc (never saved to the template) ──
  S.editingQuickSend = null;
  const prevSave = S.saveEmailDoc;
  const qsLabel = () => setTimeout(() => { const el = $('#ebsaved'); if (el && S.editingQuickSend) el.textContent = 'Quick send · for this email only'; }, 0);
  S.saveEmailDoc = (d) => { if (!S.editingQuickSend) return prevSave(d); S.emailDoc = d; qsLabel(); }; // never saved to the template
  const prevBuilder = pages['campaigns/design'];
  pages['campaigns/design'] = (v) => {
    if (!S.param('quick')) { S.editingQuickSend = null; return prevBuilder(v); }
    if (!S.qs || !S.qs.doc || !S.editingQuickSend) { location.hash = '#/send'; return; } // a reload: nothing to edit
    S.editingTemplate = null; S.editingCampaign = null; S.editingJourneyEmail = null;
    S.emailDoc = S.emailDoc || clone(S.qs.doc);
    prevBuilder(v);
    const title = $('.eb-title'); if (title) title.textContent = S.editingQuickSend.name;
    const status = $('#ebsaved'); if (status) status.textContent = 'Quick send · for this email only';
    const bk = $('.jx-back'); if (bk) { bk.href = '#/send'; bk.title = 'Back to Quick send'; }
    const done = $('#done'); if (done) { done.textContent = 'Done'; done.onclick = () => { location.hash = '#/send'; }; }
  };
  // Leaving the builder (Done or the back arrow): the edits go back to Quick send.
  window.addEventListener('hashchange', () => {
    if (S.editingQuickSend && !location.hash.startsWith('#/campaigns/design')) {
      if (S.qs && S.emailDoc) S.qs.doc = clone(S.emailDoc);
      S.editingQuickSend = null; S.emailDoc = null;
    }
  });
})();
