<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\AppointmentLetterIssued;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\LetterRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * Nureen's two official letters on the shared layout, and Supervision's
 * 'endorse' chain: an official letter only ever names someone who actually
 * acted, a preview writes and sends nothing, and a revoked letter says so
 * wherever it is downloaded from.
 *
 * Section checks render the letter's HTML from the very data the PDF is
 * built from (Support\LetterRenderer::*Data()) -- Dompdf compresses its
 * text streams, so the HTML is the honest thing to assert on. The PDFs
 * themselves are still generated and checked for being PDFs, for size, and
 * for changing on revocation.
 */
class LettersTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $supervisor;

    protected User $chair;

    protected User $cgs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        LetterRenderer::forgetLogo();

        $this->supervisor = $this->supervisor();
        $this->chair = $this->chair();
        $this->cgs = $this->cgs();
    }

    protected function supervisionRequest(string $mode, string $role = SupervisionDetail::ROLE_MAIN, string $matric = '22001001'): Application
    {
        config(['nureen.supervision.cgs_stage' => $mode]);

        $student = $this->student($matric, "s{$matric}@test.my", [
            'programme' => 'Doctor of Philosophy (Chemical Engineering)',
            'department' => $this->chair->department,
            'supervisor_id' => $role === SupervisionDetail::ROLE_CO ? $this->supervisor('main-'.$matric.'@test.my')->id : null,
        ]);

        $this->actingAs($student)->post(route('supervision.store'), [
            'requested_supervisor_id' => $this->supervisor->id,
            'appointment_role' => $role,
            'appointment_type' => 'new',
            'justification' => 'The proposed research sits squarely within the supervisor\'s current grant.',
            'supporting_documents' => [\Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        return Application::where('student_id', $student->id)->where('module_type', 'supervision')->sole();
    }

    protected function throughChair(Application $application): Application
    {
        $this->actingAs($this->supervisor)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->actingAs($this->chair)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        return $application->fresh();
    }

    /** @param  array<string, mixed>  $data */
    protected function html(string $view, array $data): string
    {
        return view($view, $data + ['qrDataUri' => null])->render();
    }

    protected function letterDocuments(Application $application, string $type): \Illuminate\Support\Collection
    {
        return ApplicationDocument::where('application_id', $application->id)->where('doc_type', $type)->get();
    }

    /* -----------------------------------------------------------------
     | The 'endorse' chain
     |------------------------------------------------------------------*/

    public function test_endorse_is_the_default_and_no_letter_exists_until_cgs_endorses(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest(config('nureen.supervision.cgs_stage')));
        $detail = SupervisionDetail::where('application_id', $application->id)->sole();

        $this->assertSame('endorse', $detail->cgs_stage_snapshot);
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_review', $application->current_stage);
        $this->assertNull($detail->letter_reference);
        $this->assertCount(0, $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE));
        Notification::assertNotSentTo($application->student, AppointmentLetterIssued::class);
    }

    public function test_endorsing_issues_the_letter_in_the_endorsers_name(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest('endorse'));

        $this->actingAs($this->cgs)
            ->get(route('supervision.queue', ['stage' => 'cgs_review']))
            ->assertSee('Preview letter')
            ->assertSee('Endorse &amp; issue letter', false);

        $this->actingAs($this->cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $detail = SupervisionDetail::where('application_id', $application->id)->sole();

        $this->assertSame(Application::STATUS_APPROVED, $application->status);
        $this->assertStringStartsWith('CGS/SVAPPT/', $detail->letter_reference);
        $this->assertSame($this->supervisor->id, $application->student->fresh()->supervisor_id);

        $stored = $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE)->sole();
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($stored->path));

        $data = app(LetterRenderer::class)->appointmentData($application);
        $this->assertSame($this->cgs->name, $data['signatory']['name']);
        $this->assertSame('Non-Executive', $data['signatory']['title']);

        $html = $this->html('nureen::letters.appointment', $data);
        $this->assertStringContainsString('Digitally endorsed via UResearch 2.0 on', $html);
        $this->assertStringContainsString('following the endorsement of the Centre for Graduate Studies, your Main Supervisor has been appointed', $html);

        // Emailed to the student with the supervisor copied in.
        Notification::assertSentTo($application->student, AppointmentLetterIssued::class,
            fn ($n, $channels, $notifiable) => collect($n->toMail($notifiable)->cc)->flatten()->contains($this->supervisor->email)
                && count($n->toMail($notifiable)->rawAttachments) === 1);
    }

    public function test_rejecting_at_the_cgs_stage_issues_nothing_and_needs_a_reason(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest('endorse'));

        $this->actingAs($this->cgs)
            ->post(route('supervision.decide', $application), ['decision' => 'reject'])
            ->assertSessionHasErrors('remarks');

        $this->actingAs($this->cgs)->post(route('supervision.decide', $application), [
            'decision' => 'reject',
            'remarks' => 'The proposed supervisor already holds the maximum number of students.',
        ]);

        $this->assertSame(Application::STATUS_REJECTED, $application->fresh()->status);
        $this->assertNull(SupervisionDetail::where('application_id', $application->id)->sole()->letter_reference);
        $this->assertCount(0, $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE));
        Notification::assertNotSentTo($application->student, AppointmentLetterIssued::class);
    }

    public function test_the_preview_never_assigns_a_reference_stores_a_file_or_emails_anyone(): void
    {
        $application = $this->throughChair($this->supervisionRequest('endorse'));
        Notification::fake();

        $response = $this->actingAs($this->cgs)->get(route('supervision.letter.preview', $application));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->assertNull(SupervisionDetail::where('application_id', $application->id)->sole()->letter_reference);
        $this->assertCount(0, $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE));
        $this->assertSame('cgs_review', $application->fresh()->current_stage);
        Notification::assertNothingSent();

        $html = $this->html('nureen::letters.appointment', app(LetterRenderer::class)->appointmentData($application, draft: true, previewer: $this->cgs));
        $this->assertStringContainsString('<div class="watermark draft">DRAFT</div>', $html);
        $this->assertStringContainsString('To be assigned on issue', $html);
        $this->assertStringContainsString($this->cgs->name, $html);
    }

    public function test_only_cgs_can_preview_and_only_at_the_cgs_stage(): void
    {
        $application = $this->supervisionRequest('endorse');

        // Still at the supervisor stage: nothing to preview yet.
        $this->actingAs($this->cgs)->get(route('supervision.letter.preview', $application))->assertNotFound();

        $this->throughChair($application);
        $this->actingAs($this->chair)->get(route('supervision.letter.preview', $application))->assertForbidden();
        $this->actingAs($application->student)->get(route('supervision.letter.preview', $application))->assertForbidden();
    }

    /* -----------------------------------------------------------------
     | 'automatic' mode never names a CGS endorser
     |------------------------------------------------------------------*/

    public function test_automatic_mode_never_names_a_cgs_endorser(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest('automatic'));
        $this->assertSame(Application::STATUS_APPROVED, $application->status);

        $data = app(LetterRenderer::class)->appointmentData($application);
        $this->assertNull($data['signatory']);
        $this->assertSame($this->chair->id, $data['issuedFollowing']->id);

        $html = $this->html('nureen::letters.appointment', $data);
        $this->assertStringNotContainsString($this->cgs->name, $html);
        $this->assertStringNotContainsString('Digitally endorsed', $html);
        $this->assertStringNotContainsString('endorsement of the Centre', $html);
        $this->assertStringContainsString("following approval by the Chair of Department, {$this->chair->name}", $html);
        $this->assertStringContainsString("following approval by {$this->chair->name}, Chair of Department", $html);

        $detail = SupervisionDetail::where('application_id', $application->id)->sole();
        $this->get(route('appointment.verify', $detail->letter_reference))
            ->assertOk()
            ->assertSee('Issued By')
            ->assertSee("following approval by {$this->chair->name}, Chair of Department")
            ->assertDontSee('Endorsed By');
    }

    /* -----------------------------------------------------------------
     | Certification: names the Senior Director who approved
     |------------------------------------------------------------------*/

    protected function certificationAtDirector(string $type = 'GA'): Application
    {
        $student = $this->student('22002002', 'cert@test.my', ['programme' => 'Master of Science (Petroleum Engineering)', 'department' => 'Petroleum Engineering']);
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'senior_director',
            'submitted_at' => now()->subDays(3),
        ]);
        GaCertificationDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $this->supervisor->id,
            'appointment_type' => $type,
            'period_start' => '2026-01-05',
            'period_end' => '2026-05-29',
            'purpose' => 'Scholarship renewal',
            'semester' => 'January 2026',
        ] + ($type === 'GA'
            ? ['course_code' => 'CDB1023', 'course_name' => 'Thermodynamics', 'course_owner_name' => 'Dr. Lim Wei Chun']
            : ['project_title' => 'Carbon Capture in Depleted Reservoirs', 'project_leader_name' => 'Dr. Aisyah Rahman', 'grant_code' => 'YUTP-015LC0-123']));

        return $application;
    }

    public function test_the_certification_letter_names_the_senior_director_who_approved(): void
    {
        Notification::fake();

        $this->user(Role::SENIOR_DIRECTOR_CGS, ['name' => 'Prof. Other Director', 'email' => 'other-director@test.my']);
        $approver = $this->user(Role::SENIOR_DIRECTOR_CGS, ['name' => 'Prof. Approving Director', 'email' => 'approving-director@test.my']);
        $application = $this->certificationAtDirector();

        $this->actingAs($approver)
            ->get(route('ga-certification.queue', ['stage' => 'senior_director']))
            ->assertSee('Preview letter');

        $this->actingAs($approver)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $data = app(LetterRenderer::class)->certificationData($application->fresh());
        $this->assertSame('Prof. Approving Director', $data['signatory']['name']);
        $this->assertSame('Senior Director', $data['signatory']['title']);

        $html = $this->html('nureen::letters.certification', $data);
        $this->assertStringContainsString('Prof. Approving Director', $html);
        $this->assertStringNotContainsString('Prof. Other Director', $html);
        // Building the mail is what catches a broken attachment call.
        Notification::assertSentTo($application->student, CertificationIssued::class,
            fn ($n, $channels, $notifiable) => count($n->toMail($notifiable)->rawAttachments) === 1);

        $reference = GaCertificationDetail::where('application_id', $application->id)->value('reference_number');
        $this->get(route('certificate.verify', $reference))->assertSee('Prof. Approving Director');
    }

    public function test_the_certification_preview_writes_and_sends_nothing(): void
    {
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $application = $this->certificationAtDirector('GRA');
        Notification::fake();

        $this->actingAs($director)
            ->get(route('ga-certification.letter.preview', $application))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertNull(GaCertificationDetail::where('application_id', $application->id)->value('reference_number'));
        $this->assertSame(Application::STATUS_PENDING, $application->fresh()->status);
        $this->assertCount(0, $this->letterDocuments($application, LetterRenderer::CERTIFICATION_DOC_TYPE));
        Notification::assertNothingSent();

        $this->actingAs($this->cgs)->get(route('ga-certification.letter.preview', $application))->assertForbidden();
    }

    /* -----------------------------------------------------------------
     | The layout: every section, with and without a logo, REVOKED
     |------------------------------------------------------------------*/

    public function test_both_letters_contain_every_section(): void
    {
        Notification::fake();

        $appointment = $this->throughChair($this->supervisionRequest('endorse'));
        $this->actingAs($this->cgs)->post(route('supervision.decide', $appointment), ['decision' => 'approve']);
        $reference = SupervisionDetail::where('application_id', $appointment->id)->value('letter_reference');

        $html = $this->html('nureen::letters.appointment', app(LetterRenderer::class)->appointmentData($appointment->fresh()));

        foreach ([
            'Universiti Teknologi PETRONAS', 'Centre for Graduate Studies (CGS)',
            '32610 Seri Iskandar, Perak Darul Ridzuan, Malaysia', 'Tel:', 'Email:',
            'Our Ref:', $reference, 'Date:',
            'Ahmad Danial', 'Matric No.: 22001001', 'Doctor of Philosophy (Chemical Engineering)',
            'Dear Ahmad Danial,', 'APPOINTMENT OF MAIN SUPERVISOR', '<ol class="paragraphs">',
            'Supervisor', 'Role', 'Appointment Type', 'Effective Date', 'New appointment',
            'Thank you.', 'Yours sincerely,', $this->cgs->name, 'Centre for Graduate Studies',
            'Digitally endorsed via UResearch 2.0 on',
            'cc:', "{$this->supervisor->name}, Main Supervisor", "{$this->chair->name}, Chair of Department",
            'Verify this letter at '.route('appointment.verify', $reference),
            'This is a computer-generated letter and does not require a physical signature.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "Appointment letter is missing: {$expected}");
        }

        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $certification = $this->certificationAtDirector('GRA');
        $this->actingAs($director)->post(route('ga-certification.decide', $certification), ['decision' => 'approve']);
        $certReference = GaCertificationDetail::where('application_id', $certification->id)->value('reference_number');

        $html = $this->html('nureen::letters.certification', app(LetterRenderer::class)->certificationData($certification->fresh()));

        foreach ([
            'Our Ref:', $certReference, 'Dear Ahmad Danial,', 'CERTIFICATION OF GRADUATE RESEARCH ASSISTANT',
            'Research Project', 'Carbon Capture in Depleted Reservoirs', 'Project Leader', 'Grant/Project Code', 'Semester', 'January 2026',
            'Scholarship renewal', 'Thank you.', 'Yours sincerely,', $director->name, 'Digitally endorsed via UResearch 2.0 on',
            'cc:', "{$this->supervisor->name}, Supervisor",
            'Verify this letter at '.route('certificate.verify', $certReference),
            'This is a computer-generated letter and does not require a physical signature.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "Certification letter is missing: {$expected}");
        }
    }

    public function test_a_ga_certification_shows_the_course_fields(): void
    {
        Notification::fake();
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $application = $this->certificationAtDirector('GA');
        $this->actingAs($director)->post(route('ga-certification.decide', $application), ['decision' => 'approve']);

        $html = $this->html('nureen::letters.certification', app(LetterRenderer::class)->certificationData($application->fresh()));

        foreach (['CERTIFICATION OF GRADUATE ASSISTANT', 'Course Code', 'CDB1023', 'Course Name', 'Thermodynamics', 'Course Owner'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('Research Project', $html);
    }

    public function test_letters_render_with_and_without_the_logo_file(): void
    {
        Notification::fake();
        $application = $this->throughChair($this->supervisionRequest('automatic'));
        $letters = app(LetterRenderer::class);

        $withLogo = $letters->appointmentData($application);
        $this->assertStringStartsWith('data:image/png;base64,', $withLogo['logo']);
        $this->assertStringContainsString('class="logo"', $this->html('nureen::letters.appointment', $withLogo));
        $this->assertStringStartsWith('%PDF', $letters->appointment($application));

        config(['nureen.letters.logo_path' => storage_path('app/does-not-exist.png')]);
        LetterRenderer::forgetLogo();

        $withoutLogo = $letters->appointmentData($application);
        $this->assertNull($withoutLogo['logo']);
        $html = $this->html('nureen::letters.appointment', $withoutLogo);
        $this->assertStringNotContainsString('class="logo"', $html);
        $this->assertStringContainsString('Universiti Teknologi PETRONAS', $html);
        $this->assertStringStartsWith('%PDF', $letters->appointment($application));
    }

    public function test_letters_stay_small_with_font_subsetting(): void
    {
        Notification::fake();
        $application = $this->throughChair($this->supervisionRequest('automatic'));

        // Before this change each letter embedded the whole DejaVu font: ~880 KB.
        $this->assertLessThan(150 * 1024, strlen(app(LetterRenderer::class)->appointment($application)));
    }

    public function test_both_letters_fit_on_one_a4_page(): void
    {
        Notification::fake();
        $pages = fn (string $pdf) => preg_match_all('#/Type\s*/Page[^s]#', $pdf);

        // A Co-Supervisor letter (the longer body) for a student with a long programme name.
        $application = $this->throughChair($this->supervisionRequest('endorse', SupervisionDetail::ROLE_CO));
        $application->student->update(['programme' => 'Doctor of Philosophy (Computer and Information Sciences)', 'department' => 'Computer and Information Sciences']);
        $this->actingAs($this->cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $this->assertSame(1, $pages(app(LetterRenderer::class)->appointment($application->fresh())));

        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $certification = $this->certificationAtDirector('GRA');
        $this->actingAs($director)->post(route('ga-certification.decide', $certification), ['decision' => 'approve']);
        $this->assertSame(1, $pages(app(LetterRenderer::class)->certification($certification->fresh())));
    }

    public function test_a_revoked_letter_carries_a_revoked_watermark_wherever_it_is_downloaded(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest('automatic', SupervisionDetail::ROLE_CO));
        $stored = $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE)->sole();
        $before = Storage::disk('local')->get($stored->path);
        $this->assertStringNotContainsString('<div class="watermark revoked">', $this->html('nureen::letters.appointment', app(LetterRenderer::class)->appointmentData($application)));

        $this->actingAs($this->cgs)->post(route('supervision.revoke', $application), ['revoked_reason' => 'Issued in error.']);

        $this->assertStringContainsString('<div class="watermark revoked">REVOKED</div>', $this->html('nureen::letters.appointment', app(LetterRenderer::class)->appointmentData($application)));

        // Same document row (every existing link still works), new content.
        $stored->refresh();
        $this->assertSame(1, $this->letterDocuments($application, LetterRenderer::APPOINTMENT_DOC_TYPE)->count());
        $this->assertNotSame($before, Storage::disk('local')->get($stored->path));
        $this->assertSame(strlen(Storage::disk('local')->get($stored->path)), $stored->size_bytes);

        $reference = SupervisionDetail::where('application_id', $application->id)->value('letter_reference');
        $this->get(route('appointment.verify', $reference))->assertSee('Revoked');

        // And the certification letter.
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);
        $certification = $this->certificationAtDirector();
        $this->actingAs($director)->post(route('ga-certification.decide', $certification), ['decision' => 'approve']);
        $this->actingAs($this->cgs)->post(route('ga-certification.revoke', $certification), ['revoked_reason' => 'Wrong semester.']);

        $this->assertStringContainsString('<div class="watermark revoked">REVOKED</div>', $this->html('nureen::letters.certification', app(LetterRenderer::class)->certificationData($certification->fresh())));
    }

    public function test_the_appointment_letter_is_publicly_verifiable(): void
    {
        Notification::fake();

        $application = $this->throughChair($this->supervisionRequest('endorse'));
        $this->actingAs($this->cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);
        $reference = SupervisionDetail::where('application_id', $application->id)->value('letter_reference');

        auth()->logout();

        $this->get(route('appointment.verify', $reference))
            ->assertOk()
            ->assertSee('Valid')
            ->assertSee($reference)
            ->assertSee('Ahmad Danial')
            ->assertSee('Main Supervisor Appointment Letter')
            ->assertSee($this->cgs->name)
            ->assertDontSee('22001001');

        $this->get(route('appointment.verify', 'CGS/SVAPPT/1999/9999'))->assertSee('No appointment letter was found');
    }

    /* -----------------------------------------------------------------
     | In-flight applications under every older snapshot still complete
     |------------------------------------------------------------------*/

    public function test_in_flight_applications_under_every_snapshot_still_complete(): void
    {
        Notification::fake();

        // Each submitted under one mode, then the default changes beneath it.
        $automatic = $this->supervisionRequest('automatic', matric: '22003001');
        $manual = $this->supervisionRequest('manual', matric: '22003002');
        $endorse = $this->supervisionRequest('endorse', matric: '22003003');
        config(['nureen.supervision.cgs_stage' => 'endorse']);

        $this->assertSame(Application::STATUS_APPROVED, $this->throughChair($automatic)->status);

        foreach ([$manual, $endorse] as $application) {
            $this->assertSame('cgs_review', $this->throughChair($application)->current_stage);
            $this->actingAs($this->cgs)->post(route('supervision.decide', $application), ['decision' => 'approve']);
            $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
        }

        foreach ([$automatic, $manual, $endorse] as $application) {
            $this->assertNotNull(SupervisionDetail::where('application_id', $application->id)->value('letter_reference'));
        }

        // 'manual' names the CGS user who approved; 'automatic' names none.
        $this->assertSame($this->cgs->name, app(LetterRenderer::class)->appointmentData($manual->fresh())['signatory']['name']);
        $this->assertNull(app(LetterRenderer::class)->appointmentData($automatic->fresh())['signatory']);

        // A certification submitted before its supervisor stage existed
        // (no supervisor_id), already at cgs_verify, still completes.
        $old = $this->certificationAtDirector();
        GaCertificationDetail::where('application_id', $old->id)->update(['supervisor_id' => null]);
        $old->forceFill(['current_stage' => 'cgs_verify'])->save();
        $this->actingAs($this->cgs)->post(route('ga-certification.decide', $old), ['decision' => 'approve']);
        $this->actingAs($this->user(Role::SENIOR_DIRECTOR_CGS))->post(route('ga-certification.decide', $old), ['decision' => 'approve']);
        $this->assertSame(Application::STATUS_APPROVED, $old->fresh()->status);
        $this->assertNotNull(GaCertificationDetail::where('application_id', $old->id)->value('reference_number'));
    }

    /* -----------------------------------------------------------------
     | Client Charter on the 'endorse' chain
     |------------------------------------------------------------------*/

    public function test_the_client_charter_handles_the_endorse_chain(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-02 09:00')); // Monday

        $application = $this->supervisionRequest('endorse');

        // Supervisor 3 + Chair 3 + CGS 0: Mon -> Thu 5 -> Tue 10.
        $expected = app(ClientCharter::class)->expectedDecision($application);
        $this->assertSame('2026-11-10', $expected['expected_date']->format('Y-m-d'));
        $this->assertSame(6, $expected['total_charter_days']);

        $this->throughChair($application);

        // At cgs_review (0 days, same working day) since Monday: not overdue
        // on Monday, overdue -- and CGS reminded -- on Tuesday.
        $this->assertFalse(app(ClientCharter::class)->stageClock($application->fresh())['overdue']);
        $this->artisan('supervision:remind-stalled');
        Notification::assertNotSentTo($this->cgs, SupervisionRequestStalled::class);

        $this->travelTo(Carbon::parse('2026-11-03 08:00'));
        $this->artisan('supervision:remind-stalled');
        Notification::assertSentTo($this->cgs, SupervisionRequestStalled::class);
    }
}
