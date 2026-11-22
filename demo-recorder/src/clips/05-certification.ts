import path from 'node:path';
import type { Recorder } from '../director.js';
import { config } from '../config.js';
import { demoApp, loginAt, type DemoInfo } from '../demoData.js';
import { approve, cardWith, openQueueRow } from './common.js';

/**
 * A certification request waiting at CGS: CGS verifies the GA/GRA status
 * and approves, the Senior Director approves (which issues the letter),
 * the student opens the letter (CGS/GACERT/...) and its public
 * verification page shows "Valid".
 */
export async function run(rec: Recorder, info: DemoInfo): Promise<void> {
  const app = demoApp(info, 'ga_certification', 0);
  const student = app.student;
  let letterRef = '';

  await rec.as('CGS officer (Zulkifly)', loginAt(app, 'cgs_verify') ?? config.staff.cgs, async (s) => {
    await s.step(`CGS checks ${student.name}'s GA/GRA status and eligibility`, async () => {
      const row = await openQueueRow(s, '/ga-certification/queue', app.id);
      const checks = row.locator('.cert-checks, :text("Appointment check")').first();
      await s.highlight(checks, 4000);
    });
    await s.step('Verified: CGS approves and passes it to the Senior Director', async () => {
      await approve(s, app.id, 'GA appointment and period verified.');
    });
  });

  await rec.as('Senior Director CGS', loginAt(app, 'senior_director'), async (s) => {
    await s.step('The Senior Director approves: the letter is issued', async () => {
      await openQueueRow(s, '/ga-certification/queue', app.id);
      await approve(s, app.id);
    });
    await s.step('The letter on the Issued list, with its CGS/GACERT reference', async () => {
      await s.goto('/ga-certification/issued');
      const block = s.page.locator(':is(div, section, li, article)')
        .filter({ hasText: new RegExp(`Application #${app.id}\\b`) })
        .filter({ hasText: /CGS\/GACERT\// })
        .last();
      await s.highlight(block, 3000);
      const text = await block.innerText();
      letterRef = new RegExp(`Application #${app.id}\\b[\\s\\S]*?(CGS\\/GACERT\\/\\d{4}\\/\\d+)`).exec(text)?.[1] ?? '';
      if (!letterRef) throw new Error(`no CGS/GACERT reference next to ${student.name}`);
    });
  });

  await rec.as(`${student.name} (student)`, student.email, async (s) => {
    await s.step('The student opens the certification letter', async () => {
      await s.goto('/documents');
      const search = s.page.locator('#doc-search');
      await s.type(search, app.reference);
      await search.press('Enter');
      await s.page.waitForLoadState('load');
      await s.settle();
      const link = s.page.locator('[data-preview-name^="certification-"]').first();
      await link.waitFor({ state: 'visible' });
      await s.showPdf(link, `GA/GRA Certification Letter (${letterRef})`, '05-certification-letter');
    });

    await s.step('Its verification link: anyone can check the letter is genuine', async () => {
      await s.goto(`/verify/certificate/${letterRef}`);
      const valid = s.page.getByText('Valid', { exact: true }).first();
      await s.caption(`Public verification of ${letterRef}: Valid`);
      await s.highlight(cardWith(s, 'Valid'), 3000);
      await valid.waitFor({ state: 'visible' });
      await s.page.screenshot({ path: path.join(config.outcomesDir, '05-verification-valid.png'), fullPage: true });
      await s.hold(1000);
    });
  });
}
