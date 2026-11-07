/* MailVesper: Migrate & save on the real API (Phase 11).
   Keeps the prototype's calculator (now saved with the workspace) and replaces the simulated import with the real
   one: connect with a read-only API key, choose what to bring (from what was actually found), watch it run.
   Suppressions always come first. A CSV goes to the contacts importer. Loaded after copilot.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const base = () => `/v1/workspaces/${wsId()}`;
  const role = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const isManager = () => ['owner', 'admin'].includes(role());
  const KEYS = { Mailchimp: 'mailchimp', SendGrid: 'sendgrid', Brevo: 'brevo', Klaviyo: 'klaviyo', Mailgun: 'mailgun', Kit: 'kit' };
  const HELP = {
    Mailchimp: 'In Mailchimp: Profile → Extras → API keys → Create a key. It ends in something like -us21.',
    SendGrid: 'In SendGrid: Settings → API Keys → Create API Key → Restricted access, with read access to Suppressions, Marketing and Templates.',
    Brevo: 'In Brevo: your name (top right) → SMTP & API → API keys → Generate a new API key.',
    Klaviyo: 'In Klaviyo: Settings → API keys → Create Private API Key → Read-only access.',
    Mailgun: 'In Mailgun: Account settings → API security → your private API key, plus your sending domain and region.',
    Kit: 'In Kit: Settings → Developer → API keys (v4) → Add a new key.',
  };
  const STAGE = { suppressions: 'Unsubscribes, bounces and spam complaints', lists: 'Lists', contacts: 'Contacts', templates: 'Templates' };
  const ago = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

  // ═══════ #/migrate: the calculator (saved) + your imports ═══════
  const proto = pages.migrate;
  pages.migrate = (v) => {
    if (!window.SL_ME) { v.innerHTML = ''; return; }
    proto(v);
    $('.page-head', v).insertAdjacentHTML('afterend', '<div id="myimports"></div>');
    let touched = false, restoring = false;
    API('GET', base() + '/migrations').then((r) => {
      const s = r.savings && r.savings.inputs;
      if (s && !touched) { // never overwrite numbers the user started typing while this loaded
        const set = (sel, val) => { const el = $(sel, v); if (el && val != null) { el.value = val; } };
        if ([...$('#cp', v).options].some((o) => o.value === s.provider)) set('#cp', s.provider);
        set('#bill', s.monthly_bill); set('#em', s.emails_per_month); set('#ct', s.contacts);
        restoring = true; $('#bill', v).dispatchEvent(new Event('input')); restoring = false; // redraw the chart, without saving it back
      }
      const imps = r.imports.filter((i) => i.status !== 'connected');
      $('#myimports').innerHTML = imps.length ? `<section class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>Your imports</h3></div></div><div class="table-wrap"><table class="tbl"><thead><tr><th>From</th><th>Status</th><th class="num">Contacts</th><th class="num">Suppressions</th><th>When</th></tr></thead><tbody>${imps.map((i) => `<tr data-id="${i.id}"><td class="cell-main">${esc(i.provider_name)}<div class="cell-sub">${esc(i.account_name || '')}</div></td><td>${S.chip({ done: 'delivered', importing: 'processing', failed: 'bounced', cancelled: 'dropped' }[i.status] || 'processing', { done: 'Done', importing: 'Importing', failed: 'Failed', cancelled: 'Cancelled' }[i.status] || i.status)}</td><td class="num">${fmt(((i.progress || {}).contacts || {}).done || 0)}</td><td class="num">${fmt(((i.progress || {}).suppressions || {}).done || 0)}</td><td class="faint">${ago(i.started_at)}</td></tr>`).join('')}</tbody></table></div></section>` : '';
      $$('#myimports tr[data-id]', v).forEach((tr) => tr.onclick = () => (location.hash = '#/migrate/import?id=' + tr.dataset.id));
    }).catch(() => {});
    let t = null;
    const save = () => { if (restoring) return; touched = true; clearTimeout(t); t = setTimeout(() => { if (role() === 'viewer') return; API('PUT', base() + '/migrations/savings', { provider: $('#cp', v).value, monthly_bill: +$('#bill', v).value || 0, emails_per_month: +$('#em', v).value || 0, contacts: +$('#ct', v).value || 0 }).catch(() => {}); }, 900); };
    ['#bill', '#em', '#ct', '#cp'].forEach((s) => { const el = $(s, v); if (el) el.addEventListener('input', save); });
    const go = () => { const p = ($('#pname', v) || {}).textContent || 'Mailchimp'; location.hash = p === 'CSV file' ? '#/contacts/import' : '#/migrate/import?p=' + encodeURIComponent(p); };
    ['#startmig', '#startmig2'].forEach((s) => { const b = $(s, v); if (b) b.onclick = go; });
  };

  // ═══════ #/migrate/import?p=Mailchimp (or ?id= to watch one) ═══════
  pages['migrate/import'] = (v) => {
    if (!window.SL_ME) { v.innerHTML = ''; return; }
    const id = S.param('id');
    if (id) return API('GET', base() + '/migrations/' + id).then((r) => run(v, r.import)).catch((err) => (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
    const prov = S.param('p') || 'Mailchimp';
    if (prov === 'CSV file') { location.hash = '#/contacts/import'; return undefined; }
    if (!KEYS[prov]) { location.hash = '#/migrate'; return undefined; }
    const P = (S.PROVIDERS.find((x) => x[0] === prov) || S.PROVIDERS[0]);
    const ctx = { imp: null };
    S.flowPage(v, {
      back: ['#/migrate', 'Migrate & save'], title: `Import from ${prov}`, sub: 'Bring everything over without losing a single unsubscribe.', ctx,
      topRight: `<span style="display:inline-flex;align-items:center;gap:8px;font-weight:600"><i style="width:26px;height:26px;border-radius:7px;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:12px;background:${P[1]};color:${P[2]}">${prov[0]}</i>${prov} → ${SL_BN()}</span>`,
      railFoot: `${ic('shield', 13)} Unsubscribes, bounces and spam complaints are always imported first, and nobody is ever re-subscribed.`,
      steps: [
        { label: 'Connect ' + prov, railSub: 'Read-only API key', title: `Connect your ${prov} account`, desc: `Read-only access is enough. We never send or change anything in ${prov}, and the key is deleted when the import finishes.`,
          render: () => (isManager() ? '' : `<div class="callout warn">${ic('alert', 15)}<div>Only owners and admins can import.</div></div>`) + `<div class="field"><label>${prov} API key</label><input id="pk" type="password" autocomplete="off" placeholder="Paste your ${prov} API key"><span class="help">${esc(HELP[prov])}</span></div>
            ${prov === 'Mailgun' ? '<div class="row2"><div class="field"><label>Sending domain</label><input id="pd" placeholder="mg.yourdomain.com"></div><div class="field"><label>Region</label><select class="select" id="pr"><option value="us">US</option><option value="eu">EU</option></select></div></div>' : ''}<div id="perr"></div>`,
          validate: (v2) => {
            if (ctx.imp) return true;
            const key = $('#pk', v2).value.trim();
            if (key.length < 8) { S.fieldError(v2, '#pk', 'Paste your API key.'); return false; }
            const btn = $('#pnext', v2); btn.disabled = true; btn.innerHTML = '<span class="spin"></span>Checking the key…';
            API('POST', base() + '/migrations', { provider: KEYS[prov], api_key: key, domain: $('#pd', v2) ? $('#pd', v2).value.trim() : undefined, region: $('#pr', v2) ? $('#pr', v2).value : undefined })
              .then((r) => { ctx.imp = r.import; $('#pnext', v).click(); })
              .catch((err) => { btn.disabled = false; btn.textContent = 'Continue'; $('#perr', v2).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
            return false;
          } },
        { label: 'Choose data', railSub: (c) => (c.imp && c.imp.discovery && c.imp.discovery.contacts != null ? fmt(c.imp.discovery.contacts) + ' contacts found' : 'What we found'), title: 'What should we bring over?',
          desc: (ctx.imp && ctx.imp.account_name) ? '' : '',
          render: (c) => {
            const d = c.imp.discovery || {};
            return `<p class="faint" style="margin:-6px 0 14px;font-size:13px">Connected to <b>${esc(c.imp.account_name)}</b>.</p>
              <div class="checklist">
                <label class="ck"><input type="checkbox" checked disabled style="accent-color:var(--accent);width:16px;height:16px"><div>Unsubscribes, bounces and complaints<small>${d.suppressions != null ? fmt(d.suppressions) + ' addresses. ' : ''}Always imported, first.</small></div><span class="r tag">Required</span></label>
                <label class="ck" style="cursor:pointer"><input type="checkbox" id="oc" checked style="accent-color:var(--accent);width:16px;height:16px"><div>Contacts<small>${d.contacts != null ? fmt(d.contacts) + ' contacts, ' : ''}with names, custom fields and tags</small></div></label>
                <label class="ck" style="cursor:pointer"><input type="checkbox" id="ot" ${d.templates ? 'checked' : ''} style="accent-color:var(--accent);width:16px;height:16px"><div>Templates<small>${d.templates != null ? fmt(d.templates) + ' found, ' : ''}merge tags converted to ${SL_BN()}’s</small></div></label>
              </div>
              ${(d.lists || []).length ? `<div class="cp-label" style="margin:16px 0 8px">Lists to bring</div><div class="checklist">${d.lists.map((l) => `<label class="ck" style="cursor:pointer"><input type="checkbox" class="ol" value="${esc(l.ext_id)}" checked style="accent-color:var(--accent);width:16px;height:16px"><div>${esc(l.name)}<small>${l.count != null ? fmt(l.count) + ' contacts' : ''}</small></div></label>`).join('')}</div>` : ''}
              <div class="field" style="margin-top:14px"><label>Tag everyone imported</label><input id="otag" value="from-${esc(KEYS[prov])}" maxlength="64"></div>
              ${(d.notes || []).length ? `<ul class="cp-fix" style="margin-top:12px">${d.notes.map((n) => `<li>${ic('info', 14)}<span>${esc(n)}</span></li>`).join('')}</ul>` : ''}`;
          },
          next: 'Start import',
          validate: (v2) => {
            const lists = $$('.ol', v2).filter((x) => x.checked).map((x) => x.value);
            const btn = $('#pnext', v2); btn.disabled = true;
            API('POST', `${base()}/migrations/${ctx.imp.id}/start`, { contacts: $('#oc', v2).checked, lists: true, templates: $('#ot', v2).checked, list_ids: lists, tag: $('#otag', v2).value.trim() || null })
              .then((r) => { location.hash = '#/migrate/import?id=' + r.import.id; })
              .catch((err) => { btn.disabled = false; toast(err.message); });
            return false;
          } },
        { label: 'Import', railSub: 'Live progress', title: 'Importing', render: () => '<p class="faint">Starting…</p>' },
      ],
    });
    return undefined;
  };

  function run(v, imp) {
    let poll = null;
    const draw = (i) => {
      const prog = i.progress || {}, stages = (i.options && i.options.stages) || Object.keys(prog);
      v.innerHTML = `<div class="flow-top"><a class="back" href="#/migrate">← Migrate & save</a></div>
        <div class="page-head"><div><h1>Import from ${esc(i.provider_name)}</h1><p>${esc(i.account_name || '')}${i.started_at ? ' · started ' + ago(i.started_at) : ''}</p></div>${i.status === 'importing' && isManager() ? '<button class="btn" id="mcancel">Stop import</button>' : ''}</div>
        <section class="card"><div class="card-body" style="display:grid;gap:18px">
          ${stages.map((s) => { const p = prog[s] || { done: 0, total: null, skipped: 0 }, active = i.stage === s && i.status === 'importing', finished = i.status === 'done' || (stages.indexOf(s) < stages.indexOf(i.stage)); const pctv = finished ? 100 : p.total ? Math.min(100, (p.done / p.total) * 100) : active ? 8 : 0;
            return `<div><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px"><span style="display:inline-flex;align-items:center;gap:7px">${finished ? ic('check', 13, 2.6) : active ? '<span class="spin sm"></span>' : ''}${esc(STAGE[s] || s)}</span><span class="faint">${finished && !p.done ? 'None found' : fmt(p.done) + (p.total ? ' / ' + fmt(p.total) : '')}${p.skipped ? ' · ' + fmt(p.skipped) + ' skipped' : ''}</span></div><div class="progress"><span style="width:${pctv}%${finished && !p.done ? ';opacity:.3' : ''}"></span></div></div>`; }).join('')}
          ${i.status === 'done' ? `<div class="callout ok">${ic('check', 16, 2.5)}<div><b>Done.</b> Everything from ${esc(i.provider_name)} is in ${SL_BN()}, and every unsubscribe is protected. We deleted the API key.</div></div><div style="display:flex;gap:8px"><a class="btn btn-primary" href="#/contacts">Go to contacts</a><a class="btn" href="#/suppressions">See suppressions</a><a class="btn" href="#/templates?tab=mine">Templates</a></div>` : ''}
          ${i.status === 'failed' ? `<div class="callout warn">${ic('alert', 15)}<div><b>The import stopped.</b> ${esc(i.error || '')} Everything imported so far is kept, suppressions included.</div></div>` : ''}
          ${i.status === 'importing' && i.error ? `<p class="ev-note">${esc(i.error)}</p>` : ''}
          ${(i.notes || []).map((n) => `<div class="callout warn">${ic('alert', 15)}<div>${esc(n)}</div></div>`).join('')}
          ${i.status === 'cancelled' ? `<div class="callout">${ic('info', 15)}<div>Stopped. What was imported before stopping is kept.</div></div>` : ''}
        </div></section>`;
      const mc = $('#mcancel'); if (mc) mc.onclick = () => API('POST', `${base()}/migrations/${i.id}/cancel`).then((r) => draw(r.import)).catch((err) => toast(err.message));
      clearTimeout(poll);
      if (i.status === 'importing') poll = setTimeout(() => { if (!location.hash.includes(i.id)) return; API('GET', `${base()}/migrations/${i.id}`).then((r) => draw(r.import)).catch(() => {}); }, 3000);
    };
    draw(imp);
  }
})();
