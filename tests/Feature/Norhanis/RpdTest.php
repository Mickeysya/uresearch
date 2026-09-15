<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Console\Commands\SendRpdReminders;
use App\Modules\Norhanis\Models\Candidacy;
use App\Modules\Norhanis\Models\RpdAppealDetail;
use App\Modules\Norhanis\Models\RpdReminderLog;
use App\Modules\Norhanis\Notifications\RpdDeadlineApproaching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * RPD Candidacy — `docs/scope/norhanis.md` Module 4, all three flows.
 *
 * The three things here that are worth a test are the three that would fail
 * silently: the deadline window, the reminder firing exactly once, and the
 * masterlist actually moving when the Dean approves an appeal.
 */
class RpdTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /* ---------------- deadline arithmetic ---------------- */

    public function test_the_rpd_window_is_eight_months_full_time_and_twelve_part_time(): void
    {
        $start = \Carbon\Carbon::parse('2026-01-15');

        $this->assertSame('2026-09-15', Candidacy::initialDeadline('msc_ft', $start)->toDateString());
        $this->assertSame('2026-09-15', Candidacy::initialDeadline('phd_ft', $start)->toDateString());
        $this->assertSame('2027-01-15', Candidacy::initialDeadline('msc_pt', $start)->toDateString());
        $this->assertSame('2027-01-15', Candidacy::initialDeadline('phd_pt', $start)->toDateString());
    }

    public function test_a_month_end_start_date_clamps_rather_than_rolling_over(): void
    {
        // 31 Jan + 8 months is 30 Sep, not 1 Oct. Rolling over would quietly
        // hand every month-end student an extra day.
        $this->assertSame(
            '2026-09-30',
            Candidacy::initialDeadline('msc_ft', \Carbon\Carbon::parse('2026-01-31'))->toDateString()
        );
    }

    /* ---------------- reminders ---------------- */

    public function test_a_reminder_fires_once_and_not_again_the_next_day(): void
    {
        Notification::fake();

        $candidacy = $this->candidacy(deadlineInDays: 85); // inside the 3-month window

        $this->artisan('rpd:remind')->assertSuccessful();
        $this->artisan('rpd:remind')->assertSuccessful();

        // Student + supervisor, once each -- not twice for the second run.
        Notification::assertSentTimes(RpdDeadlineApproaching::class, 2);
        $this->assertSame(1, RpdReminderLog::where('candidacy_id', $candidacy->id)->count());
    }

    public function test_a_candidacy_entered_late_gets_only_the_milestone_it_is_due(): void
    {
        Notification::fake();

        // 20 days out: past the 3- and 2-month marks, inside the 1-month one.
        $candidacy = $this->candidacy(deadlineInDays: 20);

        $this->artisan('rpd:remind')->assertSuccessful();

        $log = RpdReminderLog::where('candidacy_id', $candidacy->id)->sole();
        $this->assertSame(1, $log->milestone, 'A late entry should not replay reminders it already missed.');
    }

    public function test_a_defended_candidacy_is_not_reminded(): void
    {
        Notification::fake();

        $this->candidacy(deadlineInDays: 85, status: Candidacy::STATUS_DEFENDED);

        $this->artisan('rpd:remind')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /* ---------------- appeals ---------------- */

    public function test_the_ceiling_caps_what_a_student_may_request(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: 40);
        $candidacy->update(['extension_months_used' => 10]);

        $this->actingAs($candidacy->student);

        // 3 months asked, 2 left under the twelve-month ceiling.
        $this->post(route('rpd-appeal.store'), [
            'requested_months' => 3,
            'justification' => str_repeat('Fieldwork access was delayed by the refinery shutdown. ', 2),
        ])->assertSessionHasErrors('requested_months');

        $this->assertSame(0, Application::where('module_type', 'rpd_appeal')->count());
    }

    public function test_dean_approval_moves_the_masterlist_and_clears_the_reminder_log(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: 40);
        $originalDeadline = $candidacy->rpd_deadline->copy();

        // A reminder has already fired against the OLD deadline.
        RpdReminderLog::create([
            'candidacy_id' => $candidacy->id,
            'milestone' => 2,
            'deadline_at_send' => $originalDeadline,
            'sent_at' => now(),
        ]);

        $this->actingAs($candidacy->student);
        $this->post(route('rpd-appeal.store'), [
            'requested_months' => 4,
            'justification' => str_repeat('Fieldwork access was delayed by the refinery shutdown. ', 2),
        ])->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'rpd_appeal')->sole();

        foreach ([$candidacy->student->supervisor, $this->chair(), $this->cgs(), $this->dean()] as $approver) {
            $this->actingAs($approver)
                ->post(route('rpd-appeal.decide', $application), ['decision' => 'approve'])
                ->assertRedirect();
        }

        $candidacy->refresh();

        $this->assertSame(
            $originalDeadline->copy()->addMonthsNoOverflow(4)->toDateString(),
            $candidacy->rpd_deadline->toDateString(),
            'The Dean approving an appeal must move the masterlist deadline.'
        );
        $this->assertSame(Candidacy::STATUS_EXTENDED, $candidacy->status);
        $this->assertSame(4, $candidacy->extension_months_used);

        $detail = RpdAppealDetail::where('application_id', $application->id)->sole();
        $this->assertSame($candidacy->rpd_deadline->toDateString(), $detail->new_deadline->toDateString());

        // Stale milestones cleared, or the new deadline is never reminded about.
        $this->assertSame(0, RpdReminderLog::where('candidacy_id', $candidacy->id)->count());
    }

    public function test_an_intermediate_rejection_leaves_the_deadline_alone(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: 40);
        $originalDeadline = $candidacy->rpd_deadline->copy();

        $this->actingAs($candidacy->student);
        $this->post(route('rpd-appeal.store'), [
            'requested_months' => 4,
            'justification' => str_repeat('Fieldwork access was delayed by the refinery shutdown. ', 2),
        ]);

        $application = Application::where('module_type', 'rpd_appeal')->sole();

        $this->actingAs($candidacy->student->supervisor)
            ->post(route('rpd-appeal.decide', $application), ['decision' => 'reject', 'remarks' => 'Not justified.']);

        $candidacy->refresh();

        $this->assertSame($originalDeadline->toDateString(), $candidacy->rpd_deadline->toDateString());
        $this->assertSame(0, $candidacy->extension_months_used);
    }

    /* ---------------- dismissals ---------------- */

    public function test_cgs_cannot_dismiss_a_student_who_is_still_within_their_deadline(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: 30);

        $this->actingAs($this->cgs())
            ->post(route('rpd-dismissal.store'), [
                'candidacy_id' => $candidacy->id,
                'grounds' => str_repeat('The deadline has long passed with no contact. ', 2),
            ])
            ->assertSessionHasErrors('candidacy_id');

        $this->assertSame(0, Application::where('module_type', 'rpd_dismissal')->count());
    }

    public function test_registry_approval_closes_the_candidacy(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: -30);

        $this->actingAs($this->cgs())
            ->post(route('rpd-dismissal.store'), [
                'candidacy_id' => $candidacy->id,
                'grounds' => str_repeat('The deadline has long passed with no contact. ', 2),
            ])
            ->assertRedirect(route('candidacies.index'));

        $application = Application::where('module_type', 'rpd_dismissal')->sole();

        // CGS authored it, so the chain starts at the Dean, not at CGS.
        $this->assertSame('dean', $application->current_stage);
        $this->assertSame($candidacy->student_id, $application->student_id);

        foreach ([$this->dean(), $this->faculty(), $this->registry()] as $approver) {
            $this->actingAs($approver)
                ->post(route('rpd-dismissal.decide', $application), ['decision' => 'approve'])
                ->assertRedirect();
        }

        $candidacy->refresh();
        $this->assertSame(Candidacy::STATUS_DISMISSED, $candidacy->status);
        $this->assertNotNull($candidacy->student->applications()
            ->where('module_type', 'rpd_dismissal')->sole()->id);
    }

    /* ---------------- the screens render ---------------- */

    public function test_every_rpd_screen_renders(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: -5);

        $this->actingAs($candidacy->student);
        $this->get(route('candidacies.mine'))->assertOk()->assertSee('My RPD Candidacy');
        $this->get(route('rpd-appeal.create'))->assertOk();

        $cgs = $this->cgs();
        $this->actingAs($cgs);
        $this->get(route('candidacies.index'))->assertOk()->assertSee('RPD Masterlist');
        $this->get(route('candidacies.create'))->assertOk();
        $this->get(route('rpd-dismissal.create'))->assertOk()->assertSee($candidacy->student->name);

        // Each approver's queue, which is where the shared queue partial and
        // the module's own _detail partial meet.
        $this->actingAs($candidacy->student->supervisor);
        $this->get(route('rpd-appeal.queue'))->assertOk();

        $this->actingAs($this->dean());
        $this->get(route('rpd-dismissal.queue'))->assertOk();
    }

    public function test_a_student_cannot_reach_the_masterlist_or_open_a_dismissal(): void
    {
        $candidacy = $this->candidacy(deadlineInDays: -5);

        $this->actingAs($candidacy->student);

        $this->get(route('candidacies.index'))->assertForbidden();
        $this->get(route('rpd-dismissal.create'))->assertForbidden();
    }

    /* ---------------- helpers ---------------- */

    protected function candidacy(int $deadlineInDays, string $status = Candidacy::STATUS_ACTIVE): Candidacy
    {
        $student = $this->student();
        $student->update(['supervisor_id' => $this->supervisor()->id]);

        return Candidacy::create([
            'student_id' => $student->id,
            'programme_type' => 'phd_ft',
            'candidature_start_date' => now()->subMonths(6),
            'rpd_deadline' => now()->addDays($deadlineInDays),
            'status' => $status,
        ])->load('student');
    }
}
