import type { Locator } from 'playwright';
import type { Shot } from '../director.js';
import { config } from '../config.js';

/**
 * Finds application #id in a Nureen queue (through the queue's own search
 * box, as an approver would) and opens its row.
 */
export async function openQueueRow(s: Shot, queuePath: string, id: number): Promise<Locator> {
  await s.goto(queuePath);
  const search = s.page.locator('#queue-q');
  if (await search.count()) {
    await s.type(search, String(id));
    await search.press('Enter');
    await s.page.waitForLoadState('load');
    await s.settle();
  }
  const row = s.page.locator('details.queue-row')
    .filter({ has: s.page.locator('.queue-row-id', { hasText: new RegExp(`^\\s*#${id}\\s*$`) }) })
    .first();
  await row.waitFor({ state: 'visible' });
  if (!(await row.evaluate((d) => (d as HTMLDetailsElement).open))) {
    await s.click(row.locator('summary').first());
  }
  await s.hold(1500);
  return row;
}

/**
 * The decision bar's primary button (Approve / Verify / Endorse & issue
 * letter...), with a remark typed first. Waits for the page that comes
 * back and shows its confirmation.
 */
export async function approve(s: Shot, id: number, remark?: string): Promise<string> {
  const bar = s.page.locator(`#nureen-decision-${id}`);
  if (remark) await s.type(bar.locator('textarea[name="remarks"]'), remark);
  const button = bar.locator('button[name="decision"][value="approve"]');
  const label = (await button.innerText()).trim();
  await s.click(button, { navigates: true });
  const message = await s.flash();
  await s.hold();
  return `${label}: ${message}`;
}

/** Mailpit: search the mailbox for a recipient and open the newest message whose subject matches. */
export async function openEmail(s: Shot, to: string, subject: RegExp): Promise<void> {
  await s.goto(config.mailpitUrl + '/');
  const search = s.page.getByPlaceholder('Search mailbox');
  await s.type(search, `to:${to}`);
  await search.press('Enter');
  const message = s.page.locator('a.message').filter({ hasText: subject }).first();
  await message.waitFor({ state: 'visible' });
  await s.hold();
  await s.click(message);
  await s.page.waitForURL(/\/view\//);
  await s.page.locator('iframe').first().waitFor({ state: 'visible' });
  await s.settle();
  await s.hold(2500);
  // Down through the message body, then a moment on it.
  const frame = await s.page.locator('iframe').first().boundingBox();
  if (frame) {
    await s.page.mouse.move(frame.x + frame.width / 2, frame.y + Math.min(frame.height / 2, 300), { steps: 10 });
    for (let i = 0; i < 8; i++) { await s.page.mouse.wheel(0, 90); await s.page.waitForTimeout(90); }
  }
  await s.hold();
}

/** The innermost card-like block containing `text` and a `has` element (e.g. its "View details" link). */
export function cardWith(s: Shot, text: string | RegExp, has?: Locator): Locator {
  let cards = s.page.locator(':is(div, article, section, li)').filter({ hasText: text });
  if (has) cards = cards.filter({ has });
  return cards.last();
}

/** Adds months to a Y-m-d date (no overflow: 31 Aug + 6 = 28 Feb). */
export function addMonths(iso: string, months: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const target = new Date(Date.UTC(y, m - 1 + months, 1));
  const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  target.setUTCDate(Math.min(d, last));
  return target.toISOString().slice(0, 10);
}

export function addDays(iso: string, days: number): string {
  const t = new Date(iso + 'T00:00:00Z');
  t.setUTCDate(t.getUTCDate() + days);
  return t.toISOString().slice(0, 10);
}
