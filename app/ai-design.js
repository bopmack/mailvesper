/* MailVesper: "Design with AI" (#/templates/ai). A written brief becomes an editable template in the workspace's
   brand kit; the result opens in the builder. Community feature (the agent, which designs as part of bigger jobs,
   is Pro). Adds the entry point to the Templates page. Loaded after design.js. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, ic, toast } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const base = () => `/v1/workspaces/${wsId()}`;
  const role = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => !!role() && role() !== 'viewer';
  const store = { get: (k) => { try { return localStorage.getItem(k) || ''; } catch (_) { return ''; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (_) {} } };

  S.SUBTITLES = Object.assign(S.SUBTITLES || {}, { 'templates/ai': 'Design with AI' });

  const IDEAS = [
    ['Black Friday sale', 'Black Friday sale: 30% off everything for three days, ending Monday at midnight. Show three best sellers and a discount code.'],
    ['Welcome email', 'Welcome email for new subscribers: thank them for joining, say what they’ll get from us each month, and point them to our most popular products.'],
    ['Monthly newsletter', 'Monthly newsletter with three short updates: a new feature, a customer story, and an upcoming webinar.'],
    ['Product launch', 'Announce our new product: what it does, three key benefits, and an early-bird price for the first week.'],
    ['We miss you', 'Win-back email for customers who haven’t bought in 90 days: a friendly nudge, what’s new since they left, and a 15% comeback code.'],
    ['Event invite', 'Invitation to our in-person launch event: date, time and place, what to expect, and a button to reserve a seat.'],
  ];
  const TONES = [['friendly', 'Friendly'], ['professional', 'Professional'], ['bold', 'Bold'], ['minimal', 'Minimal'], ['playful', 'Playful']];
  const STEPS = ['Reading your brief', 'Writing the copy', 'Building it in your brand', 'Running the email checks'];

  // Entry point on the Templates page.
  const origTemplates = pages.templates;
  pages.templates = (v) => {
    origTemplates(v);
    const blank = $('#blank', v);
    if (blank && canEdit() && !$('#aidesign', v)) blank.insertAdjacentHTML('afterend', `<a class="btn btn-primary" id="aidesign" href="#/templates/ai">${ic('spark', 15)}Design with AI</a>`);
  };

  pages['templates/ai'] = (v) => {
    if (!window.SL_ME) { v.innerHTML = ''; return; }
    const st = { tone: store.get('sl-ai-tone') || 'friendly', busy: false };
    v.innerHTML = `<div class="flow-top"><a class="back" href="#/templates?tab=mine">← Templates</a></div>
      <div class="page-head"><div><h1>Design with AI</h1><p>Describe the email. You get a finished design in your brand kit, ready to edit in the builder.</p></div></div>
      <div class="aid">
        <section class="card aid-form"><div class="card-body">
          <div id="aidnote"></div>
          <div class="field"><label for="aibrief">What’s the email about?</label><textarea id="aibrief" rows="6" maxlength="1500" placeholder="For example: Black Friday sale, 30% off everything for three days. Show our three best sellers and a discount code.">${esc(store.get('sl-ai-brief'))}</textarea>
            <div class="aid-ideas">${IDEAS.map(([t], i) => `<button type="button" class="chip-btn" data-idea="${i}">${esc(t)}</button>`).join('')}</div></div>
          <div class="field"><label for="ailink">Where should the buttons go?</label><input id="ailink" type="url" inputmode="url" spellcheck="false" placeholder="https://yourshop.com/sale" value="${esc(store.get('sl-ai-link'))}"><span class="help">Every button and link in the design points here. You can change any of them later.</span></div>
          <div class="field" style="margin-bottom:0"><label>Tone</label><div class="seg aid-tones" role="radiogroup">${TONES.map(([k, t]) => `<button type="button" role="radio" class="${k === st.tone ? 'on' : ''}" data-tone="${k}" aria-checked="${k === st.tone}">${t}</button>`).join('')}</div></div>
        </div><div class="aid-foot"><span class="faint">${ic('shield', 13)} Your brand kit, a working unsubscribe link and your postal address are added for you.</span><button class="btn btn-primary" id="aigo">${ic('spark', 15)}Design it</button></div></section>
        <aside class="aid-side" id="aiside"><div class="cp-label" style="margin-top:0">How it works</div>
          <ol class="aid-how"><li><b>You describe it</b><span>Say what it’s for and what matters. Missing details (a price, a date) come back as [placeholders] to fill in.</span></li>
            <li><b>AI writes and lays it out</b><span>Headline, copy, sections and photos, in your tone. It never invents links: they all go where you said.</span></li>
            <li><b>You edit and send</b><span>It opens in the builder as a normal design, saved to Your templates.</span></li></ol>
          <a class="aid-brand" href="#/templates/brand">${ic('edit', 14)}Check your brand kit first</a></aside>
      </div>`;
    const go = $('#aigo');
    const sideHtml = $('#aiside').innerHTML;
    const setNote = (html) => { $('#aidnote').innerHTML = html; };
    if (!canEdit()) { setNote(`<div class="callout warn">${ic('alert', 15)}<div>Viewers can’t create templates. Ask an editor.</div></div>`); go.disabled = true; }
    API('GET', base() + '/templates/ai-design').then((r) => {
      if (!r.available) { setNote(`<div class="callout warn">${ic('alert', 15)}<div><b>AI isn’t set up on this ${SL_BN()} yet.</b> The admin adds an OpenRouter (or OpenAI) key in the platform settings, and this page switches on.</div></div>`); go.disabled = true; }
    }).catch(() => {});
    $$('[data-idea]', v).forEach((b) => b.onclick = () => { const t = $('#aibrief'); t.value = IDEAS[+b.dataset.idea][1]; t.focus(); t.setSelectionRange(t.value.length, t.value.length); });
    $$('[data-tone]', v).forEach((b) => b.onclick = () => { st.tone = b.dataset.tone; $$('[data-tone]', v).forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', String(x === b)); }); });
    const progress = (i) => { $('#aiside').innerHTML = `<div class="cp-label" style="margin-top:0">Designing</div><ol class="aid-run">${STEPS.map((s, j) => `<li class="${j < i ? 'done' : j === i ? 'now' : ''}">${j < i ? ic('check', 13, 3) : j === i ? '<span class="spin sm"></span>' : '<i></i>'}${s}</li>`).join('')}</ol><p class="faint" style="font-size:12.5px;margin-top:10px">Usually under a minute.</p>`; };
    go.onclick = () => {
      if (st.busy) return;
      const brief = $('#aibrief').value.trim(), link = $('#ailink').value.trim();
      let ok = true;
      if (brief.length < 10) { S.fieldError(v, '#aibrief', 'Tell the AI a little more about the email (at least a sentence).'); ok = false; }
      if (!/^https:\/\/[^\s<>"]+$/i.test(link)) { S.fieldError(v, '#ailink', 'Use a full https:// link, like https://yourshop.com/sale.'); ok = false; }
      if (!ok) return;
      store.set('sl-ai-brief', brief); store.set('sl-ai-link', link); store.set('sl-ai-tone', st.tone);
      st.busy = true; go.disabled = true; go.innerHTML = '<span class="spin"></span>Designing…';
      let i = 0; progress(0);
      const tick = setInterval(() => { if (i < STEPS.length - 1) progress(++i); }, 4500);
      API('POST', base() + '/templates/ai-design', { brief, link, tone: st.tone }).then((r) => {
        clearInterval(tick); progress(STEPS.length);
        store.set('sl-ai-brief', '');
        toast(`“${r.template.name}” is ready. Everything in it is editable`);
        setTimeout(() => (location.hash = '#/campaigns/design?template=' + r.template.id), 500);
      }).catch((err) => {
        clearInterval(tick); st.busy = false; go.disabled = false; go.innerHTML = `${ic('spark', 15)}Try again`;
        if (err.fields) Object.entries(err.fields).forEach(([k, m]) => S.fieldError(v, k === 'link' ? '#ailink' : '#aibrief', m));
        setNote(`<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`);
        $('#aiside').innerHTML = sideHtml;
      });
    };
  };
})();
