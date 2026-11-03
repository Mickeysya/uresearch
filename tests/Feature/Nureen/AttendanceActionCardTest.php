<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Database\Seeders\AttendanceRiskDemoSeeder;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The student dashboard's "Attendance Action" card (which replaced Core's
 * "Predicted Attendance" card), the Client Charter date on its "Active
 * Applications" card, and the viva demo seeder that sets up the three
 * attendance-risk cases.
 *
 * The card is arithmetic on the student's own sessions -- how many of the
 * sessions still to come they must attend to finish at or above 80% -- and
 * never the projection.
 */
class AttendanceActionCardTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 2 November 2026: the demo semester's four weeks are done.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
        config([
            'nureen.attendance.semester_start' => '2026-10-05',
            'nureen.attendance.semester_periods' => 14,
            'nureen.attendance.projection_min_periods' => 3,
        ]);
    }

    /** One weekly row per entry, ending on the demo semester's Fridays. */
    protected function weeks(User $student, array $attended, int $total = 10): void
    {
        foreach (array_values($attended) as $i => $count) {
            AttendanceRecord::recordPeriod($student->id, CarbonImmutable::parse('2026-10-09')->addWeeks($i)->toDateString(), $count, $total);
        }

        app(AttendanceEarlyWarning::class)->run([$student->id]);
    }

    protected function dashboard(User $student)
    {
        return $this->actingAs($student)->get(route('dashboard'))->assertOk();
    }

    /* -----------------------------------------------------------------
     | The N-of-M arithmetic
     |------------------------------------------------------------------*/

    public function test_sessions_needed_is_the_fewest_that_reach_eighty_percent(): void
    {
        // 38 of 40 so far, 100 to come: 80% of 140 is 112, so 74 more.
        $this->assertSame(74, AttendanceTarget::sessionsNeeded(38, 40, 100));
        // 74 is enough and 73 is not.
        $this->assertGreaterThanOrEqual(80.0, 100 * (38 + 74) / 140);
        $this->assertLessThan(80.0, 100 * (38 + 73) / 140);

        // Exactly on the line counts as reaching it.
        $this->assertSame(100, AttendanceTarget::sessionsNeeded(12, 40, 100));
        // A fraction of a session rounds UP: 80% of 41 is 32.8, so 3 more, not 2.
        $this->assertSame(3, AttendanceTarget::sessionsNeeded(30, 31, 10));
    }

    public function test_sessions_needed_is_zero_once_the_target_is_secured(): void
    {
        // 130 of 140 so far: even missing all 10 still ends at 86.7%.
        $this->assertSame(0, AttendanceTarget::sessionsNeeded(130, 140, 10));
    }

    public function test_sessions_needed_is_null_when_eighty_percent_is_out_of_reach(): void
    {
        // 11 of 40: 101 of the 100 left would be needed.
        $this->assertNull(AttendanceTarget::sessionsNeeded(11, 40, 100));
        // No sessions left and below the line.
        $this->assertNull(AttendanceTarget::sessionsNeeded(100, 140, 0));
    }

    public function test_remaining_sessions_use_the_semester_length_and_the_average_so_far(): void
    {
        // 4 of 14 weeks done at 10 a week: 100 to come.
        $this->assertSame(100, AttendanceTarget::remainingSessions([10, 10, 10, 10], 14));
        // An uneven week moves the average: 9.5 x 10 = 95.
        $this->assertSame(95, AttendanceTarget::remainingSessions([10, 9, 10, 9], 14));
        // A finished (or overrun) semester has nothing left.
        $this->assertSame(0, AttendanceTarget::remainingSessions(array_fill(0, 14, 10), 14));
        $this->assertSame(0, AttendanceTarget::remainingSessions(array_fill(0, 15, 10), 14));
        $this->assertSame(0, AttendanceTarget::remainingSessions([], 14));
    }

    /* -----------------------------------------------------------------
     | The card's four states
     |------------------------------------------------------------------*/

    public function test_a_student_in_good_standing_is_on_track(): void
    {
        $student = $this->student();
        $this->weeks($student, [9, 9, 9, 9]);

        $this->dashboard($student)
            ->assertSee('Attendance Target')->assertSee('On track')->assertSee('Minimum 80% this semester')
            ->assertDontSee('Attendance Action');
    }

    public function test_a_flagged_student_who_can_still_reach_eighty_percent_is_told_how_many_sessions(): void
    {
        // Projection-only: 95% so far, never below 85%, but declining.
        $student = $this->student();
        $this->weeks($student, [10, 10, 9, 9]);
        $this->assertSame('projected', AttendanceRiskState::where('student_id', $student->id)->value('reason'));

        $this->dashboard($student)
            ->assertSee('Attendance Action')
            ->assertSee('Attend 74 of the next 100 sessions')
            ->assertSee('to stay at or above 80% this semester')
            ->assertSee(route('attendance.overview'))
            // Never the projection (75%) or the trend.
            ->assertDontSee('75%')->assertDontSee('pts/period')->assertDontSee('Projected');
    }

    public function test_a_student_below_eighty_percent_who_can_still_reach_it_gets_a_target_too(): void
    {
        // 70% now (the current rule), but 80% is reachable: 84 of 100.
        $student = $this->student();
        $this->weeks($student, [6, 7, 8, 7]);

        $this->dashboard($student)->assertSee('Attend 84 of the next 100 sessions');
    }

    public function test_when_eighty_percent_is_out_of_reach_the_student_is_asked_for_a_justification(): void
    {
        $student = $this->student();
        $this->weeks($student, [3, 3, 2, 3]);

        $this->dashboard($student)
            ->assertSee('Justification needed')
            ->assertSee('Submit a justification letter and monthly activity breakdown to CGS')
            ->assertSee(route('attendance-appeal.create'))
            ->assertDontSee('Attend ');
    }

    public function test_once_an_appeal_exists_its_status_replaces_the_request(): void
    {
        $student = $this->student();
        $this->weeks($student, [3, 3, 2, 3]);

        $appeal = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'attendance_appeal',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'cgs_review',
            'submitted_at' => now(),
        ]);

        foreach ([
            Application::STATUS_PENDING => 'Appeal under review',
            Application::STATUS_APPROVED => 'Appeal approved',
            Application::STATUS_REJECTED => 'Appeal rejected',
        ] as $status => $label) {
            $appeal->forceFill(['status' => $status])->saveQuietly();

            $this->dashboard($student)
                ->assertSee($label)
                ->assertSee(route('applications.show', $appeal))
                ->assertDontSee('Justification needed');
        }
    }

    public function test_an_appeal_from_an_earlier_semester_does_not_count(): void
    {
        $student = $this->student();
        $this->weeks($student, [3, 3, 2, 3]);

        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'attendance_appeal',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'cgs_review',
            'submitted_at' => '2026-03-01 09:00',
        ]);

        $this->dashboard($student)->assertSee('Justification needed');
    }

    public function test_with_too_little_data_the_student_is_asked_to_keep_checking_in(): void
    {
        $student = $this->student();
        $this->dashboard($student)->assertSee('Keep checking in')->assertSee('Your attendance target will appear after a few weeks');

        // Two weeks, even two poor ones, is still too early to set a target.
        $this->weeks($student, [5, 5]);
        $this->dashboard($student)->assertSee('Keep checking in')->assertDontSee('Justification needed');
    }

    /* -----------------------------------------------------------------
     | Active Applications: the next Client Charter decision date
     |------------------------------------------------------------------*/

    protected function pendingGaExtension(User $student, string $stage, string $mode): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => Application::STATUS_PENDING,
            'current_stage' => $stage,
            'submitted_at' => now(),
        ]);

        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Test fixture.',
            'final_approver_snapshot' => $mode,
        ]);

        return $application;
    }

    public function test_active_applications_shows_the_nearest_expected_decision_date(): void
    {
        $student = $this->student();

        // Supervisor (3) + CGS (2) working days from Monday 2 Nov: Monday 9 Nov.
        $this->pendingGaExtension($student, 'supervisor', 'cgs');
        // Already at CGS: 2 working days, Wednesday 4 Nov -- the nearer one.
        $this->pendingGaExtension($student, 'cgs_verify', 'cgs');

        $this->dashboard($student)->assertSee('In progress · next decision by 4 Nov');
    }

    public function test_active_applications_shows_only_in_progress_without_a_charter_date(): void
    {
        $student = $this->student();
        $this->dashboard($student)->assertSee('In progress')->assertDontSee('next decision by');

        // The Senior Director's stage has no charter figure: no date is invented.
        $this->pendingGaExtension($student, 'senior_director', 'senior_director');
        // A teammate's module keeps no charter at all.
        Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now(),
        ]);

        $this->dashboard($student)->assertSee('In progress')->assertDontSee('next decision by');
    }

    public function test_another_students_pending_application_never_sets_the_date(): void
    {
        $student = $this->student();
        $this->pendingGaExtension($this->student('22001002', 'other@test.my'), 'cgs_verify', 'cgs');

        $this->dashboard($student)->assertDontSee('next decision by');
    }

    /* -----------------------------------------------------------------
     | Only the student dashboard changed
     |------------------------------------------------------------------*/

    public function test_staff_dashboards_are_unaffected(): void
    {
        $this->weeks($this->student(), [10, 10, 9, 9]);

        foreach ([$this->cgs(), $this->admin(), $this->supervisor('sup2@test.my'), $this->chair(), $this->seniorDirector(), $this->dean()] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertDontSee('Attendance Action')
                ->assertDontSee('Attendance Target')
                ->assertDontSee('next decision by');
        }
    }

    /* -----------------------------------------------------------------
     | The viva demo seeder
     |------------------------------------------------------------------*/

    public function test_the_demo_seeder_produces_the_three_cases_and_is_safe_to_rerun(): void
    {
        Notification::fake();

        $projection = $this->student(AttendanceRiskDemoSeeder::PROJECTION_ONLY, 'projection@test.my');
        $current = $this->student(AttendanceRiskDemoSeeder::CURRENT_RULE, 'current@test.my');
        $steady = $this->student(AttendanceRiskDemoSeeder::STEADY, 'steady@test.my');
        $bystander = $this->student('22001013', 'bystander@test.my');
        AttendanceRecord::recordPeriod($bystander->id, '2026-10-09', 2, 10);

        $this->seed(AttendanceRiskDemoSeeder::class);
        $this->seed(AttendanceRiskDemoSeeder::class);

        $state = fn (User $s) => AttendanceRiskState::where('student_id', $s->id)->first();

        // Projection only: comfortably above 85% now, flagged by the projection alone.
        $this->assertSame(95.0, $state($projection)->current_percentage);
        $this->assertSame(75.0, $state($projection)->projected_percentage);
        $this->assertSame('projected', $state($projection)->reason);

        // Current rule: under 80% now.
        $this->assertLessThan(80.0, $state($current)->current_percentage);
        $this->assertContains($state($current)->reason, ['current', 'both']);

        // Steady: around 90%, not flagged.
        $this->assertSame(90.0, $state($steady)->current_percentage);
        $this->assertFalse($state($steady)->at_risk);
        $this->assertNull($state($steady)->reason);

        // Re-running gave the same four weeks, not eight.
        foreach ([$projection, $current, $steady] as $student) {
            $this->assertSame(4, AttendanceRecord::where('student_id', $student->id)->count());
        }

        // Exactly one early-warning email each, despite two runs; none for steady.
        Notification::assertSentToTimes($projection, AttendanceAtRisk::class, 1);
        Notification::assertSentToTimes($current, AttendanceAtRisk::class, 1);
        Notification::assertNotSentTo($steady, AttendanceAtRisk::class);

        // Only the three demo students were touched.
        $this->assertSame(1, AttendanceRecord::where('student_id', $bystander->id)->count());
        $this->assertNull($state($bystander));

        // And their dashboard cards say what the viva walkthrough expects.
        $this->dashboard($projection)->assertSee('Attend 74 of the next 100 sessions');
        $this->dashboard($current)->assertSee('Justification needed');
        $this->dashboard($steady)->assertSee('On track');
    }
}
