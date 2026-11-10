<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Confirmed explicitly 2026-11-10: "once dah approved, status kene tukar
 * approved, start masuk service date je tukar active... status active tu
 * akan active balik after extend approved after service end date lama
 * habis." A display-only concept layered on top of the application's own
 * workflow status and candidature_status -- see
 * GraApplicationDetail::serviceStatus()'s own docblock for the exact
 * rule. Deliberately does not touch candidature_status's own meaning
 * (Stage Gate termination) or any eligibility check that already reads
 * candidature_status directly (Allowance, TA Hours, dashboard counts) --
 * those were never part of this request.
 */
class ServiceStatusTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function profile(User $student): GraGaStudentProfile
    {
        return GraGaStudentProfile::create([
            'user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS',
            'mode_of_study' => 'full_time', 'admission_date' => '2024-09-01',
        ]);
    }

    protected function graDetail(User $student, Carbon $start, Carbon $end, string $candidatureStatus = 'active', string $applicationType = 'new'): GraApplicationDetail
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'gra_application',
            'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => $start->copy()->subMonth(),
        ]);

        return GraApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $this->profile($student)->id,
            'application_type' => $applicationType, 'candidature_status' => $candidatureStatus,
            'project_leader_name' => 'Dr. Lim', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => $start, 'service_end_date' => $end,
        ]);
    }

    protected function gaDetail(User $student, Carbon $start, Carbon $end, string $candidatureStatus = 'active'): GaApplicationDetail
    {
        $application = Application::create([
            'student_id' => $student->id, 'module_type' => 'ga_application',
            'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => $start->copy()->subMonth(),
        ]);

        return GaApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $this->profile($student)->id,
            'candidature_status' => $candidatureStatus,
            'service_start_date' => $start, 'service_end_date' => $end, 'proposed_allowance' => 1500,
        ]);
    }

    public function test_gra_reads_approved_before_the_service_start_date(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01'));
        $detail = $this->graDetail($this->user('student'), Carbon::parse('2026-02-01'), Carbon::parse('2028-02-01'));

        $this->assertSame('approved', $detail->serviceStatus());
    }

    public function test_gra_reads_active_once_the_service_start_date_arrives(): void
    {
        $this->travelTo(Carbon::parse('2026-02-01'));
        $detail = $this->graDetail($this->user('student'), Carbon::parse('2026-02-01'), Carbon::parse('2028-02-01'));

        $this->assertSame('active', $detail->serviceStatus());
    }

    public function test_gra_reads_inactive_once_the_service_end_date_has_passed(): void
    {
        $this->travelTo(Carbon::parse('2028-03-01'));
        $detail = $this->graDetail($this->user('student'), Carbon::parse('2026-02-01'), Carbon::parse('2028-02-01'));

        $this->assertSame('inactive', $detail->serviceStatus());
    }

    public function test_gra_reads_terminated_regardless_of_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01'));
        $detail = $this->graDetail($this->user('student'), Carbon::parse('2026-02-01'), Carbon::parse('2028-02-01'), 'terminated');

        $this->assertSame('terminated', $detail->serviceStatus());
    }

    /**
     * The exact scenario described: the old service period already lapsed
     * (reading "inactive") before the extension's approval went through,
     * but once it does, the SAME row's service_end_date moves to the new,
     * later date -- so serviceStatus() reads "active" again immediately,
     * just by re-reading dates, with nothing specially "restored".
     */
    public function test_gra_goes_back_to_active_once_an_extension_is_approved_after_the_old_end_date_already_lapsed(): void
    {
        $student = $this->user('student');
        $detail = $this->graDetail($student, Carbon::parse('2026-01-01'), Carbon::parse('2026-06-01'));

        $this->travelTo(Carbon::parse('2026-07-01'));
        $this->assertSame('inactive', $detail->fresh()->serviceStatus());

        // The extension's own approval moves THIS SAME row's end date
        // forward -- see ApprovedGaApplicationProvider::extendEndDate()
        // for GA's equivalent, and GraApplicationController's extension
        // flow for GRA's (a new row, but the same idea: the "current"
        // appointment's own service_end_date is what moves).
        $detail->update(['service_end_date' => Carbon::parse('2027-01-01')]);

        $this->assertSame('active', $detail->fresh()->serviceStatus());
    }

    public function test_ga_follows_the_same_rule_as_gra(): void
    {
        $student = $this->user('student');

        $this->travelTo(Carbon::parse('2026-01-01'));
        $notStarted = $this->gaDetail($student, Carbon::parse('2026-02-01'), Carbon::parse('2026-08-01'));
        $this->assertSame('approved', $notStarted->serviceStatus());

        $this->travelTo(Carbon::parse('2026-02-01'));
        $this->assertSame('active', $notStarted->fresh()->serviceStatus());

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->assertSame('inactive', $notStarted->fresh()->serviceStatus());

        // GA's own extension updates this SAME row in place -- no new
        // Application, unlike GRA's.
        $notStarted->update(['service_end_date' => Carbon::parse('2027-01-01')]);
        $this->assertSame('active', $notStarted->fresh()->serviceStatus());
    }

    public function test_my_gra_application_page_shows_the_appointment_status(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01'));
        $student = $this->user('student');
        $detail = $this->graDetail($student, Carbon::parse('2026-02-01'), Carbon::parse('2028-02-01'));

        $this->actingAs($student)->get(route('gra-application.mine', $detail->application_id))
            ->assertOk()
            ->assertSee('Appointment status', false)
            ->assertSee('approved', false)
            ->assertSee('Starts 1 Feb 2026', false);

        $this->travelTo(Carbon::parse('2026-02-01'));
        $this->actingAs($student)->get(route('gra-application.mine', $detail->application_id))
            ->assertOk()
            ->assertSee('Appointment status', false)
            ->assertSee('active', false);
    }

    public function test_my_ga_application_page_shows_the_appointment_status(): void
    {
        $this->travelTo(Carbon::parse('2026-09-01'));
        $student = $this->user('student');
        $detail = $this->gaDetail($student, Carbon::parse('2026-02-01'), Carbon::parse('2026-08-01'));

        $this->actingAs($student)->get(route('ga-application.mine', $detail->application_id))
            ->assertOk()
            ->assertSee('Appointment status', false)
            ->assertSee('inactive', false)
            ->assertSee('Service ended 1 Aug 2026', false);
    }

    public function test_stage_gate_monitoring_shows_the_same_status_for_every_role(): void
    {
        // Mid-January, service starting later the same month -- still
        // "approved, not started yet" (today < service_start_date), but
        // not filtered out of the student's own view either: that view
        // only hides an appointment whose start MONTH hasn't arrived yet
        // (StageGateController::startedApplicationIds()'s own rule), and
        // January already has.
        $this->travelTo(Carbon::parse('2026-01-01'));
        $student = $this->user('student');
        $detail = $this->graDetail($student, Carbon::parse('2026-01-15'), Carbon::parse('2028-12-01'));

        \App\Modules\Haziq\Models\StageGate::create([
            'application_id' => $detail->application_id,
            'stage_order' => 1, 'stage_name' => 'RPD Completion',
            'requirement_description' => 'Research Progress Defense (RPD) completed.',
            'deadline_date' => Carbon::parse('2027-06-01'),
            'grace_end_date' => Carbon::parse('2027-07-01'),
            'completion_status' => 'pending', 'timeline_status' => 'upcoming', 'allowance_status' => 'eligible',
        ]);

        foreach (['admin', 'non_exec_cgs', 'senior_director_cgs'] as $role) {
            $this->actingAs($this->user($role))->get(route('stage-gate.index'))
                ->assertOk()
                ->assertSee('status-badge approved', false);
        }

        $this->actingAs($student)->get(route('stage-gate.index'))
            ->assertOk()
            ->assertSee('status-badge approved', false);
    }
}
