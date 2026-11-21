// Screenshot one element of a page, for checking UI fixes.
//   node snap.js <out.png> <email> <path> <css selector> [text it contains] [pad]
const { Demo } = require('./lib');
(async () => {
  const [out, email, url, sel, text, pad = '16'] = process.argv.slice(2);
  const d = await Demo.launch({ outDir: require('path').dirname(out), segment: 'snap', dry: true });
  try {
    await d.loginAs(email);
    await d.go(url);
    await d.page.evaluate(() => { const c = document.getElementById('__demo_cursor'); if (c) c.style.display = 'none'; });
    const el = await d.find(sel, text || null);
    // Pad by styling the element itself for the shot, then let Puppeteer scroll and clip.
    await el.evaluate((e, p) => { e.style.outline = `${p}px solid transparent`; e.style.boxShadow = `0 0 0 ${p}px #fff`; }, +pad);
    await el.screenshot({ path: out });
    console.log('saved', out);
  } finally { await d.browser.close(); }
})().catch(e => { console.error(e.message); process.exit(1); });
