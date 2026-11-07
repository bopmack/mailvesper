/* MailVesper signup forms: one renderer for the hosted page, the editor's preview and any website.
   Inline:  <div data-sendline-form="FORM_KEY"></div><script src="https://YOUR-SENDLINE/f/embed.js" async></script>
   Popup, slide-in or bar (as set in the form's Display settings): the same two lines; the form opens on its rules.
   Each form draws inside a shadow root (your site's styles can't break it, and it can't break yours) and posts
   without cookies. Set data-redirect="false" to stay on the page even when the form has a redirect address. */
(function () {
  'use strict';
  if (window.SendlineForms && window.SendlineForms.version >= 2) { window.SendlineForms.scan(); return; }
  var me = document.currentScript || document.querySelector('script[src*="/f/embed.js"]');
  var origin = me ? new URL(me.src, location.href).origin : location.origin;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var FONTS = { Inter: ['Inter', 'Inter:wght@400;500;600;700'], System: ['-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', null], Poppins: ['Poppins', 'Poppins:wght@400;500;600;700'],
    'DM Sans': ['"DM Sans"', 'DM+Sans:wght@400;500;600;700'], Montserrat: ['Montserrat', 'Montserrat:wght@400;500;600;700'], Lora: ['Lora', 'Lora:wght@400;500;600;700'],
    'Playfair Display': ['"Playfair Display"', 'Playfair+Display:wght@400;600;700'], Georgia: ['Georgia, "Times New Roman", serif', null] };
  var DESIGN = { accent: '#5B5BF7', background: '#F4F4F5', card: '#FFFFFF', text: '#18181B', font: 'Inter', radius: 14, layout: 'card', image: null, button_full: true, steps: [], show_mark: true };
  var loaded = {};
  function loadFont(name) {
    var f = FONTS[name]; if (!f || !f[1] || loaded[name]) return;
    loaded[name] = true;
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://fonts.googleapis.com/css2?family=' + f[1] + '&display=swap'; document.head.appendChild(l);
  }
  // Text readable on a colour: dark on light, white on dark.
  function ink(hex) { var n = parseInt(String(hex).slice(1), 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255; return (0.299 * r + 0.587 * g + 0.114 * b) > 165 ? '#18181B' : '#FFFFFF'; }
  function mix(hex, pct) { return 'color-mix(in srgb, ' + hex + ' ' + pct + '%, transparent)'; }

  function css(d) {
    var font = (FONTS[d.font] || FONTS.Inter)[0], r = Math.max(0, Math.min(28, +d.radius || 0)), ri = Math.min(r, 12);
    return ':host{all:initial;display:block;font-family:' + font + ',system-ui,sans-serif;color:' + d.text + ';--a:' + d.accent + ';--ai:' + ink(d.accent) + ';--t:' + d.text + ';--c:' + d.card + ';--b:' + mix(d.text, 16) + ';--m:' + mix(d.text, 62) + '}'
      + '*{box-sizing:border-box}.sl{position:relative;width:100%;max-width:440px;margin:0 auto;background:var(--c);border:1px solid var(--b);border-radius:' + r + 'px;overflow:hidden;color:var(--t);text-align:left;box-shadow:0 12px 40px rgba(0,0,0,.08)}'
      + '.sl.wide{max-width:780px;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr)}.sl.wide .img{min-height:100%;background-size:cover;background-position:center}'
      + '.sl.top .img{height:170px;background-size:cover;background-position:center}.in{padding:26px 26px 22px}'
      + '.mk{width:42px;height:42px;border-radius:' + ri + 'px;display:grid;place-items:center;background:' + mix(d.accent, 14) + ';color:var(--a);font-weight:700;font-size:18px;margin-bottom:14px}'
      + 'h3{margin:0 0 6px;font-size:21px;line-height:1.25;letter-spacing:-.01em;font-weight:700}p.intro{margin:0 0 16px;color:var(--m);font-size:14.5px;line-height:1.55}'
      + '.prog{display:flex;align-items:center;gap:8px;margin:0 0 14px;font-size:12px;font-weight:600;color:var(--m)}.prog i{flex:1;height:4px;border-radius:4px;background:var(--b);position:relative;overflow:hidden}.prog i b{position:absolute;inset:0 auto 0 0;background:var(--a);border-radius:4px}'
      + '.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.f{grid-column:span 2;display:grid;gap:6px;min-width:0}.f.half{grid-column:span 1}'
      + '.f>span{font-size:13px;font-weight:600}.f>span small{font-weight:400;color:var(--m)}'
      + 'input,select,textarea{font:inherit;font-size:15px;color:var(--t);background:var(--c);padding:10px 12px;border:1px solid var(--b);border-radius:' + ri + 'px;width:100%;outline:none}'
      + 'textarea{min-height:84px;resize:vertical}input:focus,select:focus,textarea:focus{border-color:var(--a);box-shadow:0 0 0 3px ' + mix(d.accent, 18) + '}'
      + '.ch{display:grid;gap:7px}.ch label,.tick{display:flex;gap:9px;align-items:flex-start;font-size:14px;font-weight:400;line-height:1.45;cursor:pointer}.ch input,.tick input{width:17px;height:17px;margin:2px 0 0;flex:none;accent-color:var(--a);padding:0}'
      + '.bad input,.bad select,.bad textarea{border-color:#DC2626}.fe{color:#DC2626;font-size:12.5px;font-weight:500}'
      + '.acts{display:flex;gap:10px;margin-top:18px}.btn{font:inherit;font-weight:600;font-size:15px;border:0;border-radius:' + ri + 'px;padding:12px 18px;cursor:pointer;background:var(--a);color:var(--ai)}'
      + '.btn.full{flex:1}.btn:hover{filter:brightness(.95)}.btn:disabled{opacity:.6;cursor:default}.btn.ghost{background:transparent;color:var(--t);border:1px solid var(--b)}'
      + '.msg .err{color:#DC2626;font-size:13.5px;margin:10px 0 0}.note{margin:12px 0 0;font-size:12px;color:var(--m)}'
      + '.done{text-align:center;padding:12px 0}.done .ok{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;margin:0 auto 12px;background:' + mix(d.accent, 14) + ';color:var(--a);font-size:22px;font-weight:700}'
      + '.done h3{margin-bottom:6px}.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}'
      + '.x{position:absolute;top:10px;right:10px;width:32px;height:32px;border-radius:50%;border:0;background:' + mix(d.card, 80) + ';color:var(--t);font-size:18px;line-height:1;cursor:pointer;z-index:2}'
      + '.x:hover{background:var(--b)}.barw{display:flex;align-items:center;gap:14px;justify-content:center;flex-wrap:wrap;padding:12px 52px 12px 18px;background:var(--a);color:var(--ai);font-size:14.5px;font-weight:600}'
      + '.barw .btn{background:var(--c);color:var(--t);padding:8px 14px;font-size:14px}'
      + '@media (max-width:640px){.sl.wide{grid-template-columns:1fr}.sl.wide .img{height:150px;min-height:0}.grid{grid-template-columns:1fr}.f.half{grid-column:span 1}.in{padding:22px 18px 18px}}';
  }

  function field(f, i, err) {
    var id = 'f' + i, req = f.required ? ' required' : '', ph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
    var lab = '<span>' + esc(f.label) + (f.required || f.type === 'consent' || f.type === 'checkbox' ? '' : ' <small>(optional)</small>') + '</span>';
    var e = err ? '<span class="fe" role="alert">' + esc(err) + '</span>' : '', cls = 'f' + (f.width === 'half' ? ' half' : '') + (err ? ' bad' : '');
    var auto = f.key === 'email' ? 'email' : f.key === 'first_name' ? 'given-name' : f.key === 'last_name' ? 'family-name' : f.type === 'phone' ? 'tel' : 'off';
    var nm = ' name="' + esc(f.key) + '" id="' + id + '"';
    switch (f.type) {
      case 'textarea': return '<label class="' + cls + '">' + lab + '<textarea' + nm + req + ph + ' maxlength="2000"></textarea>' + e + '</label>';
      case 'dropdown': return '<label class="' + cls + '">' + lab + '<select' + nm + req + '><option value="">' + esc(f.placeholder || 'Choose…') + '</option>' + (f.options || []).map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>' + e + '</label>';
      case 'radio': case 'checkboxes': return '<div class="' + cls + '" role="group" aria-label="' + esc(f.label) + '">' + lab + '<div class="ch">' + (f.options || []).map(function (o) { return '<label><input type="' + (f.type === 'radio' ? 'radio' : 'checkbox') + '" name="' + esc(f.key) + '" value="' + esc(o) + '">' + esc(o) + '</label>'; }).join('') + '</div>' + e + '</div>';
      case 'checkbox': case 'consent': return '<div class="' + cls + '"><label class="tick"><input type="checkbox"' + nm + ' value="yes"' + req + '><span>' + esc(f.label) + '</span></label>' + e + '</div>';
      case 'hidden': return '';
      default: return '<label class="' + cls + '">' + lab + '<input' + nm + ' type="' + ({ number: 'text', phone: 'tel', date: 'date', email: 'email' }[f.type] || 'text') + '"' + (f.type === 'number' ? ' inputmode="decimal"' : '') + ' autocomplete="' + auto + '"' + req + ph + ' maxlength="255">' + e + '</label>';
    }
  }

  // The same checks as the server (FormBuilder::value), so people see problems before they press the button.
  function check(f, v) {
    var label = String(f.label).toLowerCase();
    if (f.type === 'hidden') return null;
    if (f.type === 'checkboxes') return f.required && !v.length ? 'Please choose at least one for ' + label + '.' : null;
    if (f.type === 'checkbox' || f.type === 'consent') return f.required && v !== 'yes' ? (f.type === 'consent' ? 'Please tick the box to agree.' : 'Please tick ' + label + '.') : null;
    v = String(v || '').trim();
    if (!v) return f.required ? (f.key === 'email' ? 'Enter your email address.' : 'Please fill in ' + label + '.') : null;
    if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email address.';
    if (f.type === 'number' && !/^-?[\d\s,]*\.?\d+$/.test(v)) return 'Enter a number for ' + label + '.';
    if (f.type === 'phone' && !/^\+?[0-9 ()\-.]{6,20}$/.test(v)) return 'Enter a valid phone number.';
    if (f.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'Enter a date for ' + label + '.';
    return null;
  }

  /**
   * Draws a form into host (in a shadow root). opts: {preview, step, onSubmit(body) → Promise<{message, redirect, status}>, onDone, onClose, closable, follow}
   * Returns {root, destroy, go(step), steps}.
   */
  function render(host, form, opts) {
    opts = opts || {};
    var d = {}; for (var k in DESIGN) d[k] = form.design && form.design[k] != null ? form.design[k] : DESIGN[k];
    loadFont(d.font);
    var root = host.shadowRoot || host.attachShadow({ mode: 'open' });
    var fields = [{ key: 'email', label: 'Email', type: 'email', required: true, width: 'full', step: 1 }].concat((form.fields || []).map(function (f) { return Object.assign({ type: 'text', width: 'full', step: 1 }, f); }));
    var visible = fields.filter(function (f) { return f.type !== 'hidden'; });
    var steps = Math.max.apply(null, visible.map(function (f) { return +f.step || 1; }));
    var state = { step: Math.min(steps, Math.max(1, +opts.step || 1)), values: {}, errors: opts.error ? { _form: opts.error } : {}, done: null, sending: false };
    var params = new URLSearchParams(location.search);

    function read() {
      var el = root.querySelector('form'); if (!el) return;
      visible.forEach(function (f) {
        if ((+f.step || 1) !== state.step) return;
        if (f.type === 'checkboxes') state.values[f.key] = Array.prototype.map.call(el.querySelectorAll('[name="' + f.key + '"]:checked'), function (x) { return x.value; });
        else if (f.type === 'radio') { var c = el.querySelector('[name="' + f.key + '"]:checked'); state.values[f.key] = c ? c.value : ''; }
        else if (f.type === 'checkbox' || f.type === 'consent') { var b = el.querySelector('[name="' + f.key + '"]'); state.values[f.key] = b && b.checked ? 'yes' : ''; }
        else { var i = el.querySelector('[name="' + f.key + '"]'); state.values[f.key] = i ? i.value : ''; }
      });
    }
    function fill() {
      var el = root.querySelector('form'); if (!el) return;
      Object.keys(state.values).forEach(function (key) {
        var v = state.values[key];
        Array.prototype.forEach.call(el.querySelectorAll('[name="' + key + '"]'), function (i) {
          if (i.type === 'checkbox' || i.type === 'radio') i.checked = Array.isArray(v) ? v.indexOf(i.value) >= 0 : i.value === v;
          else i.value = v;
        });
      });
    }
    function draw() {
      var side = d.image && d.layout === 'image_left', top = d.image && d.layout === 'image_top', bg = d.image && d.layout === 'background';
      var body;
      if (state.done) {
        body = '<div class="done"><div class="ok">✓</div><h3>' + esc(state.done.status === 'pending' ? 'Check your inbox' : 'You’re signed up') + '</h3><p class="intro">' + esc(state.done.message) + '</p></div>';
      } else {
        var mine = visible.filter(function (f) { return (+f.step || 1) === state.step; });
        var title = (d.steps || [])[state.step - 1];
        body = (d.show_mark && state.step === 1 ? '<div class="mk">' + esc((form.sender || form.name || 'S').charAt(0).toUpperCase()) + '</div>' : '')
          + (state.step === 1 ? '<h3>' + esc(form.heading || 'Sign up to ' + (form.sender || form.name || '')) + '</h3>' + (form.intro ? '<p class="intro">' + esc(form.intro) + '</p>' : '') : '')
          + (steps > 1 ? '<div class="prog"><span>Step ' + state.step + ' of ' + steps + (title ? ' · ' + esc(title) : '') + '</span><i><b style="width:' + (state.step / steps * 100) + '%"></b></i></div>' : '')
          + '<form novalidate><div class="grid">' + mine.map(function (f) { return field(f, fields.indexOf(f), state.errors[f.key]); }).join('') + '</div>'
          + '<div class="hp" aria-hidden="true"><input name="_hp" tabindex="-1" autocomplete="off"></div>'
          + '<div class="acts">' + (state.step > 1 ? '<button type="button" class="btn ghost" data-back>Back</button>' : '') + '<button type="submit" class="btn ' + (d.button_full ? 'full' : '') + '"' + (state.sending ? ' disabled' : '') + '>' + esc(state.step < steps ? 'Next' : (form.button || 'Subscribe')) + '</button></div>'
          + '<div class="msg" aria-live="polite">' + (state.errors._form ? '<p class="err">' + esc(state.errors._form) + '</p>' : '') + '</div>'
          + (state.step === steps && form.double_opt_in ? '<p class="note">We’ll email you a link to confirm.</p>' : '') + '</form>';
      }
      var wrapStyle = bg ? ' style="background:linear-gradient(' + mix(d.card, 88) + ',' + mix(d.card, 88) + '),url(&quot;' + esc(d.image) + '&quot;) center/cover"' : '';
      var card = '<div class="sl' + (side ? ' wide' : '') + (top ? ' top' : '') + '"' + wrapStyle + '>' + (opts.closable ? '<button class="x" type="button" aria-label="Close" data-x>×</button>' : '')
        + (side || top ? '<div class="img" style="background-image:url(&quot;' + esc(d.image) + '&quot;)"></div>' : '') + '<div class="in">' + body + '</div></div>';
      root.innerHTML = '<style>' + css(d) + '</style>' + card;
      fill();
      var x = root.querySelector('[data-x]'); if (x) x.onclick = function () { if (opts.onClose) opts.onClose(); };
      var back = root.querySelector('[data-back]'); if (back) back.onclick = function () { read(); state.errors = {}; state.step--; draw(); };
      var el = root.querySelector('form'); if (!el) return;
      el.onsubmit = function (e) {
        e.preventDefault(); read(); state.errors = {};
        visible.forEach(function (f) { if ((+f.step || 1) === state.step) { var err = check(f, state.values[f.key] == null ? '' : state.values[f.key]); if (err) state.errors[f.key] = err; } });
        if (Object.keys(state.errors).length) { draw(); focusError(); return; }
        if (state.step < steps) { state.step++; draw(); return; }
        if (opts.preview) { state.done = { status: form.double_opt_in ? 'pending' : 'added', message: form.double_opt_in ? 'Almost done: check your inbox and confirm your email address.' : (form.success_message || 'Thanks for signing up!') }; draw(); return; }
        var out = { _hp: el.querySelector('[name=_hp]').value };
        fields.forEach(function (f) { if (f.type === 'hidden') { if (f.param && params.get(f.param)) out[f.key] = params.get(f.param).slice(0, 255); } else if (state.values[f.key] != null) out[f.key] = state.values[f.key]; });
        state.sending = true; draw();
        Promise.resolve(opts.onSubmit ? opts.onSubmit(out) : null).then(function (res) {
          state.sending = false;
          if (res && res.redirect && opts.follow !== false) { location.href = res.redirect; return; }
          state.done = res || { message: 'Thanks!' }; draw(); if (opts.onDone) opts.onDone(res);
        }).catch(function (err) {
          state.sending = false; state.errors = {};
          var fe = (err && err.fields) || {}, first = null;
          Object.keys(fe).forEach(function (key) { var f = fields.filter(function (x) { return x.key === key; })[0]; if (!f) return; state.errors[key] = fe[key]; if (first === null || (+f.step || 1) < first) first = +f.step || 1; });
          if (first !== null) state.step = first; else state.errors._form = (err && err.message) || 'Something went wrong. Please try again.';
          draw(); focusError();
        });
      };
    }
    function focusError() { var b = root.querySelector('.bad input, .bad select, .bad textarea'); if (b) b.focus(); }
    draw();
    return { root: root, destroy: function () { root.innerHTML = ''; }, go: function (n) { state.step = Math.min(steps, Math.max(1, n)); state.done = null; state.errors = {}; draw(); }, steps: steps };
  }

  function post(key, body) {
    return fetch(origin + '/api/v1/f/' + key, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error((d.error && d.error.message) || 'Something went wrong. Please try again.'); e.fields = (d.error && d.error.fields) || {}; throw e; } return d; }); },
        function () { throw new Error('Couldn’t reach the server. Try again.'); });
  }

  // ─── Popup, slide-in and bar: when and how often they open ───
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage off */ } },
    sget: function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }, sset: function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* storage off */ } } };
  function allowed(key, disp) {
    if (store.get('sl-f-' + key + '-done')) return false; // signed up already
    var seen = +store.get('sl-f-' + key + '-seen') || 0;
    if (disp.frequency === 'once') return !seen;
    if (disp.frequency === 'session') return !store.sget('sl-f-' + key);
    if (disp.frequency === 'days') return !seen || Date.now() - seen > disp.days * 86400000;
    return true;
  }
  /** Shows a form as a popup, slide-in or bar. frame: draw inside this element instead of the page (the editor's preview). */
  function overlay(form, key, disp, frame, preview) {
    var pos = frame ? 'absolute' : 'fixed', box = document.createElement('div'), host = document.createElement('div'), shade = null, mode = disp.mode, where = frame || document.body;
    var close = function () { if (box.parentNode) box.parentNode.removeChild(box); if (shade && shade.parentNode) shade.parentNode.removeChild(shade); document.removeEventListener('keydown', esck); };
    var esck = function (e) { if (e.key === 'Escape') close(); };
    var opts = { closable: true, onClose: close, preview: preview, follow: true, onSubmit: function (b) { return post(key, b); }, onDone: function () { store.set('sl-f-' + key + '-done', '1'); } };
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', mode === 'popup' ? 'true' : 'false'); box.setAttribute('aria-label', form.heading || 'Sign up');
    if (mode === 'popup') {
      shade = document.createElement('div');
      shade.style.cssText = 'position:' + pos + ';inset:0;background:rgba(9,9,11,.5);z-index:2147483000';
      shade.onclick = close;
      box.style.cssText = 'position:' + pos + ';z-index:2147483001;left:50%;top:50%;transform:translate(-50%,-50%);width:min(92%,' + (form.design && form.design.layout === 'image_left' && form.design.image ? 780 : 440) + 'px);max-height:92%;overflow:auto';
    } else if (mode === 'slidein') {
      box.style.cssText = 'position:' + pos + ';z-index:2147483001;bottom:16px;' + (disp.position === 'left' ? 'left' : 'right') + ':16px;width:min(92%,380px);max-height:88%;overflow:auto';
    } else { // bar: a strip with the heading; its button opens the form as a popup
      box.style.cssText = 'position:' + pos + ';z-index:2147483001;left:0;right:0;' + (disp.position === 'bottom' ? 'bottom' : 'top') + ':0';
      var d = Object.assign({}, DESIGN, form.design || {});
      loadFont(d.font);
      var sh = host.attachShadow({ mode: 'open' });
      sh.innerHTML = '<style>' + css(d) + '</style><div class="barw" style="position:relative"><span>' + esc(form.heading || 'Sign up') + '</span><button class="btn" type="button" data-open>' + esc(form.button || 'Subscribe') + '</button><button class="x" type="button" aria-label="Close" data-x style="background:transparent;color:inherit">×</button></div>';
      sh.querySelector('[data-x]').onclick = close;
      sh.querySelector('[data-open]').onclick = function () { close(); overlay(form, key, Object.assign({}, disp, { mode: 'popup' }), frame, preview); };
      box.appendChild(host); where.appendChild(box); document.addEventListener('keydown', esck);
      return close;
    }
    box.appendChild(host);
    if (shade) where.appendChild(shade);
    where.appendChild(box);
    render(host, form, opts);
    document.addEventListener('keydown', esck);
    return close;
  }
  function schedule(form, key, disp) {
    if (!allowed(key, disp)) return;
    var shown = false, timers = [], timeOk = !disp.delay, scrollOk = !disp.scroll;
    var show = function () {
      if (shown) return; shown = true; timers.forEach(clearTimeout); window.removeEventListener('scroll', onScroll); document.removeEventListener('mouseout', onOut);
      store.set('sl-f-' + key + '-seen', String(Date.now())); store.sset('sl-f-' + key, '1');
      overlay(form, key, disp, null, false);
    };
    var maybe = function () { if (timeOk && scrollOk) show(); };
    var onScroll = function () { var h = document.documentElement; if ((h.scrollTop + h.clientHeight) / Math.max(1, h.scrollHeight) * 100 >= disp.scroll) { scrollOk = true; maybe(); } };
    var onOut = function (e) { if (!e.relatedTarget && e.clientY <= 0) show(); }; // the pointer leaves through the top: about to leave the page
    if (disp.delay) timers.push(setTimeout(function () { timeOk = true; maybe(); }, disp.delay * 1000));
    if (disp.scroll) { window.addEventListener('scroll', onScroll, { passive: true }); onScroll(); }
    if (disp.exit_intent && window.matchMedia('(pointer: fine)').matches) document.addEventListener('mouseout', onOut);
    maybe();
  }

  function mount(el) {
    var key = el.getAttribute('data-sendline-form');
    if (!/^[a-z0-9]{20}$/.test(key || '') || el.getAttribute('data-sl-mounted')) return;
    el.setAttribute('data-sl-mounted', '1');
    fetch(origin + '/api/v1/f/' + key, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
      var f = d.form; if (!f) return;
      var disp = f.display || { mode: 'inline' };
      if (disp.mode !== 'inline') { if (f.open) schedule(f, key, disp); return; }
      if (el.tagName === 'SCRIPT') { var div = document.createElement('div'); el.parentNode.insertBefore(div, el); el = div; }
      if (!f.open) { el.textContent = 'Sign-ups are closed.'; return; }
      render(el, f, { follow: el.getAttribute('data-redirect') !== 'false', onSubmit: function (b) { return post(key, b); } });
    }).catch(function () {});
  }
  function scan() { Array.prototype.forEach.call(document.querySelectorAll('div[data-sendline-form],span[data-sendline-form]'), mount); }
  window.SendlineForms = { version: 2, render: render, overlay: overlay, post: post, scan: scan, fonts: Object.keys(FONTS) };
  if (me && me.hasAttribute && me.hasAttribute('data-sendline-form')) mount(me); // <script … data-sendline-form="KEY">: a popup, slide-in or bar
  if (!window.MAILVESPER_NO_SCAN && !window.SENDLINE_NO_SCAN) document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', scan) : scan();
})();
