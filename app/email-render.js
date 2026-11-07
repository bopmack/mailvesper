/* MailVesper email renderer: a builder document (JSON) → table-based email HTML, plus pre-send checks.
   The browser uses it (builder stage, previews, template gallery) as window.SendlineRender. The server uses a PHP
   port, backend/app/Services/Design/EmailHtml.php, so hosting needs no Node.js: change both together. The backend
   keeps a copy of this file (resources/email-render.cjs) that RendererParityTest runs on developer machines to
   prove the two give byte-identical emails. */
(function (root, factory) {
  const R = factory();
  if (typeof module === 'object' && module.exports) module.exports = R;
  else root.SendlineRender = R;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const utf8Bytes = (s) => (typeof TextEncoder !== 'undefined' ? new TextEncoder().encode(s).length : unescape(encodeURIComponent(s)).length);
  // Where hosted images live when an email is exported or sent (set by the page or the server).
  const config = { assetBase: typeof location !== 'undefined' && /^https?:/.test(location.protocol) ? location.origin + '/app' : '' };

  // ─── Image library (stand-ins for uploaded images; exported as CDN URLs) ───
  const svgUri = (s) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(s);
  const ASSETS = {
    logo: ['Acme logo', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='240' height='64' viewBox='0 0 240 64'><rect x='2' y='12' width='40' height='40' rx='11' fill='#18181B'/><path d='M12 41 L22 23 L32 41' stroke='#fff' stroke-width='4.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/><text x='54' y='43' font-family='Helvetica,Arial,sans-serif' font-size='28' font-weight='700' fill='#18181B'>Acme</text></svg>`)],
    hero: ['Dashboard illustration', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='1200' height='620' viewBox='0 0 1200 620'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0' stop-color='#5B5BF7'/><stop offset='1' stop-color='#9F7AEA'/></linearGradient></defs><rect width='1200' height='620' fill='url(#g)'/><circle cx='1080' cy='90' r='180' fill='#fff' opacity='.08'/><circle cx='120' cy='560' r='220' fill='#fff' opacity='.07'/><rect x='180' y='110' width='840' height='440' rx='26' fill='#fff' opacity='.96'/><rect x='180' y='110' width='840' height='58' rx='26' fill='#F4F4F5'/><circle cx='222' cy='139' r='9' fill='#E4E4E7'/><circle cx='250' cy='139' r='9' fill='#E4E4E7'/><circle cx='278' cy='139' r='9' fill='#E4E4E7'/><rect x='220' y='200' width='220' height='26' rx='8' fill='#18181B' opacity='.85'/><rect x='220' y='240' width='150' height='14' rx='7' fill='#D4D4D8'/><rect x='220' y='290' width='240' height='110' rx='16' fill='#EEEEFE'/><rect x='480' y='290' width='240' height='110' rx='16' fill='#EAF7EE'/><rect x='740' y='290' width='240' height='110' rx='16' fill='#FEF5E6'/><rect x='244' y='318' width='90' height='16' rx='8' fill='#5B5BF7'/><rect x='244' y='346' width='140' height='28' rx='8' fill='#18181B' opacity='.8'/><rect x='504' y='318' width='90' height='16' rx='8' fill='#16A34A'/><rect x='504' y='346' width='120' height='28' rx='8' fill='#18181B' opacity='.8'/><rect x='764' y='318' width='90' height='16' rx='8' fill='#B45309'/><rect x='764' y='346' width='100' height='28' rx='8' fill='#18181B' opacity='.8'/><polyline points='220,500 330,470 440,488 550,440 660,456 770,410 880,424 980,380' fill='none' stroke='#5B5BF7' stroke-width='7' stroke-linecap='round' stroke-linejoin='round'/></svg>`)],
    f1: ['Search icon', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='112' height='112' viewBox='0 0 112 112'><rect width='112' height='112' rx='28' fill='#EEEEFE'/><circle cx='51' cy='51' r='18' stroke='#5B5BF7' stroke-width='7' fill='none'/><path d='M65 65 L80 80' stroke='#5B5BF7' stroke-width='7' stroke-linecap='round'/></svg>`)],
    f2: ['Team icon', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='112' height='112' viewBox='0 0 112 112'><rect width='112' height='112' rx='28' fill='#EAF7EE'/><circle cx='44' cy='46' r='11' fill='#16A34A'/><circle cx='70' cy='46' r='11' fill='#16A34A' opacity='.6'/><path d='M26 80 C28 64 60 64 62 80 Z' fill='#16A34A'/><path d='M52 80 C54 64 86 64 88 80 Z' fill='#16A34A' opacity='.6'/></svg>`)],
    f3: ['Timeline icon', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='112' height='112' viewBox='0 0 112 112'><rect width='112' height='112' rx='28' fill='#FEF5E6'/><path d='M30 36 H82 M30 56 H70 M30 76 H58' stroke='#B45309' stroke-width='7' stroke-linecap='round'/><circle cx='30' cy='36' r='6' fill='#B45309'/><circle cx='30' cy='56' r='6' fill='#B45309'/><circle cx='30' cy='76' r='6' fill='#B45309'/></svg>`)],
    p1: ['Product: bottle', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='600' height='600' viewBox='0 0 600 600'><rect width='600' height='600' fill='#F2ECFE'/><ellipse cx='300' cy='500' rx='120' ry='18' fill='#7C3AED' opacity='.15'/><rect x='240' y='150' width='120' height='340' rx='40' fill='#7C3AED'/><rect x='270' y='100' width='60' height='70' rx='14' fill='#18181B'/><rect x='258' y='260' width='84' height='110' rx='12' fill='#fff' opacity='.9'/></svg>`)],
    p2: ['Product: box', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='600' height='600' viewBox='0 0 600 600'><rect width='600' height='600' fill='#E7F6FA'/><ellipse cx='300' cy='480' rx='150' ry='20' fill='#0891B2' opacity='.15'/><path d='M170 220 L300 160 L430 220 L430 400 L300 460 L170 400 Z' fill='#0891B2'/><path d='M170 220 L300 280 L430 220 M300 280 V460' stroke='#fff' stroke-width='8' fill='none' opacity='.7'/></svg>`)],
    avatar: ['Customer photo', svgUri(`<svg xmlns='http://www.w3.org/2000/svg' width='96' height='96' viewBox='0 0 96 96'><rect width='96' height='96' rx='48' fill='#FDEBF0'/><circle cx='48' cy='38' r='16' fill='#E11D48'/><path d='M18 86 C22 60 74 60 78 86 Z' fill='#E11D48'/></svg>`)],
  };
  const PHOTOS = ["alley", "aurora", "beans-sq", "beans", "blossom", "book-sq", "book", "bowl-sq", "bowl", "bridge", "camera-sq", "camera", "city", "coast", "coffee-sq", "coffee", "concert", "cookies-sq", "cookies", "couple", "cozy-sq", "cozy", "desk", "desk2", "fashion-sq", "fashion", "fjord", "flowers-sq", "flowers", "kitchen", "laptop", "lighthouse", "lights", "mountains", "phone", "photographer-sq", "photographer", "pier", "pug-sq", "pug", "raspberries-sq", "raspberries", "scarf-sq", "scarf", "sea", "shoes-sq", "shoes", "sparkler", "stars", "store", "suit-sq", "suit", "waterfall", "work", "work2"]; // hosted photos (Unsplash licence via Lorem Picsum), app/img/<name>.jpg
  const imgSrc = (k, forExport) => ASSETS[k] ? (forExport ? `${config.assetBase}/img/eb/${k}.png` : ASSETS[k][1]) : /^img\//.test(k) ? (forExport ? config.assetBase + '/' + k : k) : k;
  const SOCIAL = { x: ['X', '#18181B'], instagram: ['Instagram', '#E1306C'], linkedin: ['LinkedIn', '#0A66C2'], youtube: ['YouTube', '#FF0000'], facebook: ['Facebook', '#1877F2'], tiktok: ['TikTok', '#000000'] };
  const socialIcon = (k, size = 32) => { const [n, c] = SOCIAL[k]; return `<span title="${n}" style="display:inline-block;width:${size}px;height:${size}px;border-radius:50%;background:${c};color:#fff;font:700 ${Math.round(size * .38)}px/${size}px Helvetica,Arial,sans-serif;text-align:center;margin:0 4px">${n === 'LinkedIn' ? 'in' : n === 'Instagram' ? 'IG' : n === 'YouTube' ? '▶' : n === 'Facebook' ? 'f' : n === 'TikTok' ? '♪' : 'X'}</span>`; };

  // ─── Rendering (shared by the stage and the exported HTML) ───
  const padCss = (p) => `${p[0]}px ${p[1]}px ${p[2]}px ${p[3]}px`;
  const mergeView = (h, plain) => plain ? h : String(h).replace(/\{\{\s*([a-z_]+)(\|"[^"]*")?\s*\}\}/g, (m, k, fb) => `<span class="mtag" contenteditable="false" data-tag="${esc(m)}">${k.replace(/_/g, ' ')}${fb ? '' : ' ⚠'}</span>`);
  function blockHtml(b, s, mode) {
    const ex = mode !== 'stage', cdn = mode === 'export';
    const p = b.props, wrap = (inner, extra = '') => `<div style="padding:${padCss(p.pad || [0, 0, 0, 0])};${extra}">${inner}</div>`;
    const ff = `font-family:${s.font}`;
    switch (b.type) {
      case 'heading': return wrap(`<h2 class="eb-edit" style="margin:0;${ff};font-size:${p.size}px;line-height:1.25;font-weight:${p.weight};color:${p.color || s.heading};text-align:${p.align};letter-spacing:-.01em">${mergeView(p.html, ex)}</h2>`);
      case 'text': return wrap(`<div class="eb-edit" style="margin:0;${ff};font-size:${p.size}px;line-height:${p.lh};color:${p.color || s.text};text-align:${p.align}">${mergeView(p.html, ex)}</div>`);
      case 'image': { const img = `<img src="${imgSrc(p.src, cdn)}" alt="${esc(p.alt)}" width="${Math.round((s.width - p.pad[1] - p.pad[3]) * p.width / 100)}" style="display:inline-block;width:${p.width}%;max-width:100%;height:auto;border:0;border-radius:${p.radius}px">`; return wrap(p.href ? `<a href="${esc(p.href)}">${img}</a>` : img, `text-align:${p.align};line-height:0`); }
      case 'button': { const bg = p.bg || s.btn, r = p.radius == null ? s.radius : p.radius;
        const a = `<a href="${esc(p.href)}" class="${ex ? '' : 'eb-edit-btn'}" style="display:${p.full ? 'block' : 'inline-block'};background:${bg};color:${p.color};${ff};font-size:${p.size}px;font-weight:700;text-decoration:none;padding:13px 26px;border-radius:${r}px;text-align:center">${ex ? esc(p.label) : `<span class="eb-edit">${esc(p.label)}</span>`}</a>`;
        return wrap(ex ? `<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="${esc(p.href)}" style="height:46px;v-text-anchor:middle;width:220px" arcsize="${Math.round(r / 46 * 100)}%" fillcolor="${bg}" stroke="f"><center style="color:${p.color};font-family:Arial;font-size:${p.size}px;font-weight:bold">${esc(p.label)}</center></v:roundrect><![endif]--><!--[if !mso]><!-->${a}<!--<![endif]-->` : a, `text-align:${p.align}`); }
      case 'divider': return wrap(`<div style="border-top:${p.thick}px solid ${p.color};width:${p.width}%;margin:0 auto;font-size:0;line-height:0">&nbsp;</div>`);
      case 'spacer': return `<div style="height:${p.h}px;line-height:${p.h}px;font-size:0">&nbsp;</div>`;
      case 'social': return wrap(p.nets.map((k) => `<a href="${esc((p.urls && p.urls[k]) || 'https://' + k + '.com/acme')}" style="text-decoration:none">${socialIcon(k, p.size)}</a>`).join(''), `text-align:${p.align}`);
      case 'video': return wrap(`<a href="${esc(p.href)}" style="display:block;position:relative;text-decoration:none"><img src="${imgSrc(p.src, cdn)}" alt="${esc(p.alt)}" width="${s.width - p.pad[1] - p.pad[3]}" style="display:block;width:100%;height:auto;border-radius:12px;border:0"><span style="position:absolute;left:50%;top:50%;width:68px;height:68px;margin:-34px 0 0 -34px;border-radius:50%;background:rgba(24,24,27,.85);color:#fff;font:28px/68px Arial;text-align:center">▶</span></a>`);
      case 'product': return wrap(`<div style="border:1px solid #E4E4E7;border-radius:14px;overflow:hidden;background:#fff"><img src="${imgSrc(p.src, cdn)}" alt="${esc(p.name)}" width="260" style="display:block;width:100%;height:auto;border:0"><div style="padding:14px 16px 16px;${ff}"><div style="font-size:15px;font-weight:700;color:${s.heading}">${esc(p.name)}</div><div style="font-size:14px;color:${s.text};margin:2px 0 12px">${esc(p.price)}</div><a href="${esc(p.href)}" style="display:block;text-align:center;background:${s.btn};color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:10px;border-radius:${s.radius}px">${esc(p.btn)}</a></div></div>`);
      case 'coupon': return wrap(`<div style="border:2px dashed ${s.btn};border-radius:14px;padding:18px;text-align:center;${ff}"><div style="font:700 26px/1.2 'Courier New',monospace;letter-spacing:.12em;color:${s.heading}">${esc(p.code)}</div><div style="font-size:13px;color:${s.text};margin-top:6px">${esc(p.note)}</div></div>`);
      case 'quote': return wrap(`<div style="${ff};border-left:3px solid ${s.btn};padding-left:18px"><div class="eb-edit" style="font-size:18px;line-height:1.5;color:${s.heading};font-style:italic">${mergeView(p.html, ex)}</div><div style="display:flex;align-items:center;gap:10px;margin-top:12px"><img src="${imgSrc(p.src, cdn)}" alt="" width="36" height="36" style="border-radius:50%;width:36px;height:36px"><span style="font-size:13px;color:${s.text}">${esc(p.who)}</span></div></div>`);
      case 'order': return wrap(`<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="${ff};border-collapse:collapse">${p.items.map(([n, v, q, pr, im]) => `<tr><td style="padding:10px 0;border-bottom:1px solid #E4E4E7;width:64px">${im ? `<img src="${imgSrc(im, cdn)}" width="56" height="56" alt="" style="display:block;width:56px;height:56px;border-radius:10px;object-fit:cover">` : ''}</td><td style="padding:10px 12px;border-bottom:1px solid #E4E4E7;font-size:14px;color:${s.heading}"><b>${esc(n)}</b><div style="font-size:12.5px;color:#71717A">${esc(v)} · Qty ${q}</div></td><td align="right" style="padding:10px 0;border-bottom:1px solid #E4E4E7;font-size:14px;color:${s.heading};white-space:nowrap">${esc(pr)}</td></tr>`).join('')}<tr><td></td><td style="padding:12px 12px 2px;font-size:13px;color:#71717A">Shipping</td><td align="right" style="padding:12px 0 2px;font-size:13px;color:#71717A">${esc(p.shipping)}</td></tr><tr><td></td><td style="padding:4px 12px;font-size:16px;font-weight:700;color:${s.heading}">Total</td><td align="right" style="padding:4px 0;font-size:16px;font-weight:700;color:${s.heading}">${esc(p.total)}</td></tr></table>`);
      case 'menu': return wrap(p.items.split(',').map((x) => `<a href="https://acme-corp.io/${esc(x.trim().toLowerCase())}" style="${ff};font-size:14px;font-weight:600;color:${s.heading};text-decoration:none;margin-left:18px">${esc(x.trim())}</a>`).join(''), `text-align:${p.align}`);
      case 'html': return wrap(p.code);
      case 'footer': return wrap(`<div style="${ff};font-size:12px;line-height:1.6;color:#71717A;text-align:center"><div>${esc(p.note)}</div><div style="margin-top:6px"><b style="color:#52525B">${esc(p.company)}</b> · ${esc(p.address)}</div><div style="margin-top:8px"><a href="{{unsubscribe_url}}" style="color:#71717A">Unsubscribe</a> · <a href="{{preferences_url}}" style="color:#71717A">Email preferences</a> · <a href="{{web_version_url}}" style="color:#71717A">View in browser</a></div></div>`);
    }
    return '';
  }
  // A design that is one complete HTML document (an email imported from another provider) is sent exactly as written.
  const fullDocument = (doc) => { const r = doc.rows; const b = r.length === 1 && r[0].cells.length === 1 && r[0].cells[0].length === 1 ? r[0].cells[0][0] : null; return b && b.type === 'html' && /^\s*(<!doctype|<html)/i.test(String(b.props.code || '')) ? String(b.props.code) : null; };
  const previewTags = (html) => html.replace(/\{\{\s*first_name(\|"[^"]*")?\s*\}\}/g, 'Amara').replace(/\{\{\s*company(\|"[^"]*")?\s*\}\}/g, 'Lumen').replace(/\{\{\s*email\s*\}\}/g, 'amara@example.com').replace(/\{\{[^}]+\}\}/g, '#');
  // Table-based HTML for real inboxes (Outlook needs tables; columns stack on phones)
  function toHtml(doc, opts = {}) {
    const raw = fullDocument(doc);
    if (raw) return opts.preview ? previewTags(raw) : raw;
    const s = doc.settings, W = s.width;
    const rowsHtml = doc.rows.map((r) => {
      const tot = r.layout.reduce((a, b) => a + b, 0);
      const cols = r.layout.map((w, i) => `<td class="${r.stack ? 'col' : ''}" width="${Math.round(W * w / tot)}" valign="top" style="width:${(w / tot * 100).toFixed(2)}%;vertical-align:top">${r.cells[i].map((b) => blockHtml(b, s, opts.preview ? 'preview' : 'export')).join('')}</td>`).join('');
      return `<tr class="${r.hide ? 'hide-' + r.hide : ''}"><td style="padding:${r.pad[0]}px 0 ${r.pad[1]}px;background:${r.bg || s.contentBg}"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>${cols}</tr></table></td></tr>`;
    }).join('\n');
    const html = `<!doctype html><html lang="en" xmlns:v="urn:schemas-microsoft-com:vml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>${esc(opts.subject || '')}</title>
<style>body{margin:0;padding:0}img{-ms-interpolation-mode:bicubic}a{color:${s.link}}@media (max-width:${W + 20}px){.wrap{width:100%!important}.col{display:block!important;width:100%!important}.hide-mobile{display:none!important}}@media (min-width:${W + 21}px){.hide-desktop{display:none!important}}${opts.dark ? 'html{filter:invert(.93) hue-rotate(180deg)}img{filter:invert(1) hue-rotate(180deg)}' : ''}${opts.mobile ? '.col{display:block!important;width:100%!important}.hide-mobile{display:none!important}' : ''}</style></head>
<body style="margin:0;background:${s.bodyBg}"><div style="display:none;max-height:0;overflow:hidden;opacity:0">${esc(s.preheader || '')}&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:${s.bodyBg}"><tr><td align="center" style="padding:24px 8px">
<!--[if mso]><table role="presentation" width="${W}"><tr><td><![endif]-->
<table role="presentation" class="wrap" width="${W}" cellpadding="0" cellspacing="0" border="0" style="width:${W}px;max-width:100%;background:${s.contentBg};border-radius:12px;overflow:hidden">
${rowsHtml}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table></body></html>`;
    return opts.preview ? previewTags(html) : html;
  }

  // ─── Checks (Litmus-style, wired to MailVesper's deliverability angle) ───
  function checks(doc) {
    const html = toHtml(doc), kb = utf8Bytes(html) / 1024;
    const blocks = doc.rows.flatMap((r) => r.cells.flat());
    // "large" = takes at least half the email's width once its column is accounted for (icons, logos, thumbnails don't count)
    const imgs = doc.rows.flatMap((r) => { const tot = r.layout.reduce((x, y) => x + y, 0); return r.cells.flatMap((cell, i) => cell.filter((b) => { const frac = r.layout[i] / tot; return (b.type === 'image' && frac * b.props.width / 100 > .5) || ((b.type === 'video' || b.type === 'product') && frac > .5); })); });
    const noAlt = blocks.filter((b) => (b.type === 'image' || b.type === 'video') && !b.props.alt && !/^f\d$/.test(b.props.src));
    const words = blocks.filter((b) => ['text', 'heading', 'quote'].includes(b.type)).map((b) => b.props.html.replace(/<[^>]+>/g, ' ')).join(' ').split(/\s+/).filter(Boolean).length;
    const raw = fullDocument(doc);
    const footer = blocks.some((b) => b.type === 'footer') || !!(raw && /\{\{\s*unsubscribe_url\s*\}\}/.test(raw));
    const deadLinks = blocks.filter((b) => (b.type === 'button' || b.type === 'image') && b.props.href !== undefined && b.props.href !== '' && !/^https?:\/\/[^/]+\.[a-z]{2,}/i.test(b.props.href)).length + blocks.filter((b) => b.type === 'button' && !b.props.href).length;
    const noFallback = (JSON.stringify(doc).match(/\{\{\s*first_name\s*\}\}/g) || []).length;
    const ratio = imgs.length ? words / imgs.length : words;
    return { kb, html, list: [
      ['Gmail clipping', kb < 90 ? 'ok' : kb < 102 ? 'warn' : 'bad', `${kb.toFixed(1)} KB of HTML · Gmail clips at 102 KB`, kb / 102],
      ['Alt text on images', noAlt.length ? 'warn' : 'ok', noAlt.length ? `${noAlt.length} image${noAlt.length > 1 ? 's' : ''} without alt text` : 'Every image has alt text', null, noAlt[0] && noAlt[0].id],
      ['Text vs images', ratio < 25 && imgs.length > 1 ? 'warn' : 'ok', `${words} words · ${imgs.length} large image${imgs.length === 1 ? '' : 's'}${ratio < 25 && imgs.length > 1 ? ' · image-heavy, add more text' : ''}`],
      ['Unsubscribe link & address', footer ? 'ok' : 'bad', footer ? (raw ? 'Has an unsubscribe link' : 'Footer has both') : raw ? 'Add an unsubscribe link ({{unsubscribe_url}}) to the HTML' : 'Add a Footer block: required by law and by Gmail/Yahoo'],
      ['Links', deadLinks ? 'warn' : 'ok', deadLinks ? `${deadLinks} link${deadLinks > 1 ? 's' : ''} not set to a real URL` : 'All links point to real URLs'],
      ['Personalization', noFallback ? 'warn' : 'ok', noFallback ? `${noFallback} {{first_name}} without a fallback` : 'Merge tags have fallbacks'],
      ['Preview text', doc.settings.preheader ? 'ok' : 'warn', doc.settings.preheader ? 'Set' : 'Empty: inboxes will show the first line of the email'],
    ] };
  }


  const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'social', 'video', 'product', 'coupon', 'quote', 'order', 'menu', 'html', 'footer'];
  return { config, esc, ASSETS, PHOTOS, SOCIAL, socialIcon, imgSrc, padCss, mergeView, blockHtml, toHtml, checks, BLOCK_TYPES, utf8Bytes, fullDocument };
});
