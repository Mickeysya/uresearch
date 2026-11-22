import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium, type Browser, type Locator, type Page } from 'playwright';
import { config } from './config.js';
import { overlay } from './overlay.js';

/** Thrown with the clip, step and cause, so the console says exactly what failed. */
export class StepError extends Error {}

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/**
 * Runs one clip. Every role works in its own browser context (its own
 * session, logged in through the real login page), and each context is
 * recorded as one segment; the clip's video is its segments joined in order.
 */
export class Recorder {
  readonly segments: string[] = [];
  step = 0;

  /**
   * An application submitted on camera: listed in .demo-created.json at
   * once, so record.sh can register it as demo data and the next
   * `nureen:demo-fresh --reset` removes it like the rest.
   */
  static created(id: number): void {
    const file = path.join(config.videosDir, '..', '.demo-created.json');
    const ids: number[] = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : [];
    if (!ids.includes(id)) ids.push(id);
    fs.writeFileSync(file, JSON.stringify(ids));
  }

  constructor(readonly browser: Browser, readonly clip: string) {}

  static async launch(): Promise<Browser> {
    // channel 'chromium' = Chromium's new headless mode: the full browser,
    // including its built-in PDF viewer (the old headless shell has none).
    return chromium.launch({ channel: 'chromium', slowMo: config.slowMo, args: ['--hide-scrollbars'] });
  }

  /** One role's part of the clip, in a fresh context. `email` null = no login (Mailpit). */
  async as(role: string, email: string | null, fn: (s: Shot) => Promise<void>): Promise<void> {
    const dir = path.join(config.rawDir, this.clip, `${String(this.segments.length + 1).padStart(2, '0')}-${slug(role)}`);
    fs.rmSync(dir, { recursive: true, force: true });

    const context = await this.browser.newContext({
      viewport: config.viewport,
      recordVideo: { dir, size: config.viewport },
      deviceScaleFactor: 1,
      colorScheme: 'light',
      locale: 'en-GB',
      timezoneId: 'Asia/Kuala_Lumpur',
      bypassCSP: true,
      acceptDownloads: true,
    });
    await context.addInitScript('window.__name = window.__name || function (f) { return f; };');
    await context.addInitScript(overlay);
    // The app's own light/dark toggle: always light for the recording.
    await context.addInitScript(() => { try { localStorage.setItem('theme', 'light'); } catch { /* ignore */ } });

    const page = await context.newPage();
    page.setDefaultTimeout(30_000);
    page.setDefaultNavigationTimeout(60_000);
    const shot = new Shot(page, this, role);

    let failure: unknown;
    try {
      if (email) await shot.login(email);
      await fn(shot);
    } catch (e) {
      failure = e;
    }

    const video = page.video();
    await context.close();
    if (video) this.segments.push(await video.path());
    if (failure) throw failure;
  }
}

/** One page, with the on-camera actions: captions, pointing, clicking, typing, showing PDFs. */
export class Shot {
  constructor(readonly page: Page, readonly rec: Recorder, readonly role: string) {}

  /** A numbered step: caption shown while it runs; on failure a screenshot and a clear message. */
  async step(text: string, fn: () => Promise<void>): Promise<void> {
    const n = ++this.rec.step;
    const label = `Step ${n}: ${text}`;
    console.log(`  [${this.rec.clip}] ${label}`);
    await this.caption(label);
    try {
      await fn();
    } catch (e) {
      fs.mkdirSync(config.failuresDir, { recursive: true });
      const file = path.join(config.failuresDir, `${this.rec.clip}-step${String(n).padStart(2, '0')}.png`);
      await this.page.screenshot({ path: file }).catch(() => undefined);
      const cause = e instanceof Error ? e.message.split('\n')[0] : String(e);
      throw new StepError(`${this.rec.clip}, step ${n} "${text}" (as ${this.role}) failed: ${cause}\n    screenshot: demo-recorder/${path.relative(path.resolve(config.videosDir, ".."), file)}`);
    }
    await this.caption('');
  }

  async caption(text: string): Promise<void> {
    await this.page.evaluate((t) => (window as any).__demoSetCaption?.(t), text).catch(() => undefined);
  }

  /** A pause for the viewer to read -- pacing, never a wait for loading. */
  async hold(ms = config.hold): Promise<void> {
    await this.page.waitForTimeout(ms);
  }

  async goto(url: string): Promise<void> {
    await this.page.goto(url.startsWith('http') ? url : config.baseUrl + url, { waitUntil: 'load' });
    await this.settle();
    await this.hold();
  }

