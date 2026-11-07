// Developer-only: runs the browser renderer under Node for RendererParityTest (the app renders in PHP).
// stdin: {"doc": {...}, "assetBase": "https://…", "subject": "…"}  →  stdout: {"html", "kb", "checks"}
'use strict';
const R = require('./email-render.cjs');
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (d) => { input += d; });
process.stdin.on('end', () => {
  try {
    const { doc, assetBase, subject } = JSON.parse(input);
    if (assetBase) R.config.assetBase = assetBase;
    const html = R.toHtml(doc, { subject: subject || '' });
    const c = R.checks(doc);
    process.stdout.write(JSON.stringify({ html, kb: Math.round(c.kb * 10) / 10, checks: c.list.map(([name, status, detail]) => ({ name, status, detail })) }));
  } catch (e) {
    process.stderr.write(String((e && e.message) || e));
    process.exit(1);
  }
});
