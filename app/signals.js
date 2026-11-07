/* MailVesper: shared deliverability visuals and the Migrate & save calculator.
   - thresholdGauge: a rate plotted against the published review / pause lines (used by Copilot and the SES page).
   - Migrate: 12-month savings curve, switch-over timeline with warm-up, and a concept map per provider; migrate.js
     builds the real imports on top of it. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, toast, bindCopy, confirmDialog } = S;
  const pages = S.pages;

  // ─── shared mini charts ───
  S.thresholdGauge = (o) => thresholdGauge(o);
  const thresholdGauge = ({ max, val, zones, alertAt, fmtv, alertLabel }) => {
    const X = (n) => Math.min(100, (n / max) * 100);
    return `<div class="tg"><div class="tg-track">${zones.map(([from, to, cls, label]) => `<span class="tg-zone ${cls}" style="left:${X(from)}%;width:${X(to) - X(from)}%"><em>${label}</em></span>`).join('')}
      ${alertAt != null ? `<span class="tg-alert" style="left:${X(alertAt)}%"></span>` : ''}
      <span class="tg-pin" style="left:${X(val)}%"><b>${fmtv(val)}</b></span></div>
      <div class="tg-scale">${zones.map(([from]) => `<span style="left:${X(from)}%">${fmtv(from)}</span>`).join('')}<span style="left:100%">${fmtv(max)}</span></div>${alertAt != null ? `<div class="tg-legend"><i></i>${alertLabel} at ${fmtv(alertAt)}</div>` : ''}</div>`;
  };

  // ═══════ Migrate & save ═══════
  const MAPS = {
    Mailchimp: [['Audience', 'List', ''], ['Tags and groups', 'Tags', 'Groups become tags named "group: …"'], ['Segments', 'Segments', 'Rebuilt from the same conditions'], ['*|FNAME|* merge tags', '{{first_name}}', 'Converted in every template'], ['Unsubscribed and cleaned', 'Suppressions', 'Imported first, never re-subscribed'], ['Customer journeys', 'Automations', 'Recreated as paused drafts'], ['Campaign reports', 'Not imported', 'Export them before you cancel']],
    SendGrid: [['Contact lists', 'Lists', ''], ['Segments', 'Segments', ''], ['Unsubscribe groups', 'Topics', 'Global unsubscribes become suppressions'], ['Bounces, blocks, spam reports', 'Suppressions', 'Imported first'], ['Dynamic templates', 'Templates', 'Handlebars tags work as they are'], ['API keys', 'New API keys', 'Old keys keep working until you switch']],
    Brevo: [['Lists and folders', 'Lists + tags', 'Folders become tags'], ['Contact attributes', 'Custom fields', 'FIRSTNAME → first_name'], ['{{ contact.FIRSTNAME }}', '{{first_name}}', 'Converted in every template'], ['Blocklisted contacts', 'Suppressions', 'Imported first'], ['Automations', 'Automations', 'Paused drafts'], ['SMS and WhatsApp', 'Not imported', SL_BN() + ' is email only']],
    Klaviyo: [['Lists', 'Lists', ''], ['Segments', 'Segments', 'Segments built on Klaviyo events arrive empty'], ['Profile properties', 'Custom fields', ''], ['Suppressed profiles', 'Suppressions', 'Imported first'], ['Flows', 'Automations', 'Paused drafts'], ['Orders and metrics', 'Not imported', 'Send them through the ' + SL_BN() + ' API']],
    Mailgun: [['Mailing lists', 'Lists', ''], ['Bounces, unsubscribes, complaints', 'Suppressions', 'Imported first'], ['Templates', 'Templates', 'Handlebars tags work as they are'], ['Routes and inbound', 'Not imported', SL_BN() + ' handles outbound email']],
    Kit: [['Subscribers', 'Contacts', ''], ['Tags', 'Tags', ''], ['Forms', 'Lists', 'One list per form'], ['Sequences', 'Automations', 'Paused drafts'], ['{{ subscriber.first_name }}', '{{first_name}}', 'Converted in every template'], ['Cold subscribers', 'Tag: dormant', 'Left out of your first broadcasts']],
    'CSV file': [['Any column', 'Matching field', 'You confirm the matching'], ['Unsubscribed column', 'Suppressions', 'If your file has one'], ['Everything else', 'Custom fields', '']],
  };
  const savingsChart = (bill, ours) => {
    const w = 820, h = 230, pl = 52, pr = 80, pt = 12, pb = 24, max = Math.max(bill, ours, 1) * 12;
    const X = (m) => pl + (m / 12) * (w - pl - pr), Y = (n) => pt + (1 - n / max) * (h - pt - pb);
    const line = (per) => Array.from({ length: 13 }, (_, m) => `${X(m)},${Y(per * m)}`).join(' ');
    const area = `M${X(0)},${Y(0)} ${Array.from({ length: 13 }, (_, m) => `L${X(m)},${Y(bill * m)}`).join(' ')} ${Array.from({ length: 13 }, (_, m) => `L${X(12 - m)},${Y(ours * (12 - m))}`).join(' ')}Z`;
    const ticks = [0, max / 2, max];
    return `<svg viewBox="0 0 ${w} ${h}" class="sav-svg">${ticks.map((t) => `<line x1="${pl}" x2="${w - pr}" y1="${Y(t)}" y2="${Y(t)}" stroke="var(--border)" stroke-dasharray="${t ? '3 3' : ''}"/><text x="${pl - 8}" y="${Y(t) + 4}" text-anchor="end" class="sv-ax">$${fmt(Math.round(t))}</text>`).join('')}
      ${bill > ours ? `<path d="${area}" fill="var(--s-delivered)" opacity=".1"/>` : ''}
      <polyline points="${line(bill)}" fill="none" stroke="var(--text-3)" stroke-width="2" stroke-dasharray="5 4"/><polyline points="${line(ours)}" fill="none" stroke="var(--accent)" stroke-width="2.5"/>
      ${[0, 3, 6, 9, 12].map((m) => `<text x="${X(m)}" y="${h - 6}" text-anchor="middle" class="sv-ax">${m ? 'Month ' + m : 'Today'}</text>`).join('')}
      <text x="${X(12) + 8}" y="${Y(bill * 12) + 4}" class="sv-end">$${fmt(Math.round(bill * 12))}</text><text x="${X(12) + 8}" y="${Y(ours * 12) + 4}" class="sv-end ours">$${fmt(Math.round(ours * 12))}</text></svg>`;
  };
  pages.migrate = (v) => {
    const P = S.PROVIDERS;
    let prov = 'Mailchimp';
    v.innerHTML = `<div class="page-head"><div><h1>Migrate & save</h1><p>Move from your current provider without losing contacts, unsubscribes or your sender reputation.</p></div><button class="btn btn-primary" id="startmig">${ic('migrate', 15)}Start import</button></div>
      <section class="card mig-calc"><div class="mc-inputs"><div class="cp-label" style="margin-top:0">Your numbers</div>
          <div class="field"><label>Current provider</label><select id="cp">${P.slice(0, 6).map(([n]) => `<option>${n}</option>`).join('')}<option>Other</option></select></div>
          <div class="field"><label>Monthly bill (USD)</label><input id="bill" type="number" value="299" min="0"><span class="help">From your last invoice. We don't guess other providers' prices.</span></div>
          <div class="field"><label>Emails per month · <b id="ev">150,000</b></label><input class="range" id="em" type="range" min="10000" max="2000000" step="10000" value="150000"></div>
          <div class="field" style="margin-bottom:0"><label>Contacts</label><input id="ct" type="number" value="20000" min="0"></div></div>
        <div class="mc-out"><div class="mc-head"><div><div class="faint" style="font-size:12.5px">Cumulative cost over 12 months</div><div class="mc-save" id="sv"></div></div><div class="mc-key"><span><i style="border-top:2px dashed var(--text-3)"></i><span id="kname">Mailchimp</span></span><span><i style="border-top:2.5px solid var(--accent)"></i>${SL_BN()} + your SES</span></div></div>
          <div id="savchart"></div>
          <div class="bills"><div class="bill-col"><small id="bname">Mailchimp today</small><div class="bl-row"><span>Plan</span><span id="bb">$299</span></div><div class="bl-row total"><span>Per month</span><span id="bt">$299</span></div></div>
            <div class="bill-col ours"><small>${SL_BN()} + your SES</small><div class="bl-row"><span id="pn">Starter plan</span><span id="pp">$29</span></div><div class="bl-row"><span id="sesl">AWS SES</span><span id="sesc">$15.00</span></div><div class="bl-row total"><span>Per month</span><span id="ot">$44</span></div></div></div>
          <p class="ev-note">AWS SES at its public price of $0.10 per 1,000 emails. ${SL_BN()} plans: Starter $29 (up to 25k contacts), Growth $79 (up to 100k), Scale $199. Proposed pricing.</p></div></section>
      <section class="card" style="margin-top:16px"><div class="card-head"><div><h3>The switch, day by day</h3><div class="sub">You keep your old provider until warm-up ends, so nothing breaks in between.</div></div></div>
        <ol class="switchline">${[['Day 0', 'Import', 'Unsubscribes and bounces first, then contacts, segments and templates.'], ['Day 0', 'Authenticate', 'Add DKIM, SPF and DMARC for your sending domain. Auto-configure works for most DNS hosts.'], ['Days 1–14', 'Warm up', 'Your most engaged contacts get the first sends, then we widen the audience.', true], ['Day 14', 'Cancel the old plan', 'Once warm-up ends and the numbers look right.']].map(([d, t, s, ramp]) => `<li><span class="sw-dot"></span><small class="sw-day">${d}</small><b>${t}</b><p>${s}</p>${ramp ? `<div class="ramp">${[6, 12, 22, 36, 55, 78, 100].map((p, i) => `<span style="height:${p}%" title="Day ${i * 2 + 1}: ${p}% of list"></span>`).join('')}</div><small class="faint">Share of your list, every 2 days</small>` : ''}</li>`).join('')}</ol></section>
      <section class="card" style="margin-top:16px"><div class="card-head"><div><h3>What comes over</h3><div class="sub">How each concept in your old tool maps to ${SL_BN()}</div></div></div>
        <div class="prov-tabs" id="ptabs">${P.map(([n, bg, fg]) => `<button data-p="${n}"><i style="background:${bg};color:${fg}">${n === 'CSV file' ? ic('file', 12) : n[0]}</i>${n}</button>`).join('')}</div>
        <div id="cmap"></div>
        <div class="map-foot"><span class="faint">${ic('shield', 13)} Suppressions are imported before anything else, so nobody who opted out gets an email.</span><button class="btn btn-primary btn-sm" id="startmig2">Import from <span id="pname">Mailchimp</span> →</button></div></section>`;
    const renderMap = () => {
      $$('#ptabs button', v).forEach((b) => b.classList.toggle('on', b.dataset.p === prov));
      $('#pname').textContent = prov;
      $('#cmap').innerHTML = `<div class="cmap">${MAPS[prov].map(([a, b, note]) => `<div class="cm-row ${b === 'Not imported' ? 'no' : ''}"><span class="cm-from">${esc(a)}</span><span class="cm-arrow">${ic('right', 14)}</span><span class="cm-to">${esc(b)}</span><span class="cm-note">${esc(note)}</span></div>`).join('')}</div>`;
    };
    const calc = () => {
      const bill = +$('#bill').value || 0, em = +$('#em').value, ct = +$('#ct').value || 0, name = $('#cp').value;
      const ses = em / 1000 * 0.10, [plan, price] = S.planFor(ct), total = ses + price, save = bill - total;
      $('#ev').textContent = fmt(em);
      $('#kname').textContent = name; $('#bname').textContent = name + ' today';
      $('#bb').textContent = '$' + fmt(bill); $('#bt').textContent = '$' + fmt(bill);
      $('#pn').textContent = plan + ' plan'; $('#pp').textContent = '$' + price;
      $('#sesl').textContent = `AWS SES · ${fmt(em)} emails`; $('#sesc').textContent = '$' + ses.toFixed(2); $('#ot').textContent = '$' + fmt(Math.round(total));
      $('#sv').innerHTML = save > 0 ? `<b>$${fmt(Math.round(save * 12))}</b> saved in the first year <span>$${fmt(Math.round(save))} a month</span>` : `<b>Your current bill is already lower</b> <span>${SL_BN()} is cheaper above roughly $${fmt(Math.round(total))} a month</span>`;
      $('#savchart').innerHTML = savingsChart(bill, total);
      if (MAPS[name] && name !== prov) { prov = name; renderMap(); }
    };
    ['#bill', '#em', '#ct', '#cp'].forEach((s) => $(s).oninput = calc);
    $$('#ptabs button', v).forEach((b) => b.onclick = () => { prov = b.dataset.p; renderMap(); });
    const go = () => (location.hash = '#/migrate/import?p=' + encodeURIComponent(prov));
    $('#startmig').onclick = go; $('#startmig2').onclick = go;
    renderMap(); calc();
  };
})();
