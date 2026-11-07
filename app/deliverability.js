/* MailVesper: "Check my setup" and each email's Report.
   S.checkView(result) renders a setup check (route, SPF, DKIM, DMARC, blocklists); S.runSetupCheck(box, from, done)
   starts one and polls until the test email has been read back; S.reportView(report) renders an email's Report tab.
   Talks to /api/v1/workspaces/{id}/deliverability/checks and /activity/{id}/report. */
(function () {
  'use strict';
  const S = window.SL;
  const { esc, ic } = S;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const base = () => `/v1/workspaces/${(window.SL_ME || {}).current_workspace_id}`;
  const when = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
  const MARK = { pass: ['ok', 'Pass'], ok: ['ok', 'Pass'], fail: ['bad', 'Fail'], softfail: ['warn', 'Soft fail'], neutral: ['warn', 'Neutral'], none: ['warn', 'Missing'], permerror: ['bad', 'Broken'], temperror: ['warn', 'Try again'] };
  const pill = (r) => { const [k, l] = MARK[r] || ['warn', r]; return `<span class="dv-pill ${k}">${l}</span>`; };
  const css = `<style>.dv{display:grid;gap:10px}.dv-row{border:1px solid var(--border);border-radius:12px;padding:12px 14px;background:var(--surface)}.dv-row h4{margin:0 0 4px;font-size:13.5px;display:flex;gap:8px;align-items:center;justify-content:space-between}.dv-row p{margin:0;color:var(--text-2);font-size:13px;line-height:1.55}
    .dv-pill{font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 9px}.dv-pill.ok{background:var(--s-delivered-bg);color:var(--s-delivered)}.dv-pill.bad{background:var(--s-bounced-bg);color:var(--s-bounced)}.dv-pill.warn{background:var(--s-deferred-bg);color:var(--s-deferred)}.dv-pill.info{background:var(--surface-2);color:var(--text-2)}
    .dv-verdict{display:flex;gap:10px;align-items:center;padding:12px 14px;border-radius:12px;font-weight:600;font-size:14px}.dv-verdict.ok{background:var(--s-delivered-bg);color:var(--s-delivered)}.dv-verdict.bad{background:var(--s-bounced-bg);color:var(--s-bounced)}.dv-verdict.warn{background:var(--s-deferred-bg);color:var(--s-deferred)}
    .dv-path{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12.5px;margin-top:6px}.dv-path span{background:var(--surface-2);border-radius:8px;padding:4px 8px}.dv-path i{color:var(--text-3);font-style:normal}.dv-sec{font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);font-weight:700;margin:14px 0 6px}
    .dv code{font-family:var(--mono);font-size:12px;word-break:break-all}</style>`;

  /** A setup check's result. */
  S.checkView = (r, at) => {
    const dk = r.dkim || [], dkPass = dk.some((s) => s.result === 'pass');
    const listed = (r.blocklists || []).filter((l) => l.listed === true), unknown = (r.blocklists || []).filter((l) => l.listed === null);
    return `${css}<div class="dv"><div class="dv-verdict ${r.verdict.level}">${ic(r.verdict.level === 'ok' ? 'check' : 'alert', 16, 2.4)}${esc(r.verdict.title)}</div>
      <div class="dv-row"><h4>Route${at ? ` <span class="faint" style="font-weight:500;font-size:12px">checked ${esc(when(at))}</span>` : ''}</h4><p>How the test email reached the receiving server. Receivers judge the last server: <b>${esc(r.sending_host || '?')}</b> (${esc(r.sending_ip || '?')}).</p>
        <div class="dv-path">${(r.path || []).map((h, i) => `${i ? '<i>→</i>' : ''}<span>${esc(h.from)} <span class="faint">${esc(h.ip || '')}</span></span>`).join('')}<i>→</i><span>receiver</span></div></div>
      <div class="dv-row"><h4>SPF ${pill(r.spf.result)}</h4><p>${esc(r.spf.detail)}${r.spf.record ? `<br><code>${esc(r.spf.record)}</code>` : ''}</p></div>
      ${r.same_server ? `<div class="callout info" style="margin:0">${ic('info', 15)}<div>Your mail server also hosts ${SL_BN()}’s check mailbox, so this test never went out over the internet. Many servers only add a DKIM signature to mail that leaves, so DKIM can’t be judged from this test. SPF, DMARC and the DNS records still apply; an inbox test at Gmail shows the full picture.</div></div>` : ''}
      <div class="dv-row"><h4>DKIM ${pill(dkPass ? 'pass' : r.same_server ? 'neutral' : dk.length ? dk[0].result : 'none')}</h4><p>${dk.length ? dk.map((s) => `${esc(s.detail)}`).join('<br>') : 'The email wasn’t signed with DKIM. Turn DKIM signing on at your provider (cPanel: Email Deliverability; SendGrid: Sender Authentication; Google Workspace: Admin → Gmail → Authenticate email) and publish the key it gives you.'}</p></div>
      <div class="dv-row"><h4>DMARC ${pill(r.dmarc.result)}</h4><p>${esc(r.dmarc.detail)}${r.dmarc.record ? `<br><code>${esc(r.dmarc.record)}</code>` : `<br>Add a TXT record at <code>_dmarc.${esc(r.from_domain)}</code>: <code>v=DMARC1; p=none; rua=mailto:dmarc@${esc(r.from_domain)}</code>`}${r.dmarc.policy === 'none' && r.dmarc.result === 'pass' ? '<br>Once reports look clean, move to <code>p=quarantine</code>: it tells Gmail you protect your domain.' : ''}</p></div>
      <div class="dv-row"><h4>Blocklists ${listed.length ? pill('fail') : `<span class="dv-pill ok">${unknown.length ? 'Clean so far' : 'Clean'}</span>`}</h4><p>${(r.blocklists || []).map((l) => `${esc(l.list)}: ${l.listed === true ? '<b style="color:var(--s-bounced)">listed</b>' : l.listed === null ? 'couldn’t check from this server' : 'not listed'}`).join(' · ') || 'Not checked.'}</p></div></div>`;
  };

  /** Starts a check for $from and keeps the box updated until it's done. */
  S.runSetupCheck = (box, from, done) => {
    box.innerHTML = '<div class="dv-row"><p><span class="spin sm"></span> Sending a test email through your own setup and reading it back… usually under a minute.</p></div>';
    API('POST', base() + '/deliverability/checks', { from }).then((r) => {
      let tries = 0;
      const poll = () => API('GET', `${base()}/deliverability/checks/${r.check.id}`).then((x) => {
        const c = x.check;
        if (c.status === 'waiting' && ++tries < 120) { setTimeout(poll, 5000); return; }
        box.innerHTML = c.status === 'done' ? S.checkView(c.result, c.finished_at) : `<div class="callout warn">${ic('alert', 15)}<div>${esc(c.error || 'The check didn’t finish.')}</div></div>`;
        done && done(c);
      }).catch((err) => { box.innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
      setTimeout(poll, 8000);
    }).catch((err) => { box.innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
  };

  /** One email's Report tab. */
  S.reportView = (rep) => {
    const d = rep.delivery, a = rep.authentication, e = rep.engagement;
    const outcome = { delivered: ['ok', 'Delivered'], bounced: ['bad', 'Bounced'], complained: ['bad', 'Marked as spam by the recipient'], rejected: ['bad', 'Rejected'], failed: ['bad', 'Failed'], suppressed: ['info', 'Not sent (suppressed)'], sent: ['info', d.via === 'smtp' ? 'Accepted by your SMTP server' : 'Accepted by Amazon SES'] }[d.outcome] || ['info', d.outcome];
    const dm = a.dmarc_reports;
    return `${css}<div class="dv">
      <div class="dv-sec">Delivery</div>
      <div class="dv-row"><h4>${esc(outcome[1])} <span class="dv-pill ${outcome[0]}">${esc(d.via === 'smtp' ? 'SMTP' : 'Amazon SES')}</span></h4><p>${d.via === 'smtp' ? `Handed to ${esc(d.host || 'your SMTP server')}${d.sent_at ? ' at ' + esc(when(d.sent_at)) : ''}.` : d.sent_at ? `Handed to Amazon SES at ${esc(when(d.sent_at))}.` : ''}
        ${d.server_reply ? `<br>The server answered: <code>${esc(d.server_reply)}</code>` : ''}${d.outcome_detail ? `<br><code>${esc(d.outcome_detail)}</code>` : ''}
        ${!d.delivery_reported && d.outcome === 'sent' ? '<br>SMTP servers don’t report delivery. Bounces that come back later by email are picked up if you connect a mailbox (Sending → Mailbox).' : ''}</p></div>
      <div class="dv-sec">Authentication (SPF · DKIM · DMARC)</div>
      ${a.check ? S.checkView(a.check.result, a.check.at) : `<div class="dv-row"><p>Not checked yet for this domain. <b>Check my setup</b> sends a test through your exact route and reads it back the way Gmail does.</p><div style="margin-top:8px"><button class="btn btn-sm btn-primary" data-dvcheck>Check my setup</button></div><div data-dvbox style="margin-top:10px"></div></div>`}
      ${dm ? `<div class="dv-row"><h4>What receivers reported <span class="dv-pill ${dm.passed === dm.messages ? 'ok' : 'warn'}">${Math.round((dm.passed / Math.max(1, dm.messages)) * 100)}% passed</span></h4><p>DMARC reports from Gmail, Yahoo and others over 30 days: ${dm.messages} emails from your domain, ${dm.passed} passed. By sending IP: ${dm.sources.slice(0, 5).map((s) => `${esc(s.ip)} ${s.passed}/${s.messages}`).join(' · ')}</p></div>` : '<div class="dv-row"><p class="faint">No DMARC reports yet. Point your DMARC <code>rua</code> address at the mailbox you connected (Sending → Mailbox) to see what Gmail and Yahoo report.</p></div>'}
      <div class="dv-sec">Content</div>
      ${rep.content.length ? rep.content.map((c) => `<div class="dv-row"><h4>${esc(c.title)} <span class="dv-pill ${c.level === 'bad' ? 'bad' : c.level === 'warn' ? 'warn' : 'info'}">${c.level === 'bad' ? 'Fix' : c.level === 'warn' ? 'Risk' : 'Tip'}</span></h4><p>${esc(c.detail)}</p></div>`).join('') : '<div class="dv-row"><p>Nothing in the content that spam filters usually penalise.</p></div>'}
      <div class="dv-sec">What people did</div>
      <div class="dv-row"><p>${e.opens ? `<b>In the inbox, opened by a person</b>${e.first_open_at ? ' at ' + esc(when(e.first_open_at)) : ''}. Gmail, Outlook and Yahoo don’t load images in the spam folder, so a real open means it reached the inbox (or Promotions).` : 'No real opens yet.'}${e.machine_opens ? ` ${e.machine_opens} automated fetch${e.machine_opens > 1 ? 'es' : ''} (mail scanners, Apple Mail Privacy) ignored.` : ''}${e.clicks ? ` ${e.clicks} click${e.clicks > 1 ? 's' : ''}.` : ''}${e.scanner_clicks ? ` ${e.scanner_clicks} scanner click${e.scanner_clicks > 1 ? 's' : ''} not counted.` : ''}</p></div>
      <div class="dv-sec">Inbox or spam?</div>
      <div class="dv-row"><p>${e.opens ? 'Inbox: confirmed by the real open above.' : rep.delivery.outcome === 'complained' ? 'Spam: the recipient reported it as spam.' : 'Not known yet. Providers don’t tell senders the folder; a real open confirms the inbox, a spam report confirms spam. Placement tests send the same design to test mailboxes at Gmail, Outlook and Yahoo and read the folder.'}</p><div style="margin-top:8px"><a class="btn btn-sm" href="#/inbox">${rep.placement.inbox_tests ? 'Run an inbox test' : 'Inbox tests (Pro)'}</a></div></div>
    </div>`;
  };
  S.bindReport = (root, from) => {
    const b = root.querySelector('[data-dvcheck]');
    if (b) b.onclick = () => { const row = b.closest('.dv-row'); b.remove(); const p = row && row.querySelector('p'); if (p) p.remove(); S.runSetupCheck(root.querySelector('[data-dvbox]'), from); };
  };
})();
