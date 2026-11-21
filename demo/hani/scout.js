// Scouting helper: log in as each role, screenshot pages and dump their text,
// so the storyboard is written against the real UI. Not part of the video.
//   node scout.js <outdir> <email> <path> [<path> ...]
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://localhost:8000';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

(async () => {
  const [outdir, email, ...paths] = process.argv.slice(2);
  fs.mkdirSync(outdir, { recursive: true });
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true,
    defaultViewport: { width: 1920, height: 1080, deviceScaleFactor: 1 } });
  const page = await browser.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'load' });
  await page.type('#email', email);
  await page.type('#password', 'password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  for (const p of paths) {
    const slug = (email.split('@')[0] + p).replace(/[^a-z0-9]+/gi, '_');
    const res = await page.goto(BASE + p, { waitUntil: 'load' });
    await page.screenshot({ path: path.join(outdir, slug + '.png'), fullPage: true });
    const text = await page.evaluate(() => (document.querySelector('main') || document.body).innerText);
    fs.writeFileSync(path.join(outdir, slug + '.txt'), `${res.status()} ${page.url()}\n\n${text}`);
    console.log(res.status(), p, '->', slug);
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
