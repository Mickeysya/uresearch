<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "dah approve or verify mana leh nak flag pape dah... nak buat before
 * verify or approve" -- confirmed explicitly 2026-11-10. Once a viewer's
 * own stage has already recorded its decision, they specifically can no
 * longer flag a correction, even while the application is still pending
 * somewhere later in the chain. GraApplicationController::canStillFlag()
 * already had this; GaApplicationController's own claimed the same
 * reasoning in its docblock but never actually implemented the check.
 */
class RequestCorrectionStageLockTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password', 'role' => Role::ADMIN]);
    }

    protected function graApplication(): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student-gra@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_review', 'submitted_at' => now()]);

        GraApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'application_type' => 'new', 'project_leader_name' => 'Dr. X', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    protected function gaApplication(): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student-ga@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_review', 'submitted_at' => now()]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'candidature_status' => 'active', 'department_unit' => 'CS Dept', 'purpose' => 'Teaching',
            'proposed_hours_per_week' => 10, 'interview_result' => 'pass', 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    public function test_gra_admin_cannot_flag_after_already_verifying_their_own_stage(): void
    {
        $application = $this->graApplication();
        $admin = $this->admin();

        ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $admin->id, 'stage_key' => 'admin_verify', 'stage_label' => 'Admin', 'decision' => 'verified']);

        $this->actingAs($admin)->post(route('gra-application.request-correction', $application), [
            'targets' => ['document:Admission Letter & Offer Letter'],
            'remarks' => 'Wrong file.',
        ])->assertForbidden();
    }

    public function test_ga_admin_cannot_flag_after_already_verifying_their_own_stage(): void
    {
        $application = $this->gaApplication();
        $admin = $this->admin();

        ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $admin->id, 'stage_key' => 'admin_verify', 'stage_label' => 'Admin', 'decision' => 'verified']);

        $this->actingAs($admin)->post(route('ga-application.request-correction', $application), [
            'targets' => ['document:Resume/CV'],
            'remarks' => 'Wrong file.',
        ])->assertForbidden();
    }

    /**
     * Confirmed explicitly 2026-11-10: "research centre takde ni au, mark
     * correction au, set interview je, cuma boleh tengok detail students
     * ah." Research Centre is grouped with every other approver in the
     * route middleware (queue/show/decide/request-correction share one
     * group, routes.php), but HandlesApplicationCorrections::
     * requestCorrection() itself never lists Roles::RESEARCH_CENTRE in its
     * own role check -- so the route lets the request through, and the
     * controller is what actually stops it.
     */
    public function test_ga_research_centre_cannot_flag_a_correction_at_all(): void
    {
        $application = $this->gaApplication();
        $researchCentre = User::create(['name' => 'RC', 'email' => 'rc-no-correction@test.com', 'password' => 'password', 'role' => Roles::RESEARCH_CENTRE, 'department' => 'Computing']);

        $this->actingAs($researchCentre)->post(route('ga-application.request-correction', $application), [
            'targets' => ['document:Resume/CV'],
            'remarks' => 'Wrong file.',
        ])->assertForbidden();
    }

    /**
     * canStillFlag() alone used to gate show()'s "Request Correction"
     * block, with no role check at all -- Research Centre could already
     * view a GA application's show() page (it is one of the four approver
     * roles that route allows), so the form rendered for them too, even
     * though requestCorrection() itself would 403 if they actually
     * submitted it. Confirmed explicitly 2026-11-10, same message as the
     * test above.
     */
    public function test_ga_research_centre_never_sees_the_correction_form_on_the_show_page(): void
    {
        $application = $this->gaApplication();
        $researchCentre = User::create(['name' => 'RC', 'email' => 'rc-show@test.com', 'password' => 'password', 'role' => Roles::RESEARCH_CENTRE, 'department' => 'Computing']);

        $this->actingAs($researchCentre)->get(route('ga-application.show', $application))
            ->assertOk()
            ->assertDontSee('Request Correction');
    }

    /** Same gap, the queue page's own copy of the form -- it had no gate at all, role or otherwise. */
    public function test_ga_research_centre_never_sees_the_correction_form_on_the_queue_page(): void
    {
        $student = User::create(['name' => 'Student', 'email' => 'student-rc-queue@test.com', 'password' => 'password', 'role' => Role::STUDENT, 'department' => 'Computing']);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_PENDING, 'current_stage' => 'research_centre_interview', 'submitted_at' => now()]);
        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'interview_date' => now(), 'proposed_hours_per_week' => 10, 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);
        $researchCentre = User::create(['name' => 'RC', 'email' => 'rc-queue@test.com', 'password' => 'password', 'role' => Roles::RESEARCH_CENTRE, 'department' => 'Computing']);

        $this->actingAs($researchCentre)->get(route('ga-application.queue'))
            ->assertOk()
            ->assertDontSee('Request Correction');
    }

    public function test_ga_a_different_approver_can_still_flag_even_though_admin_already_decided(): void
    {
        $application = $this->gaApplication();
        $admin = $this->admin();
        $cgs = User::create(['name' => 'CGS', 'email' => 'cgs@test.com', 'password' => 'password', 'role' => Role::NON_EXEC_CGS]);

        ApprovalHistory::create(['application_id' => $application->id, 'approver_id' => $admin->id, 'stage_key' => 'admin_verify', 'stage_label' => 'Admin', 'decision' => 'verified']);

        $this->actingAs($cgs)->post(route('ga-application.request-correction', $application), [
            'targets' => ['document:Resume/CV'],
            'remarks' => 'Wrong file.',
        ])->assertRedirect();
    }
}
