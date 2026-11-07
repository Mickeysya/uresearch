<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\TaHourController;
use App\Modules\Haziq\Models\TaHourLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A plain activity log, deliberately -- the migration's own docblock:
 * "rather than the terms document's full per-activity approver/claim-
 * formula engine, which was scoped out as a separate build." These
 * tests confirm what the log actually promises (authorization scoping,
 * the single-entry range, correct monthly aggregation), not a
 * cumulative monthly cap the code was never built to enforce.
 */
class TaHourControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function student(?int $supervisorId = null): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT, 'supervisor_id' => $supervisorId]);
    }

    public function test_a_student_cannot_log_their_own_ta_hours(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('ta-hours.store'), ['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => now()->toDateString(), 'hours' => 2])
            ->assertForbidden();
    }

    public function test_a_supervisor_cannot_log_hours_for_a_student_who_is_not_their_supervisee(): void
    {
        $supervisor = User::create(['name' => 'Sup', 'email' => 'sup@test.com', 'password' => 'x', 'role' => Role::SUPERVISOR]);
        $otherSupervisor = User::create(['name' => 'Other', 'email' => 'other@test.com', 'password' => 'x', 'role' => Role::SUPERVISOR]);
        $student = $this->student($otherSupervisor->id);

        $this->actingAs($supervisor)
            ->post(route('ta-hours.store'), ['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => now()->toDateString(), 'hours' => 2])
            ->assertForbidden();
    }

    public function test_a_supervisor_can_log_hours_for_their_own_supervisee(): void
    {
        $supervisor = User::create(['name' => 'Sup', 'email' => 'sup@test.com', 'password' => 'x', 'role' => Role::SUPERVISOR]);
        $student = $this->student($supervisor->id);

        $this->actingAs($supervisor)
            ->post(route('ta-hours.store'), ['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => now()->toDateString(), 'hours' => 2])
            ->assertRedirect();

        $this->assertSame(1, TaHourLog::where('student_id', $student->id)->count());
    }

    public function test_a_single_entry_over_the_cap_is_rejected(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'x', 'role' => Role::ADMIN]);
        $student = $this->student();

        $this->actingAs($admin)
            ->post(route('ta-hours.store'), ['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => now()->toDateString(), 'hours' => 25])
            ->assertInvalid(['hours']);
    }

    public function test_this_months_total_correctly_sums_every_entry_logged_this_month(): void
    {
        $student = $this->student();
        Carbon::setTestNow('2026-11-15');

        TaHourLog::create(['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => '2026-11-02', 'hours' => 5]);
        TaHourLog::create(['student_id' => $student->id, 'activity_type' => 'Tutorial', 'activity_date' => '2026-11-10', 'hours' => 6]);
        TaHourLog::create(['student_id' => $student->id, 'activity_type' => 'Marking', 'activity_date' => '2026-10-20', 'hours' => 100]); // last month, must not count

        $result = (new TaHourController)->thisMonthFor($student);

        $this->assertSame(11.0, $result['hours']);
    }
}
