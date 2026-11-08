<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Support\DelayByActorReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks in the one formula verified by hand this session: a role's
 * SHARE of an application's total turnaround, not just its own
 * average time in isolation -- the figure that correctly flags a
 * fast-but-still-dominant stage a raw average would hide.
 */
class DelayByActorReportTest extends TestCase
{
    use RefreshDatabase;

    protected function approver(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => 'password', 'role' => $role]);
    }

    protected function application(Carbon $submittedAt): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student@test.com'.rand(), 'password' => 'password', 'role' => Role::STUDENT]);

        return Application::create(['student_id' => $student->id, 'module_type' => 'gra_application', 'status' => Application::STATUS_APPROVED, 'current_stage' => null, 'submitted_at' => $submittedAt]);
    }

    protected function decided(Application $application, User $approver, string $stageKey, Carbon $decidedAt): ApprovalHistory
    {
        $history = ApprovalHistory::create([
            'application_id' => $application->id, 'approver_id' => $approver->id,
            'stage_key' => $stageKey, 'stage_label' => ucfirst($stageKey), 'decision' => 'approved',
        ]);

        $history->created_at = $decidedAt;
        $history->save();

        return $history;
    }

    public function test_a_role_that_is_fast_in_isolation_but_dominates_the_wait_is_flagged_as_the_bottleneck(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin@test.com');
        $supervisor = $this->approver(Role::SUPERVISOR, 'sup@test.com');

        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application($submitted);

        // Admin takes 1 hour -- fast. Supervisor takes 99 hours -- the real bottleneck.
        $this->decided($application, $admin, 'admin_verify', $submitted->copy()->addHour());
        $this->decided($application, $supervisor, 'supervisor', $submitted->copy()->addHours(100));

        $rows = DelayByActorReport::rows('gra_application');
        $byRole = DelayByActorReport::byRole($rows, $rows);
        $bottleneck = DelayByActorReport::bottleneckRole($byRole);

        $this->assertSame('supervisor', $bottleneck['role']);
        $this->assertEqualsWithDelta(99.0, $bottleneck['share_percent'], 1.0);
    }

    public function test_rows_are_scoped_to_the_requested_module_type_only(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin@test.com');
        $submitted = Carbon::parse('2026-01-01');

        $gra = $this->application($submitted);
        $gra->update(['module_type' => 'gra_application']);
        $this->decided($gra, $admin, 'admin_verify', $submitted->copy()->addHour());

        $ga = $this->application($submitted);
        $ga->update(['module_type' => 'ga_application']);
        $this->decided($ga, $admin, 'admin_verify', $submitted->copy()->addHour());

        $this->assertCount(1, DelayByActorReport::rows('gra_application'));
        $this->assertCount(1, DelayByActorReport::rows('ga_application'));
        $this->assertCount(2, DelayByActorReport::rows(null));
    }

    public function test_an_individuals_vs_role_percent_is_exactly_zero_when_they_are_the_only_member_of_their_role(): void
    {
        // Regression test for the rounding bug caught 2026-11-06: comparing
        // an actor's raw average against byRole()'s already-ROUNDED figure
        // made a single-member role read as slower/faster than itself
        // purely from rounding noise (a real -25% was observed on a
        // 9-minute decision rounding to 0.2 hours at the role level).
        $supervisor = $this->approver(Role::SUPERVISOR, 'sup@test.com');
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application($submitted);

        $this->decided($application, $supervisor, 'supervisor', $submitted->copy()->addMinutes(9));

        $byActor = DelayByActorReport::byActor(DelayByActorReport::rows());

        $this->assertSame(0, $byActor->first()['vs_role_percent']);
    }
}
