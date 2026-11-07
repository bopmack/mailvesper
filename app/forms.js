/* MailVesper: the visual signup form builder (#/forms/edit?id=).
   Fields (typed questions, drag to reorder, half width, steps), Design (colours, font, image, layout), Display
   (inline, popup, slide-in or bar, with display rules) and Settings, with a live preview drawn by the same renderer
   as the hosted page and customers' sites (/f/embed.js). Edits save on their own into the form's draft; sites keep
   showing the live version until Publish. Loaded after contacts.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, confirmDialog } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}`;
  let gen = 0;
  const alive = (g, seq) => g === gen && seq === S.routeSeq;
  const ago = (iso) => { const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000)); return s < 3600 ? Math.max(1, Math.floor(s / 60)) + 'm ago' : s < 86400 ? Math.floor(s / 3600) + 'h ago' : Math.floor(s / 86400) + 'd ago'; };

  // [type, name, what it is]
  const TYPES = [['text', 'Short text', 'One line'], ['textarea', 'Long text', 'A message or notes'], ['number', 'Number', 'e.g. budget, team size'], ['phone', 'Phone', 'A phone number'],
    ['date', 'Date', 'A date picker'], ['dropdown', 'Dropdown', 'Pick one from a list'], ['radio', 'Multiple choice', 'Pick one, all shown'], ['checkboxes', 'Checkboxes', 'Pick any'],
    ['checkbox', 'Yes / no', 'A single tick box'], ['consent', 'Consent', 'A required “I agree…” box, recorded'], ['hidden', 'Hidden', 'From the page link (UTM) or a fixed value']];
  const TYPE_NAME = Object.fromEntries(TYPES.map(([k, n]) => [k, n]));
  const CHOICE = ['dropdown', 'radio', 'checkboxes'];
  const RESERVED = ['email', 'first_name', 'last_name', 'status', 'tags', 'lists', 'source', 'id', 'unsubscribe_url', 'tracking_code'];
  const FONTS = ['Inter', 'System', 'Poppins', 'DM Sans', 'Montserrat', 'Lora', 'Playfair Display', 'Georgia'];
  const LAYOUTS = [['card', 'Card', 'Just the form'], ['image_left', 'Image beside', 'A photo on the left'], ['image_top', 'Image on top', 'A banner above'], ['background', 'Image behind', 'A soft photo backdrop']];
  const MODES = [['inline', 'Inline', 'Where you paste it on the page'], ['popup', 'Popup', 'Over the page, after a moment'], ['slidein', 'Slide-in', 'A corner card, less in the way'], ['bar', 'Bar', 'A strip at the top or bottom']];
  const slug = (s) => String(s).toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 40) || 'question';

  const loadRenderer = () => (window.SendlineForms && window.SendlineForms.version >= 2 ? Promise.resolve() : new Promise((ok, no) => {
    const s = document.createElement('script'); s.src = '/f/embed.js?v=2'; s.onload = ok; s.onerror = () => no(new Error('The form preview couldn’t load.')); document.head.appendChild(s);
  }));

  pages['contacts/form'] = () => location.replace('#/forms/edit?id=' + encodeURIComponent(S.param('id') || '')); // old links
  pages['forms/edit'] = (v) => {
    const g = ++gen, seq = S.routeSeq, id = S.param('id');
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>';
    Promise.all([API('GET', `${base()}/forms/${id}`), API('GET', base() + '/lists'), API('GET', base() + '/fields'), S.senders(), loadRenderer()]).then(([r, ls, fl, snd]) => {
      if (!alive(g, seq)) return;
      const f = r.form, ro = !canEdit(), dis = ro ? 'disabled' : '';
      const st = JSON.parse(JSON.stringify(f)); // the draft being edited
      st.fields = (st.fields || []).map((x) => Object.assign({ type: 'text', width: 'full', step: 1, required: false }, x));
      const custom = (fl.fields || []).map((x) => ({ key: x.key, label: x.label, type: ({ number: 'number', date: 'date', boolean: 'checkbox' })[x.type] || 'text' }));
      const sender = (me().workspaces.find((w) => w.id === wsId()) || {}).name || f.name;
      let tab = 'fields', open = null, device = 'desktop', pstep = 1, dirty = false, codeTab = 'embed', closeOverlay = null;

      v.innerHTML = `<div class="flow-top"><a class="back" href="#/forms">← Forms</a></div>
        <div class="page-head"><div><h1 id="fh">${esc(f.name)}</h1><p><span id="fstate"></span> ${fmt(f.submissions)} sign-ups${f.double_opt_in ? ` · ${fmt(f.confirmed)} confirmed` : ''}<span id="fopen"></span></p></div>
          ${ro ? '' : `<div class="fb-actions"><span class="faint" id="fdirty" aria-live="polite"></span><button class="btn" id="fdel">${ic('trash', 14)}Delete</button><button class="btn" id="fdiscard" hidden>Discard changes</button><button class="btn btn-primary" id="fpub">Publish</button></div>`}</div>
        <div class="fb">
          <aside class="fb-side card"><div class="fb-tabs" role="tablist">${[['fields', 'Fields'], ['design', 'Design'], ['display', 'Display'], ['settings', 'Settings'], ['share', 'Share']].map(([k, l]) => `<button role="tab" data-tab="${k}">${l}</button>`).join('')}</div><div class="fb-body" id="fbody"></div></aside>
          <section class="fb-stage card"><div class="fb-bar"><div class="seg sm" id="fdev"><button data-dev="desktop">Desktop</button><button data-dev="mobile">Mobile</button></div><div id="fsteps"></div><button class="btn btn-sm" id="freplay" title="Show it again">${ic('refresh', 13)}Replay</button></div>
            <div class="fb-view" id="fview"><div class="fb-frame" id="fframe"></div></div></section>
        </div>`;

      // ─── Draft and live: edits save on their own (into the draft once the form is live); Publish puts them live ───
      const head = () => {
        $('#fstate').innerHTML = S.formState(f);
        $('#fopen').innerHTML = f.state === 'draft' ? ' · <span class="faint">Not on any site yet</span>' : ` · <a href="${esc(f.page_url)}" target="_blank" rel="noopener">Open the live page</a>`;
        if (ro) return;
        const pub = $('#fpub');
        pub.textContent = f.state === 'draft' ? 'Publish' : f.state === 'changed' || dirty ? 'Publish changes' : 'Published';
        pub.disabled = f.state === 'live' && !dirty;
        $('#fdiscard').hidden = f.state !== 'changed';
      };
      const note = (t, bad) => { const el = $('#fdirty'); if (el) { el.textContent = t; el.classList.toggle('bad', !!bad); } };
      let saveT = null, saving = null, again = false;
      const body = () => {
        const b = { name: st.name, enabled: st.enabled, heading: st.heading, intro: st.intro, button: st.button, redirect_url: st.redirect_url, success_message: st.success_message,
          list_id: st.list_id || null, tags: st.tags || [], double_opt_in: st.double_opt_in, fields: st.fields, design: st.design, display: st.display };
        if ($('#ffp-d', v)) { b.from_email = S.fromPickValue(v, 'ffp') || null; b.from_name = st.from_name || null; } else if (st.from_email) b.from_email = st.from_email;
        return b;
      };
      /** Saves now (waiting for a save already running); resolves once the server has every edit, rejects on a problem. */
      const save = () => {
        clearTimeout(saveT);
        if (saving) { again = true; return saving; }
        if (!dirty) return Promise.resolve();
        dirty = false; note('Saving…');
        saving = API('PATCH', `${base()}/forms/${f.id}`, body()).then((x) => {
          Object.assign(f, { state: x.form.state, published_at: x.form.published_at, name: x.form.name, enabled: x.form.enabled });
          if (alive(g, seq)) { $('#fh').textContent = x.form.name; note(f.state === 'draft' ? 'Draft saved' : f.state === 'changed' ? 'Saved, not published' : 'Saved'); head(); }
        }).catch((err) => { dirty = true; note('Not saved: ' + err.message, true); throw err; })
          .finally(() => { saving = null; if (again) { again = false; save().catch(() => {}); } });
        return saving;
      };
      const markDirty = () => { dirty = true; note('Editing…'); head(); clearTimeout(saveT); saveT = setTimeout(() => save().catch(() => {}), 900); };
      // Leaving the page saves what's left.
      const flushOnLeave = () => { window.removeEventListener('hashchange', flushOnLeave); if (dirty) save().catch((err) => toast('Your last form edits weren’t saved: ' + err.message)); };
      window.addEventListener('hashchange', flushOnLeave);
      // ─── Preview: the real renderer, in a mock page for popups, slide-ins and bars ───
      let pT = null;
      const preview = (soon) => { clearTimeout(pT); pT = setTimeout(drawPreview, soon ? 120 : 0); };
      function drawPreview() {
        if (!alive(g, seq)) return;
        const frame = $('#fframe'); if (!frame) return;
        if (closeOverlay) { closeOverlay(); closeOverlay = null; }
        const form = { name: st.name, sender, heading: st.heading, intro: st.intro, button: st.button, success_message: st.success_message, double_opt_in: st.double_opt_in, fields: st.fields, design: st.design, display: st.display };
        const mode = st.display.mode;
        frame.className = 'fb-frame ' + device + (mode === 'inline' ? ' inline' : ' site');
        frame.style.background = mode === 'inline' ? st.design.background : '';
        frame.innerHTML = mode === 'inline' ? '<div class="fb-host"></div>' : '<div class="fb-site"><i style="width:30%"></i><i style="width:70%;height:26px"></i><i></i><i></i><i style="width:85%"></i><i style="width:60%"></i><i></i><i style="width:75%"></i></div>';
        if (mode === 'inline') {
          const ctl = window.SendlineForms.render($('.fb-host', frame), form, { preview: true, step: pstep });
          renderSteps(ctl.steps);
        } else {
          closeOverlay = window.SendlineForms.overlay(form, f.key, st.display, frame, true);
          renderSteps(1);
        }
      }
      function renderSteps(n) {
        const box = $('#fsteps'); if (!box) return;
        if (pstep > n) pstep = n;
        box.innerHTML = n > 1 ? `<div class="seg sm">${Array.from({ length: n }, (_, i) => `<button data-ps="${i + 1}" class="${pstep === i + 1 ? 'on' : ''}">Step ${i + 1}</button>`).join('')}</div>` : '';
        $$('[data-ps]', box).forEach((b) => b.onclick = () => { pstep = +b.dataset.ps; drawPreview(); });
      }

      // ─── Left panel ───
      function renderTab() {
        $$('[data-tab]', v).forEach((b) => { b.classList.toggle('on', b.dataset.tab === tab); b.setAttribute('aria-selected', b.dataset.tab === tab); });
        const body = $('#fbody');
        body.innerHTML = ({ fields: fieldsTab, design: designTab, display: displayTab, settings: settingsTab, share: shareTab })[tab]();
        ({ fields: bindFields, design: bindDesign, display: bindDisplay, settings: bindSettings, share: bindShare })[tab](body);
      }

      // Fields: email first, then the rest; drag the handle to reorder.
      const fieldsTab = () => {
        const steps = Math.max(1, ...st.fields.filter((x) => x.type !== 'hidden').map((x) => +x.step || 1));
        const card = (x, i) => {
          const isOpen = open === i, hasOpt = CHOICE.includes(x.type);
          return `<div class="fb-f ${isOpen ? 'open' : ''}" data-i="${i}" ${ro ? '' : 'draggable="true"'}>
            <div class="fb-fh"><span class="fb-grip" title="Drag to reorder">⋮⋮</span><button class="fb-ft" data-open="${i}"><b>${esc(x.label || x.key)}</b><small>${esc(TYPE_NAME[x.type] || x.type)}${x.required ? ' · required' : ''}${x.width === 'half' ? ' · half' : ''}${steps > 1 || x.step > 1 ? ' · step ' + x.step : ''}</small></button>
              ${ro ? '' : `<button class="icon-btn" data-up="${i}" title="Move up" ${i === 0 ? 'disabled' : ''}>↑</button><button class="icon-btn" data-rm="${i}" title="Remove">${ic('x', 13)}</button>`}</div>
            ${isOpen ? `<div class="fb-fb">
              <div class="field"><label>${x.type === 'consent' ? 'What they agree to' : 'Label'}</label>${x.type === 'consent' ? `<textarea data-k="label" rows="2" maxlength="500" ${dis}>${esc(x.label)}</textarea>` : `<input data-k="label" maxlength="80" value="${esc(x.label)}" ${dis}>`}</div>
              ${['hidden', 'checkbox', 'consent', 'radio', 'checkboxes'].includes(x.type) ? '' : `<div class="field"><label>Placeholder <span class="faint">(optional)</span></label><input data-k="placeholder" maxlength="80" value="${esc(x.placeholder || '')}" ${dis}></div>`}
              ${hasOpt ? `<div class="field"><label>Choices <span class="faint">(one per line, up to 20)</span></label><textarea data-k="options" rows="4" ${dis}>${esc((x.options || []).join('\n'))}</textarea></div>` : ''}
              ${x.type === 'hidden' ? `<div class="row2"><div class="field"><label>From the link parameter</label><input data-k="param" class="mono" value="${esc(x.param || '')}" placeholder="utm_source" ${dis}></div><div class="field"><label>Otherwise</label><input data-k="value" value="${esc(x.value || '')}" placeholder="direct" ${dis}></div></div><p class="ins-help">Visitors never see it. With a parameter, <span class="mono">?utm_source=instagram</span> on the page’s link fills it; the fixed value can’t be changed by a visitor.</p>` : ''}
              ${x.type === 'hidden' ? '' : `<div class="fb-opts">${x.type === 'consent' ? '' : `<label class="check"><input type="checkbox" data-k="required" ${x.required ? 'checked' : ''} ${dis}> Required</label>`}<label class="check"><input type="checkbox" data-k="half" ${x.width === 'half' ? 'checked' : ''} ${dis}> Half width</label>
                <label class="fb-step">Step <select class="select" data-k="step" ${dis}>${[1, 2, 3].map((n) => `<option ${+x.step === n ? 'selected' : ''}>${n}</option>`).join('')}</select></label></div>`}
              <p class="ins-help" style="margin:8px 0 0">Saves to the contact field <span class="mono">${esc(x.key)}</span>.${x.type === 'consent' ? ' The wording and time they agreed are kept with the sign-up.' : ''}</p></div>` : ''}</div>`;
        };
        const have = st.fields.map((x) => x.key);
        const known = [{ key: 'first_name', label: 'First name', type: 'text' }, { key: 'last_name', label: 'Last name', type: 'text' }].concat(custom).filter((x) => !have.includes(x.key));
        return `<div class="fb-f fixed"><div class="fb-fh"><span class="fb-grip"></span><span class="fb-ft"><b>Email</b><small>Always first · required</small></span></div></div>
          <div id="flist">${st.fields.map(card).join('')}</div>
          ${ro || st.fields.length >= 20 ? '' : `<div class="fb-add"><button class="btn btn-sm" id="fadd">${ic('plus', 13)}Add a field</button></div>
          <div class="fb-menu" id="fmenu" hidden>${known.length ? `<div class="fb-ml">Contact fields</div>${known.map((x) => `<button data-known="${esc(x.key)}"><b>${esc(x.label)}</b><small>${esc(x.key)}</small></button>`).join('')}` : ''}<div class="fb-ml">New question</div>${TYPES.map(([k, n, d]) => `<button data-new="${k}"><b>${n}</b><small>${d}</small></button>`).join('')}</div>`}
          ${steps > 1 ? `<div class="ins-sec" style="margin-top:18px"><div class="ins-label">Step titles <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">(optional)</span></div>${Array.from({ length: steps }, (_, i) => `<input class="fb-stt" data-st="${i}" maxlength="60" value="${esc((st.design.steps || [])[i] || '')}" placeholder="Step ${i + 1}" ${dis}>`).join('')}</div>` : '<p class="ins-help" style="margin-top:16px">Long form? Put fields on step 2 or 3 to split it into steps.</p>'}`;
      };
      function bindFields(body) {
        $$('[data-open]', body).forEach((b) => b.onclick = () => { open = open === +b.dataset.open ? null : +b.dataset.open; renderTab(); });
        if (ro) return;
        $$('[data-rm]', body).forEach((b) => b.onclick = () => { st.fields.splice(+b.dataset.rm, 1); open = null; markDirty(); renderTab(); preview(); });
        $$('[data-up]', body).forEach((b) => b.onclick = () => { const i = +b.dataset.up; if (i < 1) return; [st.fields[i - 1], st.fields[i]] = [st.fields[i], st.fields[i - 1]]; open = null; markDirty(); renderTab(); preview(); });
        $$('.fb-f[data-i] [data-k]', body).forEach((el) => {
          const x = st.fields[+el.closest('.fb-f').dataset.i], k = el.dataset.k;
          el.oninput = el.onchange = () => {
            if (k === 'required') x.required = el.checked;
            else if (k === 'half') x.width = el.checked ? 'half' : 'full';
            else if (k === 'step') x.step = +el.value;
            else if (k === 'options') x.options = el.value.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 20);
            else x[k] = el.value;
            markDirty(); preview(true);
            if (['step', 'required', 'half'].includes(k) || (k === 'label' && el.tagName !== 'TEXTAREA')) { const t = el.closest('.fb-f').querySelector('.fb-ft'); if (t) t.innerHTML = `<b>${esc(x.label || x.key)}</b><small>${esc(TYPE_NAME[x.type])}${x.required ? ' · required' : ''}${x.width === 'half' ? ' · half' : ''}${x.step > 1 ? ' · step ' + x.step : ''}</small>`; }
            if (k === 'step') renderTab();
          };
        });
        $$('.fb-stt', body).forEach((el) => el.oninput = () => { st.design.steps = st.design.steps || []; st.design.steps[+el.dataset.st] = el.value; markDirty(); preview(true); });
        // Drag a field card to reorder.
        let from = null;
        $$('#flist .fb-f', body).forEach((el) => {
          el.ondragstart = (e) => { from = +el.dataset.i; el.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(from)); };
          el.ondragend = () => el.classList.remove('drag');
          el.ondragover = (e) => { e.preventDefault(); el.classList.add('over'); };
          el.ondragleave = () => el.classList.remove('over');
          el.ondrop = (e) => { e.preventDefault(); const to = +el.dataset.i; if (from == null || from === to) return; const [m] = st.fields.splice(from, 1); st.fields.splice(to, 0, m); from = null; open = null; markDirty(); renderTab(); preview(); };
        });
        const add = $('#fadd', body), menuEl = $('#fmenu', body);
        if (add) add.onclick = () => { menuEl.hidden = !menuEl.hidden; };
        $$('[data-known]', body).forEach((b) => b.onclick = () => { const x = [{ key: 'first_name', label: 'First name', type: 'text' }, { key: 'last_name', label: 'Last name', type: 'text' }].concat(custom).find((y) => y.key === b.dataset.known); push({ key: x.key, label: x.label, type: x.type }); });
        $$('[data-new]', body).forEach((b) => b.onclick = () => {
          const type = b.dataset.new, name = TYPE_NAME[type];
          const label = type === 'consent' ? 'I agree to receive emails from ' + sender + '.' : type === 'hidden' ? 'Source' : name;
          let key = slug(type === 'consent' ? 'marketing_consent' : type === 'hidden' ? 'lead_source' : label), n = 2;
          while (RESERVED.includes(key) || st.fields.some((y) => y.key === key)) key = slug(label) + '_' + n++;
          push({ key, label, type, required: type === 'consent', options: CHOICE.includes(type) ? ['Option 1', 'Option 2'] : undefined, param: type === 'hidden' ? 'utm_source' : undefined, value: type === 'hidden' ? 'direct' : undefined });
        });
        function push(x) { st.fields.push(Object.assign({ width: 'full', step: 1, required: false }, x)); open = st.fields.length - 1; markDirty(); renderTab(); preview(); }
      }

      // Design: colours, font, corners, image and layout.
      const designTab = () => {
        const d = st.design;
        const col = (k, l) => `<label class="fb-col"><input type="color" data-d="${k}" value="${esc(d[k])}" ${dis}><span>${l}<small class="mono">${esc(d[k])}</small></span></label>`;
        return `<div class="ins-label">Colours</div><div class="fb-cols">${col('accent', 'Button & accents')}${col('card', 'Form')}${col('text', 'Text')}${col('background', 'Page')}</div>
          <div class="row2" style="margin-top:14px"><div class="field"><label>Font</label><select class="select" id="dfont" ${dis}>${FONTS.map((x) => `<option ${d.font === x ? 'selected' : ''}>${x}</option>`).join('')}</select></div>
          <div class="field"><label>Corners <b id="drv">${d.radius}px</b></label><input type="range" id="drad" min="0" max="28" value="${d.radius}" ${dis}></div></div>
          <div class="ins-label" style="margin-top:6px">Layout</div><div class="fb-lay">${LAYOUTS.map(([k, n, x]) => `<button data-lay="${k}" class="${d.layout === k ? 'on' : ''}" ${dis}><i class="ly-${k}"></i><b>${n}</b><small>${x}</small></button>`).join('')}</div>
          <div class="field" style="margin-top:12px"><label>Image ${d.layout === 'card' ? '<span class="faint">(choose a layout with an image)</span>' : ''}</label><div style="display:flex;gap:8px"><input id="dimg" value="${esc(d.image || '')}" placeholder="https://…/photo.jpg" ${dis}>${ro || !S.pickImage ? '' : `<button class="btn btn-sm" id="dpick">${ic('upload', 13)}Choose</button>`}</div></div>
          <label class="check"><input type="checkbox" id="dbf" ${d.button_full ? 'checked' : ''} ${dis}> Full-width button</label>
          <label class="check" style="margin-top:8px"><input type="checkbox" id="dmk" ${d.show_mark ? 'checked' : ''} ${dis}> Show the initial badge (${esc(sender.charAt(0).toUpperCase())})</label>`;
      };
      function bindDesign(body) {
        if (ro) return;
        const d = st.design, ch = () => { markDirty(); preview(true); };
        $$('[data-d]', body).forEach((el) => el.oninput = () => { d[el.dataset.d] = el.value.toUpperCase(); el.nextElementSibling.querySelector('small').textContent = d[el.dataset.d]; ch(); });
        $('#dfont', body).onchange = (e) => { d.font = e.target.value; ch(); };
        $('#drad', body).oninput = (e) => { d.radius = +e.target.value; $('#drv', body).textContent = d.radius + 'px'; ch(); };
        $$('[data-lay]', body).forEach((b) => b.onclick = () => { d.layout = b.dataset.lay; if (d.layout !== 'card' && !d.image) toast('Add an image for this layout'); ch(); renderTab(); });
        $('#dimg', body).onchange = (e) => { const u = e.target.value.trim(); if (u && !/^https:\/\//.test(u)) { toast('Use an https:// image address'); return; } d.image = u || null; ch(); };
        const pick = $('#dpick', body); if (pick) pick.onclick = () => S.pickImage((url) => { d.image = url; if (d.layout === 'card') d.layout = 'image_left'; ch(); renderTab(); });
        $('#dbf', body).onchange = (e) => { d.button_full = e.target.checked; ch(); };
        $('#dmk', body).onchange = (e) => { d.show_mark = e.target.checked; ch(); };
      }

      // Display: where and when it shows on a site.
      const displayTab = () => {
        const x = st.display;
        return `<div class="fb-modes">${MODES.map(([k, n, d]) => `<button data-mode="${k}" class="${x.mode === k ? 'on' : ''}" ${dis}><i class="md-${k}"></i><b>${n}</b><small>${d}</small></button>`).join('')}</div>
          ${x.mode === 'inline' ? '<p class="ins-help" style="margin-top:14px">The form sits where you paste the code (Share tab). Change to a popup, slide-in or bar and the same code opens it on the rules below.</p>' : `
          ${x.mode === 'slidein' || x.mode === 'bar' ? `<div class="field" style="margin-top:14px"><label>Position</label><select class="select" id="xpos" ${dis}>${(x.mode === 'bar' ? [['top', 'Top of the page'], ['bottom', 'Bottom of the page']] : [['right', 'Bottom right'], ['left', 'Bottom left']]).map(([k, l]) => `<option value="${k}" ${x.position === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>` : ''}
          <div class="ins-label" style="margin-top:14px">Show it when</div>
          <div class="row2"><div class="field"><label>They’ve been on the page</label><span class="rv-days"><input type="number" id="xdel" min="0" max="300" value="${x.delay}" ${dis}> seconds</span></div><div class="field"><label>And scrolled</label><span class="rv-days"><input type="number" id="xscr" min="0" max="100" value="${x.scroll}" ${dis}> %</span></div></div>
          <label class="check"><input type="checkbox" id="xexit" ${x.exit_intent ? 'checked' : ''} ${dis}> Or straight away when they’re about to leave (mouse heads for the tab bar; desktop only)</label>
          <div class="ins-label" style="margin-top:16px">How often</div>
          <div class="row2"><div class="field"><select class="select" id="xfreq" ${dis}>${[['days', 'At most every few days'], ['session', 'Once per visit'], ['once', 'Only once, ever'], ['every', 'Every page they open']].map(([k, l]) => `<option value="${k}" ${x.frequency === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            ${x.frequency === 'days' ? `<div class="field"><span class="rv-days">every <input type="number" id="xdays" min="1" max="365" value="${x.days}" ${dis}> days</span></div>` : '<div></div>'}</div>
          <p class="ins-help">After someone signs up it never shows to them again on that browser. Closing it counts as seen.</p>`}`;
      };
      function bindDisplay(body) {
        if (ro) return;
        const x = st.display, ch = (re) => { markDirty(); if (re) renderTab(); preview(); };
        $$('[data-mode]', body).forEach((b) => b.onclick = () => { x.mode = b.dataset.mode; x.position = x.mode === 'bar' ? (x.position === 'bottom' ? 'bottom' : 'top') : (x.position === 'left' ? 'left' : 'right'); if (x.mode !== 'inline') codeTab = 'embed'; ch(true); });
        const on = (sel, fn, ev = 'oninput') => { const el = $(sel, body); if (el) el[ev] = () => { fn(el); markDirty(); }; };
        on('#xpos', (el) => { x.position = el.value; preview(); }, 'onchange');
        on('#xdel', (el) => { x.delay = Math.max(0, Math.min(300, +el.value || 0)); });
        on('#xscr', (el) => { x.scroll = Math.max(0, Math.min(100, +el.value || 0)); });
        on('#xexit', (el) => { x.exit_intent = el.checked; }, 'onchange');
        on('#xfreq', (el) => { x.frequency = el.value; renderTab(); }, 'onchange');
        on('#xdays', (el) => { x.days = Math.max(1, Math.min(365, +el.value || 7)); });
      }

      // Settings: the words, what happens, confirmation.
      const settingsTab = () => `<div class="row2"><div class="field"><label>Name <span class="faint">(only you see it)</span></label><input id="f-name" maxlength="100" value="${esc(st.name)}" ${dis}></div><div class="field"><label>Status</label><select class="select" id="f-en" ${dis}><option value="1" ${st.enabled ? 'selected' : ''}>Open for sign-ups</option><option value="0" ${st.enabled ? '' : 'selected'}>Closed</option></select></div></div>
        <div class="field"><label>Heading</label><input id="f-head" maxlength="150" value="${esc(st.heading || '')}" placeholder="Join our newsletter" ${dis}></div>
        <div class="field"><label>Intro <span class="faint">(optional)</span></label><textarea id="f-intro" rows="2" maxlength="500" placeholder="One email a week. Unsubscribe any time." ${dis}>${esc(st.intro || '')}</textarea></div>
        <div class="row2"><div class="field"><label>Button</label><input id="f-btn" maxlength="40" value="${esc(st.button || '')}" placeholder="Subscribe" ${dis}></div><div class="field"><label>Thank-you message</label><input id="f-ok" maxlength="500" value="${esc(st.success_message || '')}" placeholder="Thanks for signing up!" ${dis}></div></div>
        <div class="field"><label>After signing up, go to <span class="faint">(optional)</span></label><input id="f-redir" maxlength="500" value="${esc(st.redirect_url || '')}" placeholder="https://yoursite.com/thanks" ${dis}></div>
        <div class="row2"><div class="field"><label>Add to list</label><select class="select" id="f-list" ${dis}><option value="">No list (contacts only)</option>${ls.lists.map((l) => `<option value="${l.id}" ${st.list_id === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select></div><div class="field"><label>Tags <span class="faint">(comma separated)</span></label><input id="f-tags" value="${esc((st.tags || []).join(', '))}" placeholder="newsletter, website" ${dis}></div></div>
        <label class="check"><input type="checkbox" id="f-doi" ${st.double_opt_in ? 'checked' : ''} ${dis}> Double opt-in: email a confirmation link and add them only when they click it (recommended)</label>
        <div id="f-from" style="margin-top:12px;${st.double_opt_in ? '' : 'display:none'}">${S.hasSenders(snd) ? `<div class="row2"><div class="field"><label>Confirmation email from</label>${S.fromPick('ffp', snd, st.from_email, 'hello', ro)}</div><div class="field"><label>Sender name</label><input id="f-fname" maxlength="100" value="${esc(st.from_name || '')}" placeholder="Your company" ${dis}></div></div>` : `<div class="callout warn">${ic('alert', 15)}<div>Verify a sender under <a href="#/ses/senders">Sending</a> to send confirmation emails.</div></div>`}</div>
        <p class="ins-help" style="margin-top:12px">Journeys can start from <b>Form submitted</b> or <b>Opt-in confirmed</b>, and use the answers as <span class="mono">{{ trigger.properties.answers.<i>field</i> }}</span>.</p>
        <div class="ins-sec" style="margin-top:18px"><div class="ins-label">Latest sign-ups</div>${r.recent.length ? r.recent.map((x) => `<div class="fb-rec"><span>${esc(x.email)}</span><small>${x.status === 'pending' ? 'Waiting to confirm' : x.status === 'confirmed' ? 'Confirmed' : 'Added'} · ${ago(x.created_at)}</small></div>`).join('') : '<p class="faint" style="font-size:13px;margin:0">None yet.</p>'}</div>`;
      function bindSettings(body) {
        S.fromPickBind(body, 'ffp', markDirty);
        if (ro) return;
        const t = (sel, k) => { const el = $(sel, body); el.oninput = () => { st[k] = el.value; markDirty(); preview(true); }; };
        t('#f-name', 'name'); t('#f-head', 'heading'); t('#f-intro', 'intro'); t('#f-btn', 'button'); t('#f-ok', 'success_message'); t('#f-redir', 'redirect_url');
        $('#f-en', body).onchange = (e) => { st.enabled = e.target.value === '1'; markDirty(); };
        $('#f-list', body).onchange = (e) => { st.list_id = e.target.value || null; markDirty(); };
        $('#f-tags', body).oninput = (e) => { st.tags = e.target.value.split(',').map((x) => x.trim()).filter(Boolean); markDirty(); };
        $('#f-doi', body).onchange = (e) => { st.double_opt_in = e.target.checked; $('#f-from', body).style.display = st.double_opt_in ? '' : 'none'; markDirty(); preview(); };
        const fn = $('#f-fname', body); if (fn) fn.oninput = () => { st.from_name = fn.value; markDirty(); };
      }

      // Share: the link, the embed (inline or popup), plain HTML.
      const code = () => {
        if (codeTab === 'link') return f.page_url;
        if (codeTab === 'embed') return st.display.mode === 'inline' ? `<div data-sendline-form="${f.key}"></div>\n<script src="${f.embed_url}" async></script>` : `<script src="${f.embed_url}" data-sendline-form="${f.key}" async></script>`;
        const inp = (x) => {
          const req = x.required ? ' required' : '';
          if (x.type === 'hidden') return x.param ? `  <!-- ${x.key}: filled from ?${x.param}= on the hosted page and the embed; add it yourself here -->` : '';
          if (CHOICE.includes(x.type)) return x.type === 'dropdown' ? `  <select name="${x.key}"${req}><option value="">${esc(x.label)}</option>${(x.options || []).map((o) => `<option>${esc(o)}</option>`).join('')}</select>` : (x.options || []).map((o) => `  <label><input type="${x.type === 'radio' ? 'radio' : 'checkbox'}" name="${x.key}${x.type === 'checkboxes' ? '[]' : ''}" value="${esc(o)}"> ${esc(o)}</label>`).join('\n');
          if (x.type === 'checkbox' || x.type === 'consent') return `  <label><input type="checkbox" name="${x.key}" value="yes"${req}> ${esc(x.label)}</label>`;
          if (x.type === 'textarea') return `  <textarea name="${x.key}" placeholder="${esc(x.label)}"${req}></textarea>`;
          return `  <input type="${({ number: 'number', phone: 'tel', date: 'date' })[x.type] || 'text'}" name="${x.key}" placeholder="${esc(x.label)}"${req}>`;
        };
        return `<form action="${f.submit_url}" method="post">\n  <input type="email" name="email" placeholder="Email" required>\n${st.fields.map(inp).filter(Boolean).join('\n')}${st.fields.length ? '\n' : ''}  <input type="text" name="_hp" style="display:none" tabindex="-1" autocomplete="off">\n  <button type="submit">${esc(st.button || 'Subscribe')}</button>\n</form>`;
      };
      const HELP = { link: 'Its own page: share it in bios, QR codes and emails. Always shown inline.', html: 'A plain form you style yourself. After posting, people land on the thank-you page (or your address).' };
      const shareTab = () => `<div class="tabs" id="ftabs">${[['embed', st.display.mode === 'inline' ? 'Embed' : 'Embed (' + MODES.find((m) => m[0] === st.display.mode)[1].toLowerCase() + ')'], ['link', 'Link'], ['html', 'HTML']].map(([k, l]) => `<button class="tab ${codeTab === k ? 'on' : ''}" data-c="${k}">${l}</button>`).join('')}</div>
        <textarea class="fcode" id="fcode" readonly>${esc(code())}</textarea><div style="display:flex;gap:8px;margin-top:8px"><button class="btn btn-sm" id="fcopy">${ic('copy', 13)}Copy</button><a class="btn btn-sm" href="${esc(f.page_url)}" target="_blank" rel="noopener">Open the page</a></div>
        <p class="ins-help">${codeTab === 'embed' ? (st.display.mode === 'inline' ? 'Paste where the form should appear. It keeps its own look, and shows the answer in place.' : 'Paste once, anywhere on the page (or in your site’s footer to cover every page). It opens on the Display rules.') : HELP[codeTab]}${f.state === 'draft' ? ' <b>Publish the form first:</b> until then the link and code show nothing.' : f.state === 'changed' || dirty ? ' Sites show the <b>live</b> form: publish your changes to update it.' : ''}</p>`;
      function bindShare(body) {
        $$('[data-c]', body).forEach((b) => b.onclick = () => { codeTab = b.dataset.c; renderTab(); });
        $('#fcopy', body).onclick = () => navigator.clipboard.writeText($('#fcode', body).value).then(() => toast('Copied'));
      }

      // ─── Wiring ───
      $$('[data-tab]', v).forEach((b) => b.onclick = () => { tab = b.dataset.tab; renderTab(); });
      $$('[data-dev]', v).forEach((b) => b.onclick = () => { device = b.dataset.dev; $$('[data-dev]', v).forEach((x) => x.classList.toggle('on', x === b)); drawPreview(); });
      $('[data-dev="desktop"]', v).classList.add('on');
      $('#freplay').onclick = () => { pstep = 1; drawPreview(); };
      renderTab(); drawPreview(); head();
      if (ro) return;
      // The editors' own copy after the server answered publish / discard (fields, design… as normalised).
      const reload = (x) => {
        Object.assign(f, x.form); Object.assign(st, JSON.parse(JSON.stringify(x.form)));
        st.fields = (st.fields || []).map((y) => Object.assign({ type: 'text', width: 'full', step: 1, required: false }, y));
        dirty = false; renderTab(); drawPreview(); head();
      };
      $('#fpub').onclick = () => {
        if (st.double_opt_in && S.hasSenders(snd) && !(S.fromPickValue(v, 'ffp') || st.from_email)) { tab = 'settings'; renderTab(); return toast('Choose who the confirmation email is from.'); }
        const b = $('#fpub'); b.disabled = true;
        dirty = true; // send everything as it is now (the sender picker, for one, doesn't mark an edit)
        save().then(() => API('POST', `${base()}/forms/${f.id}/publish`)).then((x) => {
          const first = f.state === 'draft'; reload(x); note('');
          toast(first ? 'Form published: it’s live on its link and wherever you paste the code' : 'Changes published');
        }).catch((err) => toast(err.message)).finally(() => head());
      };
      $('#fdiscard').onclick = () => confirmDialog({ title: 'Discard your changes?', desc: 'The form goes back to the live version. Sites aren’t affected.', label: 'Discard changes',
        onConfirm: () => { clearTimeout(saveT); dirty = false; return (saving || Promise.resolve()).catch(() => {}).then(() => API('POST', `${base()}/forms/${f.id}/discard`)).then((x) => { reload(x); note(''); toast('Changes discarded'); }); } });
      $('#fdel').onclick = () => confirmDialog({ title: `Delete “${f.name}”?`, desc: f.state === 'draft' ? 'It was never published.' : 'The page and embed stop working. Contacts it added stay.', onConfirm: () => { clearTimeout(saveT); dirty = false; return API('DELETE', `${base()}/forms/${f.id}`).then(() => { toast('Form deleted'); location.hash = '#/forms'; }); } });
    }).catch((err) => alive(g, seq) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)} <a href="#/forms">Back to forms</a></div></div>`));
  };
})();
