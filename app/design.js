/* MailVesper: saved templates, media library and brand kit (Phase 6).
   - The builder (#/campaigns/design?template=<id>) loads a saved template and autosaves it; the server renders
     it with the same email-render.js, so the HTML sent is the HTML previewed.
   - Images: "Your images" in the builder, uploads to the media library.
   - Templates page: "Your templates" tab; gallery designs are copied into the workspace with the brand kit applied.
   - #/templates/brand: logo, colours, font, company details, social links. Loaded after contacts.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast, modal, confirmDialog, fieldError } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => myRole() && myRole() !== 'viewer';
  const canManage = () => ['owner', 'admin'].includes(myRole());
  const base = () => `/v1/workspaces/${wsId()}`;
  Object.assign(S.SUBTITLES || (S.SUBTITLES = {}), { 'templates/brand': 'Brand kit' });
  const ago = (iso) => { if (!iso) return 'never'; const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000)); return s < 60 ? 'just now' : s < 3600 ? Math.floor(s / 60) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };
  const clone = (o) => JSON.parse(JSON.stringify(o));
  if (window.SendlineRender) window.SendlineRender.config.assetBase = location.origin + '/app';

  // Multipart uploads need their own request (SL_API sends JSON).
  async function uploadFile(path, file) {
    const fd = new FormData(); fd.append('file', file);
    const xsrf = window.SL_XSRF();
    const r = await fetch('/api' + path, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf) : '', 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw Object.assign({ message: 'Upload failed. Try again.' }, d.error || {});
    return d;
  }

  // ═══════ Brand kit ═══════
  let brand = null;
  const loadBrand = () => (brand ? Promise.resolve(brand) : API('GET', base() + '/brand').then((r) => (brand = r.brand || {})).catch(() => (brand = {})));
  /** Applies the brand kit to a design: colours, font, logo, footer details and social links. */
  function applyBrand(doc, b) {
    if (!b) return doc;
    const s = doc.settings, c = b.colors || {};
    const map = { button: 'btn', link: 'link', text: 'text', heading: 'heading', background: 'bodyBg', content: 'contentBg' };
    Object.keys(map).forEach((k) => { if (c[k]) s[map[k]] = c[k]; });
    if (b.font) s.font = b.font;
    const nets = Object.keys(b.socials || {});
    doc.rows.forEach((r) => r.cells.flat().forEach((blk) => {
      const p = blk.props;
      if (blk.type === 'image' && p.src === 'logo' && b.logo_url) { p.src = b.logo_url; p.alt = b.company || p.alt; }
      if (blk.type === 'footer') { if (b.company) p.company = b.company; if (b.address) p.address = b.address; }
      if (blk.type === 'social' && nets.length) { p.nets = nets; p.urls = Object.assign({}, b.socials); }
      if (blk.type === 'button' && c.button && p.bg && p.bg.toUpperCase() === '#5B5BF7') p.bg = c.button;
    }));
    return doc;
  }
  S.applyBrand = applyBrand;

  // ═══════ Templates in the builder ═══════
  S.editingTemplate = null;
  let saveTimer = null, pending = null, saving = null;
  const status = (t) => { const el = $('#ebsaved'); if (el) el.textContent = t; };
  function pushSave() {
    if (!S.editingTemplate || !pending) return Promise.resolve();
    const doc = pending; pending = null;
    status('Saving…');
    saving = API('PATCH', `${base()}/templates/${S.editingTemplate.id}`, { doc })
      .then((r) => { status(`Template · saved · ${r.template.size_kb} KB`); return r; })
      .catch((err) => { status('Couldn’t save: ' + err.message); toast(err.message); throw err; })
      .finally(() => { saving = null; });
    return saving;
  }
  const originalSave = S.saveEmailDoc;
  S.saveEmailDoc = (d) => {
    if (!S.editingTemplate) return originalSave(d);
    S.emailDoc = d; pending = clone(d);
    clearTimeout(saveTimer); saveTimer = setTimeout(pushSave, 1200);
    status('Unsaved changes…');
  };
  S.flushTemplate = (d) => { clearTimeout(saveTimer); if (d) pending = clone(d); return (saving || Promise.resolve()).then(pushSave); };

  const originalBuilder = pages['campaigns/design'];
  pages['campaigns/design'] = (v) => {
    const id = S.param('template');
    if (!id) { S.editingTemplate = null; if (!S.editingCampaign && !S.editingJourneyEmail && !S.editingQuickSend) S.emailDoc = null; loadMedia(); return originalBuilder(v); }
    v.innerHTML = '<div class="card"><div class="card-body faint">Opening the template…</div></div>';
    Promise.all([API('GET', `${base()}/templates/${id}`), loadMedia()]).then(([r]) => {
      S.editingTemplate = { id: r.template.id, name: r.template.name, subject: r.template.subject };
      S.emailDoc = r.template.doc;
      originalBuilder(v);
      status(`Template · saved · ${r.template.size_kb} KB`);
      if (canEdit()) {
        const done = $('#done'); if (done) done.insertAdjacentHTML('beforebegin', `<button class="btn" id="ebtest">${ic('send', 15)}Send test</button>`);
        const t = $('#ebtest'); if (t) t.onclick = () => S.flushTemplate().then(() => testTemplate(S.editingTemplate));
      } else { const done = $('#done'); if (done) done.style.display = 'none'; }
    }).catch((err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)} <a href="#/templates?tab=mine">Back to templates</a></div></div>`; });
  };
  // Leaving the builder with unsaved changes: save them.
  window.addEventListener('hashchange', () => { if (S.editingTemplate && !location.hash.startsWith('#/campaigns/design')) { S.flushTemplate().catch(() => {}); S.editingTemplate = null; S.emailDoc = null; } });

  /** Copies a design into the workspace (brand kit applied) and opens it in the builder. */
  S.useTemplateDoc = (name, doc, category) => {
    if (!canEdit()) return toast('Viewers can’t create templates.');
    if (S.useTemplateDoc.busy) return;
    S.useTemplateDoc.busy = true;
    toast(`Saving “${name}” to your templates…`);
    loadBrand().then((b) => API('POST', base() + '/templates', { name, category, doc: applyBrand(doc, b) }))
      .then((r) => { toast(`“${name}” saved to your templates`); location.hash = '#/campaigns/design?template=' + r.template.id; })
      .catch((err) => toast(err.message))
      .finally(() => { S.useTemplateDoc.busy = false; });
  };

  // ── Media library inside the builder ──
  S.mediaList = null;
  function loadMedia() { return API('GET', base() + '/media').then((r) => (S.mediaList = r.media)).catch(() => (S.mediaList = [])); }
  S.mediaGrid = (src) => `<div class="ebf"><label>Your images</label><div class="asset-grid photos">${(S.mediaList || []).slice(0, 30).map((m) => `<button data-pv="src" data-val="${esc(m.url)}" class="${src === m.url ? 'on' : ''}" title="${esc(m.filename)} · ${m.width}×${m.height}"><img src="${esc(m.url)}" loading="lazy" alt=""></button>`).join('')}${canEdit() ? `<button class="asset-up" title="Upload an image">${ic('upload', 16)}</button>` : ''}</div>${S.mediaList && !S.mediaList.length ? '<p class="ebf-tip">Upload your logo and photos. JPG, PNG, GIF or WebP up to 5 MB; wide images are resized for email.</p>' : ''}</div>`;
  S.pickImage = (cb) => {
    const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/gif,image/webp';
    input.onchange = () => {
      const f = input.files[0]; if (!f) return;
      if (f.size > 5 * 1024 * 1024) return toast('Images can be up to 5 MB.');
      toast('Uploading…');
      uploadFile(base() + '/media', f).then((r) => { (S.mediaList = S.mediaList || []).unshift(r.media); toast('Image uploaded'); cb(r.media.url); }).catch((err) => toast(err.message));
    };
    input.click();
  };

  // ── "Your templates" tab on the Templates page ──
  S.countTeamTemplates = (cb) => API('GET', base() + '/templates?page=1&per_page=1').then((r) => cb(r.meta ? r.meta.total : r.templates.length)).catch(() => {});
  S.renderTeamTemplates = (grid, q) => {
    grid.innerHTML = '<p class="faint">Loading your templates…</p>';
    S.paged(base() + '/templates' + (q ? '?q=' + encodeURIComponent(q) : ''), 'templates', (r, ctl) => { // 48 at a time, searched on the server
      if (!document.body.contains(grid)) return;
      const list = r.templates;
      grid.innerHTML = list.length ? list.map((t) => `<div class="tg-card" data-tid="${t.id}"><div class="tg-thumb"><iframe tabindex="-1" sandbox loading="lazy" title="${esc(t.name)}"></iframe><div class="tg-over"><button class="btn btn-sm" data-tt="${t.id}">${ic('send', 14)}Test</button><button class="btn btn-primary btn-sm" data-te="${t.id}">${canEdit() ? 'Edit' : 'View'}</button></div></div>
        <div class="tg-info"><b>${esc(t.name)}</b><small>Updated ${ago(t.updated_at)} · ${t.size_kb} KB${t.issues ? ` · <span style="color:var(--s-deferred)">${t.issues} check${t.issues > 1 ? 's' : ''} to review</span>` : ''}</small>
        ${canEdit() ? `<div class="tg-acts"><button class="btn btn-sm btn-ghost" data-td="${t.id}" title="Duplicate">${ic('copy', 13)}</button><button class="btn btn-sm btn-ghost" data-tx="${t.id}" title="Delete">${ic('trash', 13)}</button></div>` : ''}</div></div>`).join('')
        : `<div class="empty" style="grid-column:1/-1"><div class="ic">${ic('templates', 22)}</div><h3>${q ? 'No templates match' : 'No saved templates yet'}</h3><p>${q ? 'Try another search.' : 'Pick a design from the Gallery, or start a blank design. It’s saved here with your brand kit applied.'}</p></div>`;
      // Thumbnails: the real saved HTML, loaded as cards scroll into view.
      const io = new IntersectionObserver((ents) => ents.forEach((en) => { if (!en.isIntersecting) return; io.unobserve(en.target); const id = en.target.dataset.tid; API('GET', `${base()}/templates/${id}`).then((x) => { const fr = $('iframe', en.target); if (fr) { fr.srcdoc = x.template.html.replace(/\{\{[^}]+\}\}/g, ''); fr.style.transform = `scale(${$('.tg-thumb', en.target).clientWidth / 640})`; } }).catch(() => {}); }), { rootMargin: '300px' });
      $$('[data-tid]', grid).forEach((c) => io.observe(c));
      $$('[data-te]', grid).forEach((b) => b.onclick = (e) => { e.stopPropagation(); location.hash = '#/campaigns/design?template=' + b.dataset.te; });
      $$('[data-tt]', grid).forEach((b) => b.onclick = (e) => { e.stopPropagation(); const t = r.templates.find((x) => x.id === b.dataset.tt); testTemplate(t); });
      $$('[data-td]', grid).forEach((b) => b.onclick = (e) => { e.stopPropagation(); API('POST', `${base()}/templates/${b.dataset.td}/duplicate`).then(() => { toast('Duplicated'); S.renderTeamTemplates(grid, q); }).catch((err) => toast(err.message)); });
      $$('[data-tx]', grid).forEach((b) => b.onclick = (e) => { e.stopPropagation(); const t = r.templates.find((x) => x.id === b.dataset.tx); confirmDialog({ title: `Delete “${t.name}”?`, desc: 'Emails already sent with it aren’t affected.', onConfirm: () => API('DELETE', `${base()}/templates/${t.id}`).then(() => { toast('Template deleted'); S.renderTeamTemplates(grid, q); }) }); });
      $$('[data-tid]', grid).forEach((c) => c.onclick = () => (location.hash = '#/campaigns/design?template=' + c.dataset.tid));
      const n = $('#teamn'); if (n) n.textContent = r.templates.length;
      let bar = grid.nextElementSibling && grid.nextElementSibling.classList.contains('tg-more') ? grid.nextElementSibling : null;
      if (!bar) { bar = document.createElement('div'); bar.className = 'tg-more'; grid.after(bar); }
      S.pageBar(ctl, null, bar, 'templates');
    }).ready.catch((err) => { grid.innerHTML = `<p>${esc(err.message)}</p>`; });
  };

  // ── Test send of a saved template ──
  function testTemplate(t) {
    S.senders().then((snd) => {
      if (!snd.method || !S.hasSenders(snd)) return modal({ title: 'Send a test', desc: 'Set up sending (Amazon SES or your SMTP server) and verify a sender to send tests.', icon: 'send', size: 'sm', body: '', actions: [{ label: 'Close' }, { label: 'Set up sending', kind: 'primary', onClick: () => { location.hash = '#/ses'; } }] });
      modal({ title: `Send a test of “${t.name}”`, desc: snd.method === 'smtp' ? 'Sent through your SMTP server, exactly as your recipients will get it.' : 'Sent through your Amazon SES, exactly as your recipients will get it.', icon: 'send', mount: (root) => S.fromPickBind(root, 'ttf'),
        body: `<div class="field"><label>From</label>${S.fromPick('ttf', snd, '', 'hello')}</div><div class="field"><label>Send to</label><input id="tto" type="email" value="${esc(me().user.email || '')}"></div><div id="ttr"></div>`,
        actions: [{ label: 'Close' }, { label: 'Send test', kind: 'primary', onClick: (m) => {
          const to = $('#tto', m.root).value.trim(); if (!/^\S+@\S+\.\S+$/.test(to)) return fieldError(m.root, '#tto', 'Enter a valid email address.');
          const b = m.root.querySelector('.m-foot .btn-primary'); b.disabled = true;
          API('POST', `${base()}/templates/${t.id}/test`, { from: S.fromPickValue(m.root, 'ttf'), to })
            .then((x) => { b.disabled = false; $('#ttr', m.root).innerHTML = `<div class="tf-res"><span>Sent: <span class="trk">${esc(x.tracking_code)}</span></span><a href="#/activity" class="btn btn-sm">Follow it in Activity</a></div>`; })
            .catch((err) => { b.disabled = false; $('#ttr', m.root).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
          return false;
        } }] });
    });
  }

  // ═══════ #/templates/brand ═══════
  const COLOR_LABEL = [['button', 'Buttons'], ['link', 'Links'], ['heading', 'Headings'], ['text', 'Body text'], ['background', 'Background'], ['content', 'Email body']];
  const SOCIAL_LABEL = [['instagram', 'Instagram'], ['x', 'X (Twitter)'], ['linkedin', 'LinkedIn'], ['facebook', 'Facebook'], ['youtube', 'YouTube'], ['tiktok', 'TikTok']];
  pages['templates/brand'] = (v) => {
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading your brand kit…</div></div>';
    Promise.all([API('GET', base() + '/brand'), loadMedia()]).then(([r]) => {
      const b = r.brand || {}, def = S.EB.DEFAULT_SETTINGS;
      const cur = { logo_url: b.logo_url || '', colors: Object.assign({ button: def.btn, link: def.link, heading: def.heading, text: def.text, background: def.bodyBg, content: def.contentBg }, b.colors || {}), font: b.font || def.font, company: b.company || '', address: b.address || '', socials: Object.assign({}, b.socials || {}) };
      const ro = !canManage();
      v.innerHTML = `<div class="flow-top"><a class="back" href="#/templates">← Templates</a></div>
        <div class="page-head" style="margin-bottom:16px"><div><h1>Brand kit</h1><p>Applied to every design you start from the gallery, so your emails look like you from the first click.</p></div></div>
        <div class="brand-layout"><section class="card"><div class="card-body">
          <div class="field"><label>Logo</label><div class="brand-logo">${cur.logo_url ? `<img src="${esc(cur.logo_url)}" alt="">` : '<span class="faint">No logo yet</span>'}${ro ? '' : `<button class="btn btn-sm" id="blogo">${ic('upload', 14)}${cur.logo_url ? 'Replace' : 'Upload logo'}</button>`}</div><span class="help">A PNG with a transparent background works best, at least 240 px wide.</span></div>
          <div class="field"><label>Colours</label><div class="brand-colors">${COLOR_LABEL.map(([k, l]) => `<label class="bcol"><input type="color" data-c="${k}" value="${esc(cur.colors[k])}" ${ro ? 'disabled' : ''}><span><b>${l}</b><small class="mono" id="cv-${k}">${esc(cur.colors[k])}</small></span></label>`).join('')}</div></div>
          <div class="field"><label>Font</label><select class="select" id="bfont" ${ro ? 'disabled' : ''}>${r.fonts.map((f) => `<option value='${esc(f)}' ${cur.font === f ? 'selected' : ''}>${esc(f.split(',')[0].replace(/"/g, ''))}</option>`).join('')}</select><span class="help">Email-safe fonts only, so it looks the same in Outlook.</span></div>
          <div class="row2"><div class="field"><label>Company name</label><input id="bco" value="${esc(cur.company)}" maxlength="100" ${ro ? 'disabled' : ''}></div><div class="field"><label>Postal address <span class="faint">(required in marketing email)</span></label><input id="bad" value="${esc(cur.address)}" maxlength="200" ${ro ? 'disabled' : ''}></div></div>
          <div class="field"><label>Social links</label><div class="brand-socials">${SOCIAL_LABEL.map(([k, l]) => `<label><span>${l}</span><input data-s="${k}" value="${esc(cur.socials[k] || '')}" placeholder="https://" ${ro ? 'disabled' : ''}></label>`).join('')}</div></div>
          <div id="berr"></div>
        </div>${ro ? '<div class="panel-foot"><span class="hint">Only owners and admins can change the brand kit.</span></div>' : '<div class="panel-foot"><span class="hint">Changes apply to designs you start after saving. Existing templates keep their look.</span><button class="btn btn-primary" id="bsave">Save brand kit</button></div>'}</section>
        <aside class="card brand-prev"><div class="card-head"><div><h3>Preview</h3><div class="sub">A gallery design with your brand applied</div></div></div><iframe id="bprev" sandbox title="Brand preview"></iframe></aside></div>`;
      const read = () => { $$('[data-c]', v).forEach((i) => { cur.colors[i.dataset.c] = i.value.toUpperCase(); $('#cv-' + i.dataset.c).textContent = i.value.toUpperCase(); }); cur.font = $('#bfont').value; cur.company = $('#bco').value.trim(); cur.address = $('#bad').value.trim(); $$('[data-s]', v).forEach((i) => { const val = i.value.trim(); if (val) cur.socials[i.dataset.s] = val; else delete cur.socials[i.dataset.s]; }); };
      const paint = () => { read(); const fr = $('#bprev'); fr.srcdoc = S.emailToHtml(applyBrand(S.newEmailDoc(), Object.assign({}, cur, { socials: Object.fromEntries(Object.entries(cur.socials).filter(([, u]) => /^https:\/\//.test(u))) })), { preview: true }); };
      let t; v.addEventListener('input', () => { clearTimeout(t); t = setTimeout(paint, 150); });
      paint();
      const lg = $('#blogo'); if (lg) lg.onclick = () => S.pickImage((url) => { cur.logo_url = url; $('.brand-logo').innerHTML = `<img src="${esc(url)}" alt=""><button class="btn btn-sm" id="blogo2">${ic('upload', 14)}Replace</button>`; $('#blogo2').onclick = lg.onclick; paint(); });
      const sv = $('#bsave'); if (sv) sv.onclick = () => { read(); sv.disabled = true; API('PUT', base() + '/brand', cur).then((x) => { brand = x.brand; sv.disabled = false; $('#berr').innerHTML = ''; toast('Brand kit saved'); }).catch((err) => { sv.disabled = false; $('#berr').innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; }); };
    }).catch((err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`; });
  };
})();
