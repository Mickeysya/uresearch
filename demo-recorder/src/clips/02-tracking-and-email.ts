import type { Recorder } from '../director.js';
import { demoApp, type DemoInfo } from '../demoData.js';
import { cardWith, openEmail } from './common.js';

/**
 * Track My Applications for a student whose GA Extension is with CGS:
 * the stage, who has acted so far, the next reviewer and the expected
 * decision date -- then the status emails in Mailpit.
 */
export async function run(rec: Recorder, info: DemoInfo): Promise<void> {
  const app = demoApp(info, 'ga_extension', 1);
  const student = app.student;

  await rec.as(`${student.name} (student)`, student.email, async (s) => {
    await s.step('Track My Applications: every application and its stage', async () => {
      await s.goto('/applications');
      const viewDetails = s.page.getByRole('link', { name: 'View details' });
      await s.highlight(cardWith(s, app.reference, viewDetails), 3500);
    });

    await s.step('View details: who has acted, and who reviews it next', async () => {
      const card = cardWith(s, app.reference, s.page.getByRole('link', { name: 'View details' }));
      await s.click(card.getByRole('link', { name: 'View details' }), { navigates: true });
      await s.hold();
      await s.highlight(s.page.getByText(/Verified by/).first(), 2500);
      await s.highlight(s.page.getByText(/What happens next/).locator('xpath=..'), 3000);
    });

    await s.step('Expected decision date, from the CGS Client Charter', async () => {
      await s.goto('/my-applications/expected-dates');
      await s.highlight(s.page.getByText(/expected to be done by/).first(), 3500);
    });
  });

  await rec.as('Mailpit (email inbox)', null, async (s) => {
    await s.step(`The status emails ${student.name} received`, async () => {
      await openEmail(s, student.email, /Progress Update/);
    });
  });
}
