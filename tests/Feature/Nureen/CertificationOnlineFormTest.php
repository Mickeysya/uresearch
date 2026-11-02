<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The GA/GRA Certification form, aligned with CGS's official online form:
 * student details from the profile (read-only) plus level, level of study
 * and mode of study; the MyKad/passport number (encrypted, masked for
 * everyone but CGS staff); a personal email; the declaration; PDF-only
 * supporting documents; the period behind
 * config('nureen.ga_certification.ask_period'); and the pre-filled
 * course-owner attachment.
 */
class CertificationOnlineFormTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
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
            'department' => 'Computer and Information Sciences',
            'programme' => 'PhD in Information Technology',
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(string $type = 'GA', array $overrides = []): array
    {
        $typeFields = $type === 'GA'
            ? ['course_code' => 'CCB2012', 'course_name' => 'Process Control', 'course_owner_name' => 'Dr. Aisyah Rahman']
            : ['grant_code' => 'YUTP-015LC0-123', 'project_title' => 'Reservoir Simulation under Uncertainty', 'project_leader_name' => 'Dr. Aisyah Rahman'];

        return $overrides + $typeFields + [
            'appointment_type' => $type,
            'study_level' => 'coursework',
            'level_of_study' => 'master',
            'mode_of_study' => 'full_time_odl',
            'identity_number' => '900101-08-1234',
            'personal_email' => 'danial.personal@gmail.test',
            'declaration' => '1',
            'purpose' => 'Scholarship application',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'semester' => 'Sept 2025',
            'supporting_documents' => [UploadedFile::fake()->create('signed-attachment.pdf', 80, 'application/pdf')],
        ];
    }

    protected function submitted(): Application
    {
        return Application::where('module_type', 'ga_certification')->sole();
    }

    public function test_a_ga_request_stores_every_online_form_field(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA'))
            ->assertSessionHasNoErrors();

        $application = $this->submitted();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('GA', $detail->appointment_type);
        $this->assertSame('coursework', $detail->study_level);
        $this->assertSame('master', $detail->level_of_study);
        $this->assertSame('full_time_odl', $detail->mode_of_study);
        $this->assertSame('900101-08-1234', $detail->identity_number);
        $this->assertSame('danial.personal@gmail.test', $detail->personal_email);
        $this->assertNotNull($detail->declaration_accepted_at);
        $this->assertSame('Master · Coursework · Full-time ODL', $detail->studySummary());
    }

    public function test_a_gra_request_stores_every_online_form_field(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GRA', ['mode_of_study' => 'part_time', 'level_of_study' => 'phd', 'study_level' => 'research']))
            ->assertSessionHasNoErrors();

        $detail = GaCertificationDetail::where('application_id', $this->submitted()->id)->sole();

        $this->assertSame('GRA', $detail->appointment_type);
        $this->assertSame('Reservoir Simulation under Uncertainty', $detail->project_title);
        $this->assertSame('PhD · Research · Part-time', $detail->studySummary());
        $this->assertNotNull($detail->declaration_accepted_at);
    }

    public function test_an_option_outside_configs_list_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', ['mode_of_study' => 'weekends']))
            ->assertSessionHasErrors('mode_of_study');

        $this->assertSame(0, Application::where('module_type', 'ga_certification')->count());
    }

    public function test_profile_fields_cannot_be_overridden_through_the_request(): void
    {
        $other = $this->supervisor('someone-else@test.my');

        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', [
                'name' => 'Somebody Else',
                'matric_no' => '99999999',
                'department' => 'Fake Department',
                'programme' => 'Fake Programme',
                'supervisor_id' => $other->id,
                'supervisor_email' => $other->email,
            ]))
            ->assertSessionHasNoErrors();

        $this->student->refresh();
        $this->assertSame('Ahmad Danial', $this->student->name);
        $this->assertSame('22001001', $this->student->matric_no);
        $this->assertSame('Computer and Information Sciences', $this->student->department);
        $this->assertSame('PhD in Information Technology', $this->student->programme);
        $this->assertSame($this->supervisor->id, $this->student->supervisor_id);

        $application = $this->submitted();
        $this->assertSame($this->supervisor->id, GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'));

        // The attachment, too, is filled from the profile.
        $attachment = app(LetterRenderer::class)->certificationAttachmentData($application);
        $this->assertSame('Ahmad Danial', $attachment['name']);
        $this->assertSame('PhD in Information Technology', $attachment['programme']);
    }

    public function test_the_form_shows_the_profile_read_only(): void
    {
        $this->actingAs($this->student)
            ->get(route('ga-certification.create'))
            ->assertOk()
            ->assertSee('22001001')
            ->assertSee('Computer and Information Sciences')
            ->assertSee('PhD in Information Technology')
            ->assertSee($this->supervisor->email)
            ->assertDontSee('name="programme"', false)
            ->assertDontSee('name="department"', false);
    }

    public function test_a_supporting_document_that_is_not_a_pdf_is_rejected(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', [
                'supporting_documents' => [UploadedFile::fake()->create('scan.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
            ]))
            ->assertSessionHasErrors('supporting_documents.0');

        $this->assertSame(0, Application::where('module_type', 'ga_certification')->count());
    }

    public function test_several_pdfs_are_accepted(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', [
                'supporting_documents' => [
                    UploadedFile::fake()->create('attachment.pdf', 40, 'application/pdf'),
                    UploadedFile::fake()->create('appointment-letter.pdf', 40, 'application/pdf'),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->submitted()->documents()->count());
    }

    public function test_the_declaration_is_required_to_submit(): void
    {
        $payload = $this->payload('GA');
        unset($payload['declaration']);

        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $payload)
            ->assertSessionHasErrors(['declaration' => 'Please tick the declaration to confirm the information is true and complete.']);

        $this->assertSame(0, Application::where('module_type', 'ga_certification')->count());
    }

    public function test_the_declaration_is_not_needed_to_save_a_draft(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.draft.save'), ['appointment_type' => 'GA', 'mode_of_study' => 'part_time'])
            ->assertSessionHasNoErrors();

        $draft = Application::where('module_type', 'ga_certification')->sole();
        $detail = GaCertificationDetail::where('application_id', $draft->id)->sole();

        $this->assertSame(Application::STATUS_DRAFT, $draft->status);
        $this->assertSame('part_time', $detail->mode_of_study);
        $this->assertNull($detail->declaration_accepted_at);
    }

    public function test_the_identity_number_is_stored_encrypted(): void
    {
        $this->actingAs($this->student)->post(route('ga-certification.store'), $this->payload('GA'));

        $raw = DB::table('ga_certification_details')->where('application_id', $this->submitted()->id)->value('identity_number');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('900101', $raw);
        $this->assertSame('900101-08-1234', decrypt($raw, false));
    }

    public function test_the_supervisor_sees_the_identity_number_masked_and_cgs_sees_it_in_full(): void
    {
        $this->actingAs($this->student)->post(route('ga-certification.store'), $this->payload('GA'));
        $application = $this->submitted();

        $this->actingAs($this->supervisor)
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('1234')
            ->assertDontSee('900101-08-1234')
            ->assertSee('danial.personal@gmail.test')
            ->assertSee('Master · Coursework · Full-time ODL');

        $this->actingAs($this->supervisor)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);
        $this->assertSame('cgs_verify', $application->fresh()->current_stage);

        $this->actingAs($this->cgs())
            ->get(route('ga-certification.queue'))
            ->assertOk()
            ->assertSee('900101-08-1234');
    }

    public function test_a_saved_identity_number_is_never_shown_back_on_the_form_and_blank_keeps_it(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.draft.save'), ['appointment_type' => 'GA', 'identity_number' => '900101-08-1234']);
        $draft = Application::where('module_type', 'ga_certification')->sole();

        $this->actingAs($this->student)
            ->get(route('ga-certification.draft.edit', $draft))
            ->assertOk()
            ->assertDontSee('900101-08-1234')
            ->assertSee('Leave blank to keep it.');

        // Submitting with the field blank keeps the number on file.
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', ['draft_id' => $draft->id, 'identity_number' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('900101-08-1234', GaCertificationDetail::where('application_id', $draft->id)->sole()->identity_number);
    }

    public function test_with_ask_period_on_the_period_is_required_and_printed_on_the_letter(): void
    {
        config(['nureen.ga_certification.ask_period' => true]);

        $this->actingAs($this->student)->get(route('ga-certification.create'))->assertSee('name="period_start"', false);

        $payload = $this->payload('GA');
        unset($payload['period_start'], $payload['period_end']);
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $payload)
            ->assertSessionHasErrors(['period_start', 'period_end']);

        $this->actingAs($this->student)->post(route('ga-certification.store'), $this->payload('GA'));
        $letter = app(LetterRenderer::class)->certificationData($this->submitted(), true);

        $this->assertArrayHasKey('Period', $letter['details']);
        $this->assertStringContainsString('from '.now()->subYear()->format('j F Y'), $letter['paragraphs'][0]);
    }

    public function test_with_ask_period_off_the_period_is_hidden_not_validated_and_not_printed(): void
    {
        config(['nureen.ga_certification.ask_period' => false]);

        $this->actingAs($this->student)
            ->get(route('ga-certification.create'))
            ->assertOk()
            ->assertDontSee('name="period_start"', false)
            ->assertDontSee('name="period_end"', false);

        // Anything sent anyway -- even an end before the start -- is ignored.
        $this->actingAs($this->student)
            ->post(route('ga-certification.store'), $this->payload('GA', ['period_start' => '2026-05-01', 'period_end' => '2020-01-01']))
            ->assertSessionHasNoErrors();

        $application = $this->submitted();
        $detail = GaCertificationDetail::where('application_id', $application->id)->sole();
        $this->assertNull($detail->period_start);
        $this->assertNull($detail->period_end);

        $letter = app(LetterRenderer::class)->certificationData($application, true);
        $this->assertArrayNotHasKey('Period', $letter['details']);
        $this->assertStringContainsString('during the Sept 2025 semester', $letter['paragraphs'][0]);
        $this->assertSame('Master, Coursework, Full-time ODL', $letter['details']['Level of Study']);

        // The approver's view doesn't trip over the missing period either.
        $this->actingAs($this->supervisor)->get(route('ga-certification.queue'))->assertOk()->assertDontSee('Period:');
    }

    public function test_the_attachment_downloads_as_a_pdf_for_the_owning_student(): void
    {
        $this->actingAs($this->student)->post(route('ga-certification.store'), $this->payload('GA'));

        $response = $this->actingAs($this->student)
            ->get(route('ga-certification.attachment', $this->submitted()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="GA_GRA_Certification_Attachment_22001001.pdf"');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_attachment_is_refused_to_anyone_but_the_owner(): void
    {
        $this->actingAs($this->student)->post(route('ga-certification.store'), $this->payload('GA'));
        $application = $this->submitted();

        $this->actingAs($this->student('22009999', 'other@test.my'))
            ->get(route('ga-certification.attachment', $application))
            ->assertForbidden();

        $this->actingAs($this->supervisor)
            ->get(route('ga-certification.attachment', $application))
            ->assertForbidden();
    }

    public function test_the_attachment_downloads_from_a_draft(): void
    {
        $this->actingAs($this->student)
            ->post(route('ga-certification.draft.save'), [
                'appointment_type' => 'GA',
                'course_code' => 'CCB2012',
                'course_name' => 'Process Control',
                'download_attachment' => '1',
            ])
            ->assertSessionHas('download_attachment', true);

        $draft = Application::where('module_type', 'ga_certification')->sole();
        $this->assertSame(Application::STATUS_DRAFT, $draft->status);

        // The edit page it lands on starts the download.
        $this->actingAs($this->student)
            ->withSession(['download_attachment' => true])
            ->get(route('ga-certification.draft.edit', $draft))
            ->assertSee(route('ga-certification.attachment', $draft), false)
            ->assertSee('data-auto-download', false);

        $response = $this->actingAs($this->student)
            ->get(route('ga-certification.attachment', $draft))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $data = app(LetterRenderer::class)->certificationAttachmentData($draft);
        $this->assertSame(['code' => 'CCB2012', 'course' => 'Process Control', 'owner' => null], $data['rows'][0]);
        $this->assertCount(5, $data['rows']);
    }
}
