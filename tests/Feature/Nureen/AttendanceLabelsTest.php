<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceRiskEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Every attendance percentage says which one it is. The figures are
 * unchanged: the semester so far is the one the 80% rule uses, the latest
 * week is one period's rate. A student at 70.8% so far whose last week
 * was 75% must see 70.8% as the main figure everywhere, labelled
 * "Semester so far", with "Latest week: 75%" where that is shown too.
 */
class AttendanceLabelsTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00'));
        config(['nureen.attendance.semester_periods' => 14, 'nureen.attendance.semester_start' => '2026-10-05']);

        $this->supervisor = $this->user(Role::SUPERVISOR, ['email' => 'sup@test.my', 'name' => 'Dr. Aisyah Rahman', 'department' => 'Computing']);
        $this->student = $this->student('24100313', 'nur@test.my', [
            'name' => 'Nur Insyirah', 'programme' => 'MSc Full-Time', 'department' => 'Computing', 'supervisor_id' => $this->supervisor->id,
        ]);

        // 12..15 of 20 over six weeks: 70.8% so far, 75% in the last week.
        $friday = CarbonImmutable::parse('2026-10-09');
        foreach ([12, 13, 14, 15, 16, 15] as $i => $attended) {
            $record = AttendanceRecord::recordPeriod($this->student->id, $friday->addWeeks($i)->toDateString(), $attended, 20);
            $record->update(['at_risk' => AttendanceRiskEvaluator::isAtRisk($record)]);
        }

        app(AttendanceEarlyWarning::class)->run([$this->student->id]);
    }

    public function test_the_figures_themselves_are_unchanged(): void
    {
        $this->assertSame(70.8, AttendanceRiskEvaluator::assess($this->student->id)['current']);
        $this->assertSame(70.8, (float) AttendanceRiskState::where('student_id', $this->student->id)->value('current_percentage'));
        $this->assertSame(75.0, (float) AttendanceRecord::where('student_id', $this->student->id)->latest('period_end')->value('percentage'));
    }

    public function test_the_student_dashboard_leads_with_the_semester_so_far(): void
    {
        $this->actingAs($this->student)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Semester so far', '70.8%'])
            ->assertSeeInOrder(['Latest week', '75%'])
            // The Attendance Action card carries the same figure.
            ->assertSee('Semester so far: 70.8%.');
    }

    public function test_the_students_attendance_page_shows_both_labelled(): void
    {
        $this->actingAs($this->student)->get(route('attendance.overview'))
            ->assertOk()
            ->assertSeeInOrder(['70.8%', 'Semester so far', '75%', 'Latest week']);
    }

    public function test_the_supervisor_sees_the_semester_so_far_and_the_latest_week(): void
    {
        $this->actingAs($this->supervisor)->get(route('supervisor.candidates'))
            ->assertOk()
            ->assertSee('Semester so far: 70.8%')
            ->assertSee('Latest week: 75%');

        $this->actingAs($this->supervisor)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Semester so far: 70.8%');
    }

    public function test_the_cgs_at_risk_list_labels_its_column_semester_so_far(): void
    {
        $cgs = $this->user(Role::NON_EXEC_CGS, ['email' => 'cgs@test.my', 'department' => 'CGS']);

        $this->actingAs($cgs)->get(route('attendance.at-risk'))
            ->assertOk()
            ->assertSeeInOrder(['Semester so far', 'Nur Insyirah', '70.8%'])
            ->assertSee('80% rule');
    }

    public function test_the_early_warning_email_says_semester_so_far(): void
    {
        Notification::assertSentTo($this->student, AttendanceAtRisk::class, function (AttendanceAtRisk $n) {
            $mail = (string) $n->toMail($this->student)->render();

            return str_contains($mail, 'Semester so far: 70.8%')
                && str_contains((string) $n->toMail($this->supervisor)->render(), 'Semester so far: 70.8%');
        });
    }
}
