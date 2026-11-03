<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceAlert;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Notifications\AttendanceAtRisk;
use App\Modules\Nureen\Support\AttendanceSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Nureen\Concerns\FilesAttendanceAppeals;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The end-of-semester projection and the automatic early-warning emails.
 *
 * Periods are weekly (period ending Friday), 20 sessions each, in a
 * 14-period semester -- the config defaults. The sync is run through the
 * real AttendanceSyncRunner with the 'api' source, whose queue is empty
 * here: nothing new is ingested, but the risk check that ends every sync
 * still runs, which is exactly the "automatic, no CGS action" path.
 *
 * Worked figures for the projection-only student, attended [20, 20, 19, 18]:
 * rates 100, 100, 95, 90 -- the latest period is 90%, so the original rule
 * is NOT tripped -- current 96.3%, slope -3.5 pts/period, projected 78.8%.
 */
class AttendanceEarlyWarningTest extends TestCase
{
    use FilesAttendanceAppeals;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nureen.attendance.semester_periods' => 14, 'nureen.attendance.semester_start' => null]);
    }

    protected function studentWith(array $attended, string $matric = '22001001', int $total = 20): User
    {
        $supervisor = $this->supervisor("sup-{$matric}@test.my");
        $student = $this->student($matric, "s{$matric}@test.my", ['supervisor_id' => $supervisor->id]);
        $this->setPeriods($student, $attended, $total);

        return $student;
    }

    protected function setPeriods(User $student, array $attended, int $total = 20): void
    {
        $friday = CarbonImmutable::parse('2026-09-04');

        foreach (array_values($attended) as $i => $a) {
            $record = AttendanceRecord::recordPeriod($student->id, $friday->addWeeks($i)->toDateString(), $a, $total);
            $record->update(['at_risk' => \App\Modules\Nureen\Support\AttendanceRiskEvaluator::isAtRisk($record)]);
        }
    }

    protected function sync(): void
    {
        app(AttendanceSyncRunner::class)->run('api');
    }

    protected function renderedMail(AttendanceAtRisk $notification, User $to): string
    {
        return (string) $notification->toMail($to)->render();
    }

    /* -----------------------------------------------------------------
     | Who is flagged, and what they are told
     |------------------------------------------------------------------*/

    public function test_a_projection_only_student_is_emailed_automatically_without_the_projected_figure(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 19, 18]);

        $this->sync();

        $state = AttendanceRiskState::where('student_id', $student->id)->sole();
        $this->assertTrue($state->at_risk);
        $this->assertSame('projected', $state->reason);
        $this->assertSame(96.3, $state->current_percentage);
        $this->assertSame(78.8, $state->projected_percentage);
        $this->assertSame(-3.5, $state->trend_slope);

        // The original rule did not flag them, and its per-period flag is untouched.
        $this->assertFalse(AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first()->at_risk);

        Notification::assertSentTo($student, AttendanceAtRisk::class, function (AttendanceAtRisk $n) use ($student) {
            $mail = $this->renderedMail($n, $student);

            return str_contains($mail, 'declining')
                && str_contains($mail, 'may fall below the 80% attendance requirement')
                && str_contains($mail, '96.3%')
                && str_contains($mail, route('attendance-appeal.create'))
                && str_contains($mail, $student->name)
                && ! str_contains($mail, '78.8')
                && ! str_contains($mail, '78.75')
                && ! str_contains($mail, '3.5')
                && ! preg_match('/project/i', $mail);
        });

        // The supervisor's copy follows the same no-projection rule.
        Notification::assertSentTo($student->supervisor, AttendanceAtRisk::class, function (AttendanceAtRisk $n) use ($student) {
            $mail = $this->renderedMail($n, $student->supervisor);

            return str_contains($mail, $student->name) && str_contains($mail, '96.3%') && ! str_contains($mail, '78.8');
        });
    }

    public function test_a_student_flagged_by_the_current_rule_gets_the_plain_wording(): void
    {
        Notification::fake();
        // One period at 75%: below the line now, and too short for a projection.
        $student = $this->studentWith([15]);

        $this->sync();

        $this->assertSame('current', AttendanceRiskState::where('student_id', $student->id)->value('reason'));

        Notification::assertSentTo($student, AttendanceAtRisk::class, function (AttendanceAtRisk $n) use ($student) {
            $mail = $this->renderedMail($n, $student);

            return str_contains($mail, 'is below, or close to, the 80% attendance requirement')
                && str_contains($mail, '75%')
                && ! str_contains($mail, 'declining');
        });
    }

    public function test_a_steady_student_above_80_is_neither_flagged_nor_emailed(): void
    {
        Notification::fake();
        $student = $this->studentWith([18, 18, 18, 18]);

        $this->sync();

        $this->assertFalse(AttendanceRiskState::where('student_id', $student->id)->value('at_risk'));
        Notification::assertNothingSent();
        $this->assertSame(0, AttendanceAlert::count());
    }

    public function test_the_original_rule_flags_exactly_as_before(): void
    {
        Notification::fake();
        // Declining three periods and within 5 points of 80: the original
        // early-warning rule, per period, unchanged.
        $student = $this->studentWith([19, 18, 17], '22001002');  // 95, 90, 85

        $latest = AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first();
        $this->assertTrue($latest->at_risk);

        $this->sync();

        // 'current', not 'both': with three periods the projection exists
        // but may not flag yet (projection_min_periods_to_flag = 4).
        $this->assertSame('current', AttendanceRiskState::where('student_id', $student->id)->value('reason'));
        $this->assertTrue($latest->fresh()->at_risk);
    }

    /* -----------------------------------------------------------------
     | No repeated alerts
     |------------------------------------------------------------------*/

    public function test_running_twice_sends_one_email_and_only_a_drop_beyond_the_margin_sends_another(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 19, 18]);

        $this->sync();
        $this->sync();

        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 1);
        $this->assertSame(1, AttendanceAlert::where('student_id', $student->id)->count());

        // A small further drop (projected 78.8 -> 76.0, under the 5-point margin): still one.
        $this->setPeriods($student, [20, 20, 19, 18, 17]);
        $this->sync();
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 1);

        // A sharp drop (projected well over 5 points lower): a second email.
        $this->setPeriods($student, [20, 20, 19, 18, 10]);
        $this->sync();
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 2);
        $this->assertSame(2, AttendanceAlert::where('student_id', $student->id)->count());
    }

    public function test_recovering_and_then_dropping_again_sends_a_new_email(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 19, 18]);

        $this->sync();
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 1);

        // Recovered: the same periods, now steady and high.
        $this->setPeriods($student, [20, 20, 20, 20]);
        $this->sync();
        $this->assertFalse(AttendanceRiskState::where('student_id', $student->id)->value('at_risk'));
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 1);

        // Declining again: newly at risk, so a new alert -- no margin needed.
        $this->setPeriods($student, [20, 20, 20, 20, 19, 18, 17]);
        $this->sync();
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 2);
    }

    /* -----------------------------------------------------------------
     | What CGS sees, and what students never see
     |------------------------------------------------------------------*/

    public function test_every_alert_is_recorded_and_shown_as_last_emailed_on_the_cgs_list(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30'));
        $student = $this->studentWith([20, 20, 19, 18], '22005555');
        $steady = $this->studentWith([18, 18, 18, 18], '22006666');

        $this->sync();

        $alert = AttendanceAlert::where('student_id', $student->id)->sole();
        $this->assertSame('projected', $alert->reason);
        $this->assertSame(96.3, $alert->current_percentage);
        $this->assertSame(78.8, $alert->projected_percentage);
        $this->assertTrue($alert->supervisor_copied);

        $this->actingAs($this->cgs())
            ->get(route('attendance.at-risk', ['q' => '22005555']))
            ->assertOk()
            ->assertSee('22005555')
            ->assertSee('96.3%')
            ->assertSee('78.8%')
            ->assertSee('-3.5 pts/period')
            ->assertSee('Projected')
            ->assertSee('5 Oct 2026')
            ->assertSee('9:30 am')
            ->assertDontSee('22006666');
    }

    public function test_the_cgs_list_sorts_by_projected_percentage_lowest_first(): void
    {
        Notification::fake();
        $this->studentWith([20, 20, 19, 18], '22007001');   // projected 78.8
        $this->studentWith([20, 19, 17, 15], '22007002');   // steeper, current rule too

        $this->sync();
        $cgs = $this->cgs();

        $this->actingAs($cgs)
            ->get(route('attendance.at-risk'))
            ->assertSeeInOrder(['22007002', '22007001']);
        $this->actingAs($cgs)
            ->get(route('attendance.at-risk', ['sort' => 'name']))
            ->assertOk();
    }

    public function test_no_student_facing_page_shows_the_projected_figure(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 19, 18]);
        $this->sync();

        foreach (['attendance.overview', 'attendance.history', 'dashboard'] as $route) {
            $page = $this->actingAs($student)->get(route($route))->assertOk();

            // The projection (78.8%) and its trend never reach a student --
            // nor does any "predicted" or "projected" wording.
            $page->assertDontSee('78.8')->assertDontSee('pts/period')
                ->assertDontSee('Predicted Attendance')->assertDontSee('Projected')->assertDontSee('Trending Down');
        }

        // The dashboard's cards: the Attendance Action card is plain session
        // arithmetic (77 of 80 so far; 10 more weeks of 20 sessions; 80% of
        // 280 is 224, so 147 more), and Active Applications says nothing
        // about a date when nothing is pending.
        $this->actingAs($student)->get(route('dashboard'))
            ->assertSee('Attendance Action')
            ->assertSee('Attend 147 of the next 200 sessions')
            ->assertSee('to stay at or above 80% this semester')
            ->assertDontSee('next decision by');

        // The current semester figure IS shown -- it is what the email quotes.
        $this->actingAs($student)->get(route('attendance.overview'))->assertSee('96.3%')->assertSee('This semester so far');

        $this->actingAs($student)->get(route('attendance.at-risk'))->assertForbidden();
    }

    /* -----------------------------------------------------------------
     | Approved appeals count
     |------------------------------------------------------------------*/

    public function test_an_approved_appeal_excuses_absences_and_removes_the_flag(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 15]);  // latest period 75%
        $this->sync();
        $this->assertTrue(AttendanceRiskState::where('student_id', $student->id)->value('at_risk'));

        $latest = AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first();
        $this->assertTrue($latest->at_risk);

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + [
            'attendance_record_id' => $latest->id,
            'sessions_requested' => 5,
            'reason' => 'I was on approved medical leave for the five sessions marked absent that week.',
        ])->assertRedirect();

        $appeal = Application::where('module_type', 'attendance_appeal')->sole();
        $cgs = $this->cgs();
        $this->actingAs($cgs)
            ->post(route('attendance-appeal.decide', $appeal), ['decision' => 'approve'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '5 of 5 requested sessions excused'));

        $latest->refresh();
        $this->assertSame(15, $latest->sessions_attended);   // UTrace's figure is never rewritten
        $this->assertSame(5, $latest->sessions_excused);
        $this->assertSame('100.00', (string) $latest->percentage);
        $this->assertFalse($latest->at_risk);

        $state = AttendanceRiskState::where('student_id', $student->id)->sole();
        $this->assertFalse($state->at_risk);
        $this->assertSame(100.0, $state->current_percentage);

        // Off CGS's list, and no further email for it.
        Notification::assertSentToTimes($student, AttendanceAtRisk::class, 1);
        $this->actingAs($cgs)->get(route('attendance.at-risk'))->assertDontSee($student->matric_no);

        // The student's history says why the period now reads 100%.
        $this->actingAs($student)->get(route('attendance.history'))
            ->assertSee('15 / 20 sessions')
            ->assertSee('+ 5 excused by an approved appeal');

        // A re-upload of that period keeps the excusal.
        AttendanceRecord::recordPeriod($student->id, $latest->period_end->toDateString(), 15, 20);
        $this->assertSame(5, $latest->fresh()->sessions_excused);
    }

    public function test_a_rejected_appeal_changes_nothing_and_an_appeal_cannot_cover_more_than_was_missed(): void
    {
        Notification::fake();
        $student = $this->studentWith([20, 20, 15]);
        $latest = AttendanceRecord::where('student_id', $student->id)->latest('period_end')->first();

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + [
            'attendance_record_id' => $latest->id,
            'sessions_requested' => 6,
            'reason' => 'I was on approved medical leave for the sessions marked absent that week.',
        ])->assertSessionHasErrors('sessions_requested');

        $this->actingAs($student)->post(route('attendance-appeal.store'), $this->appealDocuments() + [
            'attendance_record_id' => $latest->id,
            'sessions_requested' => 5,
            'reason' => 'I was on approved medical leave for the sessions marked absent that week.',
        ]);

        $appeal = Application::where('module_type', 'attendance_appeal')->sole();
        $this->actingAs($this->cgs())->post(route('attendance-appeal.decide', $appeal), [
            'decision' => 'reject', 'remarks' => 'No medical certificate was provided.',
        ]);

        $this->assertSame(0, $latest->fresh()->sessions_excused);
        $this->assertSame('75.00', (string) $latest->fresh()->percentage);
    }
}
