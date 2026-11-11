<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Support\DelayByActorReport;
use App\Modules\Haziq\Support\DocumentCompleteness;
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
    }

    /**
     * Confirmed explicitly 2026-11-10: "delay by actor tu buanglah gra
     * and ga extention tu... filter for gra and ga application theres no
     * such thing as both, kene choose either one." No scope averages
     * two together -- an extension is different enough in shape from a
     * new appointment (it skips GA's own Research Centre stage
     * entirely, for one) that doing so would blur what this report
     * predicts for. 'ga_extension' (Nureen's module) is its own scope,
     * never folded into 'ga_application'; GRA's own
     * 'extension' application_type needs this explicit filter instead,
     * since it shares gra_application's module_type with 'new'.
     */
    public function test_an_extension_applications_history_is_excluded(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-ext@test.com');
        $submitted = Carbon::parse('2026-01-01');

        $new = $this->application($submitted);
        $this->decided($new, $admin, 'admin_verify', $submitted->copy()->addHour());

        $extension = $this->application($submitted);
        $profile = \App\Modules\Haziq\Models\GraGaStudentProfile::create([
            'user_id' => $extension->student_id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => $submitted,
        ]);
        \App\Modules\Haziq\Models\GraApplicationDetail::create([
            'application_id' => $extension->id, 'profile_id' => $profile->id, 'application_type' => 'extension',
            'project_leader_name' => 'Dr. Lim', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => $submitted, 'service_end_date' => $submitted->copy()->addYear(),
        ]);
        $this->decided($extension, $admin, 'admin_verify', $submitted->copy()->addHour());

        $this->assertCount(1, DelayByActorReport::rows('gra_application'));
        $this->assertCount(1, DelayByActorReport::rows('gra_extension'));
    }

    /**
     * Confirmed explicitly 2026-11-10: "delay by actor analysis taleh
     * count situasi ni delay pada admin" -- once Admin's own verify
     * action is blocked until every required document is attached (see
     * GraApplicationController::decide()), the time a student spent
     * completing paperwork would otherwise read as Admin's own delay.
     * This naively-measured 42 hours is really "40 hours waiting on the
     * student, 2 hours waiting on Admin" -- only the 2 should count.
     */
    public function test_admin_verifys_measured_delay_excludes_time_spent_waiting_for_a_document(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-docwait@test.com');
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application($submitted);

        $documentCompleteAt = $submitted->copy()->addHours(40);
        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'doc_type' => DocumentCompleteness::requiredDocuments('gra_application')['resume'],
            'original_name' => 'resume.pdf', 'path' => 'fake/resume.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
        ]);
        $doc->created_at = $documentCompleteAt;
        $doc->save();

        $this->decided($application, $admin, 'admin_verify', $documentCompleteAt->copy()->addHours(2));

        $rows = DelayByActorReport::rows('gra_application');

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(2.0, $rows->first()['delay_hours'], 0.01);
    }

    /**
     * The exclusion above is scoped to the 'gra_application'/'ga_application'
     * SCOPES keys specifically -- a GRA Extension shares gra_application's
     * module_type, but was never required to attach these documents at
     * Submit at all (DocumentCompleteness's own docblock), so there is
     * nothing real to exclude: the full, naive delay stands.
     */
    public function test_the_document_wait_exclusion_does_not_apply_to_gra_extension(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-extdoc@test.com');
        $submitted = Carbon::parse('2026-01-01 00:00:00');
        $application = $this->application($submitted);

        $profile = GraGaStudentProfile::create([
            'user_id' => $application->student_id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => $submitted,
        ]);
        GraApplicationDetail::create([
            'application_id' => $application->id, 'profile_id' => $profile->id, 'application_type' => 'extension',
            'project_leader_name' => 'Dr. Lim', 'project_title' => 'Test', 'funding_source' => 'Internal',
            'service_start_date' => $submitted, 'service_end_date' => $submitted->copy()->addYear(),
        ]);

        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'doc_type' => DocumentCompleteness::requiredDocuments('gra_application')['resume'],
            'original_name' => 'resume.pdf', 'path' => 'fake/resume.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 100,
        ]);
        $doc->created_at = $submitted->copy()->addHours(40);
        $doc->save();

        $this->decided($application, $admin, 'admin_verify', $submitted->copy()->addHours(42));

        $rows = DelayByActorReport::rows('gra_extension');

        $this->assertEqualsWithDelta(42.0, $rows->first()['delay_hours'], 0.01);
    }

    /**
     * Confirmed explicitly 2026-11-10: "memang student dah approved or
     * rejected tak kisah, yang penting sampai 2 outcome tu, pending
     * stage tak kira." A still-pending application's partial journey
     * doesn't belong in the same average as one that actually finished
     * -- one finished application is enough; a pending one, no matter
     * how many decisions it already has behind it, is excluded
     * entirely.
     */
    public function test_a_still_pending_applications_history_is_excluded_even_with_real_decisions_behind_it(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-pending@test.com');
        $supervisor = $this->approver(Role::SUPERVISOR, 'sup-pending@test.com');
        $submitted = Carbon::parse('2026-01-01');

        $finished = $this->application($submitted);
        $this->decided($finished, $admin, 'admin_verify', $submitted->copy()->addHour());

        $stillPending = $this->application($submitted);
        $stillPending->update(['status' => Application::STATUS_PENDING]);
        $this->decided($stillPending, $admin, 'admin_verify', $submitted->copy()->addHour());
        $this->decided($stillPending, $supervisor, 'supervisor', $submitted->copy()->addHours(5));

        $rows = DelayByActorReport::rows('gra_application');

        $this->assertCount(1, $rows);
        $this->assertSame($finished->id, $rows->first()['application_id']);
    }

    public function test_a_rejected_application_still_counts_as_a_finished_outcome(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-rejected@test.com');
        $submitted = Carbon::parse('2026-01-01');

        $rejected = $this->application($submitted);
        $rejected->update(['status' => Application::STATUS_REJECTED]);
        $this->decided($rejected, $admin, 'admin_verify', $submitted->copy()->addHour());

        $this->assertCount(1, DelayByActorReport::rows('gra_application'));
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

        $byActor = DelayByActorReport::byActor(DelayByActorReport::rows('gra_application'));

        $this->assertSame(0, $byActor->first()['vs_role_percent']);
    }

    /**
     * Confirmed explicitly 2026-11-10: "test banyak banyak applicants,
     * with different approval time... prediction tu tepat ah takde
     * bias." Three complete applications, full four-stage chain each,
     * two different Supervisors with deliberately very different
     * speeds -- hand-computed below, so this locks in that the role-
     * level prediction correctly finds the true bottleneck by SHARE of
     * each application's own total (not just raw hours, which would
     * wrongly favour whichever stage happens to take the longest on one
     * application), and that byActor() correctly tells the two
     * Supervisors apart even though they share one role average.
     */
    public function test_the_bottleneck_and_individual_predictions_are_correct_across_several_real_applications(): void
    {
        $admin = $this->approver(Role::ADMIN, 'admin-many@test.com');
        $cgs = $this->approver(Role::NON_EXEC_CGS, 'cgs-many@test.com');
        $director = $this->approver(Role::SENIOR_DIRECTOR_CGS, 'director-many@test.com');
        $supA = $this->approver(Role::SUPERVISOR, 'sup-a-many@test.com');
        $supB = $this->approver(Role::SUPERVISOR, 'sup-b-many@test.com');

        $submitted = Carbon::parse('2026-01-01 00:00:00');

        // App 1: admin 2h, cgs 3h, Sup A 48h, director 4h -- total 57h.
        $app1 = $this->application($submitted);
        $this->decided($app1, $admin, 'admin_verify', $submitted->copy()->addHours(2));
        $this->decided($app1, $cgs, 'cgs_review', $submitted->copy()->addHours(5));
        $this->decided($app1, $supA, 'supervisor', $submitted->copy()->addHours(53));
        $this->decided($app1, $director, 'senior_director', $submitted->copy()->addHours(57));

        // App 2: admin 1h, cgs 2h, Sup A 72h, director 3h -- total 78h.
        $app2 = $this->application($submitted);
        $this->decided($app2, $admin, 'admin_verify', $submitted->copy()->addHours(1));
        $this->decided($app2, $cgs, 'cgs_review', $submitted->copy()->addHours(3));
        $this->decided($app2, $supA, 'supervisor', $submitted->copy()->addHours(75));
        $this->decided($app2, $director, 'senior_director', $submitted->copy()->addHours(78));

        // App 3: admin 3h, cgs 4h, Sup B 10h, director 5h -- total 22h.
        $app3 = $this->application($submitted);
        $this->decided($app3, $admin, 'admin_verify', $submitted->copy()->addHours(3));
        $this->decided($app3, $cgs, 'cgs_review', $submitted->copy()->addHours(7));
        $this->decided($app3, $supB, 'supervisor', $submitted->copy()->addHours(17));
        $this->decided($app3, $director, 'senior_director', $submitted->copy()->addHours(22));

        $rows = DelayByActorReport::rows('gra_application');
        $byRole = DelayByActorReport::byRole($rows, $rows);
        $bottleneck = DelayByActorReport::bottleneckRole($byRole);

        // Supervisor's share (84.2%, 92.3%, 45.5% -- avg ~74%) dominates
        // every other role, even though raw hours alone would already
        // show this -- the real point is that share is what's reported,
        // not a raw average that an outlier could distort differently.
        $this->assertSame('supervisor', $bottleneck['role']);
        $this->assertEqualsWithDelta(74.0, $bottleneck['share_percent'], 1.0);

        $byActor = DelayByActorReport::byActor($rows, $rows);
        $supARow = $byActor->firstWhere('approver_id', $supA->id);
        $supBRow = $byActor->firstWhere('approver_id', $supB->id);

        // Sup A averages 60h (48 and 72), Sup B just 10h, against a
        // 43.3h role average -- (60-43.3)/43.3 = +38%, (10-43.3)/43.3 =
        // -77%. Sup A reads as slower than the role average (the one
        // actually driving it up); Sup B reads as much faster.
        $this->assertEqualsWithDelta(38.0, $supARow['vs_role_percent'], 1.0);
        $this->assertEqualsWithDelta(-77.0, $supBRow['vs_role_percent'], 1.0);
    }
}
