// Recording toolkit for the Hani demo video: CDP screencast capture with
// pause/resume (cuts), an injected cursor and highlight box, and a caption
// timeline kept in video time. Used by record.js.
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://localhost:8000';
const MAILPIT = process.env.MAILPIT || 'http://localhost:8025';
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const sleep = ms => new Promise(r => setTimeout(r, ms));
// Bottom band of the frame the captions use (two lines plus margin).
const CAPTION_ZONE = 240;
const CAPTION_MAX = 5;

// Injected into every document: the cursor, the highlight layer and the
// tooltip used to show a native validation message (which a screencast
// cannot capture). Position survives navigations through window.name.
const OVERLAY = () => {
  const install = () => {
    if (document.getElementById('__demo_cursor') || !document.body) return;
    const style = document.createElement('style');
    style.textContent = `
      #__demo_cursor{position:fixed;left:0;top:0;width:28px;height:28px;z-index:2147483647;pointer-events:none;
        transition:transform var(--d,0ms) cubic-bezier(.4,0,.2,1);will-change:transform}
      #__demo_cursor svg{filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
      .__demo_ripple{position:fixed;width:34px;height:34px;margin:-17px 0 0 -17px;border-radius:50%;z-index:2147483646;
        pointer-events:none;border:3px solid rgba(245,158,11,.9);animation:__demo_r .5s ease-out forwards}
      @keyframes __demo_r{from{transform:scale(.3);opacity:1}to{transform:scale(1.4);opacity:0}}
      .__demo_hl{position:fixed;z-index:2147483645;pointer-events:none;border:4px solid #F59E0B;border-radius:12px;
        background:rgba(245,158,11,.14);box-shadow:0 0 0 4px rgba(245,158,11,.22);opacity:0;transition:opacity .25s ease}
      .__demo_hl.on{opacity:1}
      .__demo_tip{position:fixed;z-index:2147483645;pointer-events:none;background:#fff;color:#1f2937;font:15px/1.35 system-ui,sans-serif;
        padding:10px 14px 10px 40px;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.25);border:1px solid #d1d5db;max-width:420px}
      .__demo_tip:before{content:'!';position:absolute;left:12px;top:9px;width:20px;height:20px;border-radius:4px;background:#F59E0B;
        color:#fff;font-weight:700;text-align:center;line-height:20px}`;
    document.documentElement.appendChild(style);
    const c = document.createElement('div');
    c.id = '__demo_cursor';
    c.innerHTML = '<svg width="28" height="28" viewBox="0 0 28 28"><path d="M5 3 L5 22 L10 17.5 L13.5 25 L17 23.5 L13.6 16 L20 16 Z" fill="#111" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    let pos = { x: 960, y: 540 };
    try { const p = JSON.parse(window.name || '{}'); if (p.__cx != null) pos = { x: p.__cx, y: p.__cy }; } catch (e) {}
    c.style.transform = `translate(${pos.x - 5}px, ${pos.y - 3}px)`;
    document.body.appendChild(c);
    // Room at the foot of the page so the last rows can scroll above the caption area.
    const host = document.querySelector('.main-content') || document.body;
    if (!document.getElementById('__demo_spacer')) { const sp = document.createElement('div'); sp.id = '__demo_spacer'; sp.style.height = '260px'; host.appendChild(sp); }
  };
  window.__demoMove = (x, y, ms) => {
    install();
    const c = document.getElementById('__demo_cursor');
    c.style.setProperty('--d', ms + 'ms');
    c.style.transform = `translate(${x - 5}px, ${y - 3}px)`;
    try { window.name = JSON.stringify({ __cx: x, __cy: y }); } catch (e) {}
  };
  window.__demoRipple = (x, y) => {
    const r = document.createElement('div');
    r.className = '__demo_ripple'; r.style.left = x + 'px'; r.style.top = y + 'px';
    document.body.appendChild(r); setTimeout(() => r.remove(), 600);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
};

class Recorder {
  constructor(page, cdp, dir) {
    this.page = page; this.cdp = cdp; this.dir = dir;
    this.frames = [];      // {t, file} or {pause: t}
    this.recording = false;
    this.closed = 0;       // seconds of video before the current recording interval
    this.resumedAt = 0;
    this.n = 0;
    this.captions = [];    // {start, end, text}
    this.marks = [];       // {t, label}
    fs.mkdirSync(dir, { recursive: true });
    cdp.on('Page.screencastFrame', async f => {
      cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
      if (this.recording) this._save(Buffer.from(f.data, 'base64'));
    });
  }
  _save(buf) {
    const file = path.join(this.dir, String(++this.n).padStart(6, '0') + '.jpg');
    fs.writeFileSync(file, buf);
    this.frames.push({ t: Date.now() / 1000, file });
  }
  async start() {
    await this.cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: 1920, maxHeight: 1080, everyNthFrame: 1 });
  }
  clock() { return this.closed + (this.recording ? Date.now() / 1000 - this.resumedAt : 0); }
  async resume() {
    if (this.recording) return;
    // A screencast only sends frames on change: grab one now so the cut lands here.
    const buf = await this.page.screenshot({ type: 'jpeg', quality: 92 });
    this.recording = true;
    this.resumedAt = Date.now() / 1000;
    this._save(buf);
  }
  pause() {
    if (!this.recording) return;
    const t = Date.now() / 1000;
    this.closed += t - this.resumedAt;
    this.recording = false;
    this.frames.push({ pause: t });
  }
  mark(label) { this.marks.push({ t: +this.clock().toFixed(2), label }); }
  // Writes the ffmpeg concat list (variable frame durations) and the timeline.
  finish(outBase) {
    this.pause();
    const lines = ['ffconcat version 1.0'];
    let total = 0, last = null;
    for (let i = 0; i < this.frames.length; i++) {
      const f = this.frames[i];
      if (f.pause) continue;
      const next = this.frames[i + 1];
      const end = next ? (next.pause || next.t) : f.t + 0.04;
      const d = Math.max(0.001, end - f.t);
      lines.push(`file '${f.file}'`, `duration ${d.toFixed(4)}`);
      total += d; last = f.file;
    }
    if (last) lines.push(`file '${last}'`);
    fs.writeFileSync(outBase + '.ffconcat', lines.join('\n') + '\n');
    fs.writeFileSync(outBase + '.json', JSON.stringify({ duration: +total.toFixed(3), captions: this.captions, marks: this.marks }, null, 2));
    return total;
  }
}

