import { Recorder } from '../director.js';
import { config } from '../config.js';
import { demoApp, loginAt, type DemoInfo } from '../demoData.js';
import { makeUploads } from '../uploads.js';
import { addDays, addMonths, approve, openQueueRow } from './common.js';

/**
 * A student submits a GA Extension on camera (fills the stepper, saves a
 * draft, comes back, submits), then Admin -> CGS (System Checks) ->
 * Supervisor -> Senior Director approve, and the generated GA Extension
 * Form (UTP/CGS/001-A2) is opened.
 *
 * The student is the one nureen:demo-fresh gave an approved GA Application
 * and an approved first extension, so this is their second extension: it
 * starts the day after their current GA end date and the dates are chosen
 * so every System check passes.
 */
export async function run(rec: Recorder, info: DemoInfo): Promise<void> {
  const base = demoApp(info, 'ga_extension', 3);
  const student = base.student;
  if (!base.ga_service_end) throw new Error(`${student.email} has no approved GA application`);

  const level = (student.programme ?? '').toLowerCase().startsWith('phd') ? 'phd' : 'msc';
  const start = addDays(base.ga_service_end, 1);
  const newEnd = addDays(addMonths(start, 6), -1);
  // Exactly at Stage Gate Level 2 when the extension starts (MSc 12, PhD 18 months).
  const admission = addMonths(start, level === 'phd' ? -18 : -12);
  const candidacyEnd = addMonths(admission, level === 'phd' ? 96 : 48);
  const monthName = (iso: string) => new Date(iso + 'T00:00:00Z').toLocaleDateString('en-GB', { month: 'short', year: 'numeric', timeZone: 'UTC' });

  const files = await makeUploads(rec.browser, student, student.supervisor_name ?? 'Main Supervisor', ['identity', 'admission', 'supporting', 'acceptance']);
  let id = 0;
  let reference = '';

  await rec.as(`${student.name} (student)`, student.email, async (s) => {
    const form = s.page.locator('#ga-extension-form');
    const next = form.locator('[data-fstep-next]');
    const onStep = (label: string) => form.locator(`fieldset.fstep[data-label="${label}"]`);

    await s.step(`${student.name} opens the GA Extension form`, async () => {
      await s.goto('/ga-extension/new');
      await s.highlight(s.page.locator('.ga-profile'));
    });

    await s.step('Section B: study details', async () => {
      await s.choose(s.page.locator('#field-mode_of_study'), 'full_time');
      await s.choose(s.page.locator('#field-study_type'), 'research');
      await s.fillDate(s.page.locator('#field-admission_date'), admission);
      await s.type(s.page.locator('#field-candidature_period'), `${monthName(admission)} – ${monthName(candidacyEnd)}`);
      await s.click(form.locator(`input[name="level_of_study"][value="${level}"]`));
      await s.type(s.page.locator('#field-contact_no'), '+60 12-487 2210');
      await s.type(s.page.locator('#field-contact_email'), student.email);
      await s.click(next);
      await onStep('Identity & bank').waitFor({ state: 'visible' });
    });

    await s.step('Identity and bank details', async () => {
      await s.type(s.page.locator('#field-bank_name'), 'Maybank');
      await s.type(s.page.locator('#field-bank_account_no'), '1140 2271 4455');
      await s.type(s.page.locator('#field-identity_number'), `010315-08-${student.matric.slice(-4)}`);
      await s.click(form.locator('input[name="holds_visa"][value="0"]'));
      await s.click(next);
      await onStep('Extension details').waitFor({ state: 'visible' });
    });

    await s.step('The new end date and the reason', async () => {
      await s.highlight(onStep('Extension details').locator('.queue-meta').first());
      await s.fillDate(s.page.locator('#requested_new_end_date'), newEnd);
      await s.type(s.page.locator('#reason_for_extension'),
        'My second journal article is under review and I need six more months to finish the final experiments, while continuing my tutorial duties.');
      await s.hold(1000);
    });

    await s.step('Save as a draft and come back later', async () => {
      await s.click(form.locator('[data-save-draft]'), { navigates: true });
      await s.highlight(s.page.getByText(/Continuing your draft/));
      // The draft reopens on the step it was saved at; move on to Publications.
      for (let i = 0; i < 6 && !(await onStep('Publications').isVisible()); i++) {
        await s.click(next);
      }
      await onStep('Publications').waitFor({ state: 'visible' });
    });

    await s.step('Section C: the accepted journal article', async () => {
      await s.click(form.locator('[data-publication-add]'));
      const row = form.locator('[data-publication-list] [data-publication-row]').last();
      await s.choose(row.locator('select[name$="[type]"]'), 'journal');
      await s.choose(row.locator('select[name$="[status]"]'), 'accepted');
      await s.type(row.locator('input[name$="[year]"]'), String(new Date().getFullYear()));
      await s.type(row.locator('input[name$="[authors]"]'), `${student.name}, ${student.supervisor_name ?? ''}`.replace(/, $/, ''));
      await s.type(row.locator('input[name$="[title]"]'), 'Federated Learning for Privacy-Preserving Medical Image Classification');
      await s.type(row.locator('input[name$="[venue]"]'), 'IEEE Access');
      await s.choose(row.locator('select[name$="[database]"]'), 'wos');
      await s.choose(row.locator('select[name$="[quartile]"]'), 'Q2');
      await s.click(next);
      await onStep('Supporting documents').waitFor({ state: 'visible' });
    });

    await s.step('Section A: attach the supporting documents (PDF)', async () => {
      const attach = async (field: string, file: string) => {
        const input = form.locator(`input[type="file"][name="${field}[]"]`);
        const zone = input.locator('xpath=ancestor::*[contains(@class,"upload") or contains(@class,"doc")][1]');
        await s.pointAt((await zone.count()) ? zone : input.locator('xpath=..'));
        await input.setInputFiles(file);
        await s.hold(900);
      };
      await attach('identity_document_documents', files.identity);
      await attach('admission_letter_documents', files.admission);
      await attach('supervisor_letter_documents', files.supporting);
      await s.highlight(form.locator('[data-stage-gate-hint]'));
      await attach('acceptance_proof_documents', files.acceptance);
      await s.click(next);
      await onStep('Declaration').waitFor({ state: 'visible' });
    });

    await s.step('Declaration, review and submit', async () => {
      await s.click(form.locator('input[name="declaration"]'));
      await s.click(next);
      await s.hold();
      const submit = s.page.getByRole('button', { name: 'Submit Application' });
      await s.click(submit, { navigates: true });
      const message = await s.flash();
      id = Number(/#(\d+)/.exec(message)?.[1] ?? 0);
      if (!id) throw new Error(`no application number in "${message}"`);
      Recorder.created(id);
      reference = `GAEX-${new Date().getFullYear()}-${String(id).padStart(5, '0')}`;
      console.log(`    submitted GA Extension #${id}`);
    });
  });

  await rec.as('Admin officer', info.staff.admin ?? config.staff.admin, async (s) => {
    await s.step(`Admin checks the documents of #${id} and forwards it to CGS`, async () => {
      await openQueueRow(s, '/ga-extension/queue', id);
      await approve(s, id, 'All three required documents are attached and readable.');
    });
  });

  await rec.as('CGS officer (Zulkifly)', info.staff.cgs ?? config.staff.cgs, async (s) => {
    await s.step('CGS opens the application and reads the System Checks panel', async () => {
      const row = await openQueueRow(s, '/ga-extension/queue', id);
      await s.highlight(row.locator('.ga-checks').first(), 4000);
    });
    await s.step('Every check passes: CGS approves', async () => {
      await approve(s, id, 'Stage gate proof and dates verified against the student record.');
    });
  });

  await rec.as('Supervisor', loginAt(base, 'supervisor'), async (s) => {
    await s.step('The supervisor endorses the extension', async () => {
      await openQueueRow(s, '/ga-extension/queue', id);
      await approve(s, id, 'Endorsed. Good progress this semester.');
    });
  });

  await rec.as('Senior Director CGS', info.staff.director ?? config.staff.director, async (s) => {
    await s.step('The Senior Director gives the final approval', async () => {
      await openQueueRow(s, '/ga-extension/queue', id);
      await approve(s, id);
    });
  });

  await rec.as(`${student.name} (student)`, student.email, async (s) => {
    await s.step('The student opens the generated GA Extension Form', async () => {
      await s.goto('/documents');
      const search = s.page.locator('#doc-search');
      await s.type(search, reference);
      await search.press('Enter');
      await s.page.waitForLoadState('load');
      await s.settle();
      const link = s.page.locator(`a[data-preview-url*="/ga-extension/${id}/form/view"]`).first();
      await link.waitFor({ state: 'visible' });
      await s.hold(1200);
      await s.showPdf(link, 'Generated GA Extension Form (UTP/CGS/001-A2)', '01-ga-extension-form');
    });
  });
}

