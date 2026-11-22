import type { Recorder } from '../director.js';
import { config } from '../config.js';
import type { DemoInfo } from '../demoData.js';
import { cardWith, openEmail } from './common.js';

/**
 * The At-Risk list (one student flagged by the projection, one by the 80%
 * rule), the automatic early-warning email, and the flagged student's
 * Attendance Action card.
 */
export async function run(rec: Recorder, info: DemoInfo): Promise<void> {
  const byProjection = info.attendance.find((a) => a.reason === 'projected');
  const byRule = info.attendance.find((a) => a.reason === 'current');
  if (!byProjection || !byRule) throw new Error('nureen:demo-fresh made no projection-only or rule-only at-risk student');

  await rec.as('CGS officer (Zulkifly)', info.staff.cgs ?? config.staff.cgs, async (s) => {
    await s.step('CGS opens the At-Risk Students list', async () => {
      await s.goto('/attendance/at-risk');
      await s.highlight(s.page.locator('table').first(), 2000);
    });

    await s.step(`${byProjection.student.name}: ${byProjection.current}% now, projected ${byProjection.projected}%: flagged by the PROJECTION`, async () => {
      await s.highlight(s.page.locator(`tr[data-matric="${byProjection.student.matric}"]`), 4500);
    });

    await s.step(`${byRule.student.name}: ${byRule.current}% so far, below 80%: flagged by the RULE`, async () => {
      await s.highlight(s.page.locator(`tr[data-matric="${byRule.student.matric}"]`), 4500);
    });
  });

  await rec.as('Mailpit (email inbox)', null, async (s) => {
    await s.step(`The early-warning email sent automatically to ${byRule.student.name}`, async () => {
      await openEmail(s, byRule.student.email, /Your attendance/);
    });
  });

  await rec.as(`${byRule.student.name} (student)`, byRule.student.email, async (s) => {
    await s.step('The student sees the Attendance Action card on the dashboard', async () => {
      await s.goto('/dashboard');
      const card = cardWith(s, /Attendance Action/, s.page.getByRole('link', { name: 'View Attendance' }));
      await s.highlight(card, 4000);
      await s.highlight(s.page.getByText(/flagged as/).first().locator('xpath=..'), 3000);
    });
  });
}