  /** Network quiet and fonts ready, after a navigation. */
  async settle(): Promise<void> {
    await this.page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => undefined);
    await this.page.evaluate(() => document.fonts?.ready).catch(() => undefined);
  }

  /** Smoothly scroll an element to the middle of the screen and wait for the scroll to stop. */
  async reveal(target: Locator): Promise<void> {
    await target.waitFor({ state: 'visible' });
    await target.evaluate((el) => el.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    await this.page.waitForFunction(() => new Promise<boolean>((resolve) => {
      let last = -1; let still = 0;
      const tick = () => {
        const y = window.scrollY;
        if (y === last) { if (++still > 6) { resolve(true); return; } } else { still = 0; last = y; }
        requestAnimationFrame(tick);
      };
      tick();
    }));
  }

  /** Move the visible cursor onto an element. */
  async pointAt(target: Locator): Promise<void> {
    await this.reveal(target);
    const box = await target.boundingBox();
    if (box) await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 18 });
  }

  async click(target: Locator, opts: { navigates?: boolean } = {}): Promise<void> {
    await this.pointAt(target);
    await this.page.waitForTimeout(250);
    if (opts.navigates) {
      await Promise.all([this.page.waitForNavigation({ waitUntil: 'load' }), target.click()]);
      await this.settle();
    } else {
      await target.click();
    }
  }

  /** Types at human speed. */
  async type(target: Locator, text: string): Promise<void> {
    await this.click(target);
    await target.fill('');
    await target.pressSequentially(text, { delay: config.typeDelay });
  }

  async fillDate(target: Locator, isoDate: string): Promise<void> {
    await this.click(target);
    await target.fill(isoDate);
  }

  async choose(target: Locator, value: string): Promise<void> {
    await this.pointAt(target);
    await target.selectOption(value);
  }

  /** A temporary outline drawn round something worth looking at. */
  async highlight(target: Locator, ms = config.hold): Promise<void> {
    await this.pointAt(target);
    await target.evaluate((el) => {
      const s = (el as HTMLElement).style;
      (el as any).__demoOutline = [s.outline, s.outlineOffset, s.borderRadius];
      s.outline = '4px solid #ffb703'; s.outlineOffset = '4px'; s.borderRadius = s.borderRadius || '8px';
    });
    await this.hold(ms);
    await target.evaluate((el) => {
      const [o, off, r] = (el as any).__demoOutline ?? ['', '', ''];
      const s = (el as HTMLElement).style; s.outline = o; s.outlineOffset = off; s.borderRadius = r;
    }).catch(() => undefined);
  }

  /** Logs in through the real login page. */
  async login(email: string): Promise<void> {
    await this.step(`Log in as ${this.role}`, async () => {
      await this.page.goto(config.baseUrl + '/login', { waitUntil: 'load' });
      await this.settle();
      await this.type(this.page.locator('#email'), email);
      await this.type(this.page.locator('#password'), config.password);
      await this.click(this.page.getByRole('button', { name: 'Log In' }), { navigates: true });
      if (new URL(this.page.url()).pathname === '/login') throw new Error(`login was refused for ${email}`);
      await this.hold(1200);
    });
  }

  /** The status line a redirect flashes after an action, e.g. "Application #12 approved." */
  async flash(): Promise<string> {
    const box = this.page.locator('.message-success, [role="status"], .alert-success').first();
    await box.waitFor({ state: 'visible' });
    await this.highlight(box, 1600);
    return (await box.innerText()).trim();
  }

  /**
   * Opens a generated PDF in the app's own preview overlay and shows it:
   * a caption naming it, 3 s on the top of the page, a slow scroll through
   * every page, 3 s at the bottom. The PDF is also saved, and each page
   * rendered to PNG, under videos/outcomes/ for the slides.
   */
  async showPdf(link: Locator, title: string, outcomeName: string): Promise<void> {
    const url = await link.getAttribute('data-preview-url');
    if (!url) throw new Error('that link does not open the preview overlay');
    const loaded = this.page.waitForResponse((r) => r.url() === url && r.ok());
    await this.click(link);
    await loaded;
    const frame = this.page.locator('[data-preview-frame]');
    await frame.waitFor({ state: 'visible' });
    // The viewer is Chromium's own PDF extension inside the iframe; wait for it.
    await this.page.waitForFunction(() => {
      const f = document.querySelector('[data-preview-frame]') as HTMLIFrameElement | null;
      return !!f && !!f.contentDocument && f.contentDocument.readyState === 'complete';
    });
    await this.page.waitForEvent('frameattached', { predicate: (f) => f.url().startsWith('chrome-extension://'), timeout: 5_000 }).catch(() => undefined);
    await this.page.waitForTimeout(1200); // the viewer paints its first page after it attaches

    await this.caption(title);
    const pdf = await this.saveOutcome(url, outcomeName);
    await this.page.screenshot({ path: path.join(config.outcomesDir, `${outcomeName}-preview.png`) });
    await this.hold(3000);

    // Scroll through every page with the mouse wheel over the document.
    const box = await frame.boundingBox();
    if (box) {
      await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 12 });
      const distance = pdf.pages * 1200;
      for (let done = 0; done < distance; done += 90) {
        await this.page.mouse.wheel(0, 90);
        await this.page.waitForTimeout(70);
      }
    }
    await this.hold(3000);
    await this.caption('');
    await this.click(this.page.locator('[data-preview-close]'));
  }

  /** Saves the PDF and renders its pages to PNG. @returns its page count */
  async saveOutcome(url: string, name: string): Promise<{ pages: number }> {
    fs.mkdirSync(config.outcomesDir, { recursive: true });
    const res = await this.page.request.get(url);
    if (!res.ok()) throw new Error(`could not fetch ${url} (${res.status()})`);
    const file = path.join(config.outcomesDir, `${name}.pdf`);
    fs.writeFileSync(file, await res.body());

    let pages = (fs.readFileSync(file, 'latin1').match(/\/Type\s*\/Page\b/g) ?? []).length || 1;
    try {
      const info = execFileSync('pdfinfo', [file], { encoding: 'utf8' });
      pages = Number(/Pages:\s+(\d+)/.exec(info)?.[1] ?? pages);
      execFileSync('pdftoppm', ['-png', '-r', '120', file, path.join(config.outcomesDir, name)]);
    } catch {
      console.warn(`    (pdftoppm/pdfinfo not found: ${name}.pdf saved, page images skipped)`);
    }
    return { pages };
  }
}
