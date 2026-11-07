/* MailVesper: email builder (#/campaigns/design).
   Modelled on how the established builders work:
   - Beefree / Unlayer: Settings → Rows → Content. Drag rows (column layouts) and
     content blocks onto the stage; selecting anything opens its properties on the right;
     on-stage controls to move, duplicate and delete.
   - Stripo / Klaviyo: ready-made sections (header, hero, features, product grid, CTA,
     footer), saved sections reused across emails.
   - Mailchimp: a global Styles panel; per-row visibility on desktop / mobile; editing in
     mobile view.
   - Unlayer: Design / HTML views of the same document.
   - Litmus-style checks, MailVesper's angle: Gmail clipping at ~102 KB of HTML, missing alt
     text, image-to-text balance, unsubscribe link + postal address, links still set to "#",
     merge tags without a fallback.
   The document is JSON; the email is generated as table-based HTML from it. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast } = S;
  const pages = S.pages;
  S.SUBTITLES = Object.assign(S.SUBTITLES || {}, { 'campaigns/design': 'Email builder' });

  // Rendering lives in email-render.js, shared with the server (so the preview is exactly what is sent).
  const R = window.SendlineRender;
  const { ASSETS, PHOTOS, SOCIAL, socialIcon, imgSrc, blockHtml } = R;
  const toHtml = R.toHtml, checks = R.checks;

  // ─── Model ───
  let uidN = 0; const uid = (p) => p + Date.now().toString(36).slice(-4) + (uidN++).toString(36);
  const BLOCKS = {
    heading: ['Heading', 'title', 'Text', () => ({ html: 'A clear, short headline', size: 28, align: 'left', weight: 700, pad: [8, 28, 8, 28] })],
    text: ['Paragraph', 'file', 'Text', () => ({ html: 'Write something worth reading. Keep paragraphs short; most people read email on a phone.', size: 16, lh: 1.6, align: 'left', pad: [8, 28, 8, 28] })],
    image: ['Image', 'eye', 'Media', () => ({ src: 'hero', alt: '', href: '', width: 100, align: 'center', radius: 12, pad: [8, 28, 8, 28] })],
    button: ['Button', 'link', 'Text', () => ({ label: 'Call to action', href: 'https://acme-corp.io', bg: '', color: '#FFFFFF', radius: null, full: false, align: 'left', size: 16, pad: [12, 28, 12, 28] })],
    divider: ['Divider', 'menu', 'Layout', () => ({ color: '#E4E4E7', thick: 1, width: 100, pad: [12, 28, 12, 28] })],
    spacer: ['Spacer', 'split', 'Layout', () => ({ h: 32 })],
    social: ['Social links', 'team', 'Media', () => ({ nets: ['x', 'instagram', 'linkedin', 'youtube'], align: 'center', size: 32, pad: [12, 28, 12, 28] })],
    video: ['Video', 'zap', 'Media', () => ({ src: 'hero', href: 'https://youtube.com/watch?v=GLgXinAKS84', alt: 'Watch the product tour', pad: [8, 28, 8, 28] })],
    product: ['Product', 'card', 'Commerce', () => ({ src: 'p1', name: 'Hydration bottle', price: '$32', href: 'https://acme-corp.io/shop/bottle', btn: 'Buy now', pad: [12, 16, 12, 16] })],
    coupon: ['Coupon code', 'tag', 'Commerce', () => ({ code: 'WELCOME10', note: '10% off your first order · ends Sunday', pad: [12, 28, 12, 28] })],
    quote: ['Testimonial', 'chat', 'Text', () => ({ html: '“We moved 40,000 contacts over in an afternoon. Our open rate actually went up.”', who: 'Amara Okafor · Head of Growth, Lumen', src: 'avatar', pad: [16, 28, 16, 28] })],
    order: ['Order summary', 'card', 'Commerce', () => ({ items: [['Hydration bottle', 'Midnight · 750 ml', 1, '$32.00', 'img/raspberries-sq.jpg'], ['Travel case', 'Sand', 1, '$58.00', 'img/camera-sq.jpg']], shipping: 'Free', total: '$90.00', pad: [8, 28, 8, 28] })],
    menu: ['Menu', 'menu', 'Layout', () => ({ items: 'Product, Pricing, Blog', align: 'right', pad: [8, 28, 8, 28] })],
    html: ['Custom HTML', 'code', 'Advanced', () => ({ code: '<p style="margin:0">Custom HTML goes here.</p>', pad: [8, 28, 8, 28] })],
    footer: ['Footer', 'shield', 'Layout', () => ({ company: 'Acme Inc', address: '12 Marina Road, Lagos, Nigeria', note: 'You are receiving this because you signed up at acme-corp.io.', pad: [16, 28, 24, 28] })],
  };
  const block = (type, props) => ({ id: uid('b'), type, props: Object.assign(BLOCKS[type][3](), props || {}) });
  const row = (layout, cells, props) => ({ id: uid('r'), layout, cells: cells || layout.map(() => []), bg: '', pad: [16, 16], stack: true, hide: '', ...props });
  const LAYOUTS = [[1], [1, 1], [1, 2], [2, 1], [1, 1, 1], [1, 1, 1, 1]];
  const SECTIONS = {
    header: ['Header', 'Logo and menu', () => row([1, 2], [[block('image', { src: 'logo', alt: 'Acme', width: 72, align: 'left', radius: 0, pad: [8, 28, 8, 28] })], [block('menu')]], { pad: [12, 12] })],
    hero: ['Hero', 'Image, headline, button', () => row([1], [[block('image', { alt: 'The new Acme dashboard' }), block('heading', { html: 'Your dashboard just got faster', size: 30 }), block('text', { html: 'Hi {{first_name|"there"}}, three things you asked for are live: instant search, shared views and a new activity timeline.' }), block('button', { label: 'See what’s new' })]], { pad: [8, 16] })],
    features: ['Features', 'Three columns with icons', () => row([1, 1, 1], [['f1', 'Instant search', 'Find any record in milliseconds.'], ['f2', 'Shared views', 'Save a view, share it with your team.'], ['f3', 'Activity timeline', 'See every change, in order.']].map(([src, h, t]) => [block('image', { src, alt: '', width: 34, align: 'left', radius: 0, pad: [8, 16, 4, 16] }), block('heading', { html: h, size: 16, pad: [4, 16, 2, 16] }), block('text', { html: t, size: 14, pad: [2, 16, 8, 16] })]), { pad: [16, 12] })],
    products: ['Product grid', 'Two products', () => row([1, 1], [[block('product')], [block('product', { src: 'p2', name: 'Travel case', price: '$58', href: 'https://acme-corp.io/shop/case' })]], { pad: [12, 12] })],
    testimonial: ['Testimonial', 'Quote with photo', () => row([1], [[block('quote')]], { bg: '#FAFAFA', pad: [16, 16] })],
    cta: ['Call to action', 'Coloured band', () => row([1], [[block('heading', { html: 'Ready when you are', size: 24, align: 'center', color: '#FFFFFF' }), block('text', { html: 'Start a 14-day trial. No card needed.', align: 'center', color: '#E0E0FF' }), block('button', { label: 'Start free trial', align: 'center', bg: '#FFFFFF', color: '#4343D6' })]], { bg: '#5B5BF7', pad: [28, 28] })],
    coupon: ['Offer', 'Coupon code', () => row([1], [[block('heading', { html: 'A little thank-you', size: 22, align: 'center' }), block('coupon')]], { pad: [16, 16] })],
    footer: ['Footer', 'Social, address, unsubscribe', () => row([1], [[block('social'), block('footer')]], { bg: '#FAFAFA', pad: [16, 16] })],
  };
  const DEFAULT_SETTINGS = { width: 600, bodyBg: '#F4F4F5', contentBg: '#FFFFFF', font: 'Helvetica, Arial, sans-serif', text: '#3F3F46', heading: '#18181B', link: '#5B5BF7', btn: '#5B5BF7', radius: 8, preheader: '' };
  const newDoc = (accent) => {
    const d = { settings: Object.assign({}, DEFAULT_SETTINGS), rows: ['header', 'hero', 'features', 'cta', 'footer'].map((k) => SECTIONS[k][2]()) };
    if (accent) { d.settings.btn = accent; d.settings.link = accent; const cta = d.rows[3]; cta.bg = accent; }
    return d;
  };
  const blankDoc = () => ({ settings: Object.assign({}, DEFAULT_SETTINGS), rows: [SECTIONS.header[2](), row([1], [[]], { pad: [24, 24] }), SECTIONS.footer[2]()] });
  S.EB = { block, row, SECTIONS, DEFAULT_SETTINGS, PHOTOS };
  S.newEmailDoc = newDoc; S.blankEmailDoc = blankDoc;
  S.htmlEmailDoc = (code) => ({ settings: Object.assign({}, DEFAULT_SETTINGS), rows: [row([1], [[block('html', { code, pad: [0, 0, 0, 0] })]], { pad: [0, 0] })] });

  S.emailToHtml = toHtml;
  S.emailChecks = checks;

  // ─── Persistence (per browser; the backend phase stores it server-side) ───
  const KEY = 'sl-email-doc';
  S.loadEmailDoc = () => { if (S.emailDoc) return S.emailDoc; try { const j = localStorage.getItem(KEY); if (j) S.emailDoc = JSON.parse(j); } catch (_) {} return S.emailDoc || null; };
  S.saveEmailDoc = (d) => { S.emailDoc = d; try { d ? localStorage.setItem(KEY, JSON.stringify(d)) : localStorage.removeItem(KEY); } catch (_) {} };

  // ═══════ The builder page ═══════
  pages['campaigns/design'] = (v) => {
    let doc = JSON.parse(JSON.stringify(S.loadEmailDoc() || newDoc()));
    const s = () => doc.settings;
    let sel = null, tab = 'blocks', device = 'desktop', dark = false, view = 'design';
    const past = [], future = [];
    const commit = () => { past.push(JSON.stringify(doc)); if (past.length > 80) past.shift(); future.length = 0; S.saveEmailDoc(JSON.parse(JSON.stringify(doc))); updateUndo(); };
    const snapshot = JSON.stringify(doc); past.push(snapshot);
    const find = (id) => { for (const r of doc.rows) { if (r.id === id) return { row: r }; for (let c = 0; c < r.cells.length; c++) { const i = r.cells[c].findIndex((b) => b.id === id); if (i > -1) return { row: r, cell: r.cells[c], c, i, block: r.cells[c][i] }; } } return null; };
    const draft = S.campaignDraft || {};

    v.innerHTML = `<div class="eb">
      <header class="jx-bar eb-bar"><a class="jx-back" href="${S.editingTemplate ? '#/templates?tab=mine' : '#/campaigns/new'}" title="${S.editingTemplate ? 'Back to templates' : 'Back to the campaign'}">${ic('right', 16)}</a><div class="jx-name"><b class="eb-title">${esc(S.editingTemplate ? S.editingTemplate.name : (draft.name || 'November product newsletter'))}</b><small id="ebsaved">${S.editingTemplate ? 'Template · saved' : 'Email design · saved'}</small></div>
        <div class="eb-undo"><button class="icon-btn" id="undo" title="Undo (⌘Z)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg></button><button class="icon-btn" id="redo" title="Redo (⇧⌘Z)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg></button></div>
        <div class="spacer"></div>
        <div class="seg" id="views"><button data-view="design" class="on">Design</button><button data-view="html">HTML</button></div>
        <div class="seg" id="devs"><button data-dev="desktop" class="on" title="Desktop">${ic('overview', 14)}Desktop</button><button data-dev="mobile" title="Mobile">${ic('send', 14)}Mobile</button></div>
        <label class="jx-live" title="Simulate dark-mode colour inversion (Gmail app, Outlook)"><input type="checkbox" id="darkt"><span class="switch-sm"></span>Dark</label>
        <button class="btn" id="prev">${ic('eye', 15)}Preview & test</button>
        <button class="btn btn-primary" id="done">Save & exit</button>
      </header>
      <div class="eb-body">
        <aside class="eb-left"><nav class="eb-rail">${[['blocks', 'plus', 'Content'], ['rows', 'split', 'Rows'], ['sections', 'templates', 'Sections'], ['styles', 'edit', 'Styles']].map(([k, i, l]) => `<button data-tab="${k}" class="${tab === k ? 'on' : ''}">${ic(i, 18)}<span>${l}</span></button>`).join('')}</nav><div class="eb-lib" id="lib"></div></aside>
        <main class="eb-stage" id="stage"><div class="eb-stage-in" id="stagein"></div></main>
        <aside class="eb-right" id="props"></aside>
      </div></div>`;
    document.body.classList.add('canvas-mode');

    // ─── Left library ───
    function renderLib() {
      $$('.eb-rail button', v).forEach((b) => b.classList.toggle('on', b.dataset.tab === tab));
      const lib = $('#lib');
      if (tab === 'blocks') {
        const groups = {}; Object.entries(BLOCKS).forEach(([k, [n, i, g]]) => (groups[g] = groups[g] || []).push([k, n, i]));
        lib.innerHTML = `<div class="lib-head"><b>Content</b><small>Drag onto the email, or click to add below the selection</small></div>${Object.entries(groups).map(([g, items]) => `<div class="lib-group">${g}</div><div class="lib-grid">${items.map(([k, n, i]) => `<button class="lib-tile" draggable="true" data-add="${k}">${blockGlyph(k)}<span>${n}</span></button>`).join('')}</div>`).join('')}`;
      } else if (tab === 'rows') {
        lib.innerHTML = `<div class="lib-head"><b>Rows</b><small>Column layouts. Drop a row, then drop content into its columns.</small></div><div class="lib-rows">${LAYOUTS.map((l, i) => `<button class="lib-row" draggable="true" data-row="${i}"><span class="lr-cols">${l.map((w) => `<i style="flex:${w}"></i>`).join('')}</span><small>${l.length === 1 ? 'One column' : l.join(' : ')}</small></button>`).join('')}</div>
          <div class="lib-note">${ic('info', 13)} Columns stack on phones unless you turn it off for a row.</div>`;
      } else if (tab === 'sections') {
        lib.innerHTML = `<div class="lib-head"><b>Sections</b><small>Ready-made rows with content. Saved sections update everywhere they're used.</small></div>${Object.entries(SECTIONS).map(([k, [n, d]]) => `<button class="lib-sec" draggable="true" data-sec="${k}"><span class="ls-thumb">${sectionThumb(k)}</span><span><b>${n}</b><small>${d}</small></span></button>`).join('')}`;
      } else {
        const st = s();
        lib.innerHTML = `<div class="lib-head"><b>Styles</b><small>Defaults for the whole email. Blocks can override them.</small></div>
          <div class="brand-pull"><span>${ic('copilot', 15, 2)}</span><div><b>Use your brand</b><small>Pull logo, colours and font from acme-corp.io</small></div><button class="btn btn-sm" id="brand">Apply</button></div>
          ${field('Preview text', `<input data-set="preheader" value="${esc(st.preheader)}" placeholder="Shown after the subject in the inbox">`)}
          ${field('Content width', `<div class="eb-seg">${[560, 600, 640].map((w) => `<button data-setv="width" data-val="${w}" class="${st.width === w ? 'on' : ''}">${w}px</button>`).join('')}</div>`)}
          ${field('Font', `<select data-set="font">${['Helvetica, Arial, sans-serif', 'Georgia, Times, serif', 'Verdana, Geneva, sans-serif', 'Trebuchet MS, sans-serif', '"Courier New", monospace'].map((f) => `<option value='${f}' ${st.font === f ? 'selected' : ''}>${f.split(',')[0].replace(/"/g, '')}</option>`).join('')}</select>`, 'Email-safe fonts only, so it looks the same in Outlook.')}
          <div class="color-grid">${[['bodyBg', 'Background'], ['contentBg', 'Email body'], ['heading', 'Headings'], ['text', 'Text'], ['link', 'Links'], ['btn', 'Buttons']].map(([k, l]) => colorField(l, k, st[k], 'set')).join('')}</div>
          ${field('Button corners', `<input type="range" min="0" max="24" data-set="radius" value="${st.radius}"><span class="range-v">${st.radius}px</span>`)}`;
      }
      bindLib();
    }
    const blockGlyph = (k) => {
      const G = { heading: '<b style="font-size:17px;font-weight:800">H</b>', text: '<span class="g-lines"><i></i><i></i><i style="width:60%"></i></span>', image: '<span class="g-img"></span>', button: '<span class="g-btn"></span>', divider: '<span class="g-div"></span>', spacer: '<span class="g-sp">↕</span>', social: '<span class="g-soc"><i></i><i></i><i></i></span>', video: '<span class="g-img g-vid">▶</span>', product: '<span class="g-prod"><i></i><b></b></span>', coupon: '<span class="g-coupon">10%</span>', quote: '<b style="font-size:22px;font-family:Georgia">“</b>', menu: '<span class="g-menu"><i></i><i></i><i></i></span>', html: '<b class="mono" style="font-size:12px">&lt;/&gt;</b>', footer: '<span class="g-foot"><i></i><i style="width:70%"></i></span>' };
      return `<span class="lt-g">${G[k] || ''}</span>`;
    };
    const sectionThumb = (k) => ({ header: '<i class="st-logo"></i><i class="st-menu"></i>', hero: '<i class="st-hero"></i><i class="st-h"></i><i class="st-btn"></i>', features: '<i class="st-3"></i><i class="st-3"></i><i class="st-3"></i>', products: '<i class="st-2"></i><i class="st-2"></i>', testimonial: '<i class="st-q"></i>', cta: '<i class="st-cta"></i>', coupon: '<i class="st-cp"></i>', footer: '<i class="st-ft"></i>' })[k] || '';
    const field = (label, ctrl, help) => `<div class="ebf"><label>${label}</label><div class="ebf-c">${ctrl}</div>${help ? `<small>${help}</small>` : ''}</div>`;
    const colorField = (label, key, val, scope) => `<label class="cf"><input type="color" data-${scope}="${key}" value="${val || '#ffffff'}"><span><b>${label}</b><small class="mono">${(val || 'default').toUpperCase()}</small></span></label>`;

    function bindLib() {
      $$('[data-add]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'block', type: b.dataset.add }); b.onclick = () => addBlockNearSelection(b.dataset.add); });
      $$('[data-row]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'row', layout: LAYOUTS[+b.dataset.row] }); b.onclick = () => { doc.rows.push(row(LAYOUTS[+b.dataset.row])); commit(); renderStage(); scrollToEnd(); }; });
      $$('[data-sec]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'section', key: b.dataset.sec }); b.onclick = () => { const r = SECTIONS[b.dataset.sec][2](); const idx = sel ? Math.max(0, doc.rows.findIndex((x) => x.id === (find(sel) || {}).row?.id)) + 1 : doc.rows.length - (doc.rows.at(-1)?.cells.flat().some((x) => x.type === 'footer') ? 1 : 0); doc.rows.splice(idx, 0, r); commit(); select(r.id); }; });
      $$('[data-set]', v).forEach((inp) => { const ev = inp.type === 'range' || inp.tagName === 'INPUT' ? 'input' : 'change'; inp.addEventListener(ev, () => { const k = inp.dataset.set; s()[k] = inp.type === 'range' ? +inp.value : inp.value; if (inp.type === 'range') inp.nextElementSibling.textContent = inp.value + 'px'; if (inp.type === 'color') inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); renderStage(true); }); inp.addEventListener('change', commit); });
      $$('[data-setv]', v).forEach((b) => b.onclick = () => { s()[b.dataset.setv] = +b.dataset.val; commit(); renderLib(); renderStage(); });
      const br = $('#brand'); if (br) br.onclick = () => { Object.assign(s(), { btn: '#5B5BF7', link: '#4343D6', heading: '#18181B', font: 'Helvetica, Arial, sans-serif' }); commit(); renderLib(); renderStage(); toast('Applied brand from acme-corp.io: logo, #5B5BF7, Helvetica'); };
    }
    function addBlockNearSelection(type) {
      const nb = block(type), f = sel && find(sel);
      if (f && f.block) f.cell.splice(f.i + 1, 0, nb);
      else if (f && f.row) f.row.cells[0].push(nb);
      else { const body = doc.rows.filter((r) => !r.cells.flat().some((b) => b.type === 'footer')).at(-1); if (body) body.cells[0].push(nb); else doc.rows.push(row([1], [[nb]])); }
      commit(); select(nb.id);
    }

    // ─── Stage ───
    function renderStage(light) {
      const st = s(), mob = device === 'mobile';
      const stage = $('#stagein');
      if (view === 'html') { const h = toHtml(doc); stage.innerHTML = `<div class="eb-code"><div class="ec-head"><b>HTML</b><span class="faint">${(new Blob([h]).size / 1024).toFixed(1)} KB · table-based, Outlook-safe, columns stack on phones</span><button class="btn btn-sm" data-copy="${esc(h)}">${ic('copy', 13)}Copy</button><button class="btn btn-sm" id="dl">${ic('download', 13)}Download .html</button></div><pre>${esc(h)}</pre></div>`; S.bindCopy(stage); $('#dl').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([h], { type: 'text/html' })); a.download = 'email.html'; a.click(); }; return; }
      stage.innerHTML = `<div class="eb-frame ${mob ? 'mobile' : ''} ${dark ? 'dark' : ''}" style="background:${st.bodyBg}">
        ${mob ? '<div class="eb-phone-top"><i></i></div>' : `<div class="eb-inbox-head"><span class="eb-av">A</span><div><b>${esc(draft.from || 'Acme')}</b> <span class="faint">&lt;${esc(draft.fromEmail || 'news@acme-corp.io')}&gt;</span><div class="eb-subj">${esc(draft.subject || 'Your subject line')} <span class="faint">— ${esc(st.preheader || 'preview text')}</span></div></div></div>`}
        <div class="eb-email" id="email" style="width:${mob ? 375 : st.width}px;background:${st.contentBg};font-family:${st.font}">
        ${doc.rows.map((r, ri) => { const tot = r.layout.reduce((a, b) => a + b, 0); const stack = mob && r.stack; const hidden = (mob && r.hide === 'mobile') || (!mob && r.hide === 'desktop');
          return `<div class="eb-row ${sel === r.id ? 'sel' : ''} ${hidden ? 'hidden-here' : ''}" data-r="${r.id}" style="background:${r.bg || 'transparent'};padding:${r.pad[0]}px 0 ${r.pad[1]}px">
            <div class="eb-row-tools"><span class="rt-drag" draggable="true" data-mrow="${r.id}" title="Drag to move">${ic('menu', 13)}</span><span class="rt-label">Row ${ri + 1} · ${r.layout.length === 1 ? '1 column' : r.layout.join(':')}${r.hide ? ' · hidden on ' + r.hide : ''}</span><button data-rup="${r.id}" title="Move up">↑</button><button data-rdown="${r.id}" title="Move down">↓</button><button data-rdup="${r.id}" title="Duplicate">${ic('copy', 12)}</button><button data-rsave="${r.id}" title="Save as section">${ic('download', 12)}</button><button data-rdel="${r.id}" title="Delete" class="danger">${ic('trash', 12)}</button></div>
            ${hidden ? `<div class="eb-hidden-note">Hidden on ${r.hide}</div>` : ''}
            <div class="eb-cols" style="${stack ? 'flex-direction:column' : ''}">${r.cells.map((cell, ci) => `<div class="eb-cell" data-r="${r.id}" data-c="${ci}" style="flex:${stack ? '1 1 auto' : r.layout[ci] / tot}">${cell.length ? cell.map((b) => `<div class="eb-blk ${sel === b.id ? 'sel' : ''}" data-b="${b.id}"><div class="eb-blk-tools"><span class="bt-name">${BLOCKS[b.type][0]}</span><span class="bt-drag" draggable="true" data-mblk="${b.id}" title="Drag to move">${ic('menu', 12)}</span><button data-bdup="${b.id}" title="Duplicate">${ic('copy', 12)}</button><button data-bdel="${b.id}" title="Delete" class="danger">${ic('trash', 12)}</button></div>${S.safeHtml(blockHtml(b, st, 'stage'))}</div>`).join('') : `<div class="eb-empty-cell">${ic('plus', 16)}<span>Drop content here</span></div>`}</div>`).join('')}</div></div>`; }).join('')}
        <div class="eb-add-row" id="addrow">${ic('plus', 14)}Add a row</div></div></div>
        <div class="eb-ind" id="ind"></div>`;
      bindStage();
      if (!light) renderProps();
      else renderChecksOnly();
    }
    const scrollToEnd = () => { const st = $('#stage'); st.scrollTo({ top: st.scrollHeight, behavior: 'smooth' }); };

    function bindStage() {
      const stage = $('#stagein');
      stage.onclick = (e) => {
        const t = e.target;
        if (t.closest('a') && !t.closest('.eb-edit')) e.preventDefault();
        const btn = t.closest('button');
        if (btn) {
          const d = btn.dataset;
          if (d.bdel) { const f = find(d.bdel); f.cell.splice(f.i, 1); sel = null; commit(); return renderStage(); }
          if (d.bdup) { const f = find(d.bdup); const c = JSON.parse(JSON.stringify(f.block)); c.id = uid('b'); f.cell.splice(f.i + 1, 0, c); commit(); return select(c.id); }
          if (d.rdel) { doc.rows = doc.rows.filter((r) => r.id !== d.rdel); sel = null; commit(); return renderStage(); }
          if (d.rdup) { const i = doc.rows.findIndex((r) => r.id === d.rdup); const c = JSON.parse(JSON.stringify(doc.rows[i])); c.id = uid('r'); c.cells.forEach((cell) => cell.forEach((b) => (b.id = uid('b')))); doc.rows.splice(i + 1, 0, c); commit(); return select(c.id); }
          if (d.rup || d.rdown) { const id = d.rup || d.rdown, i = doc.rows.findIndex((r) => r.id === id), j = d.rup ? i - 1 : i + 1; if (j < 0 || j >= doc.rows.length) return; [doc.rows[i], doc.rows[j]] = [doc.rows[j], doc.rows[i]]; commit(); return renderStage(); }
          if (d.rsave) return toast('Saved as a section. Find it under Sections → Saved');
        }
        if (t.closest('#addrow')) { doc.rows.splice(doc.rows.length - (doc.rows.at(-1)?.cells.flat().some((b) => b.type === 'footer') ? 1 : 0), 0, row([1])); commit(); tab = 'blocks'; renderLib(); return renderStage(); }
        const bEl = t.closest('.eb-blk'); if (bEl) { if (sel !== bEl.dataset.b) select(bEl.dataset.b); return; }
        const rEl = t.closest('.eb-row'); if (rEl) return select(rEl.dataset.r);
        select(null);
      };
      // inline editing for text-like blocks
      $$('.eb-blk.sel .eb-edit', stage).forEach((el) => {
        el.contentEditable = 'true'; el.spellcheck = true;
        el.addEventListener('input', () => { const f = find(sel); if (!f || !f.block) return; if (f.block.type === 'button') f.block.props.label = el.textContent; else f.block.props.html = serializeEdit(el); $('#ebsaved').textContent = 'Email design · editing…'; });
        el.addEventListener('blur', () => { commit(); $('#ebsaved').textContent = 'Email design · saved'; renderChecksOnly(); });
        el.addEventListener('focus', () => showFormatBar(el));
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && el.closest('.eb-edit-btn')) e.preventDefault(); });
      });
      // drag existing rows / blocks
      $$('[data-mrow]', stage).forEach((h) => (h.ondragstart = (e) => startDrag(e, { kind: 'mrow', id: h.dataset.mrow })));
      $$('[data-mblk]', stage).forEach((h) => (h.ondragstart = (e) => startDrag(e, { kind: 'mblk', id: h.dataset.mblk })));
    }
    const serializeEdit = (el) => { const c = el.cloneNode(true); $$('.mtag', c).forEach((m) => m.replaceWith(document.createTextNode(m.dataset.tag))); return c.innerHTML; };

    // floating format bar for text editing
    function showFormatBar(el) {
      let bar = $('#fmt'); if (!bar) { bar = document.createElement('div'); bar.id = 'fmt'; bar.className = 'fmt-bar'; $('#stagein').appendChild(bar); }
      const isBtn = !!el.closest('.eb-edit-btn');
      bar.innerHTML = isBtn ? `<span class="fmt-note">Editing button label</span>` : `<button data-cmd="bold" title="Bold"><b>B</b></button><button data-cmd="italic" title="Italic"><i>I</i></button><button data-cmd="underline" title="Underline"><u>U</u></button><button data-cmd="link" title="Link">${ic('link', 13)}</button><span class="fmt-sep"></span><button data-merge="1" title="Insert personalization">{{ }} Personalize</button>`;
      const r = el.getBoundingClientRect(), base = $('#stagein').getBoundingClientRect();
      bar.style.left = Math.max(8, r.left - base.left) + 'px'; bar.style.top = (r.top - base.top - 44) + 'px'; bar.classList.add('on');
      $$('[data-cmd]', bar).forEach((b) => b.onmousedown = (e) => { e.preventDefault(); if (b.dataset.cmd === 'link') { const u = prompt('Link URL', 'https://'); if (u) document.execCommand('createLink', false, u); } else document.execCommand(b.dataset.cmd); el.dispatchEvent(new Event('input')); });
      const mg = $('[data-merge]', bar); if (mg) mg.onmousedown = (e) => { e.preventDefault(); const menu = document.createElement('div'); menu.className = 'merge-menu'; menu.innerHTML = [['first_name', 'there'], ['last_name', ''], ['company', 'your team'], ['plan', 'your plan']].map(([k, fb]) => `<button data-k="${k}" data-fb="${fb}"><span class="mono">{{${k}}}</span><small>${fb ? 'fallback "' + fb + '"' : 'no fallback'}</small></button>`).join(''); bar.appendChild(menu); $$('button', menu).forEach((m) => m.onmousedown = (ev) => { ev.preventDefault(); const tag = `{{${m.dataset.k}${m.dataset.fb ? '|"' + m.dataset.fb + '"' : ''}}}`; document.execCommand('insertHTML', false, `<span class="mtag" contenteditable="false" data-tag='${tag}'>${m.dataset.k.replace(/_/g, ' ')}</span>&nbsp;`); el.dispatchEvent(new Event('input')); menu.remove(); }); };
      el.addEventListener('blur', () => setTimeout(() => bar.classList.remove('on'), 150), { once: true });
    }

    // ─── Drag & drop with a live drop indicator ───
    let dragging = null;
    function startDrag(e, payload) { dragging = payload; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', payload.kind); document.body.classList.add('eb-dragging'); }
    const stageEl = () => $('#stage');
    function dropTarget(e) {
      const isRow = dragging && ['row', 'section', 'mrow'].includes(dragging.kind);
      const email = $('#email'); if (!email) return null;
      if (!isRow) {
        const cell = e.target.closest && e.target.closest('.eb-cell');
        if (cell) { const blks = $$(':scope > .eb-blk', cell); let i = blks.length; for (let k = 0; k < blks.length; k++) { const r = blks[k].getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) { i = k; break; } } const ref = blks[i] || blks[blks.length - 1]; const rr = cell.getBoundingClientRect(); const y = blks.length ? (blks[i] ? blks[i].getBoundingClientRect().top : ref.getBoundingClientRect().bottom) : rr.top + rr.height / 2; return { kind: 'cell', r: cell.dataset.r, c: +cell.dataset.c, i, x: rr.left, w: rr.width, y }; }
      }
      const rows = $$('.eb-row', email); let i = rows.length; for (let k = 0; k < rows.length; k++) { const r = rows[k].getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) { i = k; break; } }
      const er = email.getBoundingClientRect(); const y = rows[i] ? rows[i].getBoundingClientRect().top : (rows.length ? rows[rows.length - 1].getBoundingClientRect().bottom : er.top + 20);
      return { kind: 'rows', i, x: er.left, w: er.width, y };
    }
    function showInd(t) { const ind = $('#ind'), base = $('#stagein').getBoundingClientRect(); if (!t) return ind.classList.remove('on'); ind.style.left = (t.x - base.left) + 'px'; ind.style.width = t.w + 'px'; ind.style.top = (t.y - base.top - 2) + 'px'; ind.className = 'eb-ind on ' + t.kind; ind.dataset.label = t.kind === 'rows' ? (dragging.kind === 'mrow' ? 'Move row here' : dragging.kind === 'section' ? 'Add section here' : 'New row here') : 'Drop here'; }
    const stg = $('#stage');
    stg.addEventListener('dragover', (e) => { if (!dragging) return; e.preventDefault(); showInd(dropTarget(e)); });
    stg.addEventListener('dragleave', (e) => { if (!stg.contains(e.relatedTarget)) showInd(null); });
    stg.addEventListener('drop', (e) => {
      if (!dragging) return; e.preventDefault(); const t = dropTarget(e); showInd(null); const d = dragging; dragging = null; document.body.classList.remove('eb-dragging'); if (!t) return;
      let newSel = null;
      if (d.kind === 'block' || d.kind === 'mblk') {
        let b;
        if (d.kind === 'block') b = block(d.type); else { const f = find(d.id); b = f.block; f.cell.splice(f.i, 1); if (t.kind === 'cell' && f.row.id === t.r && f.c === t.c && f.i < t.i) t.i--; }
        if (t.kind === 'cell') find(t.r).row.cells[t.c].splice(t.i, 0, b); else doc.rows.splice(t.i, 0, row([1], [[b]]));
        newSel = b.id;
      } else if (d.kind === 'row' || d.kind === 'section') { const r = d.kind === 'row' ? row(d.layout) : SECTIONS[d.key][2](); doc.rows.splice(t.i, 0, r); newSel = r.id; }
      else if (d.kind === 'mrow') { const i = doc.rows.findIndex((r) => r.id === d.id); const [r] = doc.rows.splice(i, 1); doc.rows.splice(t.i > i ? t.i - 1 : t.i, 0, r); newSel = r.id; }
      commit(); select(newSel);
    });
    document.addEventListener('dragend', () => { dragging = null; document.body.classList.remove('eb-dragging'); const ind = $('#ind'); if (ind) ind.classList.remove('on'); });

    // ─── Right: properties, or checks + outline when nothing is selected ───
    function select(id) { sel = id; renderStage(); const el = id && $(`[data-b="${id}"], [data-r="${id}"].eb-row`); if (el) { const ed = $('.eb-edit', el); if (ed && el.classList.contains('eb-blk') && find(id).block && ['text', 'heading', 'quote'].includes(find(id).block.type)) setTimeout(() => ed.focus(), 0); } }
    const padCtl = (p) => `<div class="pad4">${['Top', 'Right', 'Bottom', 'Left'].map((l, i) => `<label><input type="number" min="0" max="80" data-pad="${i}" value="${p[i]}"><small>${l[0]}</small></label>`).join('')}</div>`;
    const alignCtl = (val, key = 'align') => `<div class="eb-seg">${['left', 'center', 'right'].map((a) => `<button data-pv="${key}" data-val="${a}" class="${val === a ? 'on' : ''}" title="${a}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">${a === 'left' ? '<path d="M4 6h16M4 12h10M4 18h13"/>' : a === 'center' ? '<path d="M4 6h16M7 12h10M5 18h14"/>' : '<path d="M4 6h16M10 12h10M7 18h13"/>'}</svg></button>`).join('')}</div>`;
    function renderProps() {
      const pane = $('#props'), f = sel && find(sel);
      if (!f) return renderChecks(pane);
      if (f.block) {
        const b = f.block, p = b.props, st = s(); let h = '';
        const t = b.type;
        if (t === 'heading' || t === 'text') h += field('Size', `<input type="range" min="${t === 'heading' ? 16 : 12}" max="${t === 'heading' ? 44 : 22}" data-p="size" value="${p.size}"><span class="range-v">${p.size}px</span>`) + field('Alignment', alignCtl(p.align)) + `<div class="color-grid one">${colorField('Text colour', 'color', p.color || (t === 'heading' ? st.heading : st.text), 'p')}</div>` + `<p class="ebf-tip">Click the text on the email to edit it. Use <b>{{ }} Personalize</b> to add the contact's name.</p>`;
        if (t === 'image' || t === 'video') h += (S.mediaGrid ? S.mediaGrid(p.src) : '') + `<div class="ebf"><label>Graphics</label><div class="asset-grid">${Object.entries(ASSETS).map(([k, [n, u]]) => `<button data-pv="src" data-val="${k}" class="${p.src === k ? 'on' : ''}" title="${n}"><img src="${u}" alt=""></button>`).join('')}<button class="asset-up" title="Upload">${ic('upload', 16)}</button></div></div><div class="ebf"><label>Photos <span class="faint" style="font-weight:500">· free to use</span></label><div class="asset-grid photos">${PHOTOS.filter((n) => !/-sq$/.test(n) || t === 'image').map((n) => `<button data-pv="src" data-val="img/${n}.jpg" class="${p.src === 'img/' + n + '.jpg' ? 'on' : ''}" title="${n}"><img src="img/${n}.jpg" loading="lazy" alt=""></button>`).join('')}</div></div>` + field('Alt text', `<input data-p="alt" value="${esc(p.alt)}" placeholder="Describe the image">`, p.alt ? 'Read aloud by screen readers and shown when images are blocked.' : '<span style="color:var(--s-deferred)">Missing. Shown when images are blocked, and read by screen readers.</span>') + field('Link', `<input data-p="href" value="${esc(p.href)}" placeholder="https://">`) + (t === 'image' ? field('Width', `<input type="range" min="20" max="100" data-p="width" value="${p.width}"><span class="range-v">${p.width}%</span>`) + field('Alignment', alignCtl(p.align)) + field('Corners', `<input type="range" min="0" max="32" data-p="radius" value="${p.radius}"><span class="range-v">${p.radius}px</span>`) : '');
        if (t === 'button') h += field('Label', `<input data-p="label" value="${esc(p.label)}">`) + field('Link', `<input data-p="href" value="${esc(p.href)}" placeholder="https://">`, /^https?:\/\/[^/]+\.[a-z]{2,}/i.test(p.href) ? '' : '<span style="color:var(--s-deferred)">Set a real URL before sending.</span>') + `<div class="color-grid">${colorField('Button', 'bg', p.bg || st.btn, 'p')}${colorField('Label', 'color', p.color, 'p')}</div>` + field('Alignment', alignCtl(p.align)) + field('Corners', `<input type="range" min="0" max="30" data-p="radius" value="${p.radius == null ? st.radius : p.radius}"><span class="range-v">${p.radius == null ? st.radius : p.radius}px</span>`) + `<label class="check"><input type="checkbox" data-pc="full" ${p.full ? 'checked' : ''}> Full width</label>`;
        if (t === 'divider') h += `<div class="color-grid one">${colorField('Line', 'color', p.color, 'p')}</div>` + field('Thickness', `<input type="range" min="1" max="6" data-p="thick" value="${p.thick}"><span class="range-v">${p.thick}px</span>`) + field('Width', `<input type="range" min="10" max="100" data-p="width" value="${p.width}"><span class="range-v">${p.width}%</span>`);
        if (t === 'spacer') h += field('Height', `<input type="range" min="8" max="96" data-p="h" value="${p.h}"><span class="range-v">${p.h}px</span>`);
        if (t === 'social') h += `<div class="ebf"><label>Networks</label><div class="soc-pick">${Object.keys(SOCIAL).map((k) => `<label class="${p.nets.includes(k) ? 'on' : ''}"><input type="checkbox" data-net="${k}" ${p.nets.includes(k) ? 'checked' : ''}>${socialIcon(k, 22)}<span>${SOCIAL[k][0]}</span></label>`).join('')}</div></div>` + field('Alignment', alignCtl(p.align)) + field('Icon size', `<input type="range" min="20" max="44" data-p="size" value="${p.size}"><span class="range-v">${p.size}px</span>`);
        if (t === 'product') h += `<div class="ebf"><label>Image</label><div class="asset-grid">${['p1', 'p2'].map((k) => `<button data-pv="src" data-val="${k}" class="${p.src === k ? 'on' : ''}"><img src="${ASSETS[k][1]}" alt=""></button>`).join('')}</div></div>` + field('Name', `<input data-p="name" value="${esc(p.name)}">`) + field('Price', `<input data-p="price" value="${esc(p.price)}">`) + field('Button', `<input data-p="btn" value="${esc(p.btn)}">`) + field('Link', `<input data-p="href" value="${esc(p.href)}">`) + `<p class="ebf-tip">${ic('card', 13)} With Shopify or WooCommerce connected, pick products and prices fill in automatically.</p>`;
        if (t === 'coupon') h += field('Code', `<input class="mono" data-p="code" value="${esc(p.code)}">`) + field('Note', `<input data-p="note" value="${esc(p.note)}">`) + `<label class="check"><input type="checkbox"> Generate a unique code per contact</label>`;
        if (t === 'quote') h += field('Person', `<input data-p="who" value="${esc(p.who)}">`) + `<p class="ebf-tip">Click the quote on the email to edit it.</p>`;
        if (t === 'menu') h += field('Items', `<input data-p="items" value="${esc(p.items)}">`, 'Comma separated') + field('Alignment', alignCtl(p.align));
        if (t === 'html') h += field('HTML', `<textarea class="mono" data-p="code" rows="10">${esc(p.code)}</textarea>`, 'Inline styles only; most inboxes strip &lt;style&gt; and scripts.');
        if (t === 'footer') h += field('Company', `<input data-p="company" value="${esc(p.company)}">`) + field('Postal address', `<input data-p="address" value="${esc(p.address)}">`, 'Required by anti-spam law (CAN-SPAM).') + field('Why they get this', `<input data-p="note" value="${esc(p.note)}">`) + `<div class="ebf-lock">${ic('shield', 13)} Unsubscribe and preference links are always included and can't be removed.</div>`;
        if (p.pad) h += field('Padding', padCtl(p.pad));
        pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic">${blockGlyph(t)}</span><div><small>Block</small><b>${BLOCKS[t][0]}</b></div><button class="icon-btn" id="pclose">${ic('x', 15)}</button></div><div class="ebpp-body">${h}</div><div class="ebpp-foot"><button class="btn btn-sm" data-act="dup">${ic('copy', 13)}Duplicate</button><button class="btn btn-sm" data-act="del">${ic('trash', 13)}Delete</button><button class="btn btn-sm" data-act="row" style="margin-left:auto">Select row</button></div>`;
        bindProps(pane, p, f);
      } else {
        const r = f.row;
        pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic">${ic('split', 15)}</span><div><small>Row</small><b>${r.layout.length === 1 ? 'One column' : r.layout.join(' : ') + ' columns'}</b></div><button class="icon-btn" id="pclose">${ic('x', 15)}</button></div><div class="ebpp-body">
          <div class="ebf"><label>Columns</label><div class="lay-pick">${LAYOUTS.map((l, i) => `<button data-lay="${i}" class="${l.join() === r.layout.join() ? 'on' : ''}">${l.map((w) => `<i style="flex:${w}"></i>`).join('')}</button>`).join('')}</div><small>Content moves into the remaining columns if you reduce them.</small></div>
          <div class="color-grid one">${colorField('Row background', 'bg', r.bg || s().contentBg, 'r')}</div>
          ${field('Padding', `<div class="pad4 pad2">${['Top', 'Bottom'].map((l, i) => `<label><input type="number" min="0" max="80" data-rpad="${i}" value="${r.pad[i]}"><small>${l}</small></label>`).join('')}</div>`)}
          <div class="ebf"><label>On phones</label><label class="check"><input type="checkbox" data-rc="stack" ${r.stack ? 'checked' : ''}> Stack columns</label></div>
          <div class="ebf"><label>Show on</label><div class="eb-seg">${[['', 'All devices'], ['mobile', 'Desktop only'], ['desktop', 'Mobile only']].map(([k, l]) => `<button data-rhide="${k}" class="${r.hide === k ? 'on' : ''}">${l}</button>`).join('')}</div></div></div>
          <div class="ebpp-foot"><button class="btn btn-sm" data-act="rdup">${ic('copy', 13)}Duplicate</button><button class="btn btn-sm" data-act="rsave">${ic('download', 13)}Save as section</button><button class="btn btn-sm" data-act="rdel">${ic('trash', 13)}</button></div>`;
        $$('[data-lay]', pane).forEach((b) => b.onclick = () => { const nl = LAYOUTS[+b.dataset.lay]; const cells = nl.map((_, i) => r.cells[i] || []); r.cells.slice(nl.length).forEach((c) => cells[nl.length - 1].push(...c)); r.layout = nl; r.cells = cells; commit(); renderStage(); });
        $$('[data-r]', pane).forEach((inp) => { inp.oninput = () => { r[inp.dataset.r] = inp.value; inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); renderStage(true); }; inp.onchange = commit; });
        $$('[data-rpad]', pane).forEach((inp) => { inp.oninput = () => { r.pad[+inp.dataset.rpad] = +inp.value; renderStage(true); }; inp.onchange = commit; });
        $$('[data-rc]', pane).forEach((inp) => inp.onchange = () => { r[inp.dataset.rc] = inp.checked; commit(); renderStage(); });
        $$('[data-rhide]', pane).forEach((b) => b.onclick = () => { r.hide = b.dataset.rhide; commit(); renderStage(); });
        $$('[data-act]', pane).forEach((b) => b.onclick = () => { const a = b.dataset.act; if (a === 'rdel') { doc.rows = doc.rows.filter((x) => x.id !== r.id); sel = null; commit(); renderStage(); } if (a === 'rdup') $(`[data-rdup="${r.id}"]`).click(); if (a === 'rsave') toast('Saved as a section'); });
      }
      const pc = $('#pclose'); if (pc) pc.onclick = () => select(null);
    }
    function bindProps(pane, p, f) {
      const live = () => renderStage(true);
      $$('[data-p]', pane).forEach((inp) => { inp.addEventListener('input', () => { const k = inp.dataset.p; p[k] = inp.type === 'range' || inp.type === 'number' ? +inp.value : inp.value; if (inp.type === 'range') inp.nextElementSibling.textContent = inp.value + (/(width)/.test(k) ? '%' : 'px'); if (inp.type === 'color') inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); live(); }); inp.addEventListener('change', () => { commit(); renderStage(); }); });
      $$('[data-pc]', pane).forEach((inp) => inp.onchange = () => { p[inp.dataset.pc] = inp.checked; commit(); renderStage(); });
      $$('[data-pv]', pane).forEach((b) => b.onclick = () => { p[b.dataset.pv] = b.dataset.val; commit(); renderStage(); });
      $$('[data-pad]', pane).forEach((inp) => { inp.oninput = () => { p.pad[+inp.dataset.pad] = +inp.value; live(); }; inp.onchange = commit; });
      $$('[data-net]', pane).forEach((inp) => inp.onchange = () => { p.nets = Object.keys(SOCIAL).filter((k) => $(`[data-net="${k}"]`, pane).checked); commit(); renderStage(); });
      $$('[data-act]', pane).forEach((b) => b.onclick = () => { const a = b.dataset.act; if (a === 'del') { f.cell.splice(f.i, 1); sel = null; commit(); renderStage(); } if (a === 'dup') $(`[data-bdup="${f.block.id}"]`).click(); if (a === 'row') select(f.row.id); });
      $$('.asset-up', pane).forEach((b) => b.onclick = () => (S.pickImage ? S.pickImage((url) => { p.src = url; commit(); select(sel); }) : toast('Uploading needs a signed-in workspace.')));
    }
    function renderChecks(pane) {
      const c = checks(doc), bad = c.list.filter((x) => x[1] !== 'ok').length;
      pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic" style="background:var(--accent-soft);color:var(--accent-text)">${ic('copilot', 15, 2)}</span><div><small>Copilot</small><b>Email checks</b></div><span class="ck-score ${bad ? 'warn' : 'ok'}">${bad ? bad + ' to fix' : 'All good'}</span></div>
        <div class="ebpp-body" id="ckbody">${checksHtml(c)}
        <div class="ebf" style="margin-top:18px"><label>Layers</label><div class="outline">${doc.rows.map((r, i) => `<button class="ol-row" data-sel="${r.id}">${ic('split', 12)}Row ${i + 1} <small>${r.layout.join(':')}</small></button>${r.cells.flat().map((b) => `<button class="ol-blk" data-sel="${b.id}">${BLOCKS[b.type][0]}<small>${esc(String(b.props.label || b.props.html || b.props.alt || b.props.name || '').replace(/<[^>]+>|\{\{[^}]+\}\}/g, '').slice(0, 26))}</small></button>`).join('')}`).join('')}</div></div></div>`;
      $$('[data-sel]', pane).forEach((b) => b.onclick = () => { select(b.dataset.sel); const el = $(`[data-b="${b.dataset.sel}"], .eb-row[data-r="${b.dataset.sel}"]`); el && el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
      $$('[data-fix]', pane).forEach((b) => b.onclick = () => { select(b.dataset.fix); const el = $(`[data-b="${b.dataset.fix}"]`); el && el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
      const fs = $('#fixstyles'); if (fs) fs.onclick = () => { tab = 'styles'; renderLib(); $('[data-set="preheader"]').focus(); };
    }
    const checksHtml = (c) => `<div class="ck-list">${c.list.map(([n, st, d, frac, fixId]) => `<div class="ck2 ${st}"><span class="ck2-ic">${st === 'ok' ? ic('check', 12, 3) : '!'}</span><div><b>${n}</b><small>${esc(d)}</small>${frac != null ? `<span class="ck2-bar"><span style="width:${Math.min(100, frac * 100)}%"></span><i style="left:${90 / 102 * 100}%"></i></span>` : ''}${fixId ? `<button class="link-btn" data-fix="${fixId}">Fix →</button>` : ''}${n === 'Preview text' && st !== 'ok' ? '<button class="link-btn" id="fixstyles" style="display:flex">Add preview text →</button>' : ''}</div></div>`).join('')}</div>`;
    function renderChecksOnly() { const b = $('#ckbody'); if (b && !sel) { const c = checks(doc); const list = $('.ck-list', b); if (list) list.outerHTML = checksHtml(c); } }

    // ─── Preview & test overlay ───
    function preview() {
      const o = document.createElement('div'); o.className = 'eb-prev';
      const st = s(); let dk = false;
      const render = () => { $('#pv-d', o).srcdoc = toHtml(doc, { preview: true, dark: dk, subject: draft.subject }); $('#pv-m', o).srcdoc = toHtml(doc, { preview: true, dark: dk, mobile: true, subject: draft.subject }); };
      o.innerHTML = `<div class="ep-bar"><b>Preview</b><span class="faint">${esc(draft.subject || 'Your subject line')} — ${esc(st.preheader || 'no preview text')}</span><div class="spacer"></div><label class="jx-live"><input type="checkbox" id="pvdark"><span class="switch-sm"></span>Dark mode</label><div class="ep-test"><input id="pvto" value="ayodele@acme-corp.io" aria-label="Send test to"><button class="btn btn-primary btn-sm" id="pvsend">${ic('send', 13)}Send test</button></div><button class="ep-close" id="pvx" title="Close preview">${ic('x', 15, 2.2)}<span>Close</span><kbd>Esc</kbd></button></div>
        <div class="ep-stage"><div class="ep-desk"><div class="ep-chrome"><i></i><i></i><i></i><span>Gmail · Desktop</span></div><iframe id="pv-d" sandbox></iframe></div><div class="ep-phone"><div class="ep-notch"></div><iframe id="pv-m" sandbox></iframe><span class="ep-cap">iPhone · Apple Mail</span></div></div>`;
      document.body.appendChild(o); render();
      $('#pvdark', o).onchange = (e) => { dk = e.target.checked; render(); };
      $('#pvx', o).onclick = () => o.remove();
      $('#pvsend', o).onclick = () => toast('Test sent to ' + $('#pvto', o).value + ' through your SES');
      const esc2 = (e) => { if (e.key === 'Escape') { o.remove(); document.removeEventListener('keydown', esc2); } }; document.addEventListener('keydown', esc2);
    }

    // ─── Top bar ───
    const updateUndo = () => { $('#undo').disabled = past.length < 2; $('#redo').disabled = !future.length; };
    const undo = () => { if (past.length < 2) return; future.push(past.pop()); doc = JSON.parse(past[past.length - 1]); S.saveEmailDoc(JSON.parse(JSON.stringify(doc))); sel = null; renderLib(); renderStage(); updateUndo(); };
    const redo = () => { if (!future.length) return; const n = future.pop(); past.push(n); doc = JSON.parse(n); S.saveEmailDoc(JSON.parse(JSON.stringify(doc))); renderLib(); renderStage(); updateUndo(); };
    $('#undo').onclick = undo; $('#redo').onclick = redo;
    $$('#devs button', v).forEach((b) => b.onclick = () => { device = b.dataset.dev; $$('#devs button', v).forEach((x) => x.classList.toggle('on', x === b)); renderStage(); });
    $$('#views button', v).forEach((b) => b.onclick = () => { view = b.dataset.view; $$('#views button', v).forEach((x) => x.classList.toggle('on', x === b)); renderStage(); });
    $('#darkt').onchange = (e) => { dark = e.target.checked; renderStage(); };
    $('#prev').onclick = preview;
    $('#done').onclick = () => {
      if (S.editingTemplate && S.flushTemplate) return S.flushTemplate(JSON.parse(JSON.stringify(doc))).then(() => { toast('Template saved'); location.hash = '#/templates?tab=mine'; });
      S.saveEmailDoc(JSON.parse(JSON.stringify(doc))); toast('Design saved'); location.hash = '#/campaigns/new';
    };
    $$('.eb-rail button', v).forEach((b) => b.onclick = () => { tab = b.dataset.tab; renderLib(); });
    const onKey = (e) => {
      if (!document.body.contains($('#stage') || document.createElement('i'))) return document.removeEventListener('keydown', onKey);
      const editing = e.target.closest && e.target.closest('input,textarea,select,[contenteditable="true"]');
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z' && !editing) { e.preventDefault(); e.shiftKey ? redo() : undo(); }
      if ((e.key === 'Delete' || e.key === 'Backspace') && !editing && sel) { e.preventDefault(); const f = find(sel); if (f.block) f.cell.splice(f.i, 1); else doc.rows = doc.rows.filter((r) => r.id !== sel); sel = null; commit(); renderStage(); }
      if (e.key === 'Escape' && !editing) select(null);
    };
    document.addEventListener('keydown', onKey);

    S.saveEmailDoc(JSON.parse(JSON.stringify(doc)));
    renderLib(); renderStage(); updateUndo();
  };
})();
