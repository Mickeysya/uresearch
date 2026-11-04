<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\FilesGaExtensions;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Feature\Nureen\Concerns\ParsesPdf;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The GA Extension Form (UTP/CGS/001-A2), generated instead of uploaded:
 * Section B-D collected on the form (profile fields read-only, account and
 * IC/passport numbers encrypted and masked, bank details behind a flag,
 * publications as JSON), Section A ticked from the documents attached,
 * Section E from approval_history, Section J filled by CGS -- and who may
 * download it. The Terms and Conditions checks are GaExtensionRulesTest.
 */
class GaExtensionFormTest extends TestCase
{
    use FilesGaExtensions;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use ParsesPdf;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: [
            'supervisor_id' => $this->supervisor->id,
            'programme' => 'MSc Information Technology',
        ]);
    }

    protected function submit(array $overrides = [], ?User $as = null): Application
    {
        $this->actingAs($as ?? $this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields($overrides) + $this->gaExtensionDocuments())
            ->assertSessionHasNoErrors();

        return Application::where('module_type', 'ga_extension')->latest('id')->firstOrFail();
    }

    protected function detail(Application $application): GaExtensionDetail
    {
        return GaExtensionDetail::where('application_id', $application->id)->sole();
    }

    protected function formData(Application $application, ?User $viewer = null): array
    {
        return app(LetterRenderer::class)->gaExtensionFormData($application, $viewer);
    }

    /* -----------------------------------------------------------------
     | Section B
     |------------------------------------------------------------------*/

    public function test_every_section_b_field_is_stored(): void
    {
        $detail = $this->detail($this->submit());

        $this->assertSame('full_time', $detail->mode_of_study);
        $this->assertSame('msc', $detail->level_of_study);
        $this->assertSame('Mar 2026 – Feb 2028', $detail->candidature_period);
        $this->assertSame('+60 12-345 6789', $detail->contact_no);
        $this->assertSame('danial.personal@gmail.test', $detail->contact_email);
        $this->assertSame('Maybank', $detail->bank_name);
        $this->assertFalse($detail->holds_visa);
        $this->assertNull($detail->visa_expiry_date);
        $this->assertNotNull($detail->declaration_accepted_at);
    }

    public function test_profile_fields_cannot_be_overridden_through_the_request(): void
    {
        $other = $this->supervisor('someone-else@test.my');

        $application = $this->submit([
            'name' => 'Somebody Else',
            'matric_no' => '99999999',
            'programme' => 'Fake Programme',
            'supervisor_id' => $other->id,
            'student_id' => '99999999',
        ]);

        $this->student->refresh();
        $this->assertSame('Ahmad Danial', $this->student->name);
        $this->assertSame('22001001', $this->student->matric_no);
        $this->assertSame('MSc Information Technology', $this->student->programme);
        $this->assertSame($this->supervisor->id, $this->student->supervisor_id);
        $this->assertSame($this->supervisor->id, $this->detail($application)->supervisor_id);

        $data = $this->formData($application, $this->student);
        $this->assertSame('Ahmad Danial', $data['student']->name);
        $this->assertSame('22001001', $data['student']->matric_no);
        $this->assertSame('MSc Information Technology', $data['student']->programme);
    }

    public function test_the_form_shows_the_profile_read_only(): void
    {
        $this->actingAs($this->student)->get(route('ga-extension.create'))
            ->assertOk()
            ->assertSee('22001001')
            ->assertSee('MSc Information Technology')
            ->assertSee('Dr. Aisyah Rahman')
            ->assertDontSee('name="programme"', false)
            ->assertDontSee('name="matric_no"', false)
            ->assertDontSee('name="supervisor_id"', false);
    }

    public function test_a_visa_holder_must_give_the_expiry_date_and_it_is_cleared_without_a_visa(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['holds_visa' => '1']) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors(['visa_expiry_date' => 'Enter your visa expiry date.']);

        $detail = $this->detail($this->submit(['holds_visa' => '1', 'visa_expiry_date' => now()->addYear()->toDateString()]));
        $this->assertTrue($detail->holds_visa);
        $this->assertSame(now()->addYear()->toDateString(), $detail->visa_expiry_date->toDateString());

        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['holds_visa' => '0', 'visa_expiry_date' => '2030-01-01']);
        $this->assertNull(GaExtensionDetail::latest('id')->first()->visa_expiry_date);
    }

    public function test_the_declaration_is_required_to_submit_but_not_to_save_a_draft(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['declaration' => null]) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors('declaration');
        $this->assertSame(0, Application::count());

        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['contact_no' => '+60 12-000 0000'])->assertSessionHasNoErrors();
        $this->assertNull(GaExtensionDetail::sole()->declaration_accepted_at);
    }

    public function test_a_backdated_extension_is_refused(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields([
                'current_end_date' => now()->subMonths(2)->endOfMonth()->toDateString(),
                'requested_new_end_date' => now()->addMonths(3)->endOfMonth()->toDateString(),
            ]) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors('current_end_date');

        $this->assertSame(0, Application::count());
    }

    /* -----------------------------------------------------------------
     | Encryption and masking
     |------------------------------------------------------------------*/

    public function test_account_and_identity_numbers_are_stored_encrypted(): void
    {
        $application = $this->submit();
        $raw = DB::table('ga_extension_details')->where('application_id', $application->id)->first();

        $this->assertStringNotContainsString('1122', $raw->bank_account_no);
        $this->assertStringNotContainsString('010203', $raw->identity_number);
        $this->assertSame('1122 3344 5566', decrypt($raw->bank_account_no, false));
        $this->assertSame('010203-08-1234', decrypt($raw->identity_number, false));
    }

    public function test_they_are_masked_everywhere_but_the_cgs_stage(): void
    {
        $application = $this->submit();

        // The supervisor's queue: masked.
        $this->actingAs($this->supervisor)->get(route('ga-extension.queue'))
            ->assertOk()
            ->assertSee('5566')->assertDontSee('1122 3344 5566')
            ->assertSee('1234')->assertDontSee('010203-08-1234');

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        // The CGS stage: in full.
        $cgs = $this->cgs();
        $this->actingAs($cgs)->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))
            ->assertOk()
            ->assertSee('1122 3344 5566')
            ->assertSee('010203-08-1234');

        // The generated form: in full for CGS, masked for the student,
        // the supervisor and the Senior Director.
        $this->assertSame('1122 3344 5566', $this->formData($application, $cgs)['account']);
        $this->assertSame('010203-08-1234', $this->formData($application, $cgs)['identity']);
        foreach ([$this->student, $this->supervisor, $this->seniorDirector()] as $viewer) {
            $data = $this->formData($application, $viewer);
            $this->assertSame(GaExtensionDetail::mask('1122 3344 5566'), $data['account']);
            $this->assertSame(GaExtensionDetail::mask('010203-08-1234'), $data['identity']);
        }
    }

    public function test_a_saved_number_is_never_shown_back_on_the_form_and_blank_keeps_it(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), [
            'bank_account_no' => '1122 3344 5566',
            'identity_number' => '010203-08-1234',
        ]);
        $draft = Application::sole();

        $this->actingAs($this->student)->get(route('ga-extension.draft.edit', $draft))
            ->assertOk()
            ->assertDontSee('1122 3344 5566')
            ->assertDontSee('010203-08-1234')
            ->assertSee('Leave blank to keep it.');

        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), ['draft_id' => $draft->id]
                + $this->gaExtensionFields(['bank_account_no' => '', 'identity_number' => ''])
                + $this->gaExtensionDocuments())
            ->assertSessionHasNoErrors();

        $detail = $this->detail($draft);
        $this->assertSame('1122 3344 5566', $detail->bank_account_no);
        $this->assertSame('010203-08-1234', $detail->identity_number);
    }

    /* -----------------------------------------------------------------
     | Bank details flag
     |------------------------------------------------------------------*/

    public function test_with_bank_details_on_they_are_asked_and_required(): void
    {
        config(['nureen.ga_extension.collect_bank_details' => true]);

        $this->actingAs($this->student)->get(route('ga-extension.create'))
            ->assertSee('name="bank_name"', false)
            ->assertSee('name="bank_account_no"', false);

        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['bank_name' => '', 'bank_account_no' => '']) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors(['bank_name', 'bank_account_no']);

        $application = $this->submit();
        $this->assertTrue($this->formData($application, $this->student)['bank']);
    }

    public function test_with_bank_details_off_they_are_hidden_ignored_and_left_off_the_form(): void
    {
        config(['nureen.ga_extension.collect_bank_details' => false]);

        $this->actingAs($this->student)->get(route('ga-extension.create'))
            ->assertOk()
            ->assertDontSee('name="bank_name"', false)
            ->assertDontSee('name="bank_account_no"', false);

        // Sent anyway, and invalid: ignored, not stored.
        $application = $this->submit(['bank_name' => str_repeat('x', 500), 'bank_account_no' => '<script>']);

        $detail = $this->detail($application);
        $this->assertNull($detail->bank_name);
        $this->assertNull($detail->bank_account_no);
        $this->assertFalse($this->formData($application, $this->cgs())['bank']);
    }

    /* -----------------------------------------------------------------
     | Section C
     |------------------------------------------------------------------*/

    public function test_publications_round_trip_as_json_and_blank_rows_are_dropped(): void
    {
        $journal = [
            'type' => 'journal', 'status' => 'published', 'authors' => 'A. Danial, A. Rahman', 'year' => '2026',
            'title' => 'Predicting attendance risk with sparse data', 'venue' => 'Journal of Educational Data Mining',
            'volume' => '18', 'pages' => '1-22', 'doi' => '10.1234/jedm.2026.001', 'issn' => '2157-2100',
            'database' => 'scopus', 'quartile' => 'Q1', 'impact_factor' => '3.21',
        ];
        $conference = ['type' => 'conference', 'status' => 'published', 'authors' => 'A. Danial', 'year' => '2025', 'title' => 'Early warning', 'venue' => 'ICEIT 2025'];

        $application = $this->submit(['publications' => [$journal, ['title' => '', 'authors' => ''], $conference]]);

        $stored = $this->detail($application)->publications;
        $this->assertCount(2, $stored);
        $this->assertEquals($journal, array_intersect_key($stored[0], $journal));
        $this->assertSame('Early warning', $stored[1]['title']);

        // Raw column is JSON, and the form data carries the rows in order.
        $raw = json_decode(DB::table('ga_extension_details')->where('application_id', $application->id)->value('publications'), true);
        $this->assertSame('Predicting attendance risk with sparse data', $raw[0]['title']);
        $rows = $this->formData($application, $this->student)['publications'];
        $this->assertSame('Early warning', $rows[1]['title']);
        $this->assertNull($rows[2]);
        $this->assertStringContainsString('A. Danial, A. Rahman (2026). Predicting attendance risk with sparse data (Published)', GaExtensionDetail::citation($rows[0]));
        $this->assertSame('Scopus, Q1', GaExtensionDetail::databaseAndQuartile($rows[0]));
    }

    public function test_a_draft_keeps_a_half_filled_publication_but_submit_needs_it_complete(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.draft.save'), ['publications' => [['title' => 'Half done']]])
            ->assertSessionHasNoErrors();
        $draft = Application::sole();
        $this->assertSame('Half done', $this->detail($draft)->publications[0]['title']);

        $this->actingAs($this->student)->get(route('ga-extension.draft.edit', $draft))->assertSee('value="Half done"', false);

        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), ['draft_id' => $draft->id] + $this->gaExtensionFields(['publications' => [['title' => 'Half done']]]) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors(['publications.0.type', 'publications.0.status', 'publications.0.authors', 'publications.0.year', 'publications.0.venue']);
    }

    public function test_a_bad_issn_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields(['publications' => [
                ['type' => 'journal', 'status' => 'accepted', 'authors' => 'A', 'year' => '2025', 'title' => 'T', 'venue' => 'V', 'issn' => 'not-an-issn'],
            ]]) + $this->gaExtensionDocuments())
            ->assertSessionHasErrors(['publications.0.issn' => 'An ISSN looks like 1234-5678.']);
    }

    /* -----------------------------------------------------------------
     | Section A
     |------------------------------------------------------------------*/

    public function test_the_checklist_ticks_follow_the_documents_and_cgs_ticks_after_cgs_passes(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-extension.store'), $this->gaExtensionFields() + $this->gaExtensionDocuments()
                + ['acceptance_proof_documents' => [UploadedFile::fake()->create('acceptance.pdf', 40, 'application/pdf')]])
            ->assertSessionHasNoErrors();
        $application = Application::sole();

        $checklist = $this->formData($application)['checklist'];
        $this->assertSame([true, true, true, true], array_column($checklist, 'student'));
        $this->assertSame([false, false, false, false], array_column($checklist, 'cgs'));
        $this->assertSame(['Proof of Journal Article Acceptance'], $checklist[3]['attached']);

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->cgs())->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame([true, true, true, true], array_column($this->formData($application)['checklist'], 'cgs'));
    }

    public function test_item_four_is_blank_without_a_stage_gate_proof(): void
    {
        $checklist = $this->formData($this->submit())['checklist'];

        $this->assertSame([true, true, true, false], array_column($checklist, 'student'));
    }

    /* -----------------------------------------------------------------
     | Section E
     |------------------------------------------------------------------*/

    public function test_section_e_on_the_cgs_chain_names_supervisor_and_cgs_and_leaves_the_director_blank(): void
    {
        config(['nureen.ga_extension.final_approver' => 'cgs']);
        $application = $this->submit();

        $e = $this->formData($application)['endorsements'];
        $this->assertNull($e['supervisor']);
        $this->assertNull($e['cgs']);

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $e = $this->formData($application)['endorsements'];
        $this->assertSame('Dr. Aisyah Rahman', $e['supervisor']['name']);
        $this->assertSame('Endorsed', $e['supervisor']['decision']);
        $this->assertNull($e['cgs']);

        $this->actingAs($this->cgs())->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $e = $this->formData($application)['endorsements'];
        $this->assertSame('M Syahmi Ifwat M Jafri', $e['cgs']['name']);
        $this->assertSame('Approved', $e['cgs']['decision']);
        $this->assertSame(now()->toDateString(), $e['cgs']['at']->toDateString());
        // Not on this chain: blank, even with a Senior Director in the system.
        $this->seniorDirector();
        $this->assertNull($e['senior_director']);
    }

    public function test_section_e_on_the_senior_director_chain_names_all_three(): void
    {
        config(['nureen.ga_extension.final_approver' => 'senior_director']);
        $application = $this->submit();

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->cgs())->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $e = $this->formData($application)['endorsements'];
        $this->assertSame('Reviewed', $e['cgs']['decision']);
        $this->assertNull($e['senior_director']);

        $this->actingAs($this->seniorDirector())->post(route('ga-extension.decide', $application), ['decision' => 'reject', 'remarks' => 'Over the maximum duration.']);

        $e = $this->formData($application)['endorsements'];
        $this->assertSame('Dr. Aisyah Rahman', $e['supervisor']['name']);
        $this->assertSame('M Syahmi Ifwat M Jafri', $e['cgs']['name']);
        $this->assertSame('En Zulkifly', $e['senior_director']['name']);
        $this->assertSame('Senior Director CGS', $e['senior_director']['role']);
        $this->assertSame('Rejected', $e['senior_director']['decision']);
        // Rejected by the director: CGS had passed it, so CGS's ticks stand.
        $this->assertTrue($this->formData($application)['checklist'][0]['cgs']);
    }

    public function test_a_return_to_the_supervisor_clears_the_endorsements_it_sent_back_over(): void
    {
        $application = $this->submit();
        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->cgs())->post(route('ga-extension.return', $application), ['remarks' => 'The admission letter is unreadable.']);

        $this->assertNull($this->formData($application)['endorsements']['supervisor']);

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);
        $this->assertSame('Dr. Aisyah Rahman', $this->formData($application)['endorsements']['supervisor']['name']);
    }

    /* -----------------------------------------------------------------
     | Section J
     |------------------------------------------------------------------*/

    public function test_cgs_fills_section_j_at_its_stage_and_it_prints_on_the_form(): void
    {
        $application = $this->submit();
        $cgs = $this->cgs();

        // Not yet at the CGS stage.
        $this->actingAs($cgs)->post(route('ga-extension.cgs-remarks', $application), ['cgs_remarks' => ['total_ta_hours' => '84']])->assertForbidden();
        // Never the supervisor.
        $this->actingAs($this->supervisor)->post(route('ga-extension.cgs-remarks', $application), ['cgs_remarks' => ['total_ta_hours' => '84']])->assertForbidden();

        $this->actingAs($this->supervisor)->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->actingAs($cgs)->get(route('ga-extension.queue', ['stage' => 'cgs_verify']))->assertSee('Save Section J');
        $this->actingAs($cgs)
            ->post(route('ga-extension.cgs-remarks', $application), ['cgs_remarks' => [
                'reporting_duty_date' => '2026-03-02', 'registration_status' => 'Registered', 'total_ta_hours' => '84', 'not_a_field' => 'x',
            ]])
            ->assertSessionHasNoErrors();

        $detail = $this->detail($application);
        $this->assertSame(['reporting_duty_date' => '2026-03-02', 'registration_status' => 'Registered', 'total_ta_hours' => '84'], array_filter($detail->cgs_remarks));
        $this->assertSame($cgs->id, $detail->cgs_remarks_by_id);

        $remarks = collect($this->formData($application)['remarks'])->pluck('value', 'label');
        $this->assertSame('2 Mar 2026', $remarks['Date of Reporting Duty']);
        $this->assertSame('84', $remarks['GA Status: b. Total TA hours']);
    }

    /* -----------------------------------------------------------------
     | Downloading the form
     |------------------------------------------------------------------*/

    protected function assertPdf($response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, $this->pdfPageCount($response->getContent(), 'GA Extension Form'));
    }

    public function test_the_owner_downloads_the_form_at_any_time_a_draft_included(): void
    {
        $this->actingAs($this->student)->post(route('ga-extension.draft.save'), ['contact_no' => '+60 12-000 0000', 'download_form' => '1'])
            ->assertSessionHas('download_form', true);
        $draft = Application::sole();

        $this->actingAs($this->student)->withSession(['download_form' => true])->get(route('ga-extension.draft.edit', $draft))
            ->assertSee('data-auto-download', false)
            ->assertSee(route('ga-extension.form-pdf', $draft), false);

        $response = $this->actingAs($this->student)->get(route('ga-extension.form-pdf', $draft));
        $this->assertPdf($response);
        $response->assertHeader('Content-Disposition', 'attachment; filename="GA_Extension_Form_22001001_'.$draft->id.'.pdf"');

        // The supervisor can't see a draft.
        $this->actingAs($this->supervisor)->get(route('ga-extension.form-pdf', $draft))->assertForbidden();

        $application = $this->submit();
        $this->assertPdf($this->actingAs($this->student)->get(route('ga-extension.form-pdf', $application)));
        $this->actingAs($this->student)->get(route('ga-extension.create'))
            ->assertSee(route('ga-extension.form-pdf', $application), false);
    }

    public function test_every_approver_on_the_chain_downloads_the_form_and_nobody_else_does(): void
    {
        config(['nureen.ga_extension.final_approver' => 'senior_director']);
        $application = $this->submit();
        $cgs = $this->cgs();
        $director = $this->seniorDirector();

        $this->assertPdf($this->actingAs($this->supervisor)->get(route('ga-extension.form-pdf', $application)));
        $this->assertPdf($this->actingAs($cgs)->get(route('ga-extension.form-pdf', $application)));
        $this->assertPdf($this->actingAs($director)->get(route('ga-extension.form-pdf', $application)));

        $this->actingAs($this->student('22009999', 'other@test.my'))->get(route('ga-extension.form-pdf', $application))->assertForbidden();
        $this->actingAs($this->supervisor('other-sup@test.my'))->get(route('ga-extension.form-pdf', $application))->assertForbidden();
        $this->actingAs($this->chair())->get(route('ga-extension.form-pdf', $application))->assertForbidden();
    }

    public function test_the_director_is_not_an_approver_on_the_cgs_chain(): void
    {
        config(['nureen.ga_extension.final_approver' => 'cgs']);
        $application = $this->submit();

        $this->actingAs($this->seniorDirector())->get(route('ga-extension.form-pdf', $application))->assertForbidden();
        $this->assertPdf($this->actingAs($this->cgs())->get(route('ga-extension.form-pdf', $application)));
    }

    public function test_the_queue_links_the_form(): void
    {
        $application = $this->submit();

        $this->actingAs($this->supervisor)->get(route('ga-extension.queue'))
            ->assertSee(route('ga-extension.form-pdf', $application), false)
            ->assertSee(route('ga-extension.form-pdf.view', $application), false)
            ->assertSee('GA Extension Form (generated)');
    }

    /* -----------------------------------------------------------------
     | Applications from before the form was generated
     |------------------------------------------------------------------*/

    public function test_an_old_application_with_an_uploaded_form_still_renders(): void
    {
        // Built the way one submitted before this change exists: the four
        // uploaded types, the old fields only, nothing from Section B-D.
        $application = Application::create([
            'student_id' => $this->student->id,
            'submitted_by_id' => $this->student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subWeek(),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $this->supervisor->id,
            'current_end_date' => now()->addMonths(2)->endOfMonth(),
            'requested_new_end_date' => now()->addMonths(8)->endOfMonth(),
            'reason_for_extension' => 'Fieldwork slipped; the analysis needs one more semester.',
            'final_approver_snapshot' => 'cgs',
        ]);
        foreach (['GA Extension Form', 'IC or Passport/Visa Copy', "Supervisor's Supporting Letter", 'Acceptance Letter or Published Paper'] as $i => $type) {
            ApplicationDocument::create([
                'application_id' => $application->id, 'doc_type' => $type, 'original_name' => "old-{$i}.pdf",
                'path' => "applications/{$application->id}/old-{$i}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 100,
            ]);
        }

        // The approver still sees the uploaded form, labelled as such, and
        // the rules it can't check say why.
        $this->actingAs($this->supervisor)->get(route('ga-extension.queue'))
            ->assertOk()
            ->assertSee('GA Extension Form')
            ->assertSee('uploaded before the form was generated')
            ->assertSee('Needs the admission date, level of study and requested end date.');

        // The generated form renders with blanks, item 4 ticked from the
        // old combined proof.
        $this->assertPdf($this->actingAs($this->supervisor)->get(route('ga-extension.form-pdf', $application)));
        $checklist = $this->formData($application)['checklist'];
        $this->assertSame([true, false, true, true], array_column($checklist, 'student'));
        $this->assertSame(['Acceptance Letter or Published Paper'], $checklist[3]['attached']);
    }

    public function test_the_ga_extension_form_is_no_longer_an_upload(): void
    {
        $this->assertArrayNotHasKey('ga_extension_form_documents', config('nureen.ga_extension.required_documents'));

        $this->actingAs($this->student)->get(route('ga-extension.create'))
            ->assertDontSee('name="ga_extension_form_documents[]"', false);
    }
}
