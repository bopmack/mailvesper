/* MailVesper: the people picker. Search contacts by name or email, or type any address and press Enter.
   Used by Quick send (one person) and campaigns ("Specific people"). Talks to /api/v1/workspaces/{id}/contacts?q=.
   S.peoplePicker(el, { multi, value: [{ id?, email, name? }], onChange(list), placeholder }) → { get(), set(list), focus() } */
(function () {
  'use strict';
  const S = window.SL;
  const { $, esc, ic } = S;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const wsId = () => (window.SL_ME || {}).current_workspace_id;
  const EMAIL = /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]{2,}$/;
  const STATUS = { unsubscribed: 'Unsubscribed', bounced: 'Bounced', complained: 'Marked as spam' };

  S.peoplePicker = (el, opts = {}) => {
    let list = (opts.value || []).slice(), hits = [], cur = -1, seq = 0, t = null;
    const multi = !!opts.multi;
    el.classList.add('pp');
    el.innerHTML = `<div class="pp-box"><span class="pp-chips"></span><input class="pp-in" type="text" autocomplete="off" spellcheck="false" placeholder="${esc(opts.placeholder || 'Search contacts or type an email')}" aria-label="${esc(opts.label || 'Recipient')}" role="combobox" aria-expanded="false"></div><div class="pp-menu" role="listbox" hidden></div>`;
    const box = $('.pp-box', el), chips = $('.pp-chips', el), input = $('.pp-in', el), menu = $('.pp-menu', el);
    const label = (p) => p.name ? `${esc(p.name)} <span class="faint">${esc(p.email)}</span>` : esc(p.email);
    const changed = () => { paint(); opts.onChange && opts.onChange(list.slice()); };
    const paint = () => {
      chips.innerHTML = list.map((p, i) => `<span class="pp-chip ${STATUS[p.status] ? 'off' : ''}" title="${esc(STATUS[p.status] ? STATUS[p.status] + ': won’t be emailed by campaigns' : p.id ? 'Contact' : 'New address')}">${p.id ? '' : ic('mail', 12)}${label(p)}${STATUS[p.status] ? ` <b>${STATUS[p.status]}</b>` : ''}<button type="button" data-rm="${i}" aria-label="Remove ${esc(p.email)}">${ic('x', 12, 2.5)}</button></span>`).join('');
      input.style.display = !multi && list.length ? 'none' : '';
      chips.querySelectorAll('[data-rm]').forEach((b) => b.onclick = (e) => { e.stopPropagation(); list.splice(+b.dataset.rm, 1); changed(); input.focus(); });
    };
    const close = () => { menu.hidden = true; input.setAttribute('aria-expanded', 'false'); cur = -1; };
    const add = (p) => {
      const email = String(p.email || '').trim().toLowerCase();
      if (!EMAIL.test(email)) return false;
      if (!list.some((x) => x.email.toLowerCase() === email)) { if (multi) list.push({ ...p, email }); else list = [{ ...p, email }]; }
      input.value = ''; close(); changed(); if (multi) input.focus();
      return true;
    };
    const showMenu = () => {
      const typed = input.value.trim();
      const rows = hits.map((c, i) => `<button type="button" class="pp-opt ${i === cur ? 'on' : ''}" data-i="${i}" role="option"><span>${c.name ? `<b>${esc(c.name)}</b> ` : ''}<span class="${c.name ? 'faint' : ''}">${esc(c.email)}</span></span>${STATUS[c.status] ? `<span class="pp-st">${STATUS[c.status]}</span>` : ''}</button>`);
      if (EMAIL.test(typed) && !hits.some((c) => c.email.toLowerCase() === typed.toLowerCase())) rows.push(`<button type="button" class="pp-opt ${cur === hits.length ? 'on' : ''}" data-new="1" role="option">${ic('plus', 13)}<span>Use <b>${esc(typed)}</b></span></button>`);
      menu.innerHTML = rows.join('') || `<div class="pp-none">${typed ? 'No contact matches. Type the full email address to use it.' : 'Start typing a name or email.'}</div>`;
      menu.hidden = false; input.setAttribute('aria-expanded', 'true');
      menu.querySelectorAll('[data-i]').forEach((b) => b.onmousedown = (e) => { e.preventDefault(); const c = hits[+b.dataset.i]; add({ id: c.id, email: c.email, name: c.name, status: c.status, first_name: c.first_name, last_name: c.last_name, fields: c.fields }); });
      const nw = menu.querySelector('[data-new]'); if (nw) nw.onmousedown = (e) => { e.preventDefault(); add({ email: typed }); };
    };
    const search = () => {
      const q = input.value.trim(), mine = ++seq;
      if (!q) { hits = []; showMenu(); return; }
      API('GET', `/v1/workspaces/${wsId()}/contacts?q=${encodeURIComponent(q)}`).then((r) => {
        if (mine !== seq) return;
        hits = (r.contacts || []).slice(0, 8); cur = hits.length ? 0 : (EMAIL.test(q) ? 0 : -1); showMenu();
      }).catch(() => { if (mine === seq) { hits = []; showMenu(); } });
    };
    input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(search, 180); }); // listener: fieldError() sets oninput
    input.onfocus = () => { if (input.value.trim()) search(); };
    input.onblur = () => setTimeout(() => { if (EMAIL.test(input.value.trim())) add({ email: input.value.trim() }); close(); }, 120);
    input.onkeydown = (e) => {
      const n = menu.querySelectorAll('.pp-opt').length;
      if (e.key === 'ArrowDown' && n) { e.preventDefault(); cur = (cur + 1) % n; showMenu(); }
      else if (e.key === 'ArrowUp' && n) { e.preventDefault(); cur = (cur - 1 + n) % n; showMenu(); }
      else if (e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === 'Tab' && input.value.trim())) {
        const opt = !menu.hidden && menu.querySelectorAll('.pp-opt')[cur];
        if (opt) { e.preventDefault(); opt.onmousedown(e); return; }
        if (input.value.trim()) { if (add({ email: input.value.trim() })) e.preventDefault(); else if (e.key !== 'Tab') { e.preventDefault(); search(); } }
      } else if (e.key === 'Escape') close();
      else if (e.key === 'Backspace' && !input.value && list.length && multi) { list.pop(); changed(); }
    };
    // Pasting a list of addresses adds them all (campaigns).
    input.onpaste = (e) => {
      if (!multi) return;
      const txt = (e.clipboardData || window.clipboardData).getData('text');
      const found = txt.split(/[\s,;<>]+/).filter((x) => EMAIL.test(x));
      if (found.length > 1) { e.preventDefault(); found.forEach((x) => { const em = x.toLowerCase(); if (!list.some((p) => p.email === em)) list.push({ email: em }); }); input.value = ''; changed(); }
    };
    box.onclick = () => input.focus();
    paint();
    return { get: () => list.slice(), set: (l) => { list = (l || []).slice(); paint(); }, focus: () => input.focus() };
  };
})();
