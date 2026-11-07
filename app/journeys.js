/* MailVesper: Automations (journeys), on the real API (Phase 8).
   Editor modelled on n8n's canvas: full-screen dotted canvas, left-to-right flow, pan / zoom / fit, minimap,
   + on every connection, a node panel to add steps and a settings panel for the selected step. From Klaviyo and
   Customer.io: a wide trigger catalogue, re-entry rules, and live counts on every step and connection.
   The draft autosaves; "Turn on" / "Publish changes" freezes a version that new entries follow.
   Loaded after campaigns.js (it also hooks the email builder for journey emails). */
(function () {
  'use strict';
  const S = window.SL;
  const { $, $$, esc, fmt, pct, ic, chip, toast, bindCopy, modal, confirmDialog, menu } = S;
  const pages = S.pages;
  const API = (m, p, b) => window.SL_API(m, p, b);
  const me = () => window.SL_ME || { user: {}, workspaces: [] };
  const wsId = () => me().current_workspace_id;
  const myRole = () => ((me().workspaces || []).find((w) => w.id === wsId()) || {}).role;
  const canEdit = () => !!myRole() && myRole() !== 'viewer';
  const base = () => `/v1/workspaces/${wsId()}`;
  S.SUBTITLES = Object.assign(S.SUBTITLES || {}, { 'automations/edit': 'Journey editor' });
  let gen = 0;
  let seq = 0;
  const alive = (g) => g === gen && seq === S.routeSeq; // still this module's latest page, and still on screen
  const day = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

  // ─── Catalogue. A null kind = shown, not available yet. ───
  const TRIGGERS = [
    ['Contacts', 'contacts', [['contact_created', 'Contact created', 'Added by API, form, webhook or by hand'], ['list_added', 'Added to a list', 'e.g. Newsletter'], ['tag_added', 'Tag added', 'A specific tag'], ['field_changed', 'Field changed', 'e.g. plan changes to Pro'], ['segment_joined', 'Joined a segment', 'Starts matching a saved segment'], ['segment_left', 'Left a segment', 'Stops matching a saved segment'], ['form_submitted', 'Form submitted', 'A ' + SL_BN() + ' signup form'], ['optin_confirmed', 'Opt-in confirmed', 'Double opt-in click']]],
    ['App events', 'zap', [['event', 'Custom event', 'Any event sent to the track API'], ['event:user_signed_up', 'Signed up', 'Event: user_signed_up'], ['event:trial_started', 'Trial started', 'Event: trial_started'], ['date:trial_ends_at:-3', 'Trial ending', '3 days before trial_ends_at'], ['event:plan_upgraded', 'Plan upgraded', 'Event: plan_upgraded'], ['event:payment_failed', 'Payment failed', 'Event: payment_failed'], ['inactive:app_opened', 'Inactive in app', 'No app_opened for N days']]],
    ['Commerce', 'card', [['event:order_placed', 'Order placed', 'Event: order_placed'], ['event:checkout_started', 'Checkout started', 'Pair with “Wait for event: order_placed”'], ['date:renews_at:-7', 'Renewal coming up', '7 days before renews_at'], ['event:product_viewed', 'Browse abandoned', 'Event: product_viewed (see the recipe)'], ['price_drop', 'Price drop', 'On a product they viewed'], ['back_in_stock', 'Back in stock', 'Product they asked about or viewed']]],
    ['Email engagement', 'mail', [['email_opened', 'Opened an email', 'Real opens; Apple machine opens excluded'], ['email_clicked', 'Clicked a link', 'Any link, or a URL containing…'], ['went_quiet', 'Went quiet', 'No real opens or clicks in N days'], ['email_replied', 'Replied', 'A reply to any of your emails (Mailbox)'], ['topic_unsubscribed', 'Unsubscribed from a topic', 'Keeps other topics']]],
    ['Dates', 'clock', [['date:birthday:0', 'Birthday', 'Every year, from the birthday field'], ['date:created_at:0', 'Anniversary', 'Every year, from when they joined'], ['date', 'Date field', 'Before, on or after any date field'], ['schedule', 'On a schedule', 'Every day, week or month']]],
    ['Integrations', 'webhooks', [['webhook', 'Incoming webhook', 'POST to this journey’s URL'], ['webhook:zap', 'Zapier or n8n', 'An HTTP step that posts to the journey URL'], ['event:stripe.invoice.paid', 'Stripe event', 'e.g. stripe.invoice.paid (Integrations)'], ['event:shopify.orders.create', 'Shopify event', 'e.g. shopify.orders.create (Integrations)']]],
  ];
  const STEPS = [
    ['AI', [['ai_agent', 'AI agent', 'Looks at the contact, decides, acts with tools'], ['ai_write', 'Write with AI', 'A line, a summary, a reply'], ['ai_classify', 'Classify with AI', 'One path per category'], ['ai_extract', 'Extract with AI', 'Pull fields out of text'], ['ai_sentiment', 'Sentiment with AI', 'Positive, neutral or negative: a path each'], ['ai_summarize', 'Summarize with AI', 'A sentence, a paragraph or bullets']]],
    ['Messages', [['email', 'Send email', 'A design, from your sender'], ['notify', 'Notify your team', 'Email or Slack message']]],
    ['Timing', [['wait', 'Wait', 'A fixed time'], ['waitfor', 'Wait for event', 'Or give up after a while'], ['window', 'Send window', 'e.g. weekdays 9:00–18:00 their time']]],
    ['Logic', [['cond', 'If / else', 'Fields, tags, lists, events, opens'], ['switch', 'Switch', 'A path per value, e.g. per plan'], ['filter', 'Filter', 'Go on only if…, otherwise leave'], ['split', 'A/B split', 'Random split by percentage'], ['goal', 'Goal', 'Contacts who do it leave the journey'], ['exit', 'Exit', 'End the journey here'], ['stop', 'Stop and error', 'End the run as failed, with a reason']]],
    ['Data', [['set', 'Edit fields', 'Work out values for later steps'], ['datetime', 'Date & Time', 'Add to a date, format it, days until…']]],
    ['Human', [['approval', 'Human approval', 'A teammate approves or rejects by email']]],
    ['Contact', [['tag', 'Add or remove tag', ''], ['field', 'Update field', ''], ['list', 'Add to or remove from list', '']]],
    ['Developers', [['http', 'HTTP Request', 'Call any API; its answer is the output'], ['webhook', 'Send webhook', 'Signed POST to your app'], ['jump', 'Move to journey', 'Hand off to another journey']]],
  ];
  // n8n's node creator: categories you open, with a back arrow, or search across everything.
  const TRIG_CATS = { Contacts: ['When a contact changes', 'Created, tagged, added to a list, joins a segment, signs up', 'user'], 'App events': ['On an event from your app', 'Anything your app sends to the track API', 'zap'],
    Commerce: ['On a purchase or product change', 'Orders, checkouts, price drops, back in stock', 'cart'],
    'Email engagement': ['On email engagement', 'Opens, clicks, replies, going quiet', 'mailopen'], Dates: ['On a date or schedule', 'Birthdays, renewals, every day / week / month', 'cal'],
    Integrations: ['On webhook call or from an app', 'Your journey URL, Zapier, n8n, Stripe, Shopify', 'hook'] };
  const STEP_CATS = { AI: ['AI', 'An agent that decides and acts, or write, classify, extract', 'ai_agent'], Messages: ['Send a message', 'Email the contact or notify your team', 'email'],
    Contact: ['Update the contact', 'Tags, fields and lists', 'tag'], Timing: ['Wait', 'A fixed time, an event, or a send window', 'wait'],
    Logic: ['Flow', 'If / else, Switch, Filter, splits, goals and exits', 'cond'], Data: ['Data transformation', 'Edit fields, dates and times', 'set'],
    Human: ['Human review', 'Wait for a teammate to approve', 'approval'], Developers: ['Action in an app', 'HTTP requests, webhooks, other journeys', 'http'] };
  // AI sub-nodes (n8n's cluster nodes): they plug in under an AI step through a port, not into the flow.
  const SUB_TYPES = ['ai_model', 'ai_memory', 'ai_tool', 'ai_parser'];
  const isSub = (n) => !!n && SUB_TYPES.includes(n.type);
  /** A node's default name: a Tool is called after what it does (HTTP Request, Calculator…), as in n8n. */
  const nameOf = (n) => n.title || (n.type === 'ai_tool' ? (TOOL_KINDS.find((t) => t[0] === (n.config || {}).tool) || [0, 'Tool'])[1] : NAME[n.type]);
  const SUB_W = 104;
  /** An AI step's ports: [connection, label, required, how many]. */
  const portsOf = (n) => (n.type === 'ai_agent' ? [['ai_model', 'Chat Model', true, 1], ['ai_memory', 'Memory', false, 1], ['ai_tool', 'Tool', false, 10], ['ai_parser', 'Output Parser', false, 1]]
    : ['ai_write', 'ai_classify', 'ai_extract', 'ai_sentiment', 'ai_summarize'].includes(n.type) ? [['ai_model', 'Chat Model', false, 1]] : []);
  // [kind, name, what it does, glyph]
  const TOOL_KINDS = [['contact', 'Read contact', 'Profile, fields, tags and lists', 'user'], ['activity', 'Read activity', 'Latest events and emails, opens, clicks', 'mailopen'],
    ['tags', 'Tag contact', 'Add and remove tags', 'tag'], ['fields', 'Update fields', 'Only the fields you allow', 'field'], ['lists', 'Lists', 'Add to / remove from lists you allow', 'list'],
    ['http', 'HTTP Request', 'Any API: the agent fills the parameters', 'http'], ['notify', 'Notify team', 'Email your team', 'notify'],
    ['think', 'Think', 'Room to reason before acting', 'think'], ['calculator', 'Calculator', 'Exact arithmetic', 'calc']];
  const PROVIDERS = { anthropic: 'Anthropic (Claude)', openai: 'OpenAI (GPT)', google: 'Google (Gemini)', 'meta-llama': 'Meta (Llama)', mistralai: 'Mistral', deepseek: 'DeepSeek', 'x-ai': 'xAI (Grok)', qwen: 'Qwen' };
  const providerOf = (m) => PROVIDERS[String(m).split('/')[0]] || String(m).split('/')[0];
  const TYPE = {
    trigger: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'Trigger'],
    email: ['mail', 'var(--s-opened-bg)', 'var(--s-opened)', 'Email'],
    wait: ['clock', 'var(--surface-2)', 'var(--text-2)', 'Wait'], waitfor: ['clock', 'var(--surface-2)', 'var(--text-2)', 'Wait for event'], window: ['clock', 'var(--surface-2)', 'var(--text-2)', 'Send window'],
    cond: ['split', 'var(--s-clicked-bg)', 'var(--s-clicked)', 'If / else'], split: ['split', 'var(--s-clicked-bg)', 'var(--s-clicked)', 'A/B split'],
    tag: ['tag', 'var(--s-processing-bg)', 'var(--s-processing)', 'Contact'], field: ['edit', 'var(--s-processing-bg)', 'var(--s-processing)', 'Contact'], list: ['contacts', 'var(--s-processing-bg)', 'var(--s-processing)', 'Contact'],
    notify: ['bell', 'var(--s-deferred-bg)', 'var(--s-deferred)', 'Notify'], webhook: ['webhooks', 'var(--surface-2)', 'var(--text-2)', 'Webhook'], jump: ['right', 'var(--surface-2)', 'var(--text-2)', 'Move'],
    goal: ['check', 'var(--s-delivered-bg)', 'var(--s-delivered)', 'Goal'], exit: ['x', 'var(--surface-2)', 'var(--text-3)', 'Exit'],
    switch: ['split', 'var(--s-clicked-bg)', 'var(--s-clicked)', 'Switch'], filter: ['split', 'var(--s-clicked-bg)', 'var(--s-clicked)', 'Filter'], stop: ['x', 'var(--s-bounced-bg)', 'var(--s-bounced)', 'Stop'],
    set: ['edit', 'var(--surface-2)', 'var(--text-2)', 'Data'], datetime: ['clock', 'var(--surface-2)', 'var(--text-2)', 'Data'], http: ['webhooks', 'var(--surface-2)', 'var(--text-2)', 'HTTP'],
    approval: ['check', 'var(--s-deferred-bg)', 'var(--s-deferred)', 'Human'],
    ai_model: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'Chat Model'], ai_memory: ['zap', 'var(--s-processing-bg)', 'var(--s-processing)', 'Memory'],
    ai_tool: ['zap', 'var(--surface-2)', 'var(--text-2)', 'Tool'], ai_parser: ['zap', 'var(--s-clicked-bg)', 'var(--s-clicked)', 'Output Parser'], ai_sentiment: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI'], ai_summarize: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI'],
    ai_write: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI'], ai_classify: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI'], ai_extract: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI'], ai_agent: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'AI agent'],
  };
  // ─── A glyph per step and per kind of trigger (n8n gives every node its own icon; colour stays by category) ───
  const svgI = (d, sz = 18) => `<svg width="${sz}" height="${sz}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
  const GLYPH = {
    zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
    email: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    wait: '<path d="M5 22h14M5 2h14M17 22v-4.17a2 2 0 0 0-.59-1.41L12 12l-4.41 4.42A2 2 0 0 0 7 17.83V22M7 2v4.17a2 2 0 0 0 .59 1.41L12 12l4.41-4.42A2 2 0 0 0 17 6.17V2"/>',
    waitfor: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M5 3 2 6M22 6l-3-3"/>',
    window: '<path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5M16 2v4M8 2v4M3 10h5"/><circle cx="16" cy="16" r="6"/><path d="M16 14v2l1 1"/>',
    cond: '<path d="M12 3v3M12 18v3M7 6h10l3 3-3 3H7zM17 12H10l-3 3 3 3h7"/>',
    split: '<path d="M16 3h5v5M8 3H3v5M12 22v-8.3a4 4 0 0 0-1.17-2.83L3 3M21 3l-7.83 7.83"/>',
    goal: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
    exit: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    tag: '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    field: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    list: '<path d="M11 12H3M16 6H3M16 18H3M18 9v6M21 12h-6"/>',
    notify: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    slack: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    webhook: '<path d="m18 16 4-4-4-4M6 8l-4 4 4 4M14.5 4l-5 16"/>',
    jump: '<circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 16l4-4-4-4"/>',
    hook: '<path d="M18 16.98h-6c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2M6 17l3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06M12 6l3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    form: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h5"/>',
    mailopen: '<path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6Z"/><path d="m22 10-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 10"/>',
    user: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    cart: '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    play: '<path d="M6 3l14 9-14 9z"/>',
    switch: '<path d="M3 12h5l4-7h9M12 12h9M12 12l-4 7h13"/><path d="m18 2 3 3-3 3M18 9l3 3-3 3M18 16l3 3-3 3"/>',
    filter: '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
    stop: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
    set: '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
    datetime: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
    http: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    approval: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    ai_sentiment: '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    ai_summarize: '<path d="M15 12h6M15 6h6M3 18h18M3 12l3-3-3-3M9 6h.01"/>',
    think: '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/>',
    calc: '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>',
    parser: '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
    chip: '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9zM9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
    memory: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/>',
    tool: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    ai_write: '<path d="M9.94 14.06 4 20M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5zM19 14l.75 2.25L22 17l-2.25.75L19 20l-.75-2.25L16 17l2.25-.75z"/>',
    ai_classify: '<path d="M12 3v4M12 7 5 13v8M12 7l7 6v8M3 21h4M17 21h4M12 3h.01"/><circle cx="12" cy="3" r="1"/>',
    ai_extract: '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8h8M7 12h10M7 16h6"/>',
    ai_agent: '<path d="M12 8V4H8"/><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/>',
  };
  const TRIG_GLYPH = { contact_created: 'user', list_added: 'list', tag_added: 'tag', field_changed: 'field', segment_joined: 'user', segment_left: 'user', form_submitted: 'form', optin_confirmed: 'form',
    topic_unsubscribed: 'mailopen', event: 'zap', inactive: 'moon', went_quiet: 'moon', price_drop: 'cart', back_in_stock: 'cart', email_opened: 'mailopen', email_clicked: 'mailopen', email_replied: 'mailopen', date: 'cal', schedule: 'cal', webhook: 'hook' };
  const glyphOf = (n) => (n.type === 'trigger' ? TRIG_GLYPH[(n.config || {}).trigger] || 'zap' : n.type === 'notify' && (n.config || {}).channel === 'slack' ? 'slack'
    : n.type === 'ai_model' ? 'chip' : n.type === 'ai_memory' ? 'memory' : n.type === 'ai_parser' ? 'parser' : n.type === 'ai_tool' ? ((TOOL_KINDS.find((t) => t[0] === (n.config || {}).tool) || [])[3] || 'tool') : n.type);
  const nodeGlyph = (n, sz) => svgI(GLYPH[glyphOf(n)] || GLYPH.zap, sz);

  // ─── Data views for the node panel's Input and Output: Schema, Table, JSON (as in n8n) ───
  const typeName = (v) => (v === null ? 'null' : Array.isArray(v) ? (v.length ? 'list' : 'empty') : typeof v === 'object' ? 'object' : typeof v);
  const short = (v) => { const t = typeof v === 'string' ? v : JSON.stringify(v); return t.length > 90 ? t.slice(0, 90) + '…' : t; };
  const isObj = (v) => v !== null && typeof v === 'object';
  const entriesOf = (v) => (Array.isArray(v) ? v.slice(0, 50).map((x, i) => [String(i), x]) : Object.entries(v));
  const schemaView = (v, path, depth) => {
    if (!isObj(v)) return '';
    const en = entriesOf(v);
    if (!en.length) return '<div class="dv-none">empty</div>';
    return en.map(([k, x]) => {
      const p = path ? path + '.' + k : k;
      if (isObj(x)) return `<details class="dv-obj" ${depth < 2 ? 'open' : ''}><summary class="dv-row" draggable="true" data-path="${esc(p)}" title="Drag into a setting"><span class="dv-k">${esc(k)}</span><span class="dv-t">${typeName(x)}${Array.isArray(x) && x.length ? ' · ' + x.length : ''}</span><span></span></summary><div class="dv-kids">${schemaView(x, p, depth + 1)}</div></details>`;
      return `<div class="dv-row dv-leaf" draggable="true" data-path="${esc(p)}" title="Drag into a setting, or click to insert {{ ${esc(p)} }}"><span class="dv-k">${esc(k)}</span><span class="dv-t">${typeName(x)}</span><span class="dv-v">${x === null || x === '' ? '<i>empty</i>' : esc(short(x))}</span></div>`;
    }).join('');
  };
  const flatten = (v, path, out) => { if (isObj(v)) { if (out.length < 400) entriesOf(v).forEach(([k, x]) => flatten(x, path ? path + '.' + k : k, out)); } else out.push([path, v]); return out; };
  const dataView = (v, path, mode) => {
    if (mode === 'json') return `<pre class="dv-json">${esc(JSON.stringify(v, null, 2))}</pre>`;
    if (mode === 'table') return `<table class="tbl dv-tbl"><tbody>${flatten(v, path, []).map(([p, x]) => `<tr><td class="mono dv-leaf" draggable="true" data-path="${esc(p)}">${esc(path ? p.slice(path.length + 1) : p)}</td><td>${x === null || x === '' ? '<i class="faint">empty</i>' : esc(short(x))}</td></tr>`).join('')}</tbody></table>`;
    return `<div class="dv-schema">${schemaView(v, path, 0)}</div>`;
  };
  /** The same expressions the server fills (JourneyData::resolve): {{ contact.… }}, {{ trigger.… }}, {{ steps.<id>.… }}. */
  const EXPR = /\{\{\s*((?:contact|trigger|steps)(?:\.[A-Za-z0-9_-]+)+)\s*\}\}/g;
  const resolveExpr = (text, data) => String(text).replace(EXPR, (_, p) => {
    let v = data;
    for (const k of p.split('.')) { if (!isObj(v) || !(k in v)) { v = null; break; } v = v[k]; }
    return v == null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v);
  });
  const hasExpr = (text) => { EXPR.lastIndex = 0; const r = EXPR.test(String(text || '')); EXPR.lastIndex = 0; return r; };

  const OUTPUTS = { trigger: [''], email: [''], wait: [''], window: [''], tag: [''], field: [''], list: [''], notify: [''], webhook: [''], waitfor: ['yes', 'no'], cond: ['yes', 'no'], split: ['a', 'b'], goal: [], exit: [], jump: [],
    ai_write: [''], ai_extract: [''], ai_agent: [''], ai_classify: ['other'], switch: ['other'], filter: [''], set: [''], datetime: [''], http: [''], stop: [], approval: ['yes', 'no'],
    ai_sentiment: ['positive', 'neutral', 'negative'], ai_summarize: [''], ai_model: [], ai_memory: [], ai_tool: [], ai_parser: [] };
  const BRANCH = { yes: 'Yes', no: 'No', a: 'A', b: 'B', other: 'Other', error: 'Error', positive: 'Positive', neutral: 'Neutral', negative: 'Negative' };
  const AI_TYPES = ['ai_write', 'ai_classify', 'ai_extract', 'ai_agent', 'ai_sentiment', 'ai_summarize'];
  // Steps that can fail have an “On error” setting (JourneyGraph::FAILABLE): continue, stop, or an Error connection.
  const FAILABLE = ['email', 'tag', 'field', 'list', 'notify', 'webhook', 'http', 'datetime', 'approval', ...AI_TYPES];
  /** The connections a step can have (JourneyGraph::outputs): a Classify step's categories, and Error when it takes one. */
  const outs = (n) => {
    const c = n.config || {}, base = OUTPUTS[n.type] || [];
    const cats = n.type === 'ai_classify' ? (c.categories || []).map((k) => k.id).filter(Boolean) : n.type === 'switch' ? (c.cases || []).map((k) => k.id).filter(Boolean) : [];
    return cats.concat(base, FAILABLE.includes(n.type) && c.on_error === 'branch' ? ['error'] : []);
  };
  const brName = (n, b) => {
    if (n && n.type === 'approval') return b === 'yes' ? 'Approved' : b === 'no' ? 'Not approved' : BRANCH[b] || b;
    if (!(n && (n.type === 'ai_classify' || n.type === 'switch') && b !== 'other' && b !== 'error')) return BRANCH[b] || b;
    const sw = n.type === 'switch', cs = (sw ? n.config.cases : n.config.categories) || [], i = cs.findIndex((k) => k.id === b);
    if (i < 0) return sw ? 'Case' : 'Category';
    return cs[i].name || (sw ? (cs[i].value !== '' && cs[i].value != null ? `${OP_LABEL[cs[i].op] || cs[i].op} ${cs[i].value}` : 'Case ' + (i + 1)) : 'Category ' + (i + 1));
  };
  const brTone = (b) => (b === 'yes' || b === 'a' || b === 'positive' ? 'yes' : b === 'error' || b === 'negative' ? 'err' : b === 'no' || b === 'b' || b === 'other' ? 'no' : 'cat');
  // Default step names (the small caps label above stays the category).
  const NAME = { trigger: 'Trigger', email: 'Email', wait: 'Wait', waitfor: 'Wait for event', window: 'Send window', cond: 'If / else', split: 'A/B split', tag: 'Tag', field: 'Update field', list: 'List', notify: 'Notify your team', webhook: 'Send webhook', jump: 'Move to journey', goal: 'Goal', exit: 'Exit', ai_write: 'Write with AI', ai_classify: 'Classify with AI', ai_extract: 'Extract with AI', ai_agent: 'AI agent',
    ai_model: 'Chat Model', ai_memory: 'Simple Memory', ai_tool: 'Tool', ai_parser: 'Output Parser', switch: 'Switch', filter: 'Filter', set: 'Edit fields', datetime: 'Date & Time', http: 'HTTP Request', stop: 'Stop and error', approval: 'Human approval', ai_sentiment: 'Sentiment with AI', ai_summarize: 'Summarize with AI' };
  const OUTCOME = { passed: 'Passed the filter', filtered: 'Filtered out', stopped: 'Stopped', positive: 'Positive', neutral: 'Neutral', negative: 'Negative', entered: 'Entered', sent: 'Sent', skipped: 'Skipped', failed: 'Failed', done: 'Done', waited: 'Waited', in_window: 'In the send window', yes: 'Went down Yes', no: 'Went down No', a: 'Went down A', b: 'Went down B', goal: 'Reached the goal', exit: 'Left', moved: 'Moved' };
  const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const DAYS_LONG = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  const ordinal = (d) => d + (d % 10 === 1 && d !== 11 ? 'st' : d % 10 === 2 && d !== 12 ? 'nd' : d % 10 === 3 && d !== 13 ? 'rd' : 'th');
  const FIELD_LABEL = { email: 'Email', first_name: 'First name', last_name: 'Last name', status: 'Status', source: 'Source', created_at: 'Date added', last_opened_at: 'Last real open', last_clicked_at: 'Last click', last_sent_at: 'Last email sent', engagement: 'Engagement score', dormant: 'Dormant', tag: 'Tag', list: 'List', event: 'Did an event', journey_email: 'Email in this journey', data: 'A value from the data…' };
  const OP_LABEL = { is: 'is', is_not: 'is not', contains: 'contains', not_contains: 'doesn’t contain', starts_with: 'starts with', ends_with: 'ends with', is_set: 'has a value', is_not_set: 'is empty', eq: '=', neq: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', before: 'is before', after: 'is after', within_days: 'is in the last', not_within_days: 'is not in the last', in_next_days: 'is in the next', is_true: 'is yes', is_false: 'is no', has: 'has', has_not: 'doesn’t have', in: 'is in', not_in: 'is not in', performed: 'did', not_performed: 'didn’t do', opened: 'was opened', clicked: 'was clicked', not_opened: 'wasn’t opened', not_clicked: 'wasn’t clicked' };
  const kindConfig = (k) => {
    if (!k) return null;
    const [t, a, b] = k.split(':');
    if (t === 'event') return { trigger: 'event', event: a || '' };
    if (t === 'inactive') return { trigger: 'inactive', activity: a || 'app_opened', days: 14 };
    if (t === 'price_drop') return { trigger: 'price_drop', min_percent: 0 };
    if (t === 'went_quiet') return { trigger: 'went_quiet', days: 60 };
    if (t === 'schedule') return { trigger: 'schedule', every: 'week', weekday: 1, monthday: 1, hour: 9 };
    if (t === 'date') return a ? { trigger: 'date', field: a, offset_days: +b || 0, hour: 9, yearly: ['birthday', 'created_at'].includes(a) } : { trigger: 'date', field: '', offset_days: 0, hour: 9, yearly: false };
    return { trigger: t };
  };
  const unitTxt = (c) => (c && c.amount ? `${c.amount} ${c.amount === 1 ? String(c.unit).replace(/s$/, '') : c.unit}` : '');
  const describe = (n, ctx) => {
    const c = n.config || {};
    switch (n.type) {
      case 'trigger': return ({ contact_created: 'Contact created', list_added: `List: ${(ctx.lists.find((l) => l.id === c.list_id) || {}).name || 'choose a list'}`, tag_added: `Tag: ${c.tag || 'choose a tag'}`, field_changed: `${c.field || 'field'} changes${c.to ? ' to ' + c.to : ''}`,
        segment_joined: `Joins “${(ctx.segments.find((x) => x.id === c.segment_id) || {}).name || 'choose a segment'}”`, segment_left: `Leaves “${(ctx.segments.find((x) => x.id === c.segment_id) || {}).name || 'choose a segment'}”`,
        form_submitted: c.form_id ? `Signs up on “${(ctx.forms.find((x) => x.id === c.form_id) || {}).name || 'form'}”` : 'Signs up on any form',
        optin_confirmed: c.form_id ? `Confirms on “${(ctx.forms.find((x) => x.id === c.form_id) || {}).name || 'form'}”` : 'Confirms on any form',
        topic_unsubscribed: c.topic_id ? `Turns off “${(ctx.topics.find((x) => x.id === c.topic_id) || {}).name || 'topic'}”` : 'Turns off any topic',
        price_drop: `Price drops${c.min_percent ? ' by ' + c.min_percent + '% or more' : ''} on a product they viewed`, back_in_stock: 'A product they wanted is back',
        inactive: `No ${c.activity || 'event'} for ${c.days || '?'} days`, went_quiet: `No real opens or clicks for ${c.days || '?'} days`,
        schedule: `Every ${c.every === 'day' ? 'day' : c.every === 'month' ? `month on the ${+c.monthday === 31 ? 'last day' : ordinal(c.monthday || 1)}` : DAYS_LONG[(c.weekday || 1) - 1]} · ${String(c.hour ?? 9).padStart(2, '0')}:00`,
        event: `Event: ${c.event || 'name it'}`, email_opened: 'Real open of any email', email_replied: 'A reply to any email (read from your mailbox)', email_clicked: c.url_contains ? `Click on a URL with “${c.url_contains}”` : 'Click on any link', webhook: 'POST to the journey URL',
        date: c.field ? `${c.offset_days ? Math.abs(c.offset_days) + ' days ' + (c.offset_days < 0 ? 'before ' : 'after ') : 'On '}${c.field}${c.yearly ? ', every year' : ''} · ${String(c.hour ?? 9).padStart(2, '0')}:00` : 'Choose a date field' })[c.trigger] || 'What starts this journey?';
      case 'email': { const e = ctx.emails[n.id]; return e && e.subject ? e.subject : 'No subject yet'; }
      case 'wait': return unitTxt(c) || 'Set a time';
      case 'waitfor': return c.event ? `${c.event === 'email.opened' ? 'Opens' : c.event === 'email.clicked' ? 'Clicks' : c.event === 'email.replied' ? 'Replies to' : c.event}${c.node ? ' “' + ((ctx.emails[c.node] || {}).subject || 'email') + '”' : ''} · up to ${unitTxt(c)}` : 'Choose an event';
      case 'window': return c.days && c.days.length ? `${c.days.join() === '1,2,3,4,5' ? 'Weekdays' : c.days.map((d) => DAYS[d - 1]).join(' ')} ${c.from}–${c.to}` : 'Pick days and hours';
      case 'cond': return c.rules && c.rules.conditions && c.rules.conditions.length ? `${c.rules.conditions.length} condition${c.rules.conditions.length > 1 ? 's' : ''} · match ${c.rules.match}` : 'Add a condition';
      case 'split': return `${c.percent_a ?? 50}% A · ${100 - (c.percent_a ?? 50)}% B`;
      case 'goal': return c.event ? `Event: ${c.event}` : 'Choose the goal event';
      case 'tag': return c.tag ? `${c.action === 'remove' ? 'Remove' : 'Add'} “${c.tag}”` : 'Choose a tag';
      case 'field': return c.key ? `${c.key} = ${c.value || '(empty)'}` : 'Choose a field';
      case 'list': return c.list_id ? `${c.action === 'remove' ? 'Remove from' : 'Add to'} ${(ctx.lists.find((l) => l.id === c.list_id) || {}).name || 'list'}` : 'Choose a list';
      case 'notify': return c.channel === 'slack' ? 'Slack message' : (c.to && c.to.length ? 'Email ' + c.to.join(', ') : 'Who to notify?');
      case 'webhook': return c.url ? c.url.replace(/^https?:\/\//, '') : 'Your endpoint URL';
      case 'jump': return (ctx.journeys.find((x) => x.id === c.journey_id) || {}).name || 'Choose a journey';
      case 'exit': return 'Leaves the journey';
      case 'ai_write': return c.prompt ? c.prompt.slice(0, 80) : 'What should it write?';
      case 'ai_classify': { const k = (c.categories || []).filter((x) => x.name); return k.length ? k.map((x) => x.name).join(' · ') + ' · Other' : 'Add categories'; }
      case 'ai_extract': { const k = (c.fields || []).filter((x) => x.name); return k.length ? 'Finds ' + k.map((x) => x.name).join(', ') : 'Choose what to take out'; }
      case 'ai_agent': return c.instructions ? c.instructions.slice(0, 80) : 'Give it instructions';
      case 'switch': return c.value ? `${c.value.replace(/\{\{\s*|\s*\}\}/g, '')} · ${(c.cases || []).length} case${(c.cases || []).length === 1 ? '' : 's'}` : 'Choose a value to route on';
      case 'filter': return c.rules && c.rules.conditions && c.rules.conditions.length ? `Only if ${c.rules.conditions.length} condition${c.rules.conditions.length > 1 ? 's' : ''} match` : 'Add a condition';
      case 'set': { const k = (c.fields || []).filter((x) => x.name); return k.length ? k.map((x) => x.name).join(', ') : 'Add a field'; }
      case 'datetime': return ({ now: 'The time now', add: `Add ${c.amount || 1} ${c.unit || 'days'}`, subtract: `Subtract ${c.amount || 1} ${c.unit || 'days'}`, format: 'Format a date', diff: `${c.unit || 'days'} until / since a date` })[c.op] || 'Choose what to do';
      case 'http': return c.url ? `${c.method || 'GET'} ${c.url.replace(/^https?:\/\//, '')}` : 'The API to call';
      case 'stop': return c.message || 'Ends the run as failed';
      case 'approval': return c.to && c.to.length ? 'Ask ' + c.to.join(', ') : 'Who approves?';
      case 'ai_model': return c.model ? c.model.split('/').pop() : 'The platform’s model';
      case 'ai_memory': return `Key ${c.key || 'agent'} · last ${c.window || 5}`;
      case 'ai_parser': return c.kind === 'list' ? `List · up to ${c.max_items || 5}` : `Structured · ${(c.fields || []).filter((x) => x.name).length} fields`;
      case 'ai_tool': return c.tool === 'http' ? `${c.method || 'GET'} ${c.name || 'tool'}` : c.tool === 'fields' ? (c.fields || []).join(', ') || 'Choose fields' : (TOOL_KINDS.find((t) => t[0] === c.tool) || [0, 0, 'Choose a tool'])[2];
      case 'ai_sentiment': case 'ai_summarize': return c.input ? c.input.replace(/\{\{\s*|\s*\}\}/g, '') : 'Choose the text';
      default: return '';
    }
  };

  // ═══════ List ═══════
  const thumb = (m) => {
    if (!m.nodes.length) return '';
    const xs = m.nodes.map((n) => n.x), ys = m.nodes.map((n) => n.y), minX = Math.min(...xs), minY = Math.min(...ys), mx = Math.max(...xs) - minX + 220, my = Math.max(...ys) - minY + 80;
    const s = Math.min(260 / mx, 84 / my), byId = Object.fromEntries(m.nodes.map((n) => [n.id, n]));
    const X = (n) => (n.x - minX) * s + 4, Y = (n) => (n.y - minY) * s + 4;
    return `<svg viewBox="0 0 ${mx * s + 8} ${my * s + 8}" class="jt-svg">${m.edges.map((e) => { const A = byId[e.from], B = byId[e.to]; if (!A || !B) return ''; const x1 = X(A) + 200 * s, y1 = Y(A) + 30 * s, x2 = X(B), y2 = Y(B) + 30 * s, mid = (x1 + x2) / 2; return `<path d="M${x1},${y1} C${mid},${y1} ${mid},${y2} ${x2},${y2}" fill="none" stroke="var(--border-strong)" stroke-width="1.5"/>`; }).join('')}${m.nodes.map((n) => `<rect x="${X(n)}" y="${Y(n)}" width="${200 * s}" height="${60 * s}" rx="${n.type === 'trigger' ? 30 * s : 8 * s}" fill="${TYPE[n.type][1]}" stroke="${TYPE[n.type][2]}" stroke-opacity=".5"/>`).join('')}</svg>`;
  };
  const newJourney = (recipe) => API('POST', base() + '/journeys', recipe ? { recipe } : { name: 'Untitled journey' }).then((r) => { location.hash = '#/automations/edit?id=' + r.journey.id; }).catch((err) => toast(err.message));
  pages.automations = (v) => {
    const g = (seq = S.routeSeq, ++gen);
    v.innerHTML = '<div class="card"><div class="card-body faint">Loading journeys…</div></div>';
    S.paged(base() + '/journeys', 'journeys', (r, ctl) => {
      if (!alive(g)) return;
      const sum = r.summary || { running: r.journeys.filter((j) => j.status === 'active').length, inside: r.journeys.reduce((a, j) => a + j.stats.inside, 0) };
      v.innerHTML = `<div class="page-head"><div><h1>Automations</h1><p>Journeys that react to what your contacts do. ${fmt(sum.running)} running, ${fmt(sum.inside)} contacts inside right now.</p></div>${canEdit() ? `<button class="btn btn-primary" id="newj">${ic('plus', 15)}New journey</button>` : ''}</div>
        ${r.journeys.length ? `<div class="jgrid">${r.journeys.map((j) => `<a class="jcard" href="#/automations/edit?id=${j.id}"><div class="jc-top"><div><b>${esc(j.name)}</b><div class="jc-trig">${j.triggers.map((t) => `<span>${ic('zap', 11, 2.4)}${esc(t)}</span>`).join('') || '<span>No trigger yet</span>'}</div></div>${j.status === 'active' ? chip('delivered', j.unpublished ? 'Active · edits' : 'Active') : j.status === 'paused' ? chip('paused') : chip('processing', 'Draft')}</div>
          <div class="jc-map">${thumb(j.map)}</div>
          <div class="jc-stats"><div><small>Entered · 30d</small><b>${fmt(j.stats.entered_30d)}</b></div><div><small>Inside now</small><b>${fmt(j.stats.inside)}</b></div><div><small>Reached goal</small><b>${j.stats.goal_rate != null && j.stats.goal_rate > 0 ? pct(j.stats.goal_rate) : '–'}</b></div><div><small>Steps</small><b>${j.map.nodes.filter((n) => n.type !== 'trigger').length}</b></div></div></a>`).join('')}</div>`
        : `<div class="card"><div class="empty"><div class="ic">${ic('automations', 22)}</div><h3>No journeys yet</h3><p>Start from a recipe below, or build one from scratch.</p></div></div>`}
        <div class="card-head" style="padding:22px 0 12px"><div><h3>Start from a recipe</h3><div class="sub">Steps and subject lines are set up. Add your designs and sender, then turn it on.</div></div></div>
        <div class="recipes">${r.recipes.map((x) => `<button class="recipe" data-r="${x.key}" ${canEdit() ? '' : 'disabled'} title="${esc(x.about)}"><span class="rc-trig">${ic('zap', 12, 2.4)}${esc(x.trigger)}</span><b>${esc(x.name)}</b><span class="rc-steps">${Array.from({ length: Math.min(6, x.steps) }, (_, i) => `<i class="${i === Math.min(6, x.steps) - 1 ? 'last' : ''}"></i>`).join('')}<small>${x.steps} steps</small></span></button>`).join('')}</div>`;
      const nb = $('#newj'); if (nb) nb.onclick = () => newJourney();
      $$('.recipe[data-r]', v).forEach((b) => b.onclick = () => { b.disabled = true; newJourney(b.dataset.r); });
      const grid = $('.jgrid', v); if (grid) { const bar = document.createElement('div'); grid.after(bar); S.pageBar(ctl, $('.page-head', v), bar, 'journeys', 'beforeend'); }
    }).ready.catch((err) => alive(g) && (v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`));
  };

  // ═══════ Editor ═══════
  const NODE_W = 236;
  pages['automations/edit'] = (v) => {
    const g = (seq = S.routeSeq, ++gen), id = S.param('id');
    if (!id) { location.hash = '#/automations'; return; }
    document.body.classList.add('canvas-mode');
    v.innerHTML = '<div class="jx"><div class="card" style="margin:40px auto;max-width:380px"><div class="card-body faint">Opening the journey…</div></div></div>';
    Promise.all([API('GET', `${base()}/journeys/${id}`), API('GET', `${base()}/journeys/${id}/stats`), API('GET', base() + '/segments/catalog'), API('GET', base() + '/journeys'),
      S.senders(), API('GET', base() + '/templates').catch(() => ({ templates: [] }))])
      .then(([r, st, cat, all, dom, tpl]) => { if (alive(g)) editor(v, g, r.journey, st.stats, cat, all.journeys.filter((x) => x.id !== id), dom, tpl.templates || []); })
      .catch((err) => alive(g) && (v.innerHTML = `<div class="card" style="margin:40px"><div class="card-body">${esc(err.message)} <a href="#/automations">Back to automations</a></div></div>`));
  };

  function editor(v, g, j, stats, cat, journeys, snd, templates) {
    const domains = S.hasSenders(snd) ? [S.firstFrom(snd)] : []; // only its length and first entry are used
    const ro = !canEdit() || j.status === 'archived';
    const f = { nodes: j.graph.nodes.map((n) => Object.assign({}, n, { config: Array.isArray(n.config) || !n.config ? {} : JSON.parse(JSON.stringify(n.config)) })), edges: j.graph.edges.map((e) => Object.assign({}, e)) };
    const ctx = { lists: cat.lists || [], segments: cat.segments || [], topics: cat.topics || [], forms: cat.forms || [], emails: Object.assign({}, j.emails), journeys };
    let view = { x: 0, y: 0, k: 1 }, sel = S.param('sel') || null, palette = null, pcat = null, live = j.status === 'active', q = '', saveT = null, saving = null, saveState = '', problems = j.problems || [], path = null;
    // A new journey starts like n8n: an "Add first step" placeholder; clicking it opens the triggers.
    if (f.nodes.length === 1 && f.nodes[0].type === 'trigger' && !f.nodes[0].config.trigger) sel = null;
    const byId = () => Object.fromEntries(f.nodes.map((n) => [n.id, n]));
    const edgeCount = (e) => { const s = (stats.edges || []).find((x) => x.from === e.from && x.to === e.to); return s ? s.count : 0; };
    const maxCount = () => Math.max(1, ...f.edges.map(edgeCount));

    v.innerHTML = `<div class="jx">
      <header class="jx-bar"><a class="jx-back" href="#/automations" title="Back to automations">${ic('right', 16)}</a><div class="jx-name"><input id="jname" value="${esc(j.name)}" aria-label="Journey name" ${ro ? 'disabled' : ''}><small id="jsub"></small></div>
        <div class="spacer"></div>
        <label class="jx-live" title="Animate contacts moving through the journey"><input type="checkbox" id="livet" ${live ? 'checked' : ''}><span class="switch-sm"></span>Live flow</label>
        <button class="btn" id="testj" title="Run every step on test data, as far as it goes. Nothing is sent.">${svgI(GLYPH.play, 14)}Test journey</button>
        <span id="jctl" class="jx-ctl"></span>
        <button class="icon-btn" id="jmore" title="More">${ic('more', 16)}</button>
      </header>
      <div class="jx-body">
        <div class="jx-canvas" id="cv"><div class="jx-world" id="world"><svg class="jx-edges" id="edges"></svg><div id="nodes"></div><svg class="jx-links" id="links"><path id="tmpl" class="e-temp" d=""/></svg></div>
          <div class="jx-tools"><button id="zin" title="Zoom in">${ic('plus', 15)}</button><button id="zout" title="Zoom out"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg></button><button id="zfit" title="Fit to view (1)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button><span id="zpct">100%</span>${ro ? '' : `<button id="tidy" title="Tidy up">${ic('split', 15)}</button>`}</div>
          ${ro ? '' : `<button class="jx-add" id="addbtn" title="Add a step (N)">${ic('plus', 18, 2.4)}</button>`}
          <div class="jx-mini" id="mini"></div>
          <div class="jx-probs" id="probs"></div>
          <div class="jx-hint" id="hint">Drag to pan · ⌘ + scroll to zoom · drag from a dot to connect steps</div>
        </div>
        <aside class="jx-panel right creator" id="pal"></aside>
        <div class="ndv" id="ndv"><section class="ndv-pane ndv-in" id="ndvin"></section><aside class="jx-panel ndv-mid" id="insp"></aside><section class="ndv-pane ndv-out" id="ndvout"></section></div>
      </div></div>`;

    // ─── Header: status, publish, save state ───
    const renderHead = () => {
      const unpublished = j.status !== 'draft' && j.unpublished;
      $('#jsub').textContent = saveState || (j.status === 'draft' ? 'Draft · not live yet' : j.status === 'archived' ? 'Archived' : `Version ${j.version} · published ${day(j.published_at)}${unpublished ? ' · unpublished changes' : ''}`);
      const st = j.status === 'active' ? '<span class="jx-status on"><span class="dot"></span>Active</span>' : `<span class="jx-status"><span class="dot"></span>${({ paused: 'Paused', archived: 'Archived' })[j.status] || 'Draft'}</span>`;
      $('#jctl').innerHTML = st + (ro ? '' : j.status === 'draft' ? `<button class="btn btn-primary" id="jpub">${ic('zap', 15)}Turn on</button>`
        : j.status === 'active' ? `${unpublished ? '<button class="btn btn-primary" id="jpub">Publish changes</button>' : ''}<button class="btn" id="jpause">Pause</button>`
          : j.status === 'paused' ? `${unpublished ? '<button class="btn" id="jpub">Publish changes</button>' : ''}<button class="btn btn-primary" id="jresume">Turn back on</button>` : '');
      const pb = $('#jpub'); if (pb) pb.onclick = publish;
      const pz = $('#jpause'); if (pz) pz.onclick = () => act('pause', 'Journey paused. Contacts stay where they are');
      const rs = $('#jresume'); if (rs) rs.onclick = () => act('resume', 'Journey is live again');
    };
    const act = (a, msg) => flush().then(() => API('POST', `${base()}/journeys/${j.id}/${a}`)).then((r) => { j = r.journey; toast(msg); renderHead(); renderEdges(); }).catch((err) => toast(err.message));
    const publish = () => flush().then(() => {
      const first = j.status === 'draft';
      const go = () => API('POST', `${base()}/journeys/${j.id}/publish`).then((r) => { j = r.journey; problems = []; live = true; $('#livet').checked = true; renderHead(); renderNodes(); renderProblems(); toast(first ? 'Journey is live' : `Version ${j.version} published. New entries follow it; people already inside finish their version.`); })
        .catch((err) => { if (err.code === 'not_ready' && err.details) { problems = err.details.problems; delete $('#probs').dataset.hidden; renderNodes(); renderProblems(); toast(`${problems.length} thing${problems.length > 1 ? 's' : ''} to fix before it can go live`); } else toast(err.message); });
      if (!first) return go();
      const t = f.nodes.filter((n) => n.type === 'trigger').map((n) => n.title || describe(n, ctx)).join(', ');
      modal({ title: 'Turn this journey on?', desc: `From now on, contacts enter when: ${esc(t)}. ${j.settings.reentry === 'once' ? 'Each contact goes through once.' : j.settings.reentry === 'always' ? 'Contacts re-enter every time it happens.' : `Contacts can re-enter ${j.settings.cooldown_days} days after leaving.`}`, icon: 'zap', size: 'sm', body: '', actions: [{ label: 'Cancel' }, { label: 'Turn on', kind: 'primary', onClick: () => { go(); } }] });
    });
    const renderProblems = () => {
      const box = $('#probs');
      box.innerHTML = problems.length ? `<div class="jp-head">${ic('alert', 14)}${problems.length} to fix before going live<button class="icon-btn" id="pbx" title="Hide">${ic('x', 13)}</button></div>${problems.map((p, i) => `<button class="jp-row" data-pi="${i}">${esc(p.message)}</button>`).join('')}` : '';
      box.classList.toggle('on', !!problems.length && !box.dataset.hidden);
      const x = $('#pbx'); if (x) x.onclick = () => { box.dataset.hidden = '1'; box.classList.remove('on'); };
      $$('.jp-row', box).forEach((b) => b.onclick = () => { const p = problems[+b.dataset.pi]; if (p.node) { sel = p.node; inspTab = 'settings'; renderNodes(); renderInspector(); centerOn(p.node); } });
    };

    // ─── Autosave ───
    const graphBody = () => ({ nodes: f.nodes.map((n) => ({ id: n.id, type: n.type, x: n.x, y: n.y, title: n.title || '', config: n.config || {} })), edges: f.edges.map((e) => ({ from: e.from, to: e.to, branch: e.branch || '' })) });
    const push = () => {
      clearTimeout(saveT); saveT = null;
      saveState = 'Saving…'; renderHead();
      saving = API('PATCH', `${base()}/journeys/${j.id}`, { graph: graphBody(), base_rev: j.draft_rev }).then((r) => { j = r.journey; problems = j.problems; saveState = ''; renderHead(); markProblems(); })
        .catch((err) => { saveState = 'Not saved: ' + err.message; renderHead(); if (err.status === 409) modal({ title: 'This journey changed elsewhere', desc: 'Someone (or another tab) saved a newer version. Reload to see it; your last change here isn’t saved.', icon: 'alert', size: 'sm', body: '', actions: [{ label: 'Reload', kind: 'primary', onClick: () => { pages['automations/edit'](v); } }] }); else toast(err.message); })
        .finally(() => { saving = null; });
      return saving;
    };
    const save = () => { if (ro) return; saveState = 'Unsaved changes…'; renderHead(); clearTimeout(saveT); saveT = setTimeout(push, 700); };
    const flush = () => (saveT ? push() : saving || Promise.resolve());

    // ─── Canvas ───
    const cv = $('#cv'), world = $('#world');
    const apply = () => { world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.k})`; cv.style.backgroundSize = `${22 * view.k}px ${22 * view.k}px`; cv.style.backgroundPosition = `${view.x}px ${view.y}px`; $('#zpct').textContent = Math.round(view.k * 100) + '%'; drawMini(); };
    const nodeH = (n) => { const el = $(`[data-n="${n.id}"]`, world); return el ? el.offsetHeight : 80; };
    const nodeStats = (n) => {
      if (n.type === 'email') { const e = (stats.emails || {})[n.id]; if (!e || !e.sent) return ''; return `<div class="nd-stats"><span><b>${fmt(e.sent)}</b>Sent</span><span><b>${e.delivered ? pct(e.opened / e.delivered, 0) : '–'}</b>Real opens</span><span><b>${e.delivered ? pct(e.clicked / e.delivered) : '–'}</b>Clicked</span></div>`; }
      const reached = (stats.nodes || {})[n.id];
      if (!reached) return '';
      if (n.type === 'trigger') return `<div class="nd-stats"><span><b>${fmt(reached)}</b>Entered</span></div>`;
      if (n.type === 'goal') return `<div class="nd-stats"><span><b>${fmt(reached)}</b>Reached${stats.entered ? ' · ' + pct(reached / stats.entered) : ''}</span></div>`;
      return `<div class="nd-stats"><span><b>${fmt(reached)}</b>Passed</span></div>`;
    };
    const probFor = (id) => problems.filter((p) => p.node === id);
    /** n8n's AI ports: Chat Model, Memory, Tool and Output Parser connectors under an AI step; sub-nodes plug in there. */
    const subsOf = (id, kind) => f.edges.filter((e) => e.to === id && e.branch === kind && isSub(byId()[e.from])).map((e) => byId()[e.from]);
    const portX = (n, kind) => { const p = portsOf(n), i = p.findIndex((x) => x[0] === kind); return n.x + NODE_W * (i + 0.5) / p.length; };
    const portsHtml = (n) => {
      const p = portsOf(n); if (!p.length) return '';
      return `<div class="nd-ports" style="grid-template-columns:repeat(${p.length},1fr)">${p.map(([k, label, req, max]) => {
        const has = subsOf(n.id, k).length;
        return `<div class="pt" data-port="${k}"><i></i><small>${label}${req ? '*' : ''}</small>${!ro && has < max ? `<button class="pt-add" data-addport="${k}" title="Add ${label.toLowerCase()}">${ic('plus', 12, 2.4)}</button>` : ''}</div>`;
      }).join('')}</div>`;
    };
    const renderNodes = () => {
      $('#nodes').innerHTML = f.nodes.map((n) => {
        const [icn, bg, col, kind] = TYPE[n.type], empty = n.type === 'trigger' && !n.config.trigger, here = (stats.here || {})[n.id];
        if (isSub(n)) return `<div class="nd nd-sub t-${n.type} ${sel === n.id ? 'nd-sel' : ''} ${probFor(n.id).length ? 'nd-bad' : ''}" data-n="${n.id}" style="left:${n.x}px;top:${n.y}px;width:${SUB_W}px">
          <span class="sub-in" title="Drag onto an AI step to plug it in there too"></span><span class="ns-c" style="background:${bg};color:${col}">${nodeGlyph(n, 22)}</span><b>${esc(nameOf(n))}</b><small>${esc(describe(n, ctx))}</small>${probFor(n.id).length ? `<span class="nd-warn" title="${esc(probFor(n.id)[0].message)}">!</span>` : ''}</div>`;
        if (empty) return `<div class="nd nd-start" data-n="${n.id}" style="left:${n.x}px;top:${n.y}px;width:${NODE_W}px" title="${ro ? '' : 'Add first step'}"><span class="ns-box">${ic('plus', 30, 2)}</span><b>${ro ? 'No trigger yet' : 'Add first step…'}</b><small>What starts this journey?</small></div>`;
        return `<div class="nd t-${n.type} ${empty ? 'nd-empty' : ''} ${sel === n.id ? 'nd-sel' : ''} ${probFor(n.id).length ? 'nd-bad' : ''} ${path && path.includes(n.id) ? 'nd-path' : ''}" data-n="${n.id}" style="left:${n.x}px;top:${n.y}px;width:${NODE_W}px">
          ${n.type !== 'trigger' ? '<span class="nd-port in" title="Drag to move this connection"></span>' : ''}${outs(n).length && !ro ? '<span class="nd-port out" title="Drag to connect"></span>' : ''}
          <div class="nd-main"><span class="nd-ic" style="background:${bg};color:${col}">${empty ? ic('plus', 18, 2) : nodeGlyph(n, 18)}</span><div class="nd-txt"><small>${n.type === 'trigger' ? svgI(GLYPH.zap, 10) + ' Trigger' : kind}</small><b>${esc(n.title || (empty ? 'Choose a trigger' : NAME[n.type]))}</b><span>${esc(describe(n, ctx))}</span></div></div>
          ${portsHtml(n)}${nodeStats(n)}${here ? `<span class="nd-badge" title="Contacts waiting at this step">${fmt(here)} here now</span>` : ''}${probFor(n.id).length ? `<span class="nd-warn" title="${esc(probFor(n.id)[0].message)}">!</span>` : ''}</div>`;
      }).join('');
      $$('.nd', world).forEach((el) => bindNode(el));
      renderEdges(); drawMini(); markTested();
    };
    const markProblems = () => {
      $$('.nd', world).forEach((el) => { const p = probFor(el.dataset.n); el.classList.toggle('nd-bad', !!p.length); const w = $('.nd-warn', el); if (w && !p.length) w.remove(); });
      if ($('#probs').classList.contains('on')) renderProblems();
    };
    const renderEdges = () => {
      const B = byId(), mc = maxCount(); let maxX = 0, maxY = 0;
      f.nodes.forEach((n) => { maxX = Math.max(maxX, n.x + NODE_W + 200); maxY = Math.max(maxY, n.y + 260); });
      const svg = $('#edges'); svg.setAttribute('width', maxX); svg.setAttribute('height', maxY);
      const outsOf = (id) => f.edges.filter((e) => e.from === id);
      svg.innerHTML = f.edges.map((e, i) => {
        const A = B[e.from], Bn = B[e.to]; if (!A || !Bn) return '';
        if (isSub(A)) { // a sub-node's dashed line up to its port
          const x1 = portX(Bn, e.branch), y1 = Bn.y + nodeH(Bn), x2 = A.x + SUB_W / 2, y2 = A.y + 4, dy = Math.max(40, (y2 - y1) / 2), mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
          const d = `M${x1},${y1} C${x1},${y1 + dy} ${x2},${y2 - dy} ${x2},${y2}`;
          return `<g class="edge sub"><path d="${d}" class="e-hit"/><path d="${d}" class="e-line e-subl"/>${ro ? '' : `<foreignObject x="${mx - 14}" y="${my - 13}" width="28" height="26" class="e-plus-wrap"><div class="e-acts"><button class="e-plus del" data-del="${i}" title="Unplug">×</button></div></foreignObject>`}</g>`;
        }
        const x1 = A.x + NODE_W, y1 = A.y + nodeH(A) / 2, x2 = Bn.x, y2 = Bn.y + nodeH(Bn) / 2, dx = Math.max(60, (x2 - x1) / 2);
        const d = `M${x1},${y1} C${x1 + dx},${y1} ${x2 - dx},${y2} ${x2},${y2}`, c = edgeCount(e);
        const w = c ? 1.6 + (c / mc) * 7 : 1.6, mx = (x1 + x2) / 2, my = (y1 + y2) / 2, tone = brTone(e.branch), good = tone === 'yes';
        const color = e.branch ? ({ yes: 'var(--s-delivered)', no: 'var(--s-deferred)', err: 'var(--s-bounced)', cat: 'var(--s-clicked)' })[tone] : 'var(--border-strong)';
        const oc = (stats.outcomes || {})[e.from] || {}, sib = e.branch && e.branch !== 'error' ? outs(A).filter((b) => b && b !== 'error').reduce((t, b) => t + (oc[b] || 0), 0) : 0;
        const label = e.branch ? brName(A, e.branch) + (sib ? ' · ' + Math.round(((oc[e.branch] || 0) / sib) * 100) + '%' : '') : '';
        return `<g class="edge" data-e="${i}"><path d="${d}" class="e-hit"/><path d="${d}" class="e-line" style="stroke:${color};stroke-width:${w}"/>${live && c && j.status === 'active' ? `<path d="${d}" class="e-flow" style="stroke-width:${Math.max(2, w * .45)}"/>` : ''}
          ${label ? `<foreignObject x="${mx - 50}" y="${my - 30}" width="100" height="24"><div class="e-label ${tone}">${esc(label)}</div></foreignObject>` : ''}
          ${c ? `<foreignObject x="${mx - 40}" y="${my + (label ? 2 : -11)}" width="80" height="22"><div class="e-count">${fmt(c)}</div></foreignObject>` : ''}
          ${ro ? '' : `<foreignObject x="${mx - 30}" y="${my - 13}" width="60" height="26" class="e-plus-wrap"><div class="e-acts"><button class="e-plus" data-ins="${i}" title="Add a step here">+</button><button class="e-plus del" data-del="${i}" title="Remove connection">×</button></div></foreignObject>`}</g>`;
      }).join('') + (ro ? '' : f.nodes.map((n) => { // n8n: every output with nothing connected ends in a +
        if (n.type === 'trigger' && !n.config.trigger) return '';
        const o = outs(n), used = f.edges.filter((e) => e.from === n.id).map((e) => e.branch || ''), free = o.filter((b) => !used.includes(b));
        if (!free.length) return '';
        const x1 = n.x + NODE_W, y1 = n.y + nodeH(n) / 2;
        return free.map((b) => { const i = o.indexOf(b), dy = o.length > 1 ? (i - (o.length - 1) / 2) * 34 : 0, x2 = x1 + 64, y2 = y1 + dy, tone = brTone(b);
          return `<g class="stub"><path d="M${x1},${y1} C${x1 + 30},${y1} ${x2 - 30},${y2} ${x2},${y2}" class="stub-line"/>${b ? `<foreignObject x="${x2 + 32}" y="${y2 - 9}" width="110" height="18"><div class="stub-label ${tone}">${esc(brName(n, b))}</div></foreignObject>` : ''}<foreignObject x="${x2}" y="${y2 - 13}" width="28" height="26"><button class="stub-plus" data-stub="${n.id}|${esc(b)}" title="Add a step${b ? ' on “' + esc(brName(n, b)) + '”' : ''}">+</button></foreignObject></g>`; }).join('');
      }).join(''));
      $$('[data-ins]', svg).forEach((b) => b.onclick = (ev) => { ev.stopPropagation(); openPalette('steps'); insertAt = +b.dataset.ins; renderPalette(); });
      $$('[data-stub]', svg).forEach((b) => b.onclick = (ev) => { ev.stopPropagation(); const [from, branch] = b.dataset.stub.split('|'); openPalette('steps', { from, branch }); });
      $$('[data-del]', svg).forEach((b) => b.onclick = (ev) => { ev.stopPropagation(); f.edges.splice(+b.dataset.del, 1); renderEdges(); save(); toast('Connection removed'); });
    };
    let insertAt = null, pendingLink = null, link = null;
    const fit = (all) => {
      const r = cv.getBoundingClientRect(), padL = 40, padR = sel || palette ? 400 : 40;
      const xs = f.nodes.map((n) => n.x), ys = f.nodes.map((n) => n.y);
      const minX = Math.min(...xs), maxX = Math.max(...xs) + NODE_W, minY = Math.min(...ys), maxY = Math.max(...f.nodes.map((n) => n.y + nodeH(n)));
      const aw = Math.max(200, r.width - padL - padR), ah = Math.max(200, r.height - 140);
      const kf = Math.min(1.1, aw / Math.max(1, maxX - minX), ah / Math.max(1, maxY - minY));
      view.k = all ? Math.max(.35, kf) : Math.max(.85, kf);
      view.x = padL + Math.max(0, (aw - (maxX - minX) * view.k) / 2) - minX * view.k;
      view.y = 60 + Math.max(0, (ah - (maxY - minY) * view.k) / 2) - minY * view.k;
      apply();
    };
    const centerOn = (nid) => { const n = byId()[nid]; if (!n) return; const r = cv.getBoundingClientRect(); view.x = (r.width - 400) / 2 - (n.x + NODE_W / 2) * view.k; view.y = r.height / 2 - (n.y + 40) * view.k; apply(); };
    const zoomAt = (k, cx, cy) => { k = Math.max(.3, Math.min(1.8, k)); view.x = cx - (cx - view.x) * (k / view.k); view.y = cy - (cy - view.y) * (k / view.k); view.k = k; apply(); };
    function drawMini() {
      const mini = $('#mini'); if (!mini || !f.nodes.length) return;
      const xs = f.nodes.map((n) => n.x), ys = f.nodes.map((n) => n.y), minX = Math.min(...xs) - 40, minY = Math.min(...ys) - 40, W = Math.max(...xs) + NODE_W + 40 - minX, H = Math.max(...ys) + 140 - minY;
      const s = Math.min(180 / W, 110 / H), r = cv.getBoundingClientRect();
      const vx = (-view.x / view.k - minX) * s, vy = (-view.y / view.k - minY) * s, vw = r.width / view.k * s, vh = r.height / view.k * s;
      mini.innerHTML = `<svg width="180" height="110">${f.nodes.map((n) => `<rect x="${(n.x - minX) * s}" y="${(n.y - minY) * s}" width="${NODE_W * s}" height="${70 * s}" rx="2" fill="${n.id === sel ? 'var(--accent)' : 'var(--border-strong)'}"/>`).join('')}<rect x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="var(--accent)" fill-opacity=".06" stroke="var(--accent)" stroke-width="1.2" rx="3"/></svg>`;
    }

    // pan + zoom
    let pan = null;
    cv.addEventListener('mousedown', (e) => { if (e.target.closest('.nd,.jx-tools,.jx-mini,.jx-add,.e-plus,.e-acts,.stub-plus,.jx-probs')) return; pan = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y }; cv.classList.add('panning'); });
    const onMove = (e) => { if (link) linkMove(e); if (pan) { view.x = pan.vx + e.clientX - pan.x; view.y = pan.vy + e.clientY - pan.y; apply(); } if (drag) dragMove(e); };
    const onUp = (e) => { if (link) return linkEnd(e); if (pan) { pan = null; cv.classList.remove('panning'); } if (drag) dragEnd(); };
    window.addEventListener('mousemove', onMove); window.addEventListener('mouseup', onUp);
    cv.addEventListener('wheel', (e) => { e.preventDefault(); const r = cv.getBoundingClientRect(); if (e.ctrlKey || e.metaKey) zoomAt(view.k * (e.deltaY < 0 ? 1.08 : .92), e.clientX - r.left, e.clientY - r.top); else { view.x -= e.deltaX; view.y -= e.deltaY; apply(); } }, { passive: false });
    cv.addEventListener('click', (e) => { if (!e.target.closest('.nd,.jx-tools,.jx-mini,.jx-add,.e-plus,.stub-plus,.jx-probs') && !moved) { sel = null; if (palette) closePalette(); renderNodes(); renderInspector(); } });

    // node drag / select
    let drag = null, moved = false;
    function bindNode(el) {
      el.addEventListener('mousedown', (e) => {
        const add = e.target.closest('[data-addport]');
        if (add) { e.stopPropagation(); e.preventDefault(); if (!ro) { subFor = { id: el.dataset.n, kind: add.dataset.addport }; openPalette('sub'); } return; }
        if (e.target.closest('.sub-in') && !ro) { e.stopPropagation(); e.preventDefault(); link = { from: el.dataset.n, sub: true }; cv.classList.add('linking'); linkMove(e); return; }
        e.stopPropagation(); const port = e.target.closest('.nd-port'); if (port && !ro) return linkStart(el.dataset.n, port.classList.contains('in'), e); const n = byId()[el.dataset.n]; drag = { n, el, x: e.clientX, y: e.clientY, nx: n.x, ny: n.y }; moved = false; });
    }
    function dragMove(e) { if (ro) return; const dx = (e.clientX - drag.x) / view.k, dy = (e.clientY - drag.y) / view.k; if (Math.abs(dx) + Math.abs(dy) > 3) moved = true; if (!moved) return; drag.n.x = Math.round((drag.nx + dx) / 10) * 10; drag.n.y = Math.round((drag.ny + dy) / 10) * 10; drag.el.style.left = drag.n.x + 'px'; drag.el.style.top = drag.n.y + 'px'; drag.el.classList.add('dragging'); renderEdges(); }
    function dragEnd() { const { n, el } = drag; el.classList.remove('dragging'); drag = null; if (moved) save(); else { if (n.type === 'trigger' && !n.config.trigger) { if (!ro) { replaceTrig = n.id; openPalette('triggers'); } } else { if (sel !== n.id) inspTab = 'settings'; sel = n.id; palette = null; renderNodes(); renderInspector(); renderPalette(); } } drawMini(); setTimeout(() => (moved = false), 0); }

    // connections: drag from an output dot; grab an input dot to move an existing connection
    const toWorld = (e) => { const r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left - view.x) / view.k, y: (e.clientY - r.top - view.y) / view.k }; };
    const freeBranch = (nid) => { const used = f.edges.filter((e) => e.from === nid).map((e) => e.branch || ''); return outs(byId()[nid]).find((b) => !used.includes(b)); };
    function linkStart(nid, fromInput, e) {
      e.preventDefault();
      if (fromInput) {
        const i = f.edges.map((x, k) => [x, k]).filter(([x]) => x.to === nid).map(([, k]) => k).pop();
        if (i == null) return;
        const edge = f.edges.splice(i, 1)[0];
        link = { from: edge.from, carry: edge }; renderEdges();
      } else {
        if (freeBranch(nid) === undefined) { toast(outs(byId()[nid]).length > 1 ? 'Every path is already connected. Remove one first.' : 'Already connected. Remove the connection first.'); return; }
        link = { from: nid };
      }
      cv.classList.add('linking'); linkMove(e);
    }
    function linkMove(e) {
      const A = byId()[link.from], p = toWorld(e);
      if (link.sub) { // a sub-node's dot: draw upwards to the step it plugs into
        const x1 = A.x + SUB_W / 2, y1 = A.y, dy = Math.max(40, Math.abs(p.y - y1) / 2);
        $('#tmpl').setAttribute('d', `M${x1},${y1} C${x1},${y1 - dy} ${p.x},${p.y + dy} ${p.x},${p.y}`);
      } else { const x1 = A.x + NODE_W, y1 = A.y + nodeH(A) / 2, dx = Math.max(60, Math.abs(p.x - x1) / 2); $('#tmpl').setAttribute('d', `M${x1},${y1} C${x1 + dx},${y1} ${p.x - dx},${p.y} ${p.x},${p.y}`); }
      const t = document.elementFromPoint(e.clientX, e.clientY), tn = t && t.closest('.nd');
      $$('.nd.drop-ok', world).forEach((x) => x !== tn && x.classList.remove('drop-ok'));
      const ok = tn && tn.dataset.n !== link.from && (link.sub ? portsOf(byId()[tn.dataset.n] || {}).some((x) => x[0] === A.type) : !tn.classList.contains('t-trigger') && !tn.classList.contains('nd-sub'));
      if (ok) tn.classList.add('drop-ok');
    }
    function linkEnd(e) {
      const t = document.elementFromPoint(e.clientX, e.clientY), tn = t && t.closest('.nd'), { from, carry, sub } = link;
      link = null; cv.classList.remove('linking'); $('#tmpl').setAttribute('d', ''); $$('.nd.drop-ok', world).forEach((x) => x.classList.remove('drop-ok'));
      if (sub) { // plug a sub-node in under another AI step
        const S2 = byId()[from], T = tn && byId()[tn.dataset.n], port = T && portsOf(T).find((x) => x[0] === S2.type);
        if (!T || T.id === from) { renderEdges(); return; }
        if (!port) { toast(`${NAME[S2.type]} can’t plug into ${T.title || NAME[T.type]}.`); return; }
        if (f.edges.some((x) => x.from === from && x.to === T.id)) { toast('Already plugged in there'); return; }
        if (subsOf(T.id, S2.type).length >= port[3]) { toast(`${T.title || NAME[T.type]} already has ${port[3] === 1 ? 'a ' + port[1].toLowerCase() : port[3] + ' tools'}. Unplug one first.`); return; }
        f.edges.push({ from, to: T.id, branch: S2.type }); renderNodes(); save(); toast('Plugged in'); return;
      }
      if (tn && tn.dataset.n !== from && !tn.classList.contains('t-trigger') && !tn.classList.contains('nd-sub')) {
        if (f.edges.some((x) => x.from === from && x.to === tn.dataset.n)) { if (carry) f.edges.push(carry); toast('Already connected'); renderEdges(); return; }
        f.edges.push({ from, to: tn.dataset.n, branch: carry ? carry.branch : freeBranch(from) });
        renderEdges(); save(); toast(carry ? 'Connection moved' : 'Connected'); return;
      }
      if (carry) { renderEdges(); save(); toast('Connection removed'); return; }
      if (t && t.closest('.jx-canvas')) { const p = toWorld(e); openPalette('steps', { from, x: Math.round(p.x / 10) * 10, y: Math.round((p.y - 34) / 10) * 10 }); }
      else renderEdges();
    }

    // ─── Left: node panel ───
    function renderPalette() {
      const pal = $('#pal');
      pal.classList.toggle('open', !!palette);
      if (!palette) { pal.innerHTML = ''; return; }
      if (palette === 'sub') return renderSubPicker(pal);
      const trig = palette === 'triggers';
      const match = (s) => s.toLowerCase().includes(q.trim().toLowerCase());
      const trigItem = ([k, a, b]) => `<button class="pl-item ${k ? '' : 'soon'}" ${k ? `data-trig="${esc(k)}"` : 'disabled'} data-title="${esc(a)}"><span class="pl-ic trig">${svgI(GLYPH[k ? TRIG_GLYPH[(kindConfig(k) || {}).trigger] || 'zap' : 'zap'], 15)}</span><span><b>${a}${k ? '' : ' <em>Soon</em>'}</b><small>${b}</small></span></button>`;
      const stepItem = ([t, a, b]) => `<button class="pl-item" data-step="${t}" data-title="${esc(a)}" draggable="true"><span class="pl-ic" style="background:${TYPE[t][1]};color:${TYPE[t][2]}">${svgI(GLYPH[t], 15)}</span><span><b>${a}</b><small>${b}</small></span></button>`;
      const groups = trig ? TRIGGERS.map(([g, , items]) => [g, items]) : STEPS;
      const CATS = trig ? TRIG_CATS : STEP_CATS, item = trig ? trigItem : stepItem;
      let list;
      if (q.trim()) {
        const hits = groups.flatMap(([, items]) => items.filter(([, a, b]) => match(a + ' ' + b)));
        list = hits.length ? hits.map(item).join('') : '<p class="pl-none">Nothing matches. Try another word.</p>';
      } else if (pcat && groups.some(([g]) => g === pcat)) {
        list = `<button class="pl-back" id="plback">${svgI('<path d="m15 18-6-6 6-6"/>', 15)}${esc((CATS[pcat] || [pcat])[0])}</button>` + groups.find(([g]) => g === pcat)[1].map(item).join('');
      } else {
        list = groups.map(([g, items]) => { const [title, desc, glyph] = CATS[g] || [g, '', 'zap']; return `<button class="pl-cat" data-cat="${esc(g)}"><span class="pl-ic ${trig ? 'trig' : 'cat'}">${svgI(GLYPH[glyph] || GLYPH.zap, 16)}</span><span><b>${esc(title)}</b><small>${esc(desc)}</small></span><span class="pl-n">${items.filter((x) => x[0]).length}</span>${svgI('<path d="m9 18 6-6-6-6"/>', 15)}</button>`; }).join('');
      }
      const link = pendingLink && !trig ? pendingLink : null, ln = link && byId()[link.from];
      pal.innerHTML = `<div class="pl-head"><div><b>${trig ? 'What triggers this journey?' : 'What happens next?'}</b><small>${trig ? 'A journey can have several triggers; contacts enter from any of them.' : link && ln ? `After “${esc(ln.title || NAME[ln.type])}”${link.branch ? ' · ' + esc(brName(ln, link.branch)) : ''}` : insertAt != null ? 'Between two steps' : 'Click to add after the selected step, or drag onto the canvas.'}</small></div><button class="icon-btn" id="plx" aria-label="Close panel">${ic('x', 15)}</button></div>
        <div class="pl-search">${ic('search', 14)}<input id="plq" placeholder="Search ${trig ? 'triggers' : 'steps'}…" value="${esc(q)}" autocomplete="off"></div>
        <div class="pl-list">${list}</div>
        ${trig ? '<div class="pl-note">Journeys start for contacts added one at a time (API, forms, webhooks, by hand). Imports and bulk edits never start them.</div>' : ''}`;
      $('#plx').onclick = () => closePalette();
      const back = $('#plback'); if (back) back.onclick = () => { pcat = null; renderPalette(); };
      $$('[data-cat]', pal).forEach((b) => b.onclick = () => { pcat = b.dataset.cat; renderPalette(); });
      const qi = $('#plq'); qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; renderPalette(); const n2 = $('#plq'); n2.focus(); n2.setSelectionRange(pos, pos); };
      qi.onkeydown = (e) => { if (e.key === 'Escape') closePalette(); if (e.key === 'Enter') { const first = $('.pl-item:not([disabled])', pal); if (first) first.click(); } };
      $$('.pl-item:not([disabled])', pal).forEach((b) => {
        b.ondragstart = (e) => e.dataTransfer.setData('text/plain', JSON.stringify(b.dataset));
        b.onclick = () => addFrom(Object.assign({}, b.dataset));
      });
    }
    /** n8n's sub-node picker, opened from an AI step's port: the brain, memory, a tool, or an output parser. */
    function renderSubPicker(pal) {
      const host = subFor && byId()[subFor.id]; if (!host) { closePalette(); return; }
      const kind = subFor.kind, models = (j.ai && j.ai.models) || [], me1 = (me().user || {}).email;
      const HEAD = { ai_model: ['Which model is its brain?', 'Each step can use its own model. The installation admin chooses which are offered, in the AI settings.'],
        ai_memory: ['Add memory', 'So the agent remembers this contact between runs.'], ai_tool: ['Give the agent a tool', 'It decides when to use it. Tools only ever work on the contact in the run.'],
        ai_parser: ['How should it answer?', 'An output parser shapes the agent’s final answer so later steps can use it.'] }[kind];
      const groups = [];
      if (kind === 'ai_model') {
        const by = {}; models.forEach((m, i) => { (by[providerOf(m)] = by[providerOf(m)] || []).push([m, i === 0]); });
        Object.entries(by).forEach(([prov, ms]) => groups.push([prov, ms.map(([m, def]) => [m.split('/').pop() + (def ? ' · default' : ''), m, { model: def ? '' : m, temperature: 0.3, max_tokens: 0 }, 'chip'])]));
        if (!models.length) groups.push(['Model', [['The platform’s model', 'Set by the admin', { model: '', temperature: 0.3, max_tokens: 0 }, 'chip']]]);
      }
      if (kind === 'ai_memory') groups.push(['Memory', [['Simple Memory', 'The last few exchanges with this contact, under a key', { key: 'agent', window: 5 }, 'memory']]]);
      if (kind === 'ai_tool') {
        const def = { fields: { fields: [] }, lists: { lists: [] }, notify: { to: [me1].filter(Boolean) }, think: { description: '' },
          http: { name: '', description: '', method: 'GET', url: '', headers: [], params: [{ name: '', description: '', type: 'string', in: 'query', required: true }] } };
        groups.push(['The contact', TOOL_KINDS.slice(0, 5).map(([k, n, d, g]) => [n, d, Object.assign({ tool: k }, def[k] || {}), g])]);
        groups.push(['Actions', TOOL_KINDS.slice(5, 7).map(([k, n, d, g]) => [n, d, Object.assign({ tool: k }, def[k] || {}), g])]);
        groups.push(['Reasoning', TOOL_KINDS.slice(7).map(([k, n, d, g]) => [n, d, Object.assign({ tool: k }, def[k] || {}), g])]);
      }
      if (kind === 'ai_parser') groups.push(['Output parsers', [['Structured Output Parser', 'Named, typed answers, e.g. tier and score', { kind: 'structured', fields: [{ name: '', type: 'text', description: '' }], autofix: true }, 'parser'],
        ['Item List Output Parser', 'A list of short items, e.g. product picks', { kind: 'list', max_items: 5, item: '' }, 'list']]]);
      const match = (x) => !q.trim() || (x[0] + ' ' + x[1]).toLowerCase().includes(q.trim().toLowerCase());
      let k = 0; const all = [];
      pal.innerHTML = `<div class="pl-head"><div><b>${HEAD[0]}</b><small>For “${esc(host.title || NAME[host.type])}”. ${HEAD[1]}</small></div><button class="icon-btn" id="plx" aria-label="Close panel">${ic('x', 15)}</button></div>
        ${kind === 'ai_model' || kind === 'ai_tool' ? `<div class="pl-search">${ic('search', 14)}<input id="plq" placeholder="Search…" value="${esc(q)}" autocomplete="off"></div>` : ''}
        <div class="pl-list">${groups.map(([g, items]) => { const it = items.filter(match); return it.length ? `<div class="pl-label">${esc(g)}</div>` + it.map((x) => { all.push(x); return `<button class="pl-item ${x[2] ? '' : 'soon'}" data-pick="${k++}" ${x[2] ? '' : 'disabled'}><span class="pl-ic" style="background:${TYPE[kind][1]};color:${TYPE[kind][2]}">${svgI(GLYPH[x[3]] || GLYPH.zap, 15)}</span><span><b>${esc(x[0])}</b><small>${esc(x[1])}</small></span></button>`; }).join('') : ''; }).join('') || '<p class="pl-none">Nothing matches.</p>'}</div>`;
      $('#plx').onclick = () => closePalette();
      const qi = $('#plq'); if (qi) qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; renderPalette(); const n2 = $('#plq'); n2.focus(); n2.setSelectionRange(pos, pos); };
      $$('[data-pick]', pal).forEach((b) => b.onclick = () => { const x = all[+b.dataset.pick]; if (x && x[2]) addSub(host, kind, JSON.parse(JSON.stringify(x[2]))); });
    }
    /** A new sub-node, placed under its port and plugged in; its settings open, as in n8n. */
    function addSub(host, kind, cfg) {
      const n = { id: uid(), type: kind, x: Math.round((portX(host, kind) - SUB_W / 2 + subsOf(host.id, kind).length * 120) / 10) * 10, y: Math.round((host.y + nodeH(host) + 130) / 10) * 10, title: '', config: cfg };
      while (f.nodes.some((m) => Math.abs(m.x - n.x) < SUB_W && Math.abs(m.y - n.y) < 90)) n.x += 120;
      f.nodes.push(n); f.edges.push({ from: n.id, to: host.id, branch: kind });
      sel = n.id; inspTab = 'settings'; palette = null; q = '';
      renderNodes(); renderInspector(); renderPalette(); save();
    }
    /** Opens the node creator: on the triggers (an empty journey) or "What happens next?" (after a step, or an output's +). */
    function openPalette(mode, linkFrom) {
      if (ro) return;
      palette = mode; pcat = null; q = ''; insertAt = null; pendingLink = linkFrom || null;
      if (mode === 'steps' && !linkFrom && sel && byId()[sel] && freeBranch(sel) !== undefined) pendingLink = { from: sel }; // "after the selected step"
      if (sel) { sel = null; renderNodes(); renderInspector(); }
      renderPalette(); setTimeout(() => { const qi = $('#plq'); if (qi && window.matchMedia('(pointer: fine)').matches) qi.focus(); }, 60);
    }
    function closePalette() { palette = null; pcat = null; insertAt = null; pendingLink = null; replaceTrig = null; renderPalette(); }
    let replaceTrig = null, inspTab = 'settings', subFor = null;
    const uid = () => 'n' + Math.random().toString(36).slice(2, 8);
    const defaults = (t) => ({ email: { type: 'marketing', skip_recent_hours: 0 }, wait: { amount: 1, unit: 'days' }, waitfor: { event: '', amount: 2, unit: 'days' }, window: { days: [1, 2, 3, 4, 5], from: '09:00', to: '18:00' },
      cond: { rules: { match: 'all', conditions: [] } }, split: { percent_a: 50 }, tag: { action: 'add', tag: '' }, field: { key: '', value: '' }, list: { action: 'add', list_id: '' },
      notify: { channel: 'email', to: [me().user.email].filter(Boolean), message: '{{ email }} reached this step.' }, goal: { event: '' }, webhook: { url: '' }, jump: { journey_id: '' },
      ai_write: { prompt: '' }, ai_classify: { input: '', instructions: '', categories: [{ id: cid(), name: '', description: '' }, { id: cid(), name: '', description: '' }] },
      ai_extract: { input: '', fields: [{ name: '', type: 'text', description: '' }] }, switch: { value: '', cases: [{ id: cid(), op: 'is', value: '', name: '' }, { id: cid(), op: 'is', value: '', name: '' }] }, filter: { rules: { match: 'all', conditions: [] } },
      set: { fields: [{ name: '', type: 'text', value: '' }] }, datetime: { op: 'add', value: '', amount: 1, unit: 'days', format: 'date' }, http: { method: 'GET', url: '', query: [], headers: [], body: [] },
      stop: { message: '' }, approval: { to: [me().user.email].filter(Boolean), message: 'Approve this for {{ email }}?', amount: 2, unit: 'days' }, ai_sentiment: { input: '' }, ai_summarize: { input: '', length: 'sentence' },
      ai_agent: { instructions: '', max_turns: 5 } })[t] || {}; // its model, memory, tools and parser are sub-nodes
    const cid = () => Math.random().toString(36).slice(2, 8).replace(/[^a-z0-9]/g, 'x');
    function addFrom(d, at) {
      if (ro) return;
      if (d.trig) {
        const cfg = kindConfig(d.trig === 'webhook:zap' ? 'webhook' : d.trig);
        const empty = (replaceTrig && byId()[replaceTrig]) || f.nodes.find((n) => n.type === 'trigger' && !n.config.trigger);
        replaceTrig = null;
        if (empty) { Object.assign(empty, { title: d.title, config: cfg }); sel = empty.id; inspTab = 'settings'; toast('Trigger set: ' + d.title); }
        else {
          const ys = f.nodes.filter((n) => n.type === 'trigger').map((n) => n.y);
          const n = { id: uid(), type: 'trigger', x: 0, y: Math.max(...ys) + 210, title: d.title, config: cfg };
          if (at) Object.assign(n, at);
          f.nodes.push(n);
          const first = f.edges.find((e) => byId()[e.from].type === 'trigger');
          if (first && !at) f.edges.push({ from: n.id, to: first.to, branch: '' });
          sel = n.id; toast('Added trigger: ' + d.title);
        }
      } else if (d.step) {
        const n = { id: uid(), type: d.step, x: 0, y: 0, title: '', config: defaults(d.step) };
        if (at) Object.assign(n, at);
        else if (pendingLink && byId()[pendingLink.from]) {
          const from = byId()[pendingLink.from], br = pendingLink.branch != null && outs(from).includes(pendingLink.branch) && !f.edges.some((e) => e.from === from.id && (e.branch || '') === pendingLink.branch) ? pendingLink.branch : freeBranch(from.id);
          if (pendingLink.x != null) { n.x = pendingLink.x; n.y = pendingLink.y; }
          else { const o = outs(from), i = Math.max(0, o.indexOf(br)); n.x = from.x + 320; n.y = from.y + (o.length > 1 ? Math.round((i - (o.length - 1) / 2) * 15) * 10 : 0); while (f.nodes.some((m) => Math.abs(m.x - n.x) < 50 && Math.abs(m.y - n.y) < 90)) n.y += 130; }
          if (br !== undefined) f.edges.push({ from: from.id, to: n.id, branch: br });
          pendingLink = null;
        }
        else if (insertAt != null) {
          const e = f.edges[insertAt], A = byId()[e.from], B = byId()[e.to];
          n.x = Math.round((A.x + B.x) / 20) * 10; n.y = Math.round(((A.y + B.y) / 2 + 120) / 10) * 10;
          f.edges.splice(insertAt, 1, { from: e.from, to: n.id, branch: e.branch });
          if (outs(n).length) f.edges.push({ from: n.id, to: e.to, branch: outs(n)[0] });
          insertAt = null;
        } else {
          const from = byId()[sel] || f.nodes[f.nodes.length - 1];
          n.x = from.x + 320; n.y = from.y;
          while (f.nodes.some((m) => Math.abs(m.x - n.x) < 50 && Math.abs(m.y - n.y) < 90)) n.y += 130;
          const br = freeBranch(from.id); if (br !== undefined) f.edges.push({ from: from.id, to: n.id, branch: br });
        }
        f.nodes.push(n); sel = n.id; inspTab = 'settings'; toast('Added: ' + d.title);
        if (n.type === 'ai_agent') { // like n8n: an agent needs a brain; start with the platform's model plugged in
          const m = { id: uid(), type: 'ai_model', x: Math.round((n.x + NODE_W / 8 - SUB_W / 2) / 10) * 10, y: n.y + 220, title: '', config: { model: '', temperature: 0.3, max_tokens: 0 } };
          f.nodes.push(m); f.edges.push({ from: m.id, to: n.id, branch: 'ai_model' });
        }
      }
      palette = null; pcat = null; q = '';
      renderNodes(); renderInspector(); renderPalette(); save();
    }
    cv.addEventListener('dragover', (e) => e.preventDefault());
    cv.addEventListener('drop', (e) => { e.preventDefault(); let d; try { d = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (_) { return; } const r = cv.getBoundingClientRect(); addFrom(d, { x: Math.round(((e.clientX - r.left - view.x) / view.k - NODE_W / 2) / 10) * 10, y: Math.round(((e.clientY - r.top - view.y) / view.k - 30) / 10) * 10 }); });

    // ─── Right: settings + people for the selected step ───
    const ruleBuilder = (rules, emailNodes) => {
      const fields = cat.fields || [];
      const typeOf = (fd) => fd === 'data' ? 'data' : fd === 'event' ? 'event' : fd === 'journey_email' ? 'jemail' : (fields.find((x) => x.field === fd) || {}).type || 'text';
      const DATA_OPS = ['is', 'is_not', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set', 'is_true', 'is_false'];
      const ops = (fd) => typeOf(fd) === 'data' ? DATA_OPS : typeOf(fd) === 'event' ? ['performed', 'not_performed'] : typeOf(fd) === 'jemail' ? ['opened', 'clicked', 'not_opened', 'not_clicked'] : (cat.ops[typeOf(fd)] || []);
      const fieldOpts = (s) => [['Data', ['data']], ['Journey', ['journey_email', 'event']], ['Contact', ['first_name', 'last_name', 'email', 'status', 'source', 'created_at']], ['Engagement', ['last_opened_at', 'last_clicked_at', 'engagement', 'dormant']], ['Lists & tags', ['list', 'tag']]]
        .map(([grp, fs]) => `<optgroup label="${grp}">${fs.map((x) => `<option value="${x}" ${s === x ? 'selected' : ''}>${FIELD_LABEL[x]}</option>`).join('')}</optgroup>`).join('')
        + (fields.some((x) => x.field.startsWith('field:')) ? `<optgroup label="Custom fields">${fields.filter((x) => x.field.startsWith('field:')).map((x) => `<option value="${esc(x.field)}" ${s === x.field ? 'selected' : ''}>${esc(x.label)}</option>`).join('')}</optgroup>` : '');
      const val = (c, i) => {
        const t = typeOf(c.field), vv = c.value == null ? '' : c.value;
        if (['is_set', 'is_not_set', 'is_true', 'is_false'].includes(c.op)) return '';
        if (t === 'jemail') return `<select class="select rv" data-i="${i}"><option value="">Choose an email</option>${emailNodes.map((n) => `<option value="${n.id}" ${vv === n.id ? 'selected' : ''}>${esc((ctx.emails[n.id] || {}).subject || n.title || 'Email')}</option>`).join('')}</select>`;
        if (t === 'event') return `<input class="rv mono" data-i="${i}" value="${esc(vv)}" placeholder="order_placed"><span class="rv-days">in the last <input class="rd" data-i="${i}" type="number" min="1" max="3650" value="${esc(c.days || '')}" placeholder="any"> days</span>`;
        if (c.field === 'status') return `<select class="select rv" data-i="${i}">${cat.statuses.map((x) => `<option ${vv === x ? 'selected' : ''}>${x}</option>`).join('')}</select>`;
        if (c.field === 'source') return `<select class="select rv" data-i="${i}">${cat.sources.map((x) => `<option ${vv === x ? 'selected' : ''}>${x}</option>`).join('')}</select>`;
        if (c.field === 'list') return `<select class="select rv" data-i="${i}"><option value="">Choose a list</option>${cat.lists.map((l) => `<option value="${l.id}" ${vv === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>`;
        if (c.field === 'tag') return `<input class="rv" data-i="${i}" list="jtagl" value="${esc(vv)}" placeholder="tag">`;
        if (t === 'date' && ['within_days', 'not_within_days', 'in_next_days'].includes(c.op)) return `<span class="rv-days"><input class="rv" data-i="${i}" type="number" min="1" max="3650" value="${esc(vv)}"> days</span>`;
        if (t === 'date') return `<input class="rv" data-i="${i}" type="date" value="${esc(vv)}">`;
        return `<input class="rv" data-i="${i}" ${t === 'number' ? 'type="number" step="any"' : ''} value="${esc(vv)}" placeholder="value">`;
      };
      return {
        html: `<div class="jr-match">Contacts who match <select class="select" id="rm" ${dis}><option value="all" ${rules.match === 'all' ? 'selected' : ''}>all</option><option value="any" ${rules.match === 'any' ? 'selected' : ''}>any</option></select> of:</div>
          ${rules.conditions.map((c, i) => `<div class="jr-row"><div class="jr-top"><select class="select rf" data-i="${i}" ${dis}>${fieldOpts(c.field)}</select>${ro ? '' : `<button class="icon-btn rx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>${c.field === 'data' ? `<div class="jr-path"><input class="rp mono" data-i="${i}" value="${esc(c.path || '')}" placeholder="Drag a value here, e.g. steps.….body.plan" ${dis}></div>` : ''}<div class="jr-bot"><select class="select ro" data-i="${i}" ${dis}>${ops(c.field).map((o) => `<option value="${o}" ${c.op === o ? 'selected' : ''}>${OP_LABEL[o] || o}</option>`).join('')}</select>${val(c, i)}</div></div>`).join('') || '<p class="ins-help" style="margin:0 0 8px">No conditions.</p>'}
          <datalist id="jtagl">${(cat.tags || []).map((x) => `<option value="${esc(x)}">`).join('')}</datalist>
          ${ro ? '' : `<button class="link-btn" id="radd">${ic('plus', 13)}Add condition</button>`}`,
        bind: (box, onChange) => {
          if (ro) return;
          $('#rm', box).onchange = (e) => { rules.match = e.target.value; onChange(); };
          $$('.rf', box).forEach((s) => s.onchange = () => { const c = rules.conditions[s.dataset.i]; c.field = s.value; c.op = ops(c.field)[0]; c.value = c.field === 'status' ? 'subscribed' : c.field === 'journey_email' && emailNodes[0] ? emailNodes[0].id : ''; delete c.days; if (c.field === 'data') c.path = c.path || ''; else delete c.path; onChange(true); });
          // A dropped {{ steps.x.y }} becomes the bare path.
          $$('.rp', box).forEach((s) => s.oninput = () => { const v = s.value.replace(/^\s*\{\{\s*|\s*\}\}\s*$/g, '').trim(); if (v !== s.value) s.value = v; rules.conditions[s.dataset.i].path = v; onChange(); });
          $$('.ro', box).forEach((s) => s.onchange = () => { rules.conditions[s.dataset.i].op = s.value; onChange(true); });
          $$('.rv', box).forEach((s) => s.oninput = s.onchange = () => { const c = rules.conditions[s.dataset.i]; c.value = s.type === 'number' ? (s.value === '' ? '' : +s.value) : s.value; onChange(); });
          $$('.rd', box).forEach((s) => s.oninput = () => { rules.conditions[s.dataset.i].days = s.value === '' ? null : +s.value; onChange(); });
          $$('.rx', box).forEach((b) => b.onclick = () => { rules.conditions.splice(+b.dataset.i, 1); onChange(true); });
          $('#radd', box).onclick = () => { rules.conditions.push(emailNodes.length ? { field: 'journey_email', op: 'opened', value: emailNodes[0].id } : { field: 'tag', op: 'has', value: '' }); onChange(true); };
        },
      };
    };
    const emailNodes = () => f.nodes.filter((n) => n.type === 'email');
    const dis = ro ? 'disabled' : '';
    const unitSel = (c, idp) => `<div class="jr-inline"><input type="number" min="1" max="365" id="${idp}a" value="${esc(c.amount || '')}" ${dis}><select class="select" id="${idp}u" ${dis}>${['minutes', 'hours', 'days'].map((u) => `<option ${c.unit === u ? 'selected' : ''}>${u}</option>`).join('')}</select></div>`;
    const branchStats = (n) => {
      const oc = (stats.outcomes || {})[n.id] || {}, keys = outs(n).filter((k) => k && k !== 'error'), tot = keys.reduce((a, k) => a + (oc[k] || 0), 0);
      return tot ? `<div class="ins-sec"><div class="ins-label">Where contacts went</div><div class="ins-branches">${keys.map((k) => `<div class="${brTone(k) === 'yes' ? 'yes' : 'no'}"><span>${esc(brName(n, k))}</span><span class="if-bar"><span style="width:${((oc[k] || 0) / tot) * 100}%"></span></span><b>${fmt(oc[k] || 0)}</b></div>`).join('')}</div>${keys.some((k) => !f.edges.some((e) => e.from === n.id && e.branch === k)) ? '<p class="ins-help">A path with nothing connected ends the journey there.</p>' : ''}</div>` : '';
    };
    // ═══ Test data and the node panel's Input / Output (n8n's node view) ═══
    // The trigger's test data is pinned on the journey; each tested step's output is kept here for this session,
    // and becomes the input of the steps after it.
    let testData = j.test_data || null; // {source, at, note, data: {contact, trigger, steps}}
    const testOut = {}; // step id → {output, branch, note, ms} or {error}
    let lastField = null; // the setting a click on an input value inserts into
    const ioMode = { in: 'schema', out: 'schema' };
    try { Object.assign(ioMode, JSON.parse(localStorage.getItem('sl.ndv') || '{}')); } catch (e) { /* storage off */ }
    const saveMode = () => { try { localStorage.setItem('sl.ndv', JSON.stringify(ioMode)); } catch (e) { /* storage off */ } };
    /** Every step that can lead here, nearest first. */
    const before = (id) => { const seen = new Set(), order = [], qu = [id]; while (qu.length) { const x = qu.shift(); f.edges.filter((e) => e.to === x && !isSub(byId()[e.from])).forEach((e) => { if (!seen.has(e.from)) { seen.add(e.from); order.push(e.from); qu.push(e.from); } }); } return order; };
    const inputFor = (n) => {
      if (!testData) return null;
      const steps = {};
      before(n.id).reverse().forEach((id) => { const r = testOut[id], m = byId()[id]; if (r && r.output && m && m.type !== 'trigger') steps[id] = r.output; });
      return { contact: testData.data.contact, trigger: testData.data.trigger, steps };
    };
    const nodeName = (id) => { const m = byId()[id]; return m ? m.title || NAME[m.type] : 'A deleted step'; };
    const tile = (m, sz) => `<span class="nd-ic" style="background:${TYPE[m.type][1]};color:${TYPE[m.type][2]}">${nodeGlyph(m, sz)}</span>`;
    /** What a sub-node did in its AI step's latest test: {host, output (the step's), calls (a tool's)}. */
    const subRun = (m) => {
      const host = f.edges.filter((e) => e.from === m.id).map((e) => byId()[e.to]).find((h) => h && testOut[h.id] && testOut[h.id].output);
      if (!host) return null;
      const o = testOut[host.id].output, calls = (o.actions || []).filter((a) => a.node === m.id);
      return { host, o, calls };
    };
    const markTested = () => $$('.nd', world).forEach((el) => {
      const m = byId()[el.dataset.n];
      const r = isSub(m) ? (subRun(m) ? { output: {} } : null) : testOut[el.dataset.n];
      const ok = m && (m.type === 'trigger' ? !!testData && !!m.config.trigger : r && !r.error), bad = r && r.error;
      let b = $('.nd-tested', el);
      if (!ok && !bad) { if (b) b.remove(); return; }
      if (!b) { b = document.createElement('span'); el.appendChild(b); }
      b.className = 'nd-tested' + (bad ? ' err' : ''); b.textContent = bad ? '!' : '✓'; b.title = bad ? r.error : 'Tested';
    });
    const refreshIO = () => { const m = byId()[sel]; if (m && $('#ndv').classList.contains('open')) { renderIO(m); previews(m); } };
    const pin = (td) => API('PUT', `${base()}/journeys/${j.id}/test-data`, { test_data: td }).then((r) => { testData = r.test_data; Object.keys(testOut).forEach((k) => delete testOut[k]); markTested(); refreshIO(); return testData; });
    const fetchTrigger = (email) => flush().then(() => API('POST', `${base()}/journeys/${j.id}/test/trigger`, email ? { email } : {})).then((r) => pin(r.sample)).then((td) => { toast(td.source === 'event' ? 'Got the latest real event' : 'Test data ready'); return td; });
    let listenT = null;
    const listen = () => API('POST', `${base()}/journeys/${j.id}/test/listen`).then((r) => {
      const was = testData && testData.at, until = Date.now() + r.listening * 1000;
      modal({ title: 'Listening for a test call', icon: 'webhooks', desc: 'Send one POST to this URL in the next two minutes (from Zapier, n8n, curl…). It becomes the test data; no run starts.',
        body: `<div class="hook"><span class="mono" style="word-break:break-all">${esc(r.url)}</span><button class="icon-btn" data-copy="${esc(r.url)}" title="Copy">${ic('copy', 14)}</button></div><pre class="payload">${esc('curl -X POST "' + r.url + '" -H "Content-Type: application/json" \\\n  -d \'{"email":"amara@example.com","properties":{"plan":"Pro"}}\'')}</pre><p class="faint" id="lsn" style="font-size:13px">Waiting…</p>`,
        mount: (root, api) => { bindCopy(root); clearInterval(listenT); listenT = setInterval(() => {
          if (!root.classList.contains('open') || !$('#lsn', root)) { clearInterval(listenT); return; } // closed, or another dialog took its place
          const left = Math.round((until - Date.now()) / 1000); const el = $('#lsn', root); if (el) el.textContent = left > 0 ? `Waiting… ${left} s left` : 'Stopped listening. Try again.';
          if (left <= 0) { clearInterval(listenT); return; }
          API('GET', `${base()}/journeys/${j.id}/test-data`).then((x) => { if (x.test_data && x.test_data.source === 'webhook' && x.test_data.at !== was) { clearInterval(listenT); testData = x.test_data; Object.keys(testOut).forEach((k) => delete testOut[k]); markTested(); refreshIO(); toast('Test call received'); api.close(); } }).catch(() => {});
        }, 3000); },
        actions: [{ label: 'Close', onClick: () => clearInterval(listenT) }] });
    }).catch((err) => toast(err.message));
    const testStep = (n, choose) => (testData ? Promise.resolve(testData) : fetchTrigger()).then(() => flush())
      .then(() => API('POST', `${base()}/journeys/${j.id}/test/step`, { node: n.id, data: inputFor(n), choose: choose || null }))
      .then((r) => { testOut[n.id] = r.result; markTested(); refreshIO(); return r.result; }, (err) => { testOut[n.id] = { error: err.message }; markTested(); refreshIO(); throw err; });
    /** Walks the draft from the trigger on the test data, testing each step and following the branch it takes. */
    const walk = async (stopAt) => {
      const trig = f.nodes.find((n) => n.type === 'trigger' && n.config.trigger);
      if (!trig) { toast('Choose a trigger first'); return null; }
      if (!testData) await fetchTrigger();
      const walked = [trig.id];
      let id = (f.edges.find((e) => e.from === trig.id) || {}).to;
      for (let i = 0; id && id !== stopAt && i < 40; i++) {
        const n = byId()[id]; if (!n) break;
        walked.push(id);
        let r; try { r = await testStep(n); } catch (e) { toast(`“${nodeName(id)}”: ${e.message}`); break; }
        if (['goal', 'exit', 'jump'].includes(n.type)) break;
        id = (f.edges.find((e) => e.from === id && (e.branch || '') === (r.branch || '')) || {}).to;
      }
      return walked;
    };
    const testJourney = () => { const b = $('#testj'); b.disabled = true; flush().then(() => walk(null)).then((w) => { if (!w) return; path = w; renderNodes(); toast(`Tested ${w.length - 1} step${w.length === 2 ? '' : 's'} on ${testData.data.contact.email}. Click a step to see what went in and out.`); }).catch((err) => toast(err.message)).finally(() => { b.disabled = false; }); };
    const insertExpr = (text) => {
      const el = lastField && document.body.contains(lastField) && !lastField.disabled ? lastField : null;
      if (!el) { (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject(new Error('no clipboard'))).then(() => toast(`Copied ${text}. Paste it into a setting.`), () => toast(text)); return; }
      const a = el.selectionStart ?? el.value.length, z = el.selectionEnd ?? el.value.length;
      el.value = el.value.slice(0, a) + text + el.value.slice(z); el.focus(); el.setSelectionRange(a + text.length, a + text.length);
      el.dispatchEvent(new Event('input', { bubbles: true }));
    };
    /** Under every setting that uses an expression: what it comes out as with the current test data. */
    const previews = (n) => {
      const insp = $('#insp'); if (!insp) return;
      const d = inputFor(n);
      $$('input, textarea', insp).forEach((el) => {
        if (el.tagName === 'INPUT' && !['text', 'search', 'url', 'email'].includes(el.type)) return;
        let pv = el.nextElementSibling && el.nextElementSibling.classList.contains('xp') ? el.nextElementSibling : null;
        if (!hasExpr(el.value)) { if (pv) pv.remove(); return; }
        if (!pv) { pv = document.createElement('div'); pv.className = 'xp'; el.after(pv); }
        const out = d ? resolveExpr(el.value, d) : null;
        pv.innerHTML = `<b>Result</b>${d ? (out === '' ? '<i>empty</i>' : esc(out)) : '<i>Fetch test data on the trigger to preview this</i>'}`;
      });
    };
    function renderIO(n) {
      const inEl = $('#ndvin'), outEl = $('#ndvout'); if (!inEl || !outEl) return;
      if (isSub(n)) return renderSubIO(n, inEl, outEl);
      const seg = (side) => `<div class="seg sm" data-side="${side}">${[['schema', 'Schema'], ['table', 'Table'], ['json', 'JSON']].map(([k, l]) => `<button data-mode="${k}" class="${ioMode[side] === k ? 'on' : ''}">${l}</button>`).join('')}</div>`;
      const isTrig = n.type === 'trigger', can = canEdit() && !ro;
      // Input
      let inner;
      if (isTrig) inner = `<div class="ndv-empty">${svgI(GLYPH.zap, 22)}<b>Where the journey starts</b><span>A trigger has no input. Its output is the contact and the event that started the run: every later step can use them.</span></div>`;
      else if (!testData) inner = `<div class="ndv-empty">${svgI(GLYPH.play, 22)}<b>No test data yet</b><span>Fetch the trigger’s test event to see the data that reaches this step.</span>${can ? '<button class="btn btn-sm btn-primary" data-act="fetch">Fetch test event</button>' : ''}</div>`;
      else {
        const d = inputFor(n), prev = before(n.id).reverse().filter((id) => byId()[id] && byId()[id].type !== 'trigger'), missing = prev.filter((id) => !testOut[id] || testOut[id].error);
        const grp = (title, icon, v, p) => `<div class="ndv-grp"><div class="ndv-gh">${icon}<span>${esc(title)}</span></div>${dataView(v, p, ioMode.in)}</div>`;
        inner = `<p class="ndv-note">Drag a value into a setting, or click it to insert it where you’re typing.</p>`
          + grp('Trigger · ' + (d.trigger.event || 'event'), `<span class="nd-ic" style="background:var(--accent-soft);color:var(--accent-text)">${svgI(GLYPH.zap, 12)}</span>`, d.trigger, 'trigger')
          + grp('Contact · ' + (d.contact.email || ''), `<span class="nd-ic" style="background:var(--s-processing-bg);color:var(--s-processing)">${svgI(GLYPH.user, 12)}</span>`, d.contact, 'contact')
          + prev.filter((id) => d.steps[id]).map((id) => grp(nodeName(id), tile(byId()[id], 12), d.steps[id], 'steps.' + id)).join('')
          + (missing.length ? `<div class="callout info" style="margin-top:8px">${ic('info', 15)}<div>${missing.map((id) => '“' + esc(nodeName(id)) + '”').join(', ')} ${missing.length === 1 ? 'hasn’t' : 'haven’t'} been tested yet, so ${missing.length === 1 ? 'its' : 'their'} output isn’t here.${can ? ' <a href="#" data-act="prev">Test the steps before this one</a>' : ''}</div></div>` : '');
      }
      inEl.innerHTML = `<div class="ndv-ph"><h4>Input</h4>${!isTrig && testData ? seg('in') : ''}</div><div class="ndv-pb">${inner}</div>`;
      // Output
      const r = isTrig ? (testData && n.config.trigger ? { output: { trigger: testData.data.trigger, contact: testData.data.contact }, note: testData.note } : null) : testOut[n.id];
      const SRC = { event: 'Real event', example: 'Example', webhook: 'Test call', run: 'From a run', edited: 'Edited' };
      const acts = !can ? '' : isTrig ? (n.config.trigger ? `<button class="btn btn-sm btn-primary" data-act="fetch">${svgI(GLYPH.zap, 13)}Fetch test event</button>${n.config.trigger === 'webhook' ? '<button class="btn btn-sm" data-act="listen">Listen for test event</button>' : ''}` : '')
        : `<button class="btn btn-sm btn-primary" data-act="test">${svgI(GLYPH.play, 12)}Test step</button>`;
      let body;
      if (isTrig && !n.config.trigger) body = '<div class="ndv-empty"><b>Choose a trigger first</b></div>';
      else if (!r) body = `<div class="ndv-empty">${svgI(GLYPH.play, 22)}<b>${isTrig ? 'No test data yet' : 'Not tested yet'}</b><span>${isTrig ? 'Fetch the latest real event that would have started this journey' + (n.config.trigger === 'webhook' ? ', or listen for a test call to its URL' : '') + '. Or test with a contact below.' : 'Test this step to see what it outputs. Nothing is sent and no contact changes' + (n.type === 'webhook' ? ', but a webhook step does call your endpoint (marked “test”)' : '') + '.'}</span></div>`;
      else if (r.error) body = `<div class="callout warn">${ic('alert', 15)}<div>${esc(r.error)}</div></div>`;
      else body = `<div class="ndv-res">${r.failed ? '<span class="chip bounced">Failed</span>' : ''}${r.branch ? `<span class="chip ${({ yes: 'delivered', err: 'bounced', cat: 'clicked' })[brTone(r.branch)] || 'deferred'}">Goes down ${esc(brName(n, r.branch))}</span>` : ''}${r.ms != null ? `<span class="tag">${r.ms} ms</span>` : ''}${isTrig && testData.source ? `<span class="tag">${SRC[testData.source] || esc(testData.source)}${testData.at ? ' · ' + day(testData.at) : ''}</span>` : ''}</div>${r.note ? `<p class="ndv-note">${esc(r.note)}</p>` : ''}${dataView(r.output, isTrig ? '' : 'steps.' + n.id, ioMode.out)}`;
      const pick = isTrig && can && n.config.trigger ? `<div class="ndv-pick"><input id="ndvem" type="email" placeholder="Test with a contact: amara@example.com"><button class="btn btn-sm" data-act="fetch-em">Use</button></div>` : '';
      const choose = !isTrig && can && ['waitfor', 'split', 'approval'].includes(n.type) ? `<div class="ndv-pick"><span class="faint">Test the path</span>${OUTPUTS[n.type].map((b) => `<button class="btn btn-sm ${r && r.branch === b ? 'btn-primary' : ''}" data-act="choose" data-b="${b}">${esc(brName(n, b))}</button>`).join('')}</div>` : '';
      outEl.innerHTML = `<div class="ndv-ph"><h4>Output</h4>${r && !r.error ? seg('out') : ''}<div class="ndv-acts">${acts}</div></div><div class="ndv-pb">${pick}${choose}${body}${isTrig && testData && can ? '<button class="link-btn" data-act="unpin" style="margin-top:12px">Clear test data</button>' : ''}</div>`;
      [inEl, outEl].forEach((root) => {
        $$('[data-mode]', root).forEach((b) => b.onclick = () => { ioMode[b.closest('[data-side]').dataset.side] = b.dataset.mode; saveMode(); renderIO(n); });
        $$('[data-path]', root).forEach((el) => { el.ondragstart = (e) => { e.dataTransfer.setData('text/plain', `{{ ${el.dataset.path} }}`); e.dataTransfer.effectAllowed = 'copy'; }; });
        $$('.dv-leaf', root).forEach((el) => el.onclick = () => insertExpr(`{{ ${el.dataset.path} }}`));
        $$('[data-act]', root).forEach((b) => b.onclick = (e) => {
          e.preventDefault();
          const a = b.dataset.act, spin = (p) => { b.disabled = true; return p.catch((err) => toast(err.message)).finally(() => { if (document.body.contains(b)) b.disabled = false; }); };
          if (a === 'fetch') spin(fetchTrigger());
          if (a === 'fetch-em') { const em = $('#ndvem', root).value.trim(); if (!em) return toast('Enter a contact’s email'); spin(fetchTrigger(em)); }
          if (a === 'listen') listen();
          if (a === 'test') spin(testStep(n));
          if (a === 'choose') spin(testStep(n, b.dataset.b));
          if (a === 'prev') spin(walk(n.id).then(() => refreshIO()));
          if (a === 'unpin') spin(pin(null).then(() => toast('Test data cleared')));
        });
      });
    }

    /** An AI step's plugged-in sub-nodes, by port, with add buttons (the canvas ports do the same). */
    const pluggedHtml = (n) => portsOf(n).map(([k, label, req, max]) => {
      const subs = subsOf(n.id, k);
      return `<div class="ag-sec" id="ag-${k}"><div class="ag-h"><span class="nd-ic" style="background:${TYPE[k][1]};color:${TYPE[k][2]}">${svgI(GLYPH[{ ai_model: 'chip', ai_memory: 'memory', ai_tool: 'tool', ai_parser: 'parser' }[k]], 13)}</span><b>${label}${req ? '*' : ''}</b><small>${subs.length ? subs.length + (max > 1 ? ' of ' + max : '') : k === 'ai_model' ? 'the platform’s model' : 'none'}</small></div>
        ${subs.map((x) => `<button class="ag-sub" data-open-sub="${x.id}">${nodeGlyph(x, 14)}<span><b>${esc(nameOf(x))}</b><small>${esc(describe(x, ctx))}</small></span>${ic('right', 13)}</button>`).join('')}
        ${!ro && subs.length < max ? `<button class="link-btn" data-addport2="${k}">${ic('plus', 13)}Add ${label.toLowerCase()}</button>` : ''}</div>`;
    }).join('');
    const pluggedInto = (n) => { const hosts = f.edges.filter((e) => e.from === n.id).map((e) => byId()[e.to]).filter(Boolean);
      return `<div class="ins-sec" style="margin-top:14px"><div class="ins-label">Plugged into</div>${hosts.length ? hosts.map((h) => `<button class="ag-sub" data-open-sub="${h.id}">${nodeGlyph(h, 14)}<span><b>${esc(h.title || NAME[h.type])}</b><small>${esc(NAME[h.type])}</small></span>${ic('right', 13)}</button>`).join('') : '<p class="ins-help" style="margin:0">Nothing yet: drag the dot on top of this node onto an AI step.</p>'}</div>`; };
    /** A sub-node's panes: what the agent sent it and what came back, from the AI step's latest test (as n8n shows sub-node runs). */
    function renderSubIO(n, inEl, outEl) {
      const run = subRun(n), host = f.edges.filter((e) => e.from === n.id).map((e) => byId()[e.to]).find(Boolean), can = canEdit() && !ro;
      let input, output;
      if (!run) {
        input = `<div class="ndv-empty">${nodeGlyph(n, 22)}<b>Part of ${host ? '“' + esc(host.title || NAME[host.type]) + '”' : 'an AI step'}</b><span>A sub-node doesn’t run on its own: the AI step it’s plugged into uses it. Test that step to see what went in and out here.</span></div>`;
        output = `<div class="ndv-empty">${svgI(GLYPH.play, 22)}<b>Not tested yet</b><span>${host ? 'Test the step to see this sub-node’s part.' : 'Plug it into an AI step first.'}</span></div>`;
      } else {
        const o = run.o;
        if (n.type === 'ai_tool') {
          input = run.calls.length ? run.calls.map((a, i) => `<div class="ndv-grp"><div class="ndv-gh"><span>Call ${i + 1} · ${esc(a.tool)}</span></div>${dataView(a.input || {}, '', 'json')}</div>`).join('') : '<div class="ndv-empty"><b>Not used</b><span>The agent didn’t need this tool in the last test.</span></div>';
          output = run.calls.map((a, i) => `<div class="ndv-grp"><div class="ndv-gh"><span>Call ${i + 1} · result</span></div>${dataView(a.result || {}, '', ioMode.out)}</div>`).join('');
        } else {
          input = `<p class="ndv-note">From the latest test of “${esc(run.host.title || NAME[run.host.type])}”.</p>`;
          const part = n.type === 'ai_model' ? { model: o.model, tokens: o.tokens } : n.type === 'ai_memory' ? (o.memory || {}) : n.type === 'ai_parser' ? Object.fromEntries(Object.entries(o).filter(([k]) => !['answer', 'actions', 'changes', 'applied', 'turns', 'memory', 'model', 'tokens'].includes(k))) : {};
          output = dataView(part, '', ioMode.out);
        }
      }
      inEl.innerHTML = `<div class="ndv-ph"><h4>Input</h4></div><div class="ndv-pb">${input}</div>`;
      outEl.innerHTML = `<div class="ndv-ph"><h4>Output</h4><div class="ndv-acts">${host && can ? `<button class="btn btn-sm btn-primary" data-act="testhost">${svgI(GLYPH.play, 12)}Test “${esc(host.title || NAME[host.type])}”</button>` : ''}</div></div><div class="ndv-pb">${output}</div>`;
      const tb = $('[data-act="testhost"]', outEl);
      if (tb) tb.onclick = () => { tb.disabled = true; testStep(host).then(() => { markTested(); renderIO(n); }).catch((err) => toast(err.message)).finally(() => { if (document.body.contains(tb)) tb.disabled = false; }); };
    }
    function renderInspector() {
      const insp = $('#insp'), n = byId()[sel];
      insp.classList.toggle('open', !!n);
      $('#ndv').classList.toggle('open', !!n);
      if (!n) { insp.innerHTML = ''; insp.dataset.node = ''; drawMini(); return; }
      if (isSub(n) && inspTab === 'data') inspTab = 'settings'; // sub-nodes have no People tab
      const [icn, bg, col, kind] = TYPE[n.type], c = n.config;
      const reached = (stats.nodes || {})[n.id] || 0, here = (stats.here || {})[n.id] || 0;
      const customKeys = (cat.fields || []).filter((x) => x.field.startsWith('field:'));
      let body = '';
      const nameField = n.type === 'trigger' && !c.trigger ? '' : `<div class="field"><label>Step name</label><input id="ntitle" value="${esc(n.title || '')}" placeholder="${esc(NAME[n.type])}" maxlength="120" ${dis}></div>`;
      if (n.type === 'trigger' && !c.trigger) body = `<div class="ins-empty">${ic('zap', 22, 2)}<b>No trigger yet</b><p>Pick one from the panel on the left: contact changes, app events from the track API, email engagement, dates, or a webhook.</p></div>`;
      else if (n.type === 'trigger') {
        const t = c.trigger;
        body = `<div class="trig-card"><span class="tc-ic">${ic('zap', 16, 2)}</span><div class="tc-txt"><small>Trigger</small><b>${esc(n.title || t)}</b><span id="tdesc">${esc(describe(n, ctx))}</span></div>${ro ? '' : '<button class="btn btn-sm" id="chg">Change</button>'}</div>${nameField}
          ${t === 'list_added' ? `<div class="field"><label>List</label><select class="select" id="tl" ${dis}><option value="">Choose a list</option>${cat.lists.map((l) => `<option value="${l.id}" ${c.list_id === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select></div>` : ''}
          ${t === 'tag_added' ? `<div class="field"><label>Tag</label><input id="tt" list="jtagl2" value="${esc(c.tag || '')}" placeholder="e.g. vip" ${dis}><datalist id="jtagl2">${(cat.tags || []).map((x) => `<option value="${esc(x)}">`).join('')}</datalist></div>` : ''}
          ${t === 'field_changed' ? `<div class="row2"><div class="field"><label>Field</label><select class="select" id="tf" ${dis}><option value="">Choose…</option>${['first_name', 'last_name'].concat(customKeys.map((x) => x.field.slice(6))).map((k) => `<option ${c.field === k ? 'selected' : ''}>${esc(k)}</option>`).join('')}</select></div><div class="field"><label>Changes to <span class="faint">(optional)</span></label><input id="tto" value="${esc(c.to || '')}" placeholder="any value" ${dis}></div></div>` : ''}
          ${t === 'segment_joined' || t === 'segment_left' ? `<div class="field"><label>Segment</label><select class="select" id="tsg" ${dis}><option value="">Choose a segment</option>${ctx.segments.map((x) => `<option value="${x.id}" ${c.segment_id === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select><p class="ins-help">Checked every five minutes. People already ${t === 'segment_joined' ? 'in' : 'out of'} the segment when you turn this on don’t start${ctx.segments.length ? '' : '. Save a segment under <a href="#/contacts">Contacts</a> first'}.</p></div>` : ''}
          ${t === 'form_submitted' || t === 'optin_confirmed' ? `<div class="field"><label>Form</label><select class="select" id="tfm" ${dis}><option value="">Any form</option>${ctx.forms.map((x) => `<option value="${x.id}" ${c.form_id === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select><p class="ins-help">${t === 'optin_confirmed' ? 'When someone clicks the link in the confirmation email of a double opt-in form.' : 'When a form adds someone: at once, or after they confirm on a double opt-in form.'} Forms are under <a href="#/forms">Forms</a>.</p></div>` : ''}
          ${t === 'topic_unsubscribed' ? `<div class="field"><label>Topic</label><select class="select" id="ttp" ${dis}><option value="">Any topic</option>${ctx.topics.map((x) => `<option value="${x.id}" ${c.topic_id === x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select><p class="ins-help">When someone turns a topic off on the unsubscribe page, or you do it on their contact. They stay subscribed to everything else.</p></div>` : ''}
          ${t === 'price_drop' ? `<div class="field"><label>Only drops of at least</label><span class="rv-days"><input type="number" id="tmp" min="0" max="90" value="${esc(c.min_percent || 0)}" ${dis}> %</span><p class="ins-help">From <span class="mono">POST /v1/products</span> or Shopify product updates, to contacts who sent <span class="mono">product_viewed</span> for it in the last 30 days. The email can use {{ name }}, {{ price }}, {{ old_price }}, {{ url }} and {{ image }}. See <a href="#/integrations">Integrations</a>.</p></div>` : ''}
          ${t === 'back_in_stock' ? `<p class="ins-help">When a product goes from out of stock to in stock, for contacts who sent <span class="mono">back_in_stock_requested</span> (90 days) or <span class="mono">product_viewed</span> (30 days) for it. See <a href="#/integrations">Integrations</a>.</p>` : ''}
          ${t === 'inactive' ? `<div class="row2"><div class="field"><label>Activity event</label><input id="tia" class="mono" value="${esc(c.activity || '')}" placeholder="app_opened" ${dis}></div><div class="field"><label>Missing for</label><span class="rv-days"><input type="number" id="tid" min="1" max="365" value="${esc(c.days || '')}" ${dis}> days</span></div></div><p class="ins-help">For people who sent this event before (<span class="mono">POST /v1/events</span>) and then stopped. Checked hourly; once per quiet spell.</p>` : ''}
          ${t === 'went_quiet' ? `<div class="field"><label>No real opens or clicks for</label><span class="rv-days"><input type="number" id="tid" min="1" max="365" value="${esc(c.days || '')}" ${dis}> days</span><p class="ins-help">Only people you’re still emailing (one sent in that time). Checked hourly; once per quiet spell. Apple Mail hides real opens, so a click is what counts there.</p></div>` : ''}
          ${t === 'schedule' ? `<div class="row3"><div class="field"><label>Every</label><select class="select" id="tse" ${dis}>${[['day', 'Day'], ['week', 'Week'], ['month', 'Month']].map(([k, l]) => `<option value="${k}" ${c.every === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
            ${c.every === 'week' ? `<div class="field"><label>On</label><select class="select" id="tsw" ${dis}>${DAYS_LONG.map((d, i) => `<option value="${i + 1}" ${+(c.weekday || 1) === i + 1 ? 'selected' : ''}>${d}</option>`).join('')}</select></div>` : c.every === 'month' ? `<div class="field"><label>On the</label><select class="select" id="tsm" ${dis}>${Array.from({ length: 31 }, (_, i) => `<option value="${i + 1}" ${+(c.monthday || 1) === i + 1 ? 'selected' : ''}>${i === 30 ? 'Last day' : ordinal(i + 1)}</option>`).join('')}</select></div>` : '<div></div>'}
            <div class="field"><label>At</label><select class="select" id="tdh" ${dis}>${Array.from({ length: 24 }, (_, h) => `<option value="${h}" ${+(c.hour ?? 9) === h ? 'selected' : ''}>${String(h).padStart(2, '0')}:00</option>`).join('')}</select></div></div>
            <p class="ins-help">Everyone subscribed enters, in each contact’s <span class="mono">timezone</span> (or the journey’s). Narrow it with the filter below, and allow re-entry “every time” so they come back each ${esc(c.every || 'week')}.</p>` : ''}
          ${t === 'event' ? `<div class="field"><label>Event name</label><input id="te" class="mono" value="${esc(c.event || '')}" placeholder="trial_started" ${dis}><p class="ins-help">Your app sends it to <span class="mono">POST /v1/events</span> with a full-access API key.</p></div>` : ''}
          ${t === 'email_clicked' ? `<div class="field"><label>Only links containing <span class="faint">(optional)</span></label><input id="tu" value="${esc(c.url_contains || '')}" placeholder="/pricing" ${dis}></div>` : ''}
          ${t === 'date' ? `<div class="field"><label>Date field</label><select class="select" id="tdf" ${dis}><option value="">Choose…</option><option value="created_at" ${c.field === 'created_at' ? 'selected' : ''}>Date added (created_at)</option>${customKeys.map((x) => `<option value="${esc(x.field.slice(6))}" ${c.field === x.field.slice(6) ? 'selected' : ''}>${esc(x.label)} (${esc(x.field.slice(6))})</option>`).join('')}${c.field && c.field !== 'created_at' && !customKeys.some((x) => x.field === 'field:' + c.field) ? `<option selected value="${esc(c.field)}">${esc(c.field)}</option>` : ''}</select></div>
            <div class="row3"><div class="field"><label>Days</label><input type="number" id="tdo" min="-365" max="365" value="${esc(c.offset_days || 0)}" ${dis}></div><div class="field"><label>At</label><select class="select" id="tdh" ${dis}>${Array.from({ length: 24 }, (_, h) => `<option value="${h}" ${+(c.hour ?? 9) === h ? 'selected' : ''}>${String(h).padStart(2, '0')}:00</option>`).join('')}</select></div><div class="field"><label>Repeat</label><select class="select" id="tdy" ${dis}><option value="1" ${c.yearly ? 'selected' : ''}>Every year</option><option value="0" ${c.yearly ? '' : 'selected'}>Once</option></select></div></div>
            <p class="ins-help">Negative days = before the date (−3 = three days before). The hour is in each contact’s <span class="mono">timezone</span> field, or the journey’s time zone. Dates like 1994-11-03, or 11-03 for birthdays.</p>` : ''}
          ${t === 'webhook' ? `<div class="ins-sec"><div class="ins-label">POST JSON to this URL</div><div class="hook"><span class="mono">${esc(j.hook_url)}</span><button class="icon-btn" data-copy="${esc(j.hook_url)}" title="Copy">${ic('copy', 14)}</button></div><pre class="payload">${esc(JSON.stringify({ email: 'amara@example.com', first_name: 'Amara', id: 'unique-event-id', properties: { plan: 'Pro' } }, null, 2))}</pre><p class="ins-help">In Zapier or n8n, add a webhook / HTTP Request step. Only <span class="mono">email</span> is required; <span class="mono">id</span> makes retries safe. The URL is the key: keep it private.</p></div>` : ''}
          <div class="ins-sec"><div class="ins-label">Only when <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">(optional filter)</span></div><div id="tfilt"></div></div>
          <div class="ins-sec"><div class="ins-label">Can someone enter again?</div><div class="rhythms">${[['once', 'Only once', 'They never re-enter'], ['cooldown', `Again after ${j.settings.cooldown_days} days`, 'Counted from when they leave'], ['always', 'Every time it happens', 'Each event starts a new run']].map(([k, a, b]) => `<button class="rh ${j.settings.reentry === k ? 'on' : ''}" data-rh="${k}" ${dis}><span class="rh-txt"><b>${a}</b><small>${b}</small></span></button>`).join('')}</div></div>`;
      } else if (n.type === 'email') {
        const e = ctx.emails[n.id] || {};
        body = `${nameField}<div class="mail-frame"><iframe id="mframe" sandbox tabindex="-1"></iframe>${ro || !e.has_design ? '' : `<button class="btn btn-sm mf-edit" id="mfe">${ic('edit', 13)}Edit design</button>`}</div>
          ${!e.has_design && !ro ? `<div class="jr-starts"><button class="btn btn-sm btn-primary" data-es="blank">${ic('edit', 13)}Design from scratch</button><button class="btn btn-sm" data-es="gallery">Gallery</button>${templates.length ? `<select class="select" id="etpl"><option value="">Your templates…</option>${templates.map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}</select>` : ''}</div>` : ''}
          <div class="field"><label>Subject</label><input id="es" maxlength="255" value="${esc(e.subject || '')}" placeholder="Welcome, {{ first_name | there }}" ${dis}></div>
          <div class="field"><label>Preview text</label><input id="ep" maxlength="300" value="${esc(e.preheader || '')}" placeholder="Shown after the subject" ${dis}></div>
          ${domains.length ? `<div class="field"><label>From</label><input id="efn" value="${esc(e.from_name || '')}" placeholder="Sender name" style="margin-bottom:6px" ${dis}>${S.fromPick('efp', snd, e.from_email, 'hello', !!dis)}${e.from_email ? '' : `<button class="link-btn" id="efset">${ic('check', 13)}Use this sender</button>`}</div>` : `<div class="callout warn">${ic('alert', 15)}<div>Verify a sender under <a href="${snd.method === 'smtp' ? '#/ses/smtp' : '#/domains'}">${snd.method === 'smtp' ? 'Sending' : 'Domains'}</a> to send from it.</div></div>`}
          <div class="field"><label>Reply-to <span class="faint">(optional)</span></label><input id="ert" type="email" value="${esc(e.reply_to || '')}" ${dis}></div>
          <div class="field"><label>Kind of email</label><select class="select" id="ety" ${dis}><option value="marketing" ${c.type !== 'transactional' ? 'selected' : ''}>Marketing (unsubscribe link; skips unsubscribed)</option><option value="transactional" ${c.type === 'transactional' ? 'selected' : ''}>Transactional (receipts, payment problems)</option></select></div>
          <label class="check"><input type="checkbox" id="esk" ${c.skip_recent_hours ? 'checked' : ''} ${dis}> Skip if they got a marketing email in the last 16 hours</label>
          ${e.checks && e.checks.some((x) => x.status !== 'ok') ? `<ul class="ct-checks">${e.checks.filter((x) => x.status !== 'ok').map((x) => `<li class="${x.status}"><span>!</span>${esc(x.detail)}</li>`).join('')}</ul>` : ''}
          ${e.has_design && canEdit() ? `<button class="btn btn-sm" id="etest" style="margin-top:12px">${ic('send', 13)}Send test</button>` : ''}
          ${(stats.emails || {})[n.id] ? (() => { const s = stats.emails[n.id]; return `<div class="ins-sec" style="margin-top:16px"><div class="ins-label">Sent from this step · ${fmt(s.sent)}</div><div class="kpis"><div><b>${s.sent ? pct(s.delivered / s.sent, 0) : '–'}</b><small>Delivered</small></div><div><b>${s.delivered ? pct(s.opened / s.delivered, 0) : '–'}</b><small>Real opens</small></div><div><b>${s.delivered ? pct(s.clicked / s.delivered) : '–'}</b><small>Clicked</small></div></div></div>`; })() : ''}`;
      } else if (n.type === 'wait') body = `${nameField}<div class="field"><label>Wait for</label>${unitSel(c, 'w')}</div><p class="ins-help">Then continues to the next step. Add a Send window after it to land in working hours.</p>`;
      else if (n.type === 'window') body = `${nameField}<div class="ins-sec"><div class="ins-label">Continue only on</div><div class="day-pills" id="dp">${DAYS.map((d, i) => `<span data-d="${i + 1}" class="${(c.days || []).includes(i + 1) ? 'on' : ''}" title="${d}">${d[0]}</span>`).join('')}</div></div>
        <div class="row2"><div class="field"><label>From</label><input type="time" id="wf" value="${esc(c.from || '09:00')}" ${dis}></div><div class="field"><label>To</label><input type="time" id="wt" value="${esc(c.to || '18:00')}" ${dis}></div></div><p class="ins-help">In each contact’s time zone (their <span class="mono">timezone</span> field), otherwise ${esc(j.settings.timezone)}.</p>`;
      else if (n.type === 'waitfor') {
        const evs = ['email.opened', 'email.clicked', 'email.replied'];
        body = `${nameField}<div class="field"><label>Wait until they</label><select class="select" id="wfk" ${dis}><option value="custom" ${evs.includes(c.event) ? '' : 'selected'}>do an app event</option><option value="email.opened" ${c.event === 'email.opened' ? 'selected' : ''}>open an email</option><option value="email.clicked" ${c.event === 'email.clicked' ? 'selected' : ''}>click an email</option><option value="email.replied" ${c.event === 'email.replied' ? 'selected' : ''}>reply to an email</option></select></div>
          ${evs.includes(c.event) ? `<div class="field"><label>Which email</label><select class="select" id="wfn" ${dis}><option value="">Any email</option>${emailNodes().map((m) => `<option value="${m.id}" ${c.node === m.id ? 'selected' : ''}>${esc((ctx.emails[m.id] || {}).subject || m.title || 'Email')}</option>`).join('')}</select></div>` : `<div class="field"><label>Event name</label><input id="wfe" class="mono" value="${esc(c.event || '')}" placeholder="app_opened" ${dis}></div>`}
          <div class="field"><label>Give up after</label>${unitSel(c, 'wfx')}</div><p class="ins-help">If it happens in time they go down <b>Yes</b> straight away; otherwise down <b>No</b>. Real opens and clicks only; replies need a mailbox under <a href="#/ses/mailbox">Sending → Mailbox</a>.</p>${branchStats(n)}`;
      } else if (n.type === 'cond') body = `${nameField}<div class="ins-sec"><div class="ins-label">Go down “Yes” when</div><div id="crules"></div></div>${branchStats(n)}`;
      else if (n.type === 'split') body = `${nameField}<div class="field"><label>A gets <b id="spv">${c.percent_a ?? 50}%</b>, B gets the rest</label><input type="range" id="sp" min="1" max="99" value="${c.percent_a ?? 50}" ${dis}></div><p class="ins-help">Random and stable: a contact always lands on the same side.</p>${branchStats(n)}`;
      else if (n.type === 'goal') body = `${nameField}<p class="ins-help" style="margin-top:0">Contacts who do this leave the journey right away, wherever they are, so they stop getting nudges. It works connected or on its own.</p><div class="field"><label>Goal event</label><input id="ge" class="mono" value="${esc(c.event || '')}" placeholder="plan_upgraded" ${dis}></div>${reached && stats.entered ? `<div class="ins-big"><b>${pct(reached / stats.entered)}</b><span>of everyone who entered reached this goal</span></div>` : ''}`;
      else if (n.type === 'tag') body = `${nameField}<div class="row2"><div class="field"><label>Action</label><select class="select" id="ka" ${dis}><option value="add" ${c.action !== 'remove' ? 'selected' : ''}>Add tag</option><option value="remove" ${c.action === 'remove' ? 'selected' : ''}>Remove tag</option></select></div><div class="field"><label>Tag</label><input id="kt" list="jtagl3" value="${esc(c.tag || '')}" ${dis}><datalist id="jtagl3">${(cat.tags || []).map((x) => `<option value="${esc(x)}">`).join('')}</datalist></div></div>`;
      else if (n.type === 'field') body = `${nameField}<div class="field"><label>Field</label><input id="fk" list="jfl" value="${esc(c.key || '')}" placeholder="plan" ${dis}><datalist id="jfl"><option value="first_name"><option value="last_name">${customKeys.map((x) => `<option value="${esc(x.field.slice(6))}">`).join('')}</datalist></div><div class="field"><label>New value <span class="faint">(empty clears it)</span></label><input id="fv" value="${esc(c.value || '')}" ${dis}></div>`;
      else if (n.type === 'list') body = `${nameField}<div class="row2"><div class="field"><label>Action</label><select class="select" id="la" ${dis}><option value="add" ${c.action !== 'remove' ? 'selected' : ''}>Add to</option><option value="remove" ${c.action === 'remove' ? 'selected' : ''}>Remove from</option></select></div><div class="field"><label>List</label><select class="select" id="ll" ${dis}><option value="">Choose…</option>${cat.lists.map((l) => `<option value="${l.id}" ${c.list_id === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select></div></div>`;
      else if (n.type === 'notify') body = `${nameField}<div class="field"><label>Send to</label><select class="select" id="nc" ${dis}><option value="email" ${c.channel !== 'slack' ? 'selected' : ''}>Email</option><option value="slack" ${c.channel === 'slack' ? 'selected' : ''}>Slack</option></select></div>
        ${c.channel === 'slack' ? `<div class="field"><label>Slack incoming-webhook URL</label><input id="nsu" value="${esc(c.slack_url || '')}" placeholder="https://hooks.slack.com/services/…" ${dis}></div>` : `<div class="field"><label>Team addresses <span class="faint">(comma-separated, up to 5)</span></label><input id="nto" value="${esc((c.to || []).join(', '))}" ${dis}></div>`}
        <div class="field"><label>Message</label><textarea id="nm" rows="4" ${dis}>${esc(c.message || '')}</textarea><p class="ins-help">Use {{ email }}, {{ first_name }}, {{ journey }}.</p></div>`;
      else if (n.type === 'webhook') body = `${nameField}<div class="field"><label>Endpoint URL</label><input id="hu" value="${esc(c.url || '')}" placeholder="https://api.yourapp.com/sendline" ${dis}></div>
        <div class="ins-sec"><div class="ins-label">Body fields <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">(optional, sent as “data”)</span></div>${(Array.isArray(c.fields) ? c.fields : []).map((fd, i) => `<div class="hf-row"><input class="hfn mono" data-i="${i}" value="${esc(fd.name || '')}" placeholder="name" aria-label="Field name" ${dis}><div class="hf-v"><input class="hfv" data-i="${i}" value="${esc(fd.value || '')}" placeholder="{{ trigger.properties.plan }}" aria-label="Value" ${dis}></div>${ro ? '' : `<button class="icon-btn hfx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro ? '' : `<button class="link-btn" id="hfadd">${ic('plus', 13)}Add field</button>`}
          <p class="ins-help">Drag values in from the input. Your endpoint’s answer becomes this step’s output: later steps can use it, e.g. <span class="mono">{{ steps.${esc(n.id)}.body.plan }}</span>, or branch on it in an If / else.</p></div>
        <p class="ins-help">We POST the contact, the trigger event and earlier steps’ data as JSON, signed with this journey’s secret (Standard Webhooks headers). Public HTTPS only; a 10-second timeout. <a href="/docs/#journey-webhooks" target="_blank" rel="noopener">How to verify</a></p>${['owner', 'admin'].includes(myRole()) ? `<div class="field"><label>Signing secret</label><div class="hook"><span class="mono" id="jsec">whsec_••••••••</span><button class="btn btn-sm" id="jsecbtn">Reveal</button></div></div>` : ''}`;
      else if (n.type === 'jump') body = `${nameField}<div class="field"><label>Move to</label><select class="select" id="jj" ${dis}><option value="">Choose a journey</option>${journeys.map((x) => `<option value="${x.id}" ${c.journey_id === x.id ? 'selected' : ''}>${esc(x.name)}${x.status === 'active' ? '' : ' (not on)'}</option>`).join('')}</select></div><p class="ins-help">They leave this journey and start the other one (its re-entry rule applies).</p>`;
      else if (n.type === 'ai_write') body = `${nameField}<div class="field"><label>What should the AI write?</label><textarea id="aip" rows="6" placeholder="One friendly sentence for {{ contact.first_name }} about their order of {{ trigger.properties.product }}." ${dis}>${esc(c.prompt || '')}</textarea>
        <p class="ins-help">Drag values in from the input. The contact and the trigger event always go along. The answer is <span class="mono">{{ steps.${esc(n.id)}.text }}</span>: use it in a field, a notification or a webhook. To put it in an email, save it to a field with Update field and use that field’s merge tag.</p></div>`;
      else if (n.type === 'ai_classify') body = `${nameField}<div class="field"><label>What to classify <span class="faint">(optional)</span></label><input id="aci" value="${esc(c.input || '')}" placeholder="{{ trigger.properties.message }}" ${dis}><p class="ins-help">Empty: the AI looks at the contact and the event.</p></div>
        <div class="ins-sec"><div class="ins-label">Categories <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">(each one is a path)</span></div>${(c.categories || []).map((k, i) => `<div class="ai-row"><input class="acn" data-i="${i}" value="${esc(k.name || '')}" placeholder="e.g. Billing" maxlength="60" aria-label="Category name" ${dis}><input class="acd" data-i="${i}" value="${esc(k.description || '')}" placeholder="When it fits (optional)" maxlength="300" aria-label="Description" ${dis}>${ro ? '' : `<button class="icon-btn acx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}
          <div class="ai-row ai-other"><span>Other</span><span class="faint">When nothing fits clearly</span></div>${ro || (c.categories || []).length >= 8 ? '' : `<button class="link-btn" id="acadd">${ic('plus', 13)}Add category</button>`}</div>
        <div class="field"><label>More guidance <span class="faint">(optional)</span></label><textarea id="acs" rows="2" placeholder="Refund questions count as Billing." ${dis}>${esc(c.instructions || '')}</textarea></div>
        <p class="ins-help">The choice is <span class="mono">{{ steps.${esc(n.id)}.category }}</span>, with a short <span class="mono">reason</span>.</p>${branchStats(n)}`;
      else if (n.type === 'ai_extract') body = `${nameField}<div class="field"><label>Text to read</label><input id="axi" value="${esc(c.input || '')}" placeholder="{{ trigger.properties.message }}" ${dis}></div>
        <div class="ins-sec"><div class="ins-label">Take out</div>${(c.fields || []).map((fd, i) => `<div class="ai-row ai-x"><input class="axn mono" data-i="${i}" value="${esc(fd.name || '')}" placeholder="name" aria-label="Field name" ${dis}><select class="select axt" data-i="${i}" aria-label="Type" ${dis}>${[['text', 'Text'], ['number', 'Number'], ['yes_no', 'Yes / no'], ['date', 'Date']].map(([k, l]) => `<option value="${k}" ${fd.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select><input class="axd" data-i="${i}" value="${esc(fd.description || '')}" placeholder="What it is (optional)" aria-label="Description" ${dis}>${ro ? '' : `<button class="icon-btn axx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro || (c.fields || []).length >= 15 ? '' : `<button class="link-btn" id="axadd">${ic('plus', 13)}Add field</button>`}
          <p class="ins-help">Each one becomes <span class="mono">{{ steps.${esc(n.id)}.<i>name</i> }}</span>, or empty when the text doesn’t say. The AI never guesses.</p></div>`;
      else if (n.type === 'ai_agent') body = `${nameField}<div class="field"><label>Instructions <span class="faint">(the prompt)</span></label><textarea id="agi" rows="7" placeholder="Look at what {{ contact.first_name }} bought and opened. If they spend a lot and engage, tag them vip. Otherwise do nothing." ${dis}>${esc(c.instructions || '')}</textarea></div>
          ${pluggedHtml(n)}
          <div class="field"><label>Thinking rounds, at most</label><select class="select" id="agm" ${dis}>${[2, 3, 4, 5, 6, 7, 8].map((k) => `<option ${+(c.max_turns || 5) === k ? 'selected' : ''}>${k}</option>`).join('')}</select></div>
          <p class="ins-help">Plug a chat model, memory, tools and an output parser into the ports under the agent on the canvas, as in n8n. It only ever sees and changes this contact. Its answer is <span class="mono">{{ steps.${esc(n.id)}.answer }}</span>; the tools it used are in <span class="mono">actions</span>. Test step really runs it; tools that change something are only described.</p>`;
      else if (n.type === 'ai_model') {
        const models = (j.ai && j.ai.models) || [], by = {};
        models.forEach((m, i) => { if (i) (by[providerOf(m)] = by[providerOf(m)] || []).push(m); });
        body = `${nameField}<div class="field"><label>Model (the brain)</label><select class="select" id="smm" ${dis}><option value="">The platform’s model${models[0] ? ' · ' + esc(models[0]) : ''}</option>${Object.entries(by).map(([p2, ms]) => `<optgroup label="${esc(p2)}">${ms.map((m) => `<option value="${esc(m)}" ${c.model === m ? 'selected' : ''}>${esc(m.split('/').pop())}</option>`).join('')}</optgroup>`).join('')}${c.model && !models.includes(c.model) ? `<option selected value="${esc(c.model)}">${esc(c.model)} (no longer offered)</option>` : ''}</select><p class="ins-help">GPT, Claude, Gemini and others, through the platform’s OpenRouter key. The installation admin chooses which are offered, in the AI settings.</p></div>
          <div class="field"><label>Temperature <b id="smtv">${c.temperature ?? 0.3}</b></label><input type="range" id="smt" min="0" max="1" step="0.1" value="${c.temperature ?? 0.3}" ${dis}><p class="ins-help">Lower: steady and exact. Higher: more varied wording.</p></div>
          <div class="field"><label>Longest answer <span class="faint">(tokens; 0 = automatic)</span></label><input type="number" id="smx" min="0" max="4000" step="100" value="${c.max_tokens || 0}" ${dis}></div>${pluggedInto(n)}`;
      } else if (n.type === 'ai_memory') body = `${nameField}<div class="row2"><div class="field"><label>Memory key</label><input id="smk" class="mono" maxlength="40" value="${esc(c.key || 'agent')}" ${dis}></div><div class="field"><label>Remember the last</label><select class="select" id="smw" ${dis}>${[1, 2, 3, 5, 10, 20].map((k) => `<option value="${k}" ${+(c.window || 5) === k ? 'selected' : ''}>${k} exchange${k > 1 ? 's' : ''}</option>`).join('')}</select></div></div>
          <p class="ins-help">Like n8n’s Simple Memory, kept per contact: each run sees what the agent said about this contact before. Agents with the same key share it, across journeys. It goes when the contact is deleted; tests read it but add nothing.</p>${pluggedInto(n)}`;
      else if (n.type === 'ai_parser') body = `${nameField}<div class="field"><label>Kind</label><select class="select" id="spk" ${dis}><option value="structured" ${c.kind !== 'list' ? 'selected' : ''}>Structured: named, typed answers</option><option value="list" ${c.kind === 'list' ? 'selected' : ''}>Item list: a list of short items</option></select></div>
          ${c.kind === 'list' ? `<div class="row2"><div class="field"><label>Up to</label><span class="rv-days"><input type="number" id="spm" min="1" max="50" value="${c.max_items || 5}" ${dis}> items</span></div><div class="field"><label>Each item is <span class="faint">(optional)</span></label><input id="spi" maxlength="200" value="${esc(c.item || '')}" placeholder="a product name" ${dis}></div></div><p class="ins-help">The list is <span class="mono">{{ steps.<i>agent</i>.items }}</span>.</p>`
            : `<div class="ins-sec"><div class="ins-label">Fields</div>${(c.fields || []).map((fd, i) => `<div class="ai-row ai-x"><input class="spf-n mono" data-i="${i}" value="${esc(fd.name || '')}" placeholder="name" aria-label="Field name" ${dis}><select class="select spf-t" data-i="${i}" ${dis}>${[['text', 'Text'], ['number', 'Number'], ['yes_no', 'Yes / no'], ['date', 'Date']].map(([k, l]) => `<option value="${k}" ${fd.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select><input class="spf-d" data-i="${i}" value="${esc(fd.description || '')}" placeholder="What it means (optional)" ${dis}>${ro ? '' : `<button class="icon-btn spf-x" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro || (c.fields || []).length >= 10 ? '' : `<button class="link-btn" id="spf-add">${ic('plus', 13)}Add field</button>`}</div>
            <label class="check"><input type="checkbox" id="spa" ${c.autofix !== false ? 'checked' : ''} ${dis}> Auto-fix: if the answer isn’t in this format, ask the model once more</label><p class="ins-help">Each field becomes <span class="mono">{{ steps.<i>agent</i>.<i>name</i> }}</span>, so an If / else can branch on the agent’s decision.</p>`}${pluggedInto(n)}`;
      else if (n.type === 'ai_tool') {
        const k = c.tool;
        body = `${nameField}<div class="field"><label>Tool</label><select class="select" id="stk" ${dis}>${TOOL_KINDS.map(([x, nm]) => `<option value="${x}" ${k === x ? 'selected' : ''}>${nm}</option>`).join('')}</select><p class="ins-help">${esc((TOOL_KINDS.find((t) => t[0] === k) || [0, 0, ''])[2])}.</p></div>
          ${k === 'fields' ? `<div class="field"><label>Fields it may change <span class="faint">(comma-separated)</span></label><input id="stf" list="jfl3" value="${esc((c.fields || []).join(', '))}" placeholder="tier, lead_score" ${dis}><datalist id="jfl3"><option value="first_name"><option value="last_name">${customKeys.map((x) => `<option value="${esc(x.field.slice(6))}">`).join('')}</datalist></div>` : ''}
          ${k === 'lists' ? `<div class="field"><label>Lists it may use</label>${cat.lists.length ? cat.lists.map((l) => `<label class="check"><input type="checkbox" data-stl="${l.id}" ${(c.lists || []).includes(l.id) ? 'checked' : ''} ${dis}> ${esc(l.name)}</label>`).join('') : '<p class="ins-help">No lists yet.</p>'}</div>` : ''}
          ${k === 'notify' ? `<div class="field"><label>Team addresses it may email <span class="faint">(comma-separated, up to 5)</span></label><input id="stn" value="${esc((c.to || []).join(', '))}" ${dis}></div>` : ''}
          ${k === 'think' ? `<div class="field"><label>Description <span class="faint">(optional)</span></label><textarea id="std" rows="2" maxlength="300" placeholder="Use the tool to think about something…" ${dis}>${esc(c.description || '')}</textarea></div>` : ''}
          ${k === 'http' ? `<div class="row2"><div class="field"><label>Tool name</label><input id="shn" class="mono" maxlength="40" value="${esc(c.name || '')}" placeholder="check_stock" ${dis}></div><div class="field"><label>Method</label><select class="select" id="shm" ${dis}>${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option ${(c.method || 'GET') === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div></div>
            <div class="field"><label>What it does and when to use it</label><textarea id="shd" rows="2" maxlength="300" placeholder="Looks up how many of a product are in stock." ${dis}>${esc(c.description || '')}</textarea><p class="ins-help">The agent decides from this, so be specific.</p></div>
            <div class="field"><label>URL</label><input id="shu" value="${esc(c.url || '')}" placeholder="https://api.yourshop.com/stock/{sku}" ${dis}><p class="ins-help">Put {name} where a parameter goes in the path; {{ … }} values from the data work too.</p></div>
            <div class="ins-sec"><div class="ins-label">Parameters the agent fills</div>${(c.params || []).map((pr, i) => `<div class="ht-p"><div class="ai-row" style="grid-template-columns:minmax(0,1fr) 92px 92px auto"><input class="shp-n mono" data-i="${i}" value="${esc(pr.name || '')}" placeholder="sku" aria-label="Name" ${dis}><select class="select shp-t" data-i="${i}" ${dis}>${['string', 'number', 'boolean'].map((t) => `<option ${pr.type === t ? 'selected' : ''}>${t}</option>`).join('')}</select><select class="select shp-in" data-i="${i}" ${dis}>${[['query', 'query'], ['body', 'body'], ['path', 'path']].map(([v2, l]) => `<option value="${v2}" ${pr.in === v2 ? 'selected' : ''}>${l}</option>`).join('')}</select>${ro ? '' : `<button class="icon-btn shp-x" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div><input class="shp-d" data-i="${i}" value="${esc(pr.description || '')}" placeholder="What the agent should put here" ${dis}><label class="check" style="padding:4px 0;border:0"><input type="checkbox" class="shp-r" data-i="${i}" ${pr.required !== false ? 'checked' : ''} ${dis}> Required</label></div>`).join('')}${ro ? '' : `<button class="link-btn" id="shp-add">${ic('plus', 13)}Add parameter</button>`}</div>
            <div class="ins-sec"><div class="ins-label">Headers</div>${(c.headers || []).map((h, i) => `<div class="hf-row"><input class="shh-n mono" data-i="${i}" value="${esc(h.name || '')}" placeholder="Authorization" ${dis}><div class="hf-v"><input class="shh-v" data-i="${i}" value="${esc(h.value || '')}" placeholder="Bearer …" ${dis}></div>${ro ? '' : `<button class="icon-btn shh-x" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro ? '' : `<button class="link-btn" id="shh-add">${ic('plus', 13)}Add header</button>`}<p class="ins-help">Public HTTPS only, 10 s, no redirects. In a test, GET runs for real; other methods are only described. Header values are kept in the journey.</p></div>` : ''}
          ${pluggedInto(n)}`;
      } else if (n.type === 'switch') {
        const SW = ['is', 'is_not', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set'];
        body = `${nameField}<div class="field"><label>Route on this value</label><input id="swv" value="${esc(c.value || '')}" placeholder="{{ trigger.properties.plan }}" ${dis}><p class="ins-help">Drag a value in from the input.</p></div>
          <div class="ins-sec"><div class="ins-label">Cases <span class="faint" style="text-transform:none;letter-spacing:0;font-weight:500">(the first that matches wins; each is a path)</span></div>${(c.cases || []).map((k, i) => `<div class="ai-row sw-row"><select class="select swo" data-i="${i}" aria-label="Comparison" ${dis}>${SW.map((o) => `<option value="${o}" ${k.op === o ? 'selected' : ''}>${OP_LABEL[o]}</option>`).join('')}</select><input class="swc" data-i="${i}" value="${esc(k.value || '')}" placeholder="${['is_set', 'is_not_set'].includes(k.op) ? '' : 'pro'}" ${['is_set', 'is_not_set'].includes(k.op) ? 'disabled' : ''} ${dis} aria-label="Value"><input class="swn" data-i="${i}" value="${esc(k.name || '')}" placeholder="Path name (optional)" maxlength="60" aria-label="Path name" ${dis}>${ro ? '' : `<button class="icon-btn swx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}
          <div class="ai-row ai-other"><span>Other</span><span class="faint">When no case matches</span></div>${ro || (c.cases || []).length >= 10 ? '' : `<button class="link-btn" id="swadd">${ic('plus', 13)}Add case</button>`}</div>${branchStats(n)}`;
      } else if (n.type === 'filter') body = `${nameField}<div class="ins-sec"><div class="ins-label">Go on only when</div><div id="crules"></div></div><p class="ins-help">Everyone else leaves the journey here (shown as “Filtered out” in their history). Like If / else without a No path.</p>`;
      else if (n.type === 'set') body = `${nameField}<div class="ins-sec"><div class="ins-label">Fields</div>${(c.fields || []).map((fd, i) => `<div class="ai-row ai-x"><input class="stn mono" data-i="${i}" value="${esc(fd.name || '')}" placeholder="name" aria-label="Field name" ${dis}><select class="select stt" data-i="${i}" aria-label="Type" ${dis}>${[['text', 'Text'], ['number', 'Number'], ['yes_no', 'Yes / no'], ['date', 'Date']].map(([k, l]) => `<option value="${k}" ${fd.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select><input class="stv" data-i="${i}" value="${esc(fd.value || '')}" placeholder="{{ trigger.properties.amount }}" aria-label="Value" ${dis}>${ro ? '' : `<button class="icon-btn stx" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro || (c.fields || []).length >= 20 ? '' : `<button class="link-btn" id="stadd">${ic('plus', 13)}Add field</button>`}
        <p class="ins-help">Mix text and values, e.g. <span class="mono">{{ contact.first_name }} from {{ contact.fields.city }}</span>. Later steps use them as <span class="mono">{{ steps.${esc(n.id)}.<i>name</i> }}</span>. Nothing is saved on the contact: use Update field for that.</p></div>`;
      else if (n.type === 'datetime') {
        const op = c.op || 'add';
        body = `${nameField}<div class="field"><label>What to do</label><select class="select" id="dto" ${dis}>${[['add', 'Add time to a date'], ['subtract', 'Subtract time from a date'], ['format', 'Format a date'], ['diff', 'Time until / since a date'], ['now', 'The time now']].map(([k, l]) => `<option value="${k}" ${op === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          ${op !== 'now' ? `<div class="field"><label>Date</label><input id="dtv" value="${esc(c.value || '')}" placeholder="{{ contact.fields.renews_at }}" ${dis}><p class="ins-help">2026-11-03, 2026-11-03 14:00, ISO 8601 or a Unix timestamp.</p></div>` : ''}
          ${op === 'add' || op === 'subtract' ? `<div class="field"><label>${op === 'add' ? 'Add' : 'Subtract'}</label><div class="jr-inline"><input type="number" min="1" max="3650" id="dta" value="${esc(c.amount || 1)}" ${dis}><select class="select" id="dtu" ${dis}>${['minutes', 'hours', 'days', 'weeks', 'months'].map((u) => `<option ${c.unit === u ? 'selected' : ''}>${u}</option>`).join('')}</select></div></div>` : ''}
          ${op === 'diff' ? `<div class="field"><label>Count in</label><select class="select" id="dtu" ${dis}>${['minutes', 'hours', 'days', 'weeks', 'months'].map((u) => `<option ${c.unit === u ? 'selected' : ''}>${u}</option>`).join('')}</select><p class="ins-help">Positive: still to come. Negative: already past.</p></div>`
            : `<div class="field"><label>Output format</label><select class="select" id="dtf" ${dis}>${[['date', '2026-11-03'], ['datetime', '2026-11-03 14:00'], ['long', '3 November 2026'], ['short', '3 Nov'], ['weekday', 'Tuesday'], ['time', '14:00'], ['iso', 'ISO 8601 (UTC)']].map(([k, l]) => `<option value="${k}" ${(c.format || 'date') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div>`}
          <p class="ins-help">In the contact’s <span class="mono">timezone</span> field, or the journey’s (${esc(j.settings.timezone)}). The answer is <span class="mono">{{ steps.${esc(n.id)}.result }}</span>.</p>`;
      } else if (n.type === 'http') {
        const kv = (key, label, ph, mono) => `<div class="ins-sec"><div class="ins-label">${label}</div>${(c[key] || []).map((fd, i) => `<div class="hf-row"><input class="kvn ${mono ? 'mono' : ''}" data-k="${key}" data-i="${i}" value="${esc(fd.name || '')}" placeholder="${ph[0]}" aria-label="Name" ${dis}><div class="hf-v"><input class="kvv" data-k="${key}" data-i="${i}" value="${esc(fd.value || '')}" placeholder="${ph[1]}" aria-label="Value" ${dis}></div>${ro ? '' : `<button class="icon-btn kvx" data-k="${key}" data-i="${i}" title="Remove">${ic('x', 13)}</button>`}</div>`).join('')}${ro ? '' : `<button class="link-btn kvadd" data-k="${key}">${ic('plus', 13)}Add</button>`}</div>`;
        body = `${nameField}<div class="row2" style="grid-template-columns:110px 1fr"><div class="field"><label>Method</label><select class="select" id="htm" ${dis}>${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option ${(c.method || 'GET') === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div><div class="field"><label>URL</label><input id="htu" value="${esc(c.url || '')}" placeholder="https://api.example.com/customers/{{ contact.email }}" ${dis}></div></div>
          ${kv('query', 'Query parameters', ['email', '{{ contact.email }}'])}${kv('headers', 'Headers', ['Authorization', 'Bearer …'], true)}${['POST', 'PUT', 'PATCH'].includes(c.method) ? kv('body', 'JSON body', ['amount', '{{ trigger.properties.amount }}']) : ''}
          <p class="ins-help">Public HTTPS only (checked again when the URL is filled in), 10-second timeout, no redirects. The answer is the output: <span class="mono">{{ steps.${esc(n.id)}.body.… }}</span>. Test step calls it for real. Header values are kept in the journey, so anyone who can edit it can see them.</p>`;
      } else if (n.type === 'stop') body = `${nameField}<div class="field"><label>Reason</label><input id="stm" value="${esc(c.message || '')}" maxlength="255" placeholder="Out of stock: handled by hand" ${dis}></div><p class="ins-help">The run ends here as failed, with this reason in the contact’s history. Use it for cases that need a person.</p>`;
      else if (n.type === 'approval') body = `${nameField}<div class="field"><label>Teammates who can answer <span class="faint">(comma-separated, up to 5)</span></label><input id="apt" value="${esc((c.to || []).join(', '))}" ${dis}></div>
        <div class="field"><label>What they’re approving</label><textarea id="apm" rows="3" ${dis}>${esc(c.message || '')}</textarea></div>
        <div class="field"><label>Wait for an answer up to</label>${unitSel(c, 'apx')}</div>
        <p class="ins-help">They get an email with a link: the page shows the request and Approve / Reject buttons (opening the link alone does nothing, so email scanners can’t answer it). One answer counts. Approved goes down <b>Approved</b>; rejected or no answer in time goes down <b>Not approved</b>.</p>${branchStats(n)}`;
      else if (n.type === 'ai_sentiment') body = `${nameField}<div class="field"><label>Text to read</label><input id="aii" value="${esc(c.input || '')}" placeholder="{{ trigger.properties.message }}" ${dis}></div><p class="ins-help">Goes down Positive, Neutral or Negative. Also outputs a <span class="mono">score</span> from −1 to 1 and a short <span class="mono">reason</span>.</p>${branchStats(n)}`;
      else if (n.type === 'ai_summarize') body = `${nameField}<div class="field"><label>Text to summarise</label><input id="aii" value="${esc(c.input || '')}" placeholder="{{ trigger.properties.message }}" ${dis}></div><div class="field"><label>Length</label><select class="select" id="ail" ${dis}>${[['sentence', 'One sentence'], ['paragraph', 'A short paragraph'], ['bullets', 'Up to 5 bullet points']].map(([k, l]) => `<option value="${k}" ${(c.length || 'sentence') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></div><p class="ins-help">The result is <span class="mono">{{ steps.${esc(n.id)}.summary }}</span>.</p>`;
      else body = `${nameField}<p class="ins-help" style="margin-top:0">Contacts leave the journey here.</p>`;
      if (AI_TYPES.includes(n.type) && j.ai && !j.ai.available) body = `<div class="callout warn" style="margin:0 0 14px">${ic('alert', 15)}<div>${esc(j.ai.reason || 'AI isn’t available.')}</div></div>` + body;
      if (FAILABLE.includes(n.type)) body += `<div class="ins-sec"><div class="ins-label">On error</div><select class="select" id="oerr" ${dis}>${[['continue', 'Continue to the next step'], ['stop', 'Stop the run'], ['branch', 'Take the Error path']].map(([k, l]) => `<option value="${k}" ${(c.on_error || 'continue') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <p class="ins-help">${AI_TYPES.includes(n.type) ? 'If the AI is unavailable, over the daily limit or answers badly. ' : n.type === 'webhook' ? 'If your endpoint is down or answers with an error. ' : ''}${(c.on_error || 'continue') === 'branch' ? 'Drag a second connection from this step: it becomes the Error path.' : 'The failure is always in the run’s history.'}</p></div>`;
      const pr = probFor(n.id);
      // Redrawing the panel (a tick box, a new row…) must not throw the reader back to the top.
      const was = insp.dataset.node === n.id + '|' + inspTab ? ($('.in-body', insp) || {}).scrollTop || 0 : 0;
      insp.dataset.node = n.id + '|' + inspTab;
      insp.innerHTML = `<div class="in-head"><span class="nd-ic" style="background:${bg};color:${col}">${nodeGlyph(n, 17)}</span><div style="min-width:0"><small>${n.type === 'trigger' ? 'Trigger' : kind}</small><b>${esc(nameOf(n))}</b></div><button class="icon-btn" id="inx" aria-label="Close">${ic('x', 15)}</button></div>
        <div class="in-tabs"><button data-it="settings" class="${inspTab === 'settings' ? 'on' : ''}">Settings</button>${isSub(n) ? '' : `<button data-it="data" class="${inspTab === 'data' ? 'on' : ''}">People</button>`}${reached || here ? `<span class="in-count"><b>${fmt(reached)}</b> passed${here ? ` · <b>${fmt(here)}</b> here now` : ''}</span>` : ''}</div>
        <div class="in-body">${pr.length && inspTab === 'settings' ? `<div class="callout warn" style="margin:0 0 14px">${ic('alert', 15)}<div>${pr.map((p) => esc(p.message)).join('<br>')}</div></div>` : ''}${inspTab === 'settings' ? body : '<div id="ppl" class="faint" style="font-size:13px">Loading…</div>'}</div>
        ${ro ? '' : `<div class="in-foot"><button class="btn btn-sm" id="dup">${ic('copy', 13)}Duplicate</button><button class="btn btn-sm" id="del">${ic('trash', 13)}Delete</button></div>`}`;
      bindInspector(n, insp);
      if (was) { const ib = $('.in-body', insp); if (ib) ib.scrollTop = was; }
      if (!insp.dataset.fx) { // once: the panel element stays; focusin has no on… property
        insp.dataset.fx = '1';
        insp.addEventListener('focusin', (e) => { if (e.target.matches('input:not([type]), input[type=text], input[type=search], input[type=url], textarea')) lastField = e.target; });
      }
      insp.oninput = () => previews(n);
      renderIO(n); previews(n);
      drawMini();
    }
    // Email step content saves straight to the email draft (the graph only holds the step).
    let eT = null;
    const saveEmail = (n, body, now) => {
      clearTimeout(eT);
      const run = () => flush().then(() => API('PUT', `${base()}/journeys/${j.id}/emails/${n.id}`, body)).then((r) => {
        ctx.emails[n.id] = Object.assign({}, r.email, { doc: undefined, html: undefined });
        const el = $(`[data-n="${n.id}"] .nd-txt span`, world); if (el) el.textContent = describe(n, ctx);
        refreshProblems(); return r.email;
      }).catch((err) => { toast(err.message); });
      if (now) return run();
      eT = setTimeout(run, 600); return Promise.resolve();
    };
    const refreshProblems = () => API('GET', `${base()}/journeys/${j.id}`).then((r) => { problems = r.journey.problems; j.draft_rev = r.journey.draft_rev; j.unpublished = r.journey.unpublished; markProblems(); renderHead(); }).catch(() => {});
    function bindInspector(n, insp) {
      bindCopy(insp);
      const c = n.config;
      const changed = (rerender) => { const el = $(`[data-n="${n.id}"] .nd-txt span`, world); if (el) el.textContent = describe(n, ctx); const td = $('#tdesc', insp); if (td) td.textContent = describe(n, ctx); save(); if (rerender) renderInspector(); };
      $$('[data-it]', insp).forEach((b) => b.onclick = () => { inspTab = b.dataset.it; renderInspector(); });
      $('#inx').onclick = () => { sel = null; renderNodes(); renderInspector(); };
      if (inspTab === 'data') { loadPeople(n); return; }
      if (n.type === 'email') loadMail(n);
      const tfilt = $('#tfilt', insp);
      if (tfilt) { c.filter = c.filter && c.filter.conditions ? c.filter : { match: 'all', conditions: [] }; const rb = ruleBuilder(c.filter, emailNodes()); tfilt.innerHTML = rb.html; rb.bind(tfilt, (re) => { changed(); if (re) renderInspector(); }); }
      const cr = $('#crules', insp);
      if (cr) { c.rules = c.rules && c.rules.conditions ? c.rules : { match: 'all', conditions: [] }; const rb = ruleBuilder(c.rules, emailNodes()); cr.innerHTML = rb.html; rb.bind(cr, (re) => { changed(); if (re) renderInspector(); }); }
      if (ro) return;
      const bind = (s, fn, ev = 'oninput') => { const el = $(s, insp); if (el) el[ev] = () => fn(el); };
      bind('#ntitle', (el) => { n.title = el.value; const b = $(`[data-n="${n.id}"] .nd-txt b`, world); if (b) b.textContent = n.title || NAME[n.type]; $('.in-head b', insp).textContent = n.title || NAME[n.type]; save(); });
      const chg = $('#chg'); if (chg) chg.onclick = () => { const id = n.id; openPalette('triggers'); replaceTrig = id; };
      // trigger
      bind('#tl', (el) => { c.list_id = el.value; changed(); }, 'onchange');
      bind('#tt', (el) => { c.tag = el.value.trim().toLowerCase(); changed(); });
      bind('#tf', (el) => { c.field = el.value; changed(); }, 'onchange');
      bind('#tto', (el) => { c.to = el.value; changed(); });
      bind('#te', (el) => { c.event = el.value.trim(); changed(); });
      bind('#tsg', (el) => { c.segment_id = el.value; changed(); }, 'onchange');
      bind('#tfm', (el) => { c.form_id = el.value || null; changed(); }, 'onchange');
      bind('#ttp', (el) => { c.topic_id = el.value || null; changed(); }, 'onchange');
      bind('#tmp', (el) => { c.min_percent = Math.max(0, Math.min(90, +el.value || 0)); changed(); });
      bind('#tia', (el) => { c.activity = el.value.trim(); changed(); });
      bind('#tid', (el) => { c.days = +el.value || 0; changed(); });
      bind('#tse', (el) => { c.every = el.value; changed(true); }, 'onchange');
      bind('#tsw', (el) => { c.weekday = +el.value; changed(); }, 'onchange');
      bind('#tsm', (el) => { c.monthday = +el.value; changed(); }, 'onchange');
      bind('#tu', (el) => { c.url_contains = el.value.trim(); changed(); });
      bind('#tdf', (el) => { c.field = el.value; c.yearly = ['birthday', 'created_at'].includes(el.value); changed(true); }, 'onchange');
      bind('#tdo', (el) => { c.offset_days = +el.value || 0; changed(); });
      bind('#tdh', (el) => { c.hour = +el.value; changed(); }, 'onchange');
      bind('#tdy', (el) => { c.yearly = el.value === '1'; changed(); }, 'onchange');
      $$('[data-rh]', insp).forEach((b) => b.onclick = () => API('PATCH', `${base()}/journeys/${j.id}`, { settings: { reentry: b.dataset.rh } }).then((r) => { Object.assign(j, { settings: r.journey.settings, draft_rev: r.journey.draft_rev, unpublished: r.journey.unpublished }); renderHead(); renderInspector(); }).catch((err) => toast(err.message)));
      // email
      if (n.type === 'email') {
        bind('#es', (el) => saveEmail(n, { subject: el.value }));
        bind('#ep', (el) => saveEmail(n, { preheader: el.value }));
        const from = () => saveEmail(n, { from_name: $('#efn').value.trim() || null, from_email: S.fromPickValue(insp, 'efp') });
        bind('#efn', from); bind('#efp-l', from); S.fromPickBind(insp, 'efp', from);
        const fs = $('#efset'); if (fs) fs.onclick = () => { fs.remove(); from(); };
        bind('#ert', (el) => { const val = el.value.trim(); if (!val || /^\S+@\S+\.\S+$/.test(val)) saveEmail(n, { reply_to: val || null }); else toast('Enter a valid reply-to address.'); }, 'onchange');
        bind('#ety', (el) => { c.type = el.value; changed(); }, 'onchange');
        bind('#esk', (el) => { c.skip_recent_hours = el.checked ? 16 : 0; changed(); }, 'onchange');
        const mfe = $('#mfe'); if (mfe) mfe.onclick = () => openDesign(n);
        $$('[data-es]', insp).forEach((b) => b.onclick = () => {
          if (b.dataset.es === 'gallery') { S.pickForJourney = { id: j.id, node: n.id }; flush().then(() => { location.hash = '#/templates'; toast('Pick a design, then choose “Use template”'); }); return; }
          b.disabled = true;
          Promise.resolve(S.applyBrand ? API('GET', base() + '/brand').then((r) => S.applyBrand(S.blankEmailDoc(), r.brand)) : S.blankEmailDoc()).then((doc) => saveEmail(n, { doc }, true)).then(() => openDesign(n));
        });
        bind('#etpl', (el) => { if (el.value) saveEmail(n, { template_id: el.value }, true).then(() => renderInspector()); }, 'onchange');
        const et = $('#etest'); if (et) et.onclick = () => testEmail(n);
      }
      // timing, logic, contact, developers
      bind('#wa', (el) => { c.amount = +el.value; changed(); }); bind('#wu', (el) => { c.unit = el.value; changed(); }, 'onchange');
      bind('#wfxa', (el) => { c.amount = +el.value; changed(); }); bind('#wfxu', (el) => { c.unit = el.value; changed(); }, 'onchange');
      bind('#wfk', (el) => { c.event = el.value === 'custom' ? '' : el.value; c.node = el.value === 'custom' ? null : ((emailNodes()[0] || {}).id || null); changed(true); }, 'onchange');
      bind('#wfn', (el) => { c.node = el.value || null; changed(); }, 'onchange');
      bind('#wfe', (el) => { c.event = el.value.trim(); changed(); });
      $$('#dp span', insp).forEach((s) => s.onclick = () => { const d = +s.dataset.d; c.days = (c.days || []).includes(d) ? c.days.filter((x) => x !== d) : (c.days || []).concat(d).sort(); s.classList.toggle('on'); changed(); });
      bind('#wf', (el) => { c.from = el.value; changed(); }, 'onchange'); bind('#wt', (el) => { c.to = el.value; changed(); }, 'onchange');
      bind('#sp', (el) => { c.percent_a = +el.value; $('#spv').textContent = el.value + '%'; changed(); });
      bind('#ge', (el) => { c.event = el.value.trim(); changed(); });
      bind('#ka', (el) => { c.action = el.value; changed(); }, 'onchange'); bind('#kt', (el) => { c.tag = el.value.trim().toLowerCase(); changed(); });
      bind('#fk', (el) => { c.key = el.value.trim(); changed(); }); bind('#fv', (el) => { c.value = el.value; changed(); });
      bind('#la', (el) => { c.action = el.value; changed(); }, 'onchange'); bind('#ll', (el) => { c.list_id = el.value; changed(); }, 'onchange');
      bind('#nc', (el) => { c.channel = el.value; changed(true); }, 'onchange');
      bind('#nto', (el) => { c.to = el.value.split(',').map((x) => x.trim()).filter(Boolean); changed(); });
      bind('#nsu', (el) => { c.slack_url = el.value.trim(); changed(); }); bind('#nm', (el) => { c.message = el.value; changed(); });
      bind('#hu', (el) => { c.url = el.value.trim(); changed(); });
      if (n.type === 'webhook') {
        if (!Array.isArray(c.fields)) c.fields = [];
        $$('.hfn', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].name = el.value.trim(); changed(); });
        $$('.hfv', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].value = el.value; changed(); });
        $$('.hfx', insp).forEach((el) => el.onclick = () => { c.fields.splice(+el.dataset.i, 1); changed(true); });
        const ha = $('#hfadd', insp); if (ha) ha.onclick = () => { c.fields.push({ name: '', value: '' }); changed(true); };
      }
      const sb = $('#jsecbtn', insp);
      if (sb) sb.onclick = () => { sb.disabled = true; API('GET', base() + '/journeys/' + j.id + '/secret').then((r) => { $('#jsec', insp).textContent = r.secret; sb.textContent = 'Copy'; sb.disabled = false; sb.onclick = () => navigator.clipboard.writeText(r.secret).then(() => toast('Secret copied')); }).catch((err) => { sb.disabled = false; toast(err.message); }); };
      bind('#jj', (el) => { c.journey_id = el.value; changed(); }, 'onchange');
      // AI steps
      const relabel = () => renderEdges();
      bind('#aip', (el) => { c.prompt = el.value; changed(); });
      bind('#aci', (el) => { c.input = el.value; changed(); });
      bind('#acs', (el) => { c.instructions = el.value; changed(); });
      if (n.type === 'ai_classify') {
        if (!Array.isArray(c.categories)) c.categories = [];
        $$('.acn', insp).forEach((el) => el.oninput = () => { c.categories[el.dataset.i].name = el.value; changed(); relabel(); });
        $$('.acd', insp).forEach((el) => el.oninput = () => { c.categories[el.dataset.i].description = el.value; changed(); });
        $$('.acx', insp).forEach((el) => el.onclick = () => { const k = c.categories.splice(+el.dataset.i, 1)[0]; f.edges = f.edges.filter((e) => !(e.from === n.id && e.branch === k.id)); renderEdges(); changed(true); });
        const add = $('#acadd', insp); if (add) add.onclick = () => { c.categories.push({ id: cid(), name: '', description: '' }); changed(true); };
      }
      bind('#axi', (el) => { c.input = el.value; changed(); });
      if (n.type === 'ai_extract') {
        if (!Array.isArray(c.fields)) c.fields = [];
        $$('.axn', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].name = el.value.trim(); changed(); });
        $$('.axt', insp).forEach((el) => el.onchange = () => { c.fields[el.dataset.i].type = el.value; changed(); });
        $$('.axd', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].description = el.value; changed(); });
        $$('.axx', insp).forEach((el) => el.onclick = () => { c.fields.splice(+el.dataset.i, 1); changed(true); });
        const add = $('#axadd', insp); if (add) add.onclick = () => { c.fields.push({ name: '', type: 'text', description: '' }); changed(true); };
      }
      bind('#agi', (el) => { c.instructions = el.value; changed(); });
      bind('#agm', (el) => { c.max_turns = +el.value; changed(); }, 'onchange');
      // sub-nodes (n8n's cluster nodes) and the plugged-in lists
      $$('[data-open-sub]', insp).forEach((b2) => b2.onclick = () => { sel = b2.dataset.openSub; inspTab = 'settings'; renderNodes(); renderInspector(); });
      $$('[data-addport2]', insp).forEach((b2) => b2.onclick = () => { subFor = { id: n.id, kind: b2.dataset.addport2 }; openPalette('sub'); });
      const subCh = (re) => { changed(re); const el = $(`[data-n="${n.id}"] small`, world); if (el && isSub(n)) el.textContent = describe(n, ctx); };
      bind('#smm', (el) => { c.model = el.value; subCh(); renderNodes(); }, 'onchange');
      bind('#smt', (el) => { c.temperature = +el.value; $('#smtv', insp).textContent = el.value; subCh(); });
      bind('#smx', (el) => { c.max_tokens = Math.max(0, Math.min(4000, +el.value || 0)); subCh(); });
      bind('#smk', (el) => { c.key = el.value.trim().toLowerCase(); subCh(); });
      bind('#smw', (el) => { c.window = +el.value; subCh(); }, 'onchange');
      bind('#spk', (el) => { c.kind = el.value; if (c.kind === 'list') { c.max_items = c.max_items || 5; c.item = c.item || ''; } else { c.fields = c.fields && c.fields.length ? c.fields : [{ name: '', type: 'text', description: '' }]; c.autofix = c.autofix !== false; } subCh(true); }, 'onchange');
      bind('#spm', (el) => { c.max_items = Math.max(1, Math.min(50, +el.value || 5)); subCh(); });
      bind('#spi', (el) => { c.item = el.value; subCh(); });
      bind('#spa', (el) => { c.autofix = el.checked; subCh(); }, 'onchange');
      if (n.type === 'ai_parser' && c.kind !== 'list') {
        if (!Array.isArray(c.fields)) c.fields = [];
        $$('.spf-n', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].name = el.value.trim(); subCh(); });
        $$('.spf-t', insp).forEach((el) => el.onchange = () => { c.fields[el.dataset.i].type = el.value; subCh(); });
        $$('.spf-d', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].description = el.value; subCh(); });
        $$('.spf-x', insp).forEach((el) => el.onclick = () => { c.fields.splice(+el.dataset.i, 1); subCh(true); });
        const add = $('#spf-add', insp); if (add) add.onclick = () => { c.fields.push({ name: '', type: 'text', description: '' }); subCh(true); };
      }
      bind('#stk', (el) => { const keep = { tool: el.value }; if (el.value === 'http') Object.assign(keep, { name: '', description: '', method: 'GET', url: '', headers: [], params: [{ name: '', description: '', type: 'string', in: 'query', required: true }] }); if (el.value === 'notify') keep.to = [me().user.email].filter(Boolean); n.config = keep; subCh(true); renderNodes(); }, 'onchange');
      bind('#stf', (el) => { c.fields = el.value.split(',').map((x) => x.trim()).filter(Boolean); subCh(); });
      $$('[data-stl]', insp).forEach((el) => el.onchange = () => { const id = el.dataset.stl; c.lists = (c.lists || []).filter((x) => x !== id).concat(el.checked ? [id] : []); subCh(); });
      bind('#stn', (el) => { c.to = el.value.split(',').map((x) => x.trim()).filter(Boolean); subCh(); });
      bind('#std', (el) => { c.description = el.value; subCh(); });
      bind('#shn', (el) => { c.name = el.value.trim(); subCh(); });
      bind('#shm', (el) => { c.method = el.value; subCh(); }, 'onchange');
      bind('#shd', (el) => { c.description = el.value; subCh(); });
      bind('#shu', (el) => { c.url = el.value.trim(); subCh(); });
      if (n.type === 'ai_tool' && c.tool === 'http') {
        ['params', 'headers'].forEach((k2) => { if (!Array.isArray(c[k2])) c[k2] = []; });
        const row = (cls, fn, ev = 'oninput') => $$(cls, insp).forEach((el) => el[ev] = () => { fn(c.params[el.dataset.i], el); subCh(); });
        row('.shp-n', (pr, el) => { pr.name = el.value.trim(); }); row('.shp-d', (pr, el) => { pr.description = el.value; });
        row('.shp-t', (pr, el) => { pr.type = el.value; }, 'onchange'); row('.shp-in', (pr, el) => { pr.in = el.value; }, 'onchange'); row('.shp-r', (pr, el) => { pr.required = el.checked; }, 'onchange');
        $$('.shp-x', insp).forEach((el) => el.onclick = () => { c.params.splice(+el.dataset.i, 1); subCh(true); });
        const pa = $('#shp-add', insp); if (pa) pa.onclick = () => { c.params.push({ name: '', description: '', type: 'string', in: 'query', required: true }); subCh(true); };
        $$('.shh-n', insp).forEach((el) => el.oninput = () => { c.headers[el.dataset.i].name = el.value.trim(); subCh(); });
        $$('.shh-v', insp).forEach((el) => el.oninput = () => { c.headers[el.dataset.i].value = el.value; subCh(); });
        $$('.shh-x', insp).forEach((el) => el.onclick = () => { c.headers.splice(+el.dataset.i, 1); subCh(true); });
        const ha = $('#shh-add', insp); if (ha) ha.onclick = () => { c.headers.push({ name: '', value: '' }); subCh(true); };
      }
      // core nodes
      bind('#swv', (el) => { c.value = el.value; changed(); });
      if (n.type === 'switch') {
        if (!Array.isArray(c.cases)) c.cases = [];
        $$('.swo', insp).forEach((el) => el.onchange = () => { c.cases[el.dataset.i].op = el.value; changed(true); renderEdges(); });
        $$('.swc', insp).forEach((el) => el.oninput = () => { c.cases[el.dataset.i].value = el.value; changed(); renderEdges(); });
        $$('.swn', insp).forEach((el) => el.oninput = () => { c.cases[el.dataset.i].name = el.value; changed(); renderEdges(); });
        $$('.swx', insp).forEach((el) => el.onclick = () => { const k = c.cases.splice(+el.dataset.i, 1)[0]; f.edges = f.edges.filter((e) => !(e.from === n.id && e.branch === k.id)); renderEdges(); changed(true); });
        const add = $('#swadd', insp); if (add) add.onclick = () => { c.cases.push({ id: cid(), op: 'is', value: '', name: '' }); changed(true); renderEdges(); };
      }
      if (n.type === 'set') {
        if (!Array.isArray(c.fields)) c.fields = [];
        $$('.stn', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].name = el.value.trim(); changed(); });
        $$('.stt', insp).forEach((el) => el.onchange = () => { c.fields[el.dataset.i].type = el.value; changed(); });
        $$('.stv', insp).forEach((el) => el.oninput = () => { c.fields[el.dataset.i].value = el.value; changed(); });
        $$('.stx', insp).forEach((el) => el.onclick = () => { c.fields.splice(+el.dataset.i, 1); changed(true); });
        const add = $('#stadd', insp); if (add) add.onclick = () => { c.fields.push({ name: '', type: 'text', value: '' }); changed(true); };
      }
      bind('#dto', (el) => { c.op = el.value; changed(true); }, 'onchange');
      bind('#dtv', (el) => { c.value = el.value; changed(); });
      bind('#dta', (el) => { c.amount = +el.value; changed(); });
      bind('#dtu', (el) => { c.unit = el.value; changed(); }, 'onchange');
      bind('#dtf', (el) => { c.format = el.value; changed(); }, 'onchange');
      bind('#htm', (el) => { c.method = el.value; changed(true); }, 'onchange');
      bind('#htu', (el) => { c.url = el.value.trim(); changed(); });
      if (n.type === 'http') {
        ['query', 'headers', 'body'].forEach((k) => { if (!Array.isArray(c[k])) c[k] = []; });
        $$('.kvn', insp).forEach((el) => el.oninput = () => { c[el.dataset.k][el.dataset.i].name = el.value.trim(); changed(); });
        $$('.kvv', insp).forEach((el) => el.oninput = () => { c[el.dataset.k][el.dataset.i].value = el.value; changed(); });
        $$('.kvx', insp).forEach((el) => el.onclick = () => { c[el.dataset.k].splice(+el.dataset.i, 1); changed(true); });
        $$('.kvadd', insp).forEach((el) => el.onclick = () => { c[el.dataset.k].push({ name: '', value: '' }); changed(true); });
      }
      bind('#stm', (el) => { c.message = el.value; changed(); });
      bind('#apt', (el) => { c.to = el.value.split(',').map((x) => x.trim()).filter(Boolean); changed(); });
      bind('#apm', (el) => { c.message = el.value; changed(); });
      bind('#apxa', (el) => { c.amount = +el.value; changed(); }); bind('#apxu', (el) => { c.unit = el.value; changed(); }, 'onchange');
      bind('#aii', (el) => { c.input = el.value; changed(); });
      bind('#ail', (el) => { c.length = el.value; changed(); }, 'onchange');
      bind('#oerr', (el) => { c.on_error = el.value; if (el.value !== 'branch') f.edges = f.edges.filter((e) => !(e.from === n.id && e.branch === 'error')); renderNodes(); changed(true); }, 'onchange');
      $('#del').onclick = () => {
        if (f.nodes.length < 2) return toast('A journey needs at least a trigger');
        const main = (e) => !isSub(byId()[e.from]); // sub-node lines aren't part of the flow
        const ins = f.edges.filter((e) => e.to === n.id && main(e)), outs = f.edges.filter((e) => e.from === n.id && !isSub(n));
        // An AI step takes the sub-nodes that are plugged only into it along (as in n8n, they're part of it).
        const own = f.edges.filter((e) => e.to === n.id && !main(e)).map((e) => e.from).filter((id) => !f.edges.some((e) => e.from === id && e.to !== n.id));
        f.edges = f.edges.filter((e) => e.from !== n.id && e.to !== n.id && !own.includes(e.from));
        if (ins.length === 1 && outs.length === 1 && !f.edges.some((e) => e.from === ins[0].from && (e.branch || '') === (ins[0].branch || ''))) f.edges.push({ from: ins[0].from, to: outs[0].to, branch: ins[0].branch });
        f.nodes = f.nodes.filter((m) => m.id !== n.id && !own.includes(m.id)); delete ctx.emails[n.id]; sel = null; renderNodes(); renderInspector(); save(); toast(isSub(n) ? 'Unplugged and deleted' : own.length ? `Step and its ${own.length} sub-node${own.length > 1 ? 's' : ''} deleted` : 'Step deleted');
      };
      $('#dup').onclick = () => { if (n.type === 'trigger' && !n.config.trigger) return; const cp = JSON.parse(JSON.stringify(n)); cp.id = uid(); cp.y = n.y + 150; f.nodes.push(cp); sel = cp.id; renderNodes(); renderInspector(); save(); if (n.type === 'email') toast('Duplicated. Design the copy’s email too.'); };
    }
    const loadMail = (n) => {
      const mf = $('#mframe'); if (!mf) return;
      const e = ctx.emails[n.id];
      if (!e || !e.has_design) { mf.srcdoc = '<html><body style="font-family:-apple-system,sans-serif;display:grid;place-items:center;height:90vh;color:#a1a1aa;margin:0;background:#f4f4f5;font-size:13px">No design yet</body></html>'; return; }
      API('GET', `${base()}/journeys/${j.id}/emails/${n.id}`).then((r) => { const fr = $('#mframe'); if (fr) fr.srcdoc = String(r.email.html || '').replace(/\{\{\s*first_name[^}]*\}\}/g, 'Amara').replace(/\{\{[^}]+\}\}/g, '#'); }).catch(() => {});
    };
    const openDesign = (n) => flush().then(() => { location.hash = `#/campaigns/design?journey=${j.id}&node=${n.id}`; });
    const testEmail = (n) => modal({ title: 'Send a test', desc: 'Exactly this step’s email, through your Amazon SES.', icon: 'send', body: `<div class="field"><label>Send to</label><input id="tto2" type="email" value="${esc(me().user.email || '')}"></div><div id="ttr2"></div>`,
      actions: [{ label: 'Close' }, { label: 'Send test', kind: 'primary', onClick: (m) => {
        const b = m.root.querySelector('.m-foot .btn-primary'); b.disabled = true;
        API('POST', `${base()}/journeys/${j.id}/emails/${n.id}/test`, { to: $('#tto2', m.root).value.trim(), from: domains[0] })
          .then((x) => { b.disabled = false; $('#ttr2', m.root).innerHTML = `<div class="tf-res"><span>Sent: <span class="trk">${esc(x.tracking_code)}</span></span><a class="btn btn-sm" href="#/activity">Follow it in Activity</a></div>`; })
          .catch((err) => { b.disabled = false; $('#ttr2', m.root).innerHTML = `<div class="callout warn">${ic('alert', 15)}<div>${esc(err.message)}</div></div>`; });
        return false;
      } }] });
    const RUN_LABEL = { waiting: 'Waiting', running: 'Moving', completed: 'Finished', goal: 'Reached goal', exited: 'Removed', failed: 'Stopped' };
    const loadPeople = (n) => API('GET', `${base()}/journeys/${j.id}/runs?node=${encodeURIComponent(n.id)}`).then((r) => {
      const box = $('#ppl'); if (!box) return;
      box.innerHTML = r.runs.length ? r.runs.map((x) => `<button class="recent jr-run" data-run="${x.id}"><div><b>${esc(x.contact ? x.contact.name || x.contact.email : 'Deleted contact')}</b><small>${esc(x.contact ? x.contact.email : '')}</small></div><span>${x.status === 'waiting' && x.node === n.id ? (x.waiting_for ? 'Waiting for ' + esc(x.waiting_for) : 'Until ' + day(x.wake_at)) : RUN_LABEL[x.status] || x.status}</span></button>`).join('') : '<p class="ins-help" style="margin-top:0">Nobody has reached this step yet.</p>';
      $$('[data-run]', box).forEach((b) => b.onclick = () => openRun(b.dataset.run));
    }).catch(() => {});
    const openRun = (runId) => API('GET', `${base()}/journeys/${j.id}/runs/${runId}`).then((r) => {
      const B = byId(), x = r.run;
      // Debug in editor (n8n): the run's real contact and event become the test data, each step's output fills the panel.
      const debug = () => pin({ source: 'run', at: x.entered_at, note: `From ${x.contact ? x.contact.email : 'a contact'}’s run.`, data: { contact: x.contact, trigger: x.trigger, steps: {} } }).then(() => {
        x.steps.forEach((st) => { if (st.output && B[st.node] && B[st.node].type !== 'trigger') testOut[st.node] = { output: st.output, failed: st.outcome === 'failed', branch: st.outcome && outs(B[st.node]).includes(st.outcome) ? st.outcome : st.outcome === 'failed' && outs(B[st.node]).includes('error') ? 'error' : null, note: st.detail || OUTCOME[st.outcome] || '', ms: st.ms }; });
        path = x.steps.map((st) => st.node).filter((id) => B[id]); renderNodes(); refreshIO(); toast('The run’s data is in the editor. Click a step to see what went in and out.');
      });
      modal({ title: 'One contact’s journey', desc: x.status === 'waiting' ? `Waiting at “${(B[x.node] || {}).title || 'a step'}”${x.wake_at ? ' until ' + day(x.wake_at) : ''}.` : (x.exit_reason || RUN_LABEL[x.status] || x.status), icon: 'automations',
        body: `<ol class="jr-steps">${x.steps.map((st) => `<li><b>${esc((B[st.node] || {}).title || (B[st.node] ? NAME[B[st.node].type] : 'A deleted step'))}</b><span>${esc(OUTCOME[st.outcome] || (B[st.node] && st.outcome ? 'Went down ' + brName(B[st.node], st.outcome) : st.outcome || ''))}${st.detail ? ' · ' + esc(st.detail) : ''}${st.ms != null ? ' · ' + st.ms + ' ms' : ''}</span><time>${day(st.at)}</time>${st.output ? `<details class="jr-out"><summary>Output</summary>${dataView(st.output, '', 'json')}</details>` : ''}</li>`).join('')}</ol>`,
        actions: [...(x.status === 'waiting' && canEdit() ? [{ label: 'Remove from journey', onClick: () => API('POST', `${base()}/journeys/${j.id}/runs/${runId}/exit`).then(() => { toast('Removed from the journey'); reloadStats(); }).catch((err) => toast(err.message)) }] : []),
          ...(canEdit() && !ro && x.contact ? [{ label: 'Debug in editor', onClick: () => { debug().catch((err) => toast(err.message)); } }] : []), { label: 'Close', kind: 'primary' }] });
    }).catch((err) => toast(err.message));
    const reloadStats = () => API('GET', `${base()}/journeys/${j.id}/stats`).then((r) => { stats = r.stats; renderNodes(); if (sel && !document.activeElement.closest('#insp')) renderInspector(); }).catch(() => {});

    // tidy: columns by graph depth
    const tidy = () => {
      const depth = {};
      f.nodes.filter((n) => n.type === 'trigger').forEach((n) => (depth[n.id] = 0));
      for (let i = 0; i < f.nodes.length; i++) f.edges.forEach((e) => { if (depth[e.from] != null) depth[e.to] = Math.max(depth[e.to] || 0, depth[e.from] + 1); });
      const cols = {};
      f.nodes.filter((n) => !isSub(n)).forEach((n) => { const d = depth[n.id] || 0; (cols[d] = cols[d] || []).push(n); });
      const gap = (n) => (portsOf(n).length && f.edges.some((e) => e.to === n.id && isSub(byId()[e.from])) ? 340 : 170); // room for the sub-nodes underneath
      Object.entries(cols).forEach(([d, ns]) => { const h = ns.reduce((a, n) => a + gap(n), 0); let y = 200 - h / 2; ns.forEach((n) => { n.x = +d * 320; n.y = y; y += gap(n); }); });
      f.nodes.filter(isSub).forEach((m) => { const host = byId()[(f.edges.find((e) => e.from === m.id) || {}).to]; if (!host) return; const sib = f.edges.filter((e) => e.to === host.id && isSub(byId()[e.from])).map((e) => e.from), i = sib.indexOf(m.id);
        m.x = Math.round(host.x + i * 116 - (sib.length - 1) * 58 + NODE_W / 2 - SUB_W / 2); m.y = host.y + 210; });
      renderNodes(); fit(); save(); toast('Tidied up');
    };

    // settings / more
    const settings = () => modal({ title: 'Journey settings', icon: 'settings', size: 'sm',
      body: `<div class="field"><label>Re-entry</label><select class="select" id="sre" ${dis}><option value="once" ${j.settings.reentry === 'once' ? 'selected' : ''}>Only once per contact</option><option value="cooldown" ${j.settings.reentry === 'cooldown' ? 'selected' : ''}>Again after a cool-down</option><option value="always" ${j.settings.reentry === 'always' ? 'selected' : ''}>Every time the trigger happens</option></select></div>
        <div class="field"><label>Cool-down (days after leaving)</label><input type="number" id="scd" min="1" max="365" value="${j.settings.cooldown_days}" ${dis}></div>
        <div class="field"><label>Time zone for contacts without one</label><select class="select" id="stz" ${dis}>${(Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : ['UTC', 'Africa/Lagos', 'Europe/London', 'America/New_York']).map((z) => `<option ${z === j.settings.timezone ? 'selected' : ''}>${esc(z)}</option>`).join('')}</select></div>`,
      actions: ro ? [{ label: 'Close', kind: 'primary' }] : [{ label: 'Cancel' }, { label: 'Save', kind: 'primary', onClick: (m) => API('PATCH', `${base()}/journeys/${j.id}`, { settings: { reentry: $('#sre', m.root).value, cooldown_days: +$('#scd', m.root).value || 30, timezone: $('#stz', m.root).value } }).then((r) => { j = r.journey; renderHead(); if (sel) renderInspector(); toast('Settings saved'); }).catch((err) => toast(err.message)) }] });
    $('#jmore').onclick = (e) => menu(e.currentTarget, [
      { label: 'Settings', icon: 'settings', onClick: settings },
      ...(canEdit() ? [{ label: 'Duplicate', icon: 'copy', onClick: () => flush().then(() => API('POST', `${base()}/journeys/${j.id}/duplicate`)).then((r) => { toast('Duplicated as a draft'); location.hash = '#/automations/edit?id=' + r.journey.id; }).catch((err) => toast(err.message)) },
        'sep', { label: j.status === 'draft' ? 'Delete' : 'Archive', icon: 'trash', danger: true, onClick: () => confirmDialog({ title: j.status === 'draft' ? `Delete “${j.name}”?` : `Archive “${j.name}”?`, desc: j.status === 'draft' ? 'This draft will be removed.' : 'It stops for good: nobody new enters, and everyone inside leaves now. Its history and stats stay.', label: j.status === 'draft' ? 'Delete' : 'Archive', onConfirm: () => API('DELETE', `${base()}/journeys/${j.id}`).then(() => { toast(j.status === 'draft' ? 'Journey deleted' : 'Journey archived'); location.hash = '#/automations'; }).catch((err) => toast(err.message)) }) }] : []),
    ], 'right');

    // toolbar + keys
    let nameT = null;
    $('#jname').oninput = (e) => { clearTimeout(nameT); nameT = setTimeout(() => API('PATCH', `${base()}/journeys/${j.id}`, { name: e.target.value.trim() || 'Untitled journey' }).then((r) => { j.name = r.journey.name; }).catch((err) => toast(err.message)), 600); };
    $('#zin').onclick = () => { const r = cv.getBoundingClientRect(); zoomAt(view.k * 1.15, r.width / 2, r.height / 2); };
    $('#zout').onclick = () => { const r = cv.getBoundingClientRect(); zoomAt(view.k / 1.15, r.width / 2, r.height / 2); };
    $('#zfit').onclick = () => fit(true);
    const td = $('#tidy'); if (td) td.onclick = tidy;
    const ab = $('#addbtn'); if (ab) ab.onclick = () => { if (palette) closePalette(); else openPalette(f.nodes.some((n) => n.type === 'trigger' && !n.config.trigger) ? 'triggers' : 'steps'); };
    $('#livet').onchange = (e) => { live = e.target.checked; renderEdges(); };
    $('#testj').onclick = testJourney;
    const onKey = (e) => { if (!document.body.contains(cv)) return document.removeEventListener('keydown', onKey); if (e.target.closest('input,select,textarea') || document.querySelector('.m-back,.modal-back')) return; if (e.key === '1') fit(true); if ((e.key === 'n' || e.key === 'N') && ab) ab.click(); if (e.key === 'Escape') { closePalette(); sel = null; renderNodes(); renderInspector(); } };
    document.addEventListener('keydown', onKey);
    window.onresize = () => document.body.contains(cv) && drawMini();
    const poll = setInterval(() => { if (!alive(g) || !document.body.contains(cv)) return clearInterval(poll); if (!document.hidden && j.status === 'active') reloadStats(); }, 20000);
    window.addEventListener('hashchange', function leave() { if (!location.hash.startsWith('#/automations/edit')) { window.removeEventListener('hashchange', leave); flush(); clearInterval(poll); window.removeEventListener('mousemove', onMove); window.removeEventListener('mouseup', onUp); } });

    // Agents saved before sub-nodes kept their model, memory, tools and output format inline: turn them into sub-nodes.
    if (!ro) {
      let moved = 0;
      f.nodes.filter((n) => n.type === 'ai_agent' && !f.edges.some((e) => e.to === n.id && isSub(byId()[e.from]))).forEach((n) => {
        const c = n.config || {}, put = (type, config, i) => { const m = { id: uid(), type, x: n.x - 120 + i * 116, y: n.y + 230, title: '', config }; f.nodes.push(m); f.edges.push({ from: m.id, to: n.id, branch: type }); moved++; };
        let i = 0;
        put('ai_model', { model: c.model || '', temperature: 0.3, max_tokens: 0 }, i++);
        if (c.memory && c.memory.on) put('ai_memory', { key: c.memory.key || 'agent', window: c.memory.window || 5 }, i++);
        (c.tools || []).forEach((t) => put('ai_tool', Object.assign({ tool: t }, t === 'fields' ? { fields: c.fields || [] } : {}, t === 'lists' ? { lists: c.lists || [] } : {}), i++));
        if ((c.output || []).some((x) => x.name)) put('ai_parser', { kind: 'structured', fields: c.output, autofix: true }, i++);
        ['model', 'memory', 'tools', 'fields', 'lists', 'output'].forEach((k) => delete c[k]);
      });
      if (moved) save();
    }
    renderHead(); renderNodes(); renderPalette(); renderInspector(); renderProblems();
    $('#probs').dataset.hidden = '1'; $('#probs').classList.remove('on'); // problems show when you try to turn it on, or on the steps themselves
    requestAnimationFrame(() => { renderEdges(); if (sel && S.param('sel')) centerOn(sel); else fit(); setTimeout(() => $('#hint') && $('#hint').classList.add('gone'), 4000); });
  }

  // ── Builder: a journey email's design (autosaves to the step) ──
  S.editingJourneyEmail = null;
  let jSaveT = null, jPending = null, jSaving = null;
  const jStatus = (t) => { const el = $('#ebsaved'); if (el) el.textContent = t; };
  const jPush = () => { const e = S.editingJourneyEmail; if (!e || !jPending) return Promise.resolve(); const doc = jPending; jPending = null; jStatus('Saving…'); jSaving = API('PUT', `${base()}/journeys/${e.id}/emails/${e.node}`, { doc }).then((r) => jStatus(`Journey email · saved · ${r.email.size_kb} KB`)).catch((err) => { jStatus('Couldn’t save: ' + err.message); toast(err.message); }).finally(() => { jSaving = null; }); return jSaving; };
  const prevSave = S.saveEmailDoc;
  S.saveEmailDoc = (d) => { if (!S.editingJourneyEmail) return prevSave(d); S.emailDoc = d; jPending = JSON.parse(JSON.stringify(d)); clearTimeout(jSaveT); jSaveT = setTimeout(jPush, 1200); jStatus('Unsaved changes…'); };
  const prevBuilder = pages['campaigns/design'];
  pages['campaigns/design'] = (v) => {
    const jid = S.param('journey'), node = S.param('node');
    if (!jid || !node) { S.editingJourneyEmail = null; return prevBuilder(v); }
    v.innerHTML = '<div class="card"><div class="card-body faint">Opening the design…</div></div>';
    API('GET', `${base()}/journeys/${jid}/emails/${node}`).then((r) => {
      const back = `#/automations/edit?id=${jid}&sel=${node}`;
      if (!r.email.doc) { location.hash = back; return; }
      S.editingTemplate = null; S.editingCampaign = null; S.editingJourneyEmail = { id: jid, node }; S.emailDoc = r.email.doc;
      prevBuilder(v);
      const bk = $('.jx-back'); if (bk) { bk.href = back; bk.title = 'Back to the journey'; }
      jStatus(`Journey email · saved · ${r.email.size_kb} KB`);
      const done = $('#done'); if (done) { done.textContent = 'Save & back'; done.onclick = () => { clearTimeout(jSaveT); jPending = jPending || JSON.parse(JSON.stringify(S.emailDoc)); (jSaving || Promise.resolve()).then(jPush).then(() => { location.hash = back; }); }; }
    }).catch((err) => { v.innerHTML = `<div class="card"><div class="card-body">${esc(err.message)}</div></div>`; });
  };
  window.addEventListener('hashchange', () => { if (S.editingJourneyEmail && !location.hash.startsWith('#/campaigns/design')) { clearTimeout(jSaveT); jPush(); S.editingJourneyEmail = null; S.emailDoc = null; } });
  // Gallery → journey email: "Use template" fills the step that asked for it.
  const prevUse = S.useTemplateDoc;
  S.useTemplateDoc = (name, doc, category) => {
    if (!S.pickForJourney) return prevUse(name, doc, category);
    const t = S.pickForJourney; S.pickForJourney = null;
    toast('Adding the design to your journey…');
    API('GET', base() + '/brand').then((r) => (S.applyBrand ? S.applyBrand(doc, r.brand) : doc)).then((d) => API('PUT', `${base()}/journeys/${t.id}/emails/${t.node}`, { doc: d }))
      .then(() => { location.hash = `#/campaigns/design?journey=${t.id}&node=${t.node}`; }).catch((err) => toast(err.message));
  };

  // leaving the editor restores the normal app chrome
  const FULL = /^#\/(automations\/edit|campaigns\/design|agent)/;
  window.addEventListener('hashchange', () => { if (!FULL.test(location.hash)) document.body.classList.remove('canvas-mode'); });
  if (!FULL.test(location.hash)) document.body.classList.remove('canvas-mode');
})();
