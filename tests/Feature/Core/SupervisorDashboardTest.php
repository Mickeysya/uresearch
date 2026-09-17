<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The Supervisor's dashboard.
 *
 * Shares its queue half with the Chair (ApproverDashboard). What is tested
 * here is the half that is not shared: a supervisor is accountable for named
 * students, and "is one of my candidates in trouble" is the question no queue
 * can answer.
 */
class SupervisorDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function candidate(User $supervisor, string $name, string $matric, ?float $percentage = null): User
    {
        $student = $this->student(
            matric: $matric,
            email: strtolower(explode(' ', $name)[0]).$matric.'@test.my',
            attributes: ['name' => $name, 'supervisor_id' => $supervisor->id],
        );

        if ($percentage !== null) {
            AttendanceRecord::create([
                'student_id' => $student->id,
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end' => now()->subMonth()->endOfMonth(),
                'sessions_attended' => (int) round($percentage),
                'sessions_total' => 100,
                'percentage' => $percentage,
                'at_risk' => $percentage < 80,
            ]);
        }

        return $student;
    }

    public function test_a_supervisor_gets_the_supervisor_dashboard(): void
    {
        $this->actingAs($this->supervisor())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My candidates')
            ->assertSee('Your queues')
            ->assertSee('Flagged at risk')
            // Not the generic approver screen.
            ->assertDontSee('dashboardChart', false);
    }

    /**
     * The panel that makes this a supervisor's screen. Attendance reaches it
     * through Contracts\SuppliesAttendance, so Core never names Nureen.
     */
    public function test_candidates_are_listed_with_attendance_worst_first(): void
    {
        $supervisor = $this->supervisor();

        $this->candidate($supervisor, 'Ahmad Danial', '22001001', 91.0);
        $this->candidate($supervisor, 'Siti Nurhaliza', '22001002', 62.0);

        // Somebody else's student must not appear.
        $this->candidate($this->supervisor('other@test.my'), 'Raj Kumar', '22001003', 55.0);

        $html = $this->actingAs($supervisor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ahmad Danial')
            ->assertSee('Siti Nurhaliza')
            ->assertDontSee('Raj Kumar')
            ->getContent();

        // At-risk first: the supervisor should not have to read the list to
        // find the student who needs them.
        $this->assertLessThan(
            strpos($html, 'Ahmad Danial'),
            strpos($html, 'Siti Nurhaliza'),
            'A flagged candidate belongs at the top of the list.'
        );

        // Two candidates, one of them flagged.
        $this->assertStringContainsString('62%', $html);
        $this->assertStringContainsString('91%', $html);
    }

    public function test_the_at_risk_figure_counts_only_this_supervisors_students(): void
    {
        $supervisor = $this->supervisor();

        $this->candidate($supervisor, 'Ahmad Danial', '22001001', 91.0);
        $this->candidate($supervisor, 'Siti Nurhaliza', '22001002', 62.0);
        $this->candidate($this->supervisor('other@test.my'), 'Raj Kumar', '22001003', 40.0);

        $this->actingAs($supervisor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Flagged at risk')
            // One of theirs is flagged, not two.
            ->assertSee('on attendance');

        $dash = new \App\Modules\Core\Services\SupervisorDashboard(
            $supervisor, app(\App\Modules\Core\Services\ModuleRegistry::class)
        );

        $this->assertSame(2, $dash->candidateCount());
        $this->assertSame(1, $dash->atRiskCount());
    }

    public function test_a_supervisor_with_no_students_is_told_so(): void
    {
        $this->actingAs($this->supervisor())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No students are assigned to you yet.');
    }

    /**
     * The two screens do not draw the same chart, deliberately.
     *
     * The Chair's asks how long work has sat on a desk, which is a bar. This
     * one asks whether the cohort is healthy, which is parts of a whole and
     * reads as a ring with the headcount in the middle. Same library, same
     * tokens, different question.
     */
    public function test_the_supervisor_gets_a_doughnut_of_the_cohort_not_the_chairs_bar(): void
    {
        $supervisor = $this->supervisor();

        $this->candidate($supervisor, 'Ahmad Danial', '22001001', 91.0);
        $this->candidate($supervisor, 'Siti Nurhaliza', '22001002', 62.0);
        $this->candidate($supervisor, 'Raj Kumar', '22001003');   // no reading

        $html = $this->actingAs($supervisor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Cohort attendance')
            ->assertDontSee('How long they have waited')
            ->getContent();

        $this->assertStringContainsString("type: 'doughnut'", $html);

        // One good, one critical, none warning, one with nothing on file --
        // and "Not recorded" is a slice rather than a silently dropped row.
        $this->assertStringContainsString('data: [1,0,1,1]', $html);
        $this->assertStringContainsString('Not recorded', $html);
    }

    /** The queue half, shared with the Chair, still works here. */
    public function test_the_queue_half_still_works(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->candidate($supervisor, 'Ahmad Danial', '22001001');

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'travel',
            'status' => Application::STATUS_PENDING,
            'current_stage' => 'supervisor',
            'submitted_at' => now()->subDays(33),
        ]);

        TravelDetail::create([
            'application_id' => $application->id,
            'type_of_request' => 'Field Visit',
            'travel_start_date' => now()->addMonth(),
            'travel_end_date' => now()->addMonth()->addDays(3),
            'duration_days' => 4,
            'reason_for_travel' => 'Conference',
            'destination_address' => 'Kuala Lumpur',
            'is_international' => false,
        ]);

        $this->actingAs($supervisor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('33 days')
            ->assertSee('well past the 30-day mark')
            ->assertSee('Go to the oldest');
    }
}
