/* MailVesper: #/integrations. Stripe and Shopify webhooks into contact events, and the product API behind
   price-drop and back-in-stock journeys. Talks to /api/v1/workspaces/{id}/integrations. */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, ic, toast, confirmDialog } = S;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const role = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canManage = () => ['owner', 'admin'].includes(role());
  const base = () => `/v1/workspaces/${wsId()}`;
  const ago = (iso) => { if (!iso) return 'never'; const s = Math.max(0, (Date.now() - new Date(iso)) / 1000); return s < 90 ? 'just now' : s < 3600 ? Math.floor(s / 60) + ' min ago' : s < 86400 ? Math.floor(s / 3600) + ' h ago' : new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); };
  let gen = 0;

  const INFO = {
    stripe: {
      name: 'Stripe', blurb: 'Payments, subscriptions and checkouts become contact events.',
      steps: ['In Stripe, open <b>Developers → Webhooks → Add endpoint</b>.', 'Paste the URL below as the endpoint URL.', 'Choose the events to send (the ones below are a good start).', 'Reveal the endpoint’s <b>Signing secret</b> (whsec_…) and paste it here.'],
      secret: 'Signing secret', ph: 'whsec_…',
      events: [['stripe.checkout.session.completed', 'Checkout paid'], ['stripe.checkout.session.expired', 'Checkout abandoned (has recovery_url)'], ['stripe.invoice.paid', 'Invoice paid'], ['stripe.invoice.payment_failed', 'Payment failed (has invoice_url)'], ['stripe.customer.subscription.created', 'Subscription started'], ['stripe.customer.subscription.deleted', 'Subscription ended'], ['stripe.charge.refunded', 'Refund']],
      note: 'Events without an email (like subscriptions) are matched through the Stripe customer id, saved on the contact the first time an event carries both.',
    },
    shopify: {
      name: 'Shopify', blurb: 'Orders, checkouts and customers become contact events; product updates power price-drop and back-in-stock emails.',
      steps: ['In Shopify admin, open <b>Settings → Notifications → Webhooks → Create webhook</b>.', 'Format JSON, the URL below, and the latest API version. Add one webhook per event.', 'Copy the signing secret shown under the webhook list (“Your webhooks will be signed with…”) and paste it here.'],
      secret: 'Webhook signing secret', ph: 'from Settings → Notifications → Webhooks', shop: true,
      events: [['shopify.checkouts.create', 'Checkout started (has checkout_url)'], ['shopify.orders.create', 'Order placed'], ['shopify.orders.fulfilled', 'Order fulfilled'], ['shopify.customers.create', 'Customer created'], ['products/update', 'Product changed: price drops and restocks']],
      note: 'Customers’ email-marketing consent is saved in the accepts_marketing field: add “accepts_marketing is yes” as a filter on marketing journeys.',
    },
  };

  S.pages.integrations = (v) => {
    const g = ++gen;
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading…</div></div>';
    API('GET', base() + '/integrations').then((r) => {
      if (g !== gen) return;
      const site = location.origin;
      v.innerHTML = `<div class="page-head"><div><h1>Integrations</h1><p>Events from your store and payments start journeys and keep contacts up to date.</p></div></div>
        <style>.ig{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,460px),1fr));gap:16px}.ig-steps{margin:0 0 12px;padding-left:18px;color:var(--text-2);font-size:13.5px;line-height:1.7}
        .ig-ev{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px}.ig-ev:last-child{border-bottom:0}.ig-ev span{color:var(--text-2)}
        .ig-url{display:flex;gap:8px;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:8px 10px;font-family:var(--mono);font-size:12px;word-break:break-all}
        .ig-pre{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px;font-family:var(--mono);font-size:12px;overflow:auto;white-space:pre}</style>
        <div class="ig">${r.integrations.map((it) => card(it)).join('')}
        <div class="card"><div class="card-head"><b>Any store: product updates</b></div><div class="card-body">
          <p class="faint" style="margin:0 0 10px;font-size:13.5px">Not on Shopify? Send each product’s price and stock when they change, and the events that show interest. ${SL_BN()} keeps the last price and stock and does the rest.</p>
          <div class="ig-pre">POST ${esc(site)}/api/v1/products
Authorization: Bearer &lt;full-access API key&gt;
{"product_id": "sku-123", "price": 39.00, "in_stock": true,
 "name": "Linen shirt", "url": "https://…", "image": "https://…"}</div>
          <p class="faint" style="margin:10px 0 6px;font-size:13.5px">Interest comes from events you send to <span class="mono">POST /v1/events</span> with <span class="mono">"product_id"</span> in the properties:</p>
          <div class="ig-ev"><b class="mono">product_viewed</b><span>price drops (30 days) and restocks</span></div><div class="ig-ev"><b class="mono">added_to_cart</b><span>browse-abandoned recipe</span></div><div class="ig-ev"><b class="mono">back_in_stock_requested</b><span>“notify me” buttons (90 days)</span></div>
          <p class="ins-help" style="margin-top:10px">Then use the <b>Price drop</b>, <b>Back in stock</b> or <b>Browse abandoned</b> recipes under <a href="#/automations">Automations</a>.</p>
        </div></div></div>`;
      S.bindCopy && S.bindCopy(v);
      r.integrations.forEach((it) => bind(it));
    }).catch((err) => g === gen && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  function card(it) {
    const i = INFO[it.provider], p = it.provider, ro = !canManage();
    const status = !it.connected ? '<span class="chip processing">Not connected</span>' : !it.enabled ? '<span class="chip paused">Paused</span>' : it.last_error ? '<span class="chip bounced">Needs attention</span>' : it.events_received ? '<span class="chip delivered">Receiving</span>' : '<span class="chip processing">Waiting for the first event</span>';
    return `<div class="card" id="ig-${p}"><div class="card-head" style="display:flex;justify-content:space-between;align-items:center"><b>${i.name}</b>${status}</div><div class="card-body">
      <p class="faint" style="margin:0 0 12px;font-size:13.5px">${i.blurb}</p>
      ${it.connected ? `<div class="field"><label>Webhook URL</label><div class="ig-url"><span style="flex:1">${esc(it.webhook_url)}</span><button class="icon-btn" data-copy="${esc(it.webhook_url)}" title="Copy">${ic('copy', 14)}</button></div></div>
        <p class="faint" style="font-size:12.5px;margin:-4px 0 12px">${fmt(it.events_received)} events · last ${ago(it.last_event_at)}</p>
        ${it.last_error ? `<div class="callout warn" style="margin-bottom:12px">${ic('alert', 15)}<div>${esc(it.last_error)} <span class="faint">(${ago(it.last_error_at)})</span></div></div>` : ''}` : ''}
      <ol class="ig-steps">${i.steps.map((s) => `<li>${s}</li>`).join('')}</ol>
      ${ro ? '<p class="faint" style="font-size:13px">Owners and admins connect integrations.</p>' : `<div class="field"><label>${i.secret}${it.has_secret ? ' <span class="faint">(saved; paste a new one to replace it)</span>' : ''}</label><input type="password" autocomplete="off" id="sec-${p}" placeholder="${esc(i.ph)}"></div>
        ${i.shop ? `<div class="field"><label>Store address <span class="faint">(for product links)</span></label><input id="shop-${p}" value="${esc(it.shop_domain || '')}" placeholder="your-store.myshopify.com"></div>` : ''}
        <div style="display:flex;gap:8px"><button class="btn btn-primary btn-sm" id="save-${p}">${it.connected ? 'Save' : 'Connect'}</button>${it.connected ? `<button class="btn btn-sm" id="pause-${p}">${it.enabled ? 'Pause' : 'Resume'}</button><button class="btn btn-sm btn-ghost" id="del-${p}">Disconnect</button>` : ''}</div>`}
      <div class="ins-label" style="margin:16px 0 4px">Events you can start journeys from</div>
      ${i.events.map(([e, d]) => `<div class="ig-ev"><b class="mono">${esc(e)}</b><span>${esc(d)}</span></div>`).join('')}
      <p class="ins-help" style="margin-top:10px">${i.note} In a journey, pick <b>Custom event</b> (or the ${i.name} entry under Integrations) and use these names.</p>
    </div></div>`;
  }

  function bind(it) {
    const p = it.provider, save = $('#save-' + p);
    if (!save) return;
    save.onclick = () => {
      const body = {}, sec = $('#sec-' + p).value.trim(), shop = $('#shop-' + p);
      if (sec) body.secret = sec;
      if (shop) body.shop_domain = shop.value.trim() || null;
      if (!it.connected && !sec) return toast(`Paste the ${INFO[p].secret.toLowerCase()} first.`);
      save.disabled = true;
      API('PUT', `${base()}/integrations/${p}`, body).then(() => { toast(`${INFO[p].name} ${it.connected ? 'saved' : 'connected'}`); S.route(); }).catch((err) => { save.disabled = false; toast(err.message); });
    };
    const pause = $('#pause-' + p); if (pause) pause.onclick = () => API('PUT', `${base()}/integrations/${p}`, { enabled: !it.enabled }).then(() => S.route()).catch((err) => toast(err.message));
    const del = $('#del-' + p); if (del) del.onclick = () => confirmDialog({ title: `Disconnect ${INFO[p].name}?`, desc: 'Its webhook URL stops working. Contacts and their past events stay.', onConfirm: () => API('DELETE', `${base()}/integrations/${p}`).then(() => { toast('Disconnected'); S.route(); }) });
  }
})();