class Demo {
  static async launch({ outDir, segment, dry = false }) {
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: true,
      defaultViewport: { width: 1920, height: 1080, deviceScaleFactor: 1 },
      args: ['--hide-scrollbars', '--font-render-hinting=none', '--lang=en-GB'] });
    const page = await browser.newPage();
    await page.evaluateOnNewDocument(OVERLAY);
    const cdp = await page.createCDPSession();
    const d = new Demo();
    Object.assign(d, { browser, page, cdp, dry, outDir, segment, cursor: { x: 960, y: 540 }, _hl: [] });
    d.rec = new Recorder(page, cdp, path.join(outDir, 'frames', segment));
    page.setDefaultTimeout(20000);
    if (!dry) await d.rec.start();
    return d;
  }

  // ---- time -------------------------------------------------------------
  async wait(ms) { await sleep(this.dry ? Math.min(ms, 150) : ms); }

  // ---- cuts and users ---------------------------------------------------
  // Everything inside fn is off camera; recording resumes on the result.
  async cut(fn) {
    this.rec.pause();
    await this.hideHighlights();
    await fn();
    await this.settle();
    await this.restoreCursor();
    await sleep(150);
    if (!this.dry) await this.rec.resume();
  }
  async loginAs(email) {
    const client = this.cdp;
    await client.send('Network.clearBrowserCookies');
    await this.page.goto(BASE + '/login', { waitUntil: 'load' });
    // Off camera, so filled through the DOM: synthetic keystrokes are
    // sometimes dropped after a long run of page input.
    await Promise.all([
      this.page.waitForNavigation({ waitUntil: 'load' }),
      this.page.evaluate(email => {
        document.querySelector('#email').value = email;
        document.querySelector('#password').value = 'password';
        document.querySelector('#email').form.requestSubmit();
      }, email),
    ]);
    if (this.page.url().includes('/login')) throw new Error('login failed for ' + email);
  }
  async go(url) {
    await this.page.goto(url.startsWith('http') || url.startsWith('file:') ? url : BASE + url, { waitUntil: 'load' });
    await this.settle();
  }
  async as(email, url) { await this.cut(async () => { await this.loginAs(email); await this.go(url); }); }
  async settle() {
    try { await this.page.waitForNetworkIdle({ idleTime: 300, timeout: 8000 }); } catch (e) {}
    try { await this.page.evaluate(() => document.fonts && document.fonts.ready); } catch (e) {}
  }

  // ---- elements -----------------------------------------------------------
  // The smallest element matching `sel` whose text contains `text`.
  async find(sel, text, { within = null, visible = true } = {}) {
    const h = await this.page.evaluateHandle((sel, text, within, visible) => {
      const root = within ? document.querySelector(within) : document;
      const norm = s => (s || '').replace(/\s+/g, ' ').trim();
      const all = [...root.querySelectorAll(sel)].filter(e => text == null || norm(e.innerText || e.textContent || e.value).toLowerCase().includes(text.toLowerCase()));
      const vis = all.filter(e => !visible || (e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden'));
      vis.sort((a, b) => norm(a.innerText).length - norm(b.innerText).length);
      return vis[0] || null;
    }, sel, text ?? null, within, visible);
    const el = h.asElement();
    if (!el) throw new Error(`not found: ${sel} ${text ? '"' + text + '"' : ''} on ${this.page.url()}`);
    return el;
  }
  async findAny(...cands) {
    for (const c of cands) { try { return await this.find(...c); } catch (e) {} }
    throw new Error('none found: ' + JSON.stringify(cands) + ' on ' + this.page.url());
  }
  async el(target) { return typeof target === 'string' ? this.find(target) : Array.isArray(target) ? this.find(...target) : target; }
  async scrollTo(target, block = 'center') {
    const el = await this.el(target);
    const y0 = await el.evaluate((e, block, smooth, zone) => {
      const r = e.getBoundingClientRect();
      const inView = r.top >= 90 && r.bottom <= window.innerHeight - zone;
      if (inView) return null;
      e.scrollIntoView({ behavior: smooth ? 'smooth' : 'instant', block });
      return Math.round(r.top);
    }, block, !this.dry, CAPTION_ZONE);
    if (y0 !== null) {
      // Wait for the scroll to finish: the element has moved, then holds still.
      let last = y0, still = 0, movedOnce = false;
      for (let i = 0; i < 60 && still < 2; i++) {
        await sleep(50);
        const y = await el.evaluate(e => Math.round(e.getBoundingClientRect().top));
        if (y !== y0) movedOnce = true;
        if (!movedOnce && i >= 6) break; // could not scroll (already at the end)
        still = (movedOnce && y === last) ? still + 1 : 0;
        last = y;
      }
      await this.wait(150);
    }
    return el;
  }
  async scrollBy(dy, ms = 700) {
    await this.page.evaluate(dy => window.scrollBy({ top: dy, behavior: 'smooth' }), dy);
    await this.wait(ms);
  }

  // ---- cursor -------------------------------------------------------------
  async restoreCursor() {
    try { await this.page.evaluate((x, y) => window.__demoMove && window.__demoMove(x, y, 0), this.cursor.x, this.cursor.y); } catch (e) {}
  }
  async moveTo(target, { dx = 0, dy = 0 } = {}) {
    const el = await this.scrollTo(target);
    const box = await el.boundingBox();
    if (!box) throw new Error('no box for target');
    const x = Math.round(box.x + Math.min(box.width / 2, 60) + dx), y = Math.round(box.y + box.height / 2 + dy);
    const dist = Math.hypot(x - this.cursor.x, y - this.cursor.y);
    const ms = this.dry ? 0 : Math.round(Math.min(650, Math.max(250, dist * 0.55)));
    await this.page.evaluate((x, y, ms) => window.__demoMove(x, y, ms), x, y, ms);
    await this.page.mouse.move(x, y, { steps: 12 });
    await this.wait(ms + 80);
    this.cursor = { x, y };
    return el;
  }
  async click(target, opts = {}) {
    const el = await this.moveTo(target, opts);
    await this.page.evaluate((x, y) => window.__demoRipple(x, y), this.cursor.x, this.cursor.y);
    await this.wait(70);
    await this.page.evaluate(() => { window.__clicked = false; window.addEventListener('click', () => { window.__clicked = true; }, { capture: true, once: true }); }).catch(() => {});
    await this.page.mouse.click(this.cursor.x, this.cursor.y);
    // Synthetic mouse clicks sometimes never reach the page (seen on several
    // forms here). If no click event arrived, activate the element directly.
    try {
      await sleep(80);
      const got = await this.page.evaluate(() => window.__clicked);
      if (got === false) await el.evaluate(e => e.click());
    } catch (e) { /* the click navigated: it arrived */ }
    // Fallback: on some pages a synthetic click does not reach a radio or
    // checkbox. The cursor is already on it, so set the value the way a
    // click would and fire the page's own input/change handlers.
    try {
      await el.evaluate(e => {
        const sum = e.closest('summary');
        if (sum && sum.parentElement && !sum.parentElement.open) { sum.parentElement.open = true; return; }
        if (!e.matches('label, input')) return;
        const input = e.matches('input') ? e : (e.control || e.querySelector('input'));
        if (input && (input.type === 'radio' || input.type === 'checkbox') && !input.checked) {
          input.checked = true;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    } catch (e) { /* the click navigated away: nothing to set */ }
    return el;
  }
  // Click, and wait for the navigation it causes.
  async clickNav(target, opts = {}) {
    const nav = this.page.waitForNavigation({ waitUntil: 'load', timeout: 30000 });
    nav.catch(() => {});
    const el = await this.click(target, opts);
    // Same fallback as click(): if the click did not reach the button,
    // submit its form through it (validation still runs).
    const moved = await Promise.race([nav.then(() => true), sleep(2500).then(() => false)]);
    if (!moved) await el.evaluate(b => b.form ? b.form.requestSubmit(b) : b.click()).catch(() => {});
    // The page load itself is cut, like a user switch.
    const wasRecording = this.rec.recording;
    this.rec.pause();
    await nav;
    await this.settle();
    await this.restoreCursor();
    await sleep(120);
    if (wasRecording && !this.dry) await this.rec.resume();
  }
  async type(target, text, { delay = 55 } = {}) {
    const el = await this.click(target);
    await el.evaluate(e => { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); });
    for (const ch of text) {
      await this.page.keyboard.type(ch);
      if (!this.dry) await sleep(delay * (0.6 + Math.random() * 0.8));
    }
    // Keystrokes are sometimes dropped (as with clicks): make sure the value landed.
    await el.evaluate((e, t) => {
      if (e.value !== t) { e.value = t; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }
    }, text);
  }
  // A paste: long text appears at once, as it would from the clipboard.
  async paste(target, text) {
    const el = await this.click(target);
    await el.evaluate((e, t) => { e.value = t; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, text);
    await this.wait(400);
  }

  // ---- highlights and captions ------------------------------------------
  async highlight(target, { hold = 2500, pad = 8, keep = false, union = null } = {}) {
    const els = [await this.scrollTo(target)];
    if (union) for (const u of union) els.push(await this.el(u));
    const box = await this.page.evaluate((pad, ...els) => {
      const rs = els.map(e => e.getBoundingClientRect());
      const l = Math.min(...rs.map(r => r.left)), t = Math.min(...rs.map(r => r.top));
      const r = Math.max(...rs.map(r => r.right)), b = Math.max(...rs.map(r => r.bottom));
      const d = document.createElement('div');
      d.className = '__demo_hl';
      Object.assign(d.style, { left: (l - pad) + 'px', top: (t - pad) + 'px', width: (r - l + 2 * pad) + 'px', height: (b - t + 2 * pad) + 'px' });
      document.body.appendChild(d);
      requestAnimationFrame(() => requestAnimationFrame(() => d.classList.add('on')));
      return { l, t, r, b };
    }, pad, ...els);
    this.rec.mark(`highlight ${Math.round(box.l)},${Math.round(box.t)} ${Math.round(box.r - box.l)}x${Math.round(box.b - box.t)}`);
    this._hl.push({ t: this.rec.clock(), bottom: box.b + pad });
    await this.wait(hold);
    if (!keep) await this.hideHighlights();
    return els[0];
  }
  // Highlight text inside an iframe (Mailpit's message body): the box is
  // measured in the frame, drawn on the main page.
  async highlightInFrame(frameSel, sel, text, { hold = 2500, pad = 8 } = {}) {
    const frameEl = await this.page.$(frameSel);
    const frame = frameEl && await frameEl.contentFrame();
    if (!frame) throw new Error('no frame ' + frameSel);
    const h = await frame.evaluateHandle((sel, text) => {
      const all = [...document.querySelectorAll(sel)].filter(e => e.innerText.toLowerCase().includes(text.toLowerCase()));
      all.sort((a, b) => a.innerText.length - b.innerText.length);
      return all[0] || null;
    }, sel, text);
    const el = h.asElement();
    if (!el) throw new Error(`not found in frame: ${sel} "${text}"`);
    const b = await el.boundingBox();
    await this.page.evaluate((b, pad) => {
      const d = document.createElement('div');
      d.className = '__demo_hl';
      Object.assign(d.style, { left: (b.x - pad) + 'px', top: (b.y - pad) + 'px', width: (b.width + 2 * pad) + 'px', height: (b.height + 2 * pad) + 'px' });
      document.body.appendChild(d);
      requestAnimationFrame(() => requestAnimationFrame(() => d.classList.add('on')));
    }, b, pad);
    this.rec.mark(`highlight-frame ${Math.round(b.x)},${Math.round(b.y)}`);
    this._hl.push({ t: this.rec.clock(), bottom: b.y + b.height + pad });
    await this.wait(hold);
    await this.hideHighlights();
  }
  async hideHighlights() {
    try {
      await this.page.evaluate(() => document.querySelectorAll('.__demo_hl,.__demo_tip').forEach(d => { d.classList.remove('on'); d.style.opacity = 0; setTimeout(() => d.remove(), 300); }));
    } catch (e) {}
    await sleep(this.dry ? 0 : 250);
  }
  // A tooltip with an element's real native validation message.
  async validationTip(target) {
    const el = await this.el(target);
    return el.evaluate(e => {
      const r = e.getBoundingClientRect();
      const t = document.createElement('div');
      t.className = '__demo_tip'; t.textContent = e.validationMessage;
      Object.assign(t.style, { left: r.left + 'px', top: (r.bottom + 10) + 'px' });
      document.body.appendChild(t);
      return e.validationMessage;
    });
  }
  // Shows a caption for the duration of fn, at least min seconds.
  // If fn highlights something, the caption starts just before the first
  // highlight (so the two arrive together); it lasts at most CAPTION_MAX s.
  // A caption whose highlights reach into the bottom band is drawn at the top.
  async caption(text, fn = async () => {}, { min = 3 } = {}) {
    this._hl = [];
    let start = this.rec.clock();
    const t0 = Date.now();
    await fn();
    if (this._hl.length) start = Math.max(start, this._hl[0].t - 0.25);
    const left = min * 1000 - (this.rec.clock() - start) * 1000;
    if (left > 0) await this.wait(left);
    const end = Math.min(this.rec.clock(), start + CAPTION_MAX);
    const top = this._hl.some(h => h.t <= end && h.bottom > 1080 - CAPTION_ZONE);
    this.rec.captions.push({ start: +start.toFixed(2), end: +end.toFixed(2), text, ...(top ? { pos: 'top' } : {}) });
    this._hl = [];
  }

  // ---- files ------------------------------------------------------------
  // Downloads an app URL with this session's cookies and opens it in Chrome's PDF viewer.
  async openPdf(url, name) {
    const b64 = await this.page.evaluate(async url => {
      const r = await fetch(url, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const buf = new Uint8Array(await r.arrayBuffer());
      let s = ''; for (let i = 0; i < buf.length; i += 0x8000) s += String.fromCharCode(...buf.subarray(i, i + 0x8000));
      return btoa(s);
    }, url);
    const file = path.join(this.outDir, 'files', name);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, Buffer.from(b64, 'base64'));
    await this.page.goto('file://' + file, { waitUntil: 'load' });
    await sleep(1500);
    return file;
  }
  // The newest Mailpit message matching a search, opened in Mailpit's UI.
  async mailpit(query) {
    // query: a substring of the subject (newest match wins).
    const res = await fetch(`${MAILPIT}/api/v1/messages?limit=200`).then(r => r.json());
    const m = (res.messages || []).find(x => x.Subject.includes(query));
    if (!m) throw new Error('no Mailpit message for ' + query);
    await this.go(`${MAILPIT}/view/${m.ID}`);
    await sleep(800);
    return m;
  }

  async shot(name) {
    const dir = path.join(this.outDir, 'shots', this.segment);
    fs.mkdirSync(dir, { recursive: true });
    await this.page.screenshot({ path: path.join(dir, name + '.png') });
  }
  async close(outBase) {
    const total = this.dry ? 0 : this.rec.finish(outBase);
    await this.browser.close();
    return total;
  }
}

module.exports = { Demo, BASE, MAILPIT, sleep };
