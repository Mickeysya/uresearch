import type { Recorder } from '../director.js';
import { config } from '../config.js';
import { demoApp, loginAt, type DemoInfo } from '../demoData.js';
import { approve, openQueueRow } from './common.js';

/**
 * A supervision request waiting at the supervisor goes Supervisor ->
 * Chair -> CGS endorses (which issues the letter), then the appointment
 * letter (CGS/SVAPPT/...) is opened.
 */
export async function run(rec: Recorder, info: DemoInfo): Promise<void> {
  const app = demoApp(info, 'supervision', 0);
  const name = app.student.name;

  await rec.as('Supervisor', loginAt(app, 'supervisor'), async (s) => {
    await s.step(`The supervisor reviews ${name}'s request and endorses it`, async () => {
      const row = await openQueueRow(s, '/supervision/queue', app.id);
      await s.highlight(row.locator('.doc-list, .nureen-docs').first(), 2000);
      await approve(s, app.id, 'I agree to continue as Main Supervisor.');
    });
  });

  await rec.as('Chair of Department', loginAt(app, 'chair'), async (s) => {
    await s.step('The Chair of Department approves', async () => {
      await openQueueRow(s, '/supervision/queue', app.id);
      await approve(s, app.id);
    });
  });

  await rec.as('CGS officer (Zulkifly)', loginAt(app, 'cgs_review') ?? config.staff.cgs, async (s) => {
    await s.step('CGS endorses: the appointment letter is issued', async () => {
      await openQueueRow(s, '/supervision/queue', app.id);
      await approve(s, app.id);
    });

    await s.step('The issued letter, with its CGS/SVAPPT reference', async () => {
      await s.goto('/supervision/issued');
      // The innermost block naming this student that holds a letter link:
      // the list's own container names every student, so take the deepest.
      const letter = '[data-preview-name^="Appointment letter"]';
      const entry = s.page.locator(':is(tr, li, div)', { hasText: name }).filter({ has: s.page.locator(letter) }).last();
      const link = entry.locator(letter).first();
      await s.highlight(entry, 2500);
      await link.waitFor({ state: 'visible' });
      const title = (await link.getAttribute('data-preview-name')) ?? 'Appointment letter';
      await s.showPdf(link, `Supervisor appointment letter (${title.replace('Appointment letter ', '')})`, '04-appointment-letter');
    });
  });
}
