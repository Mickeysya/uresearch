<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\ApplicationAwaitingYou;
use App\Modules\Nureen\Notifications\ApplicationStalled;
use App\Modules\Nureen\Notifications\AppointmentLetterIssued;
use App\Modules\Nureen\Notifications\AppointmentLetterIssuedToCgs;
use App\Modules\Nureen\Notifications\AppointmentLetterRevoked;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Notifications\CertificationIssued;
use App\Modules\Nureen\Notifications\CertificationRevoked;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionCc;
use App\Modules\Nureen\Notifications\GaExtensionFinalDecisionInformational;
use App\Modules\Nureen\Notifications\SupervisionEscalatedToCgs;
use App\Modules\Nureen\Notifications\SupervisionFinalDecisionCc;
use App\Modules\Nureen\Notifications\SupervisionRequestStalled;
use App\Modules\Nureen\Support\LetterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Every email this module sends links to a page its recipient can actually
 * open: each test builds the real message for the real recipient and
 * follows the button as that person, expecting 200.
 *
 * Fixed on 3 Nov 2026: the supervisor's outcome copies and the revocation
 * email linked staff to applications.index (students-only, 403), and the
 * Senior Director's GA Extension information email to a queue with nothing
 * for them. Staff now land on nureen.application, the read-only staff view.
 */
class EmailLinksTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected User $cgs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->supervisor = $this->supervisor('aisyah@test.my');
        $this->student = $this->student('22001001', 'student@test.my', ['supervisor_id' => $this->supervisor->id, 'department' => 'Computing']);
        $this->cgs = $this->cgs();
    }

    /** Follow the email's button as its recipient. */
    protected function assertLinkOpens(object $notification, User $recipient): void
    {
        $url = $notification->toMail($recipient)->actionUrl;

        $this->assertNotNull($url, class_basename($notification).' has no button for '.$recipient->email);
        $this->actingAs($recipient)->get($url)->assertOk();
    }

    protected function application(string $module, string $status, string $stage): Application
    {
        return Application::create([
            'student_id' => $this->student->id, 'submitted_by_id' => $this->student->id, 'module_type' => $module,
            'status' => $status, 'current_stage' => $stage, 'submitted_at' => now()->subDays(10),
        ]);
    }

    protected function decided(Application $application, User $by, string $stage, string $decision): void
    {
        ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $by->id, 'stage_key' => $stage, 'stage_label' => $stage, 'decision' => $decision]);
    }

    protected function gaExtension(string $status, string $stage, string $mode = 'cgs'): Application
    {
        $application = $this->application('ga_extension', $status, $stage);
        GaExtensionDetail::create(['application_id' => $application->id, 'supervisor_id' => $this->supervisor->id, 'current_end_date' => '2026-12-01', 'requested_new_end_date' => '2027-06-01', 'reason_for_extension' => 'Test fixture.', 'final_approver_snapshot' => $mode]);

        return $application;
    }

    protected function certification(string $status, string $stage, ?string $reference = null): array
    {
        $application = $this->application('ga_certification', $status, $stage);
        $detail = GaCertificationDetail::create(['application_id' => $application->id, 'supervisor_id' => $this->supervisor->id, 'appointment_type' => 'GA', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'purpose' => 'x', 'course_code' => 'C1', 'course_name' => 'Course', 'course_owner_name' => 'Dr. X', 'semester' => 'Jan 2026', 'reference_number' => $reference]);

        return [$application, $detail];
    }

    protected function supervision(string $status, string $stage, ?string $reference = null): array
    {
        $application = $this->application('supervision', $status, $stage);
        $detail = SupervisionDetail::create(['application_id' => $application->id, 'requested_supervisor_id' => $this->supervisor->id, 'justification' => 'x', 'appointment_role' => SupervisionDetail::ROLE_CO, 'appointment_type' => 'new', 'cgs_stage_snapshot' => 'endorse', 'letter_reference' => $reference, 'letter_issued_at' => $reference ? now() : null]);

        return [$application, $detail];
    }

    protected function letter(Application $application, string $type): ApplicationDocument
    {
        Storage::disk('local')->put("letters/{$application->id}.pdf", '%PDF-1.4 test');

        return ApplicationDocument::create(['application_id' => $application->id, 'doc_type' => $type, 'original_name' => 'letter.pdf', 'path' => "letters/{$application->id}.pdf", 'mime_type' => 'application/pdf']);
    }

    protected function stage(Application $application): \App\Modules\Core\Support\Stage
    {
        return app(WorkflowEngine::class)->currentStage($application)
            ?? collect(app(WorkflowEngine::class)->stagesFor($application))->last();
    }

    /* ---- to reviewers: their queue ---- */

    public function test_application_awaiting_you_opens_the_reviewers_queue(): void
    {
        $ga = $this->gaExtension(Application::STATUS_PENDING, 'supervisor');
        $this->assertLinkOpens(new ApplicationAwaitingYou($ga, 'GA Extension', $this->stage($ga), route('ga-extension.queue')), $this->supervisor);

        $appeal = $this->application('attendance_appeal', Application::STATUS_PENDING, 'cgs_review');
        $this->assertLinkOpens(new ApplicationAwaitingYou($appeal, 'Attendance Appeal', $this->stage($appeal), route('attendance-appeal.queue')), $this->cgs);
    }

    public function test_application_stalled_opens_the_reviewers_queue(): void
    {
        $ga = $this->gaExtension(Application::STATUS_PENDING, 'supervisor');
        $this->assertLinkOpens(new ApplicationStalled($ga, $this->stage($ga), 5, 3), $this->supervisor);

        [$cert] = $this->certification(Application::STATUS_PENDING, 'cgs_verify');
        $this->assertLinkOpens(new ApplicationStalled($cert, $this->stage($cert), 4, 2), $this->cgs);
    }

    public function test_supervision_request_stalled_opens_the_supervisors_queue(): void
    {
        [$application] = $this->supervision(Application::STATUS_PENDING, 'supervisor');
        $this->assertLinkOpens(new SupervisionRequestStalled($application, $this->stage($application), 5, 3), $this->supervisor);
    }

    public function test_supervision_escalated_to_cgs_opens_the_cgs_queue(): void
    {
        [$application] = $this->supervision(Application::STATUS_PENDING, 'supervisor');
        $this->assertLinkOpens(new SupervisionEscalatedToCgs($application, $this->supervisor->name, 2), $this->cgs);
    }

    public function test_appointment_letter_issued_to_cgs_opens_the_issued_letters_list(): void
    {
        [$application, $detail] = $this->supervision(Application::STATUS_APPROVED, 'cgs_review', 'CGS/SVAPPT/2026/0001');
        $this->assertLinkOpens(new AppointmentLetterIssuedToCgs($application, $detail), $this->cgs);
    }

    /* ---- to students: their own details page ---- */

    public function test_letter_issued_emails_open_the_students_details_page(): void
    {
        [$sv, $svDetail] = $this->supervision(Application::STATUS_APPROVED, 'cgs_review', 'CGS/SVAPPT/2026/0001');
        $letter = $this->letter($sv, LetterRenderer::APPOINTMENT_DOC_TYPE);
        $this->assertLinkOpens(new AppointmentLetterIssued($sv, $svDetail, $letter, $this->supervisor), $this->student);

        // The appointed supervisor gets their own copy (no longer a CC on the
        // student's message), linking to the staff view.
        $copy = (new AppointmentLetterIssued($sv, $svDetail, $letter, $this->supervisor))->toMail($this->supervisor);
        $this->assertSame([], $copy->cc);
        $this->assertLinkOpens(new AppointmentLetterIssued($sv, $svDetail, $letter, $this->supervisor), $this->supervisor);

        [$cert] = $this->certification(Application::STATUS_APPROVED, 'senior_director', 'CGS/GACERT/2026/0001');
        $this->assertLinkOpens(new CertificationIssued($cert, $this->letter($cert, LetterRenderer::CERTIFICATION_DOC_TYPE)), $this->student);
    }

    public function test_the_attendance_warning_opens_the_appeal_form_for_the_student_and_has_no_button_for_the_supervisor(): void
    {
        $this->assertLinkOpens(new AttendanceAtRisk($this->student, AttendanceRiskState::REASON_CURRENT, 70.0), $this->student);
        $this->assertNull((new AttendanceAtRisk($this->student, AttendanceRiskState::REASON_CURRENT, 70.0))->toMail($this->supervisor)->actionUrl);
    }

    /* ---- the fixed ones: staff copies open the staff view ---- */

    public function test_the_supervisors_ga_extension_outcome_copy_opens_the_application(): void
    {
        $ga = $this->gaExtension(Application::STATUS_APPROVED, 'cgs_verify');
        $this->decided($ga, $this->supervisor, 'supervisor', 'endorsed');
        $this->decided($ga, $this->cgs, 'cgs_verify', 'approved');

        $this->assertLinkOpens(new GaExtensionFinalDecisionCc($ga, $this->stage($ga), true), $this->supervisor);
    }

    public function test_the_senior_directors_ga_extension_information_email_opens_the_application(): void
    {
        $ga = $this->gaExtension(Application::STATUS_APPROVED, 'cgs_verify', 'cgs');
        $this->decided($ga, $this->cgs, 'cgs_verify', 'approved');
        $director = $this->user(Role::SENIOR_DIRECTOR_CGS);

        $this->assertLinkOpens(new GaExtensionFinalDecisionInformational($ga, $this->stage($ga), true), $director);
        $this->actingAs($director)->get(route('nureen.application', $ga))->assertSee($ga->reference())->assertSee('Approved');
    }

    public function test_the_supervisors_supervision_outcome_copy_opens_the_application(): void
    {
        [$application] = $this->supervision(Application::STATUS_APPROVED, 'cgs_review');
        $this->decided($application, $this->supervisor, 'supervisor', 'endorsed');

        $this->assertLinkOpens(new SupervisionFinalDecisionCc($application, $this->stage($application), true), $this->supervisor);
    }

    public function test_revocation_emails_open_a_page_for_both_the_student_and_the_supervisor(): void
    {
        [$sv, $svDetail] = $this->supervision(Application::STATUS_APPROVED, 'cgs_review', 'CGS/SVAPPT/2026/0002');
        $svDetail->update(['letter_revoked_at' => now(), 'letter_revoked_reason' => 'Wrong programme.']);
        [$cert, $certDetail] = $this->certification(Application::STATUS_APPROVED, 'senior_director', 'CGS/GACERT/2026/0002');
        $certDetail->update(['revoked_at' => now(), 'revoked_reason' => 'Wrong period.']);

        foreach ([$this->student, $this->supervisor] as $person) {
            $this->assertLinkOpens(new AppointmentLetterRevoked($svDetail), $person);
            $this->assertLinkOpens(new CertificationRevoked($certDetail), $person);
        }
    }

    /* ---- the staff view is not a back door ---- */

    public function test_the_staff_view_is_only_for_staff_with_a_part_in_the_application(): void
    {
        $ga = $this->gaExtension(Application::STATUS_PENDING, 'supervisor');
        $url = route('nureen.application', $ga);

        $this->actingAs($this->supervisor)->get($url)->assertOk();          // named supervisor
        $this->actingAs($this->cgs)->get($url)->assertOk();                 // CGS owns a stage of its chain
        $this->actingAs($this->supervisor('other@test.my'))->get($url)->assertForbidden();   // another supervisor
        $this->actingAs($this->student)->get($url)->assertForbidden();      // students use applications.show

        [$sv] = $this->supervision(Application::STATUS_PENDING, 'chair');
        $chair = $this->user(Role::CHAIR, ['email' => 'chair-cs@test.my', 'department' => 'Computing']);
        $otherChair = $this->user(Role::CHAIR, ['email' => 'chair-ee@test.my', 'department' => 'Electrical & Electronics Engineering']);
        $this->actingAs($chair)->get(route('nureen.application', $sv))->assertOk();
        $this->actingAs($otherChair)->get(route('nureen.application', $sv))->assertForbidden();

        // Teammates' modules are not served here.
        $travel = $this->application('travel', Application::STATUS_PENDING, 'supervisor');
        $this->actingAs($this->cgs)->get(route('nureen.application', $travel))->assertForbidden();
    }
}
