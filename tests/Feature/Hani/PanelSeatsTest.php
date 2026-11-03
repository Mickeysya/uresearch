<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSeatHistory;
use App\Modules\Hani\Notifications\PanelSeatChanged;
use App\Modules\Hani\Support\PanelSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Support\PanelSeats: release ties up the mains only; a decline frees the
 * examiner, promotes an eligible backup and updates the seat columns Jason's
 * module reads; replacements; closing out the evaluation.
 */
class PanelSeatsTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $ae;

    protected User $nonExec;

    /** @var array<string, Examiner> */
    protected array $panel;

    protected ExaminerNomination $nomination;

    protected function setUp(): void
    {
        parent::setUp();

        $supervisor = $this->supervisor();
        $this->student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
        $this->nonExec = $this->cgs();

        $make = fn (string $name, string $type) => Examiner::create([
            'name' => $name, 'email' => str($name)->slug().'@example.com', 'department' => 'Computing',
            'type' => $type, 'institution' => $type === Examiner::TYPE_EXTERNAL ? 'Universiti Malaya' : null, 'is_active' => true,
        ]);

        $this->panel = [
            'internal_main_id' => $make('Dr Internal Main', Examiner::TYPE_INTERNAL),
            'internal_backup_id' => $make('Dr Internal Backup', Examiner::TYPE_INTERNAL),
            'external_main_id' => $make('Prof External Main', Examiner::TYPE_EXTERNAL),
            'external_backup_id' => $make('Prof External Backup', Examiner::TYPE_EXTERNAL),
        ];

        $application = Application::create([
            'student_id' => $this->student->id, 'submitted_by_id' => $supervisor->id, 'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_APPROVED, 'current_stage' => 'cgs_release',
        ]);

        $this->nomination = ExaminerNomination::create(['application_id' => $application->id, 'thesis_title' => 'A thesis']
            + array_map(fn (Examiner $e) => $e->id, $this->panel));
    }

    protected function release(): void
    {
        app(PanelSeats::class)->release($this->nomination);
    }

    protected function fresh(string $seat): Examiner
    {
        return $this->panel[$seat]->fresh();
    }

    public function test_release_ties_up_the_two_mains_and_leaves_the_backups_on_standby(): void
    {
        $this->release();

        $this->assertSame(Examiner::STATE_ASSIGNED, $this->fresh('internal_main_id')->state());
        $this->assertSame(Examiner::STATE_ASSIGNED, $this->fresh('external_main_id')->state());
        $this->assertSame(Examiner::STATE_AVAILABLE, $this->fresh('internal_backup_id')->state());
        $this->assertSame(Examiner::STATE_AVAILABLE, $this->fresh('external_backup_id')->state());

        $this->assertSame(['released', 'standby', 'released', 'standby'],
            ExaminerSeatHistory::orderBy('id')->pluck('event')->all());
    }

    public function test_with_letters_to_backups_on_all_four_are_tied_up(): void
    {
        config(['hani.appointments.letters_to_backups' => true]);

        $this->release();

        foreach (array_keys($this->panel) as $seat) {
            $this->assertSame(Examiner::STATE_ASSIGNED, $this->fresh($seat)->state(), $seat);
        }
    }

    public function test_a_decline_frees_the_examiner_promotes_the_backup_and_tells_operations(): void
    {
        Notification::fake();
        $this->release();

        $this->actingAs($this->ae)
            ->post(route('examiner-panel.decline', $this->nomination), [
                'seat' => 'external_main_id', 'reason' => 'On sabbatical abroad.',
            ])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Backup Prof External Backup has been promoted'));

        $this->nomination->refresh();

        // The seat columns are the CURRENT panel: Jason's module reads them.
        $this->assertSame($this->panel['external_backup_id']->id, $this->nomination->external_main_id);
        $this->assertNull($this->nomination->external_backup_id);

        // Freed at once, with no gap: they never examined.
        $declined = $this->fresh('external_main_id');
        $this->assertNull($declined->assigned_until);
        $this->assertNull($declined->last_examination_date);
        $this->assertTrue($declined->isEligible());

        $this->assertSame(Examiner::STATE_ASSIGNED, $this->fresh('external_backup_id')->state());

        $this->assertDatabaseHas('examiner_seat_history', ['seat' => 'external_main_id', 'examiner_id' => $declined->id, 'event' => 'declined', 'reason' => 'On sabbatical abroad.']);
        $this->assertDatabaseHas('examiner_seat_history', ['seat' => 'external_main_id', 'examiner_id' => $this->panel['external_backup_id']->id, 'event' => 'promoted']);

        Notification::assertSentTo($this->nonExec, PanelSeatChanged::class,
            fn ($n) => $n->headline() === 'Backup promoted — please send the appointment letter to Prof External Backup');
    }

    public function test_an_ineligible_backup_is_not_promoted_and_a_replacement_is_appointed_from_suggestions(): void
    {
        Notification::fake();
        $this->release();

        // The backup has since examined elsewhere and is on gap.
        $this->panel['internal_backup_id']->update(['last_examination_date' => now()->subDays(5)]);

        $replacement = Examiner::create(['name' => 'Dr Replacement', 'email' => 'replacement@example.com', 'department' => 'Computing',
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true, 'expertise' => 'Anything']);
        $replacement->expertiseKeywords()->create(['keyword' => 'thesis']);

        $this->actingAs($this->ae)->post(route('examiner-panel.decline', $this->nomination), [
            'seat' => 'internal_main_id', 'reason' => 'Declined by email.',
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'choose a replacement'));

        $this->nomination->refresh();
        $this->assertNull($this->nomination->internal_main_id);
        $this->assertSame($this->panel['internal_backup_id']->id, $this->nomination->internal_backup_id, 'The ineligible backup keeps their seat.');
        $this->assertDatabaseHas('examiner_seat_history', ['seat' => 'internal_main_id', 'event' => 'vacant']);
        Notification::assertNothingSent();

        $this->actingAs($this->ae)->get(route('examiner-panel.show', $this->nomination))
            ->assertOk()
            ->assertSee('Replacement for the Internal (main) seat')
            ->assertSee('Dr Replacement');

        $this->actingAs($this->ae)->post(route('examiner-panel.replace', $this->nomination), [
            'seat' => 'internal_main_id', 'examiner_id' => $replacement->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($replacement->id, $this->nomination->fresh()->internal_main_id);
        $this->assertSame(Examiner::STATE_ASSIGNED, $replacement->fresh()->state());
        Notification::assertSentTo($this->nonExec, PanelSeatChanged::class,
            fn ($n) => $n->headline() === 'Replacement examiner appointed — please send the appointment letter to Dr Replacement');
    }

    public function test_a_replacement_must_be_eligible_and_from_the_right_department(): void
    {
        $this->release();
        $this->actingAs($this->ae)->post(route('examiner-panel.decline', $this->nomination), ['seat' => 'internal_main_id', 'reason' => 'x']);
        // Backup promoted; decline them too, leaving the internal main empty.
        $this->actingAs($this->ae)->post(route('examiner-panel.decline', $this->nomination), ['seat' => 'internal_main_id', 'reason' => 'y']);
        $this->assertNull($this->nomination->fresh()->internal_main_id);

        $elsewhere = Examiner::create(['name' => 'Dr Elsewhere', 'email' => 'elsewhere@example.com', 'department' => 'Petroleum Engineering',
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true]);

        $this->actingAs($this->ae)->post(route('examiner-panel.replace', $this->nomination), [
            'seat' => 'internal_main_id', 'examiner_id' => $elsewhere->id,
        ])->assertSessionHasErrors('examiner_id');

        $this->assertNull($this->nomination->fresh()->internal_main_id);
    }

    public function test_a_decline_needs_a_reason_and_only_a_main_can_decline(): void
    {
        $this->release();

        $this->actingAs($this->ae)->post(route('examiner-panel.decline', $this->nomination), ['seat' => 'external_main_id', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->ae)->post(route('examiner-panel.decline', $this->nomination), ['seat' => 'external_backup_id', 'reason' => 'x'])
            ->assertSessionHasErrors('seat');

        $this->assertSame($this->panel['external_main_id']->id, $this->nomination->fresh()->external_main_id);
    }

    public function test_only_the_departments_ae_or_the_senior_executive_manage_a_released_panel(): void
    {
        $this->release();

        $otherAe = $this->user(Role::ACADEMIC_EXEC, ['email' => 'ae.other@test.my', 'department' => 'Petroleum Engineering']);
        $this->actingAs($otherAe)->get(route('examiner-panel.show', $this->nomination))->assertForbidden();
        $this->actingAs($this->nonExec)->get(route('examiner-panel.show', $this->nomination))->assertForbidden();
        $this->actingAs($this->seniorExec())->get(route('examiner-panel.show', $this->nomination))->assertOk();

        // Not released yet: nothing to decline.
        $this->nomination->application->forceFill(['status' => Application::STATUS_PENDING])->save();
        $this->actingAs($this->ae)->get(route('examiner-panel.show', $this->nomination))->assertNotFound();
    }

    public function test_completing_the_evaluation_starts_the_gap_for_the_examiners_and_frees_an_unused_backup(): void
    {
        config(['hani.appointments.letters_to_backups' => true]);
        $this->release();

        $this->actingAs($this->ae)->get(route('examiner-nomination.pending-evaluation'))
            ->assertOk()->assertSee('Application #'.$this->nomination->application_id);

        $this->actingAs($this->ae)->post(route('examiner-nomination.mark-complete', $this->nomination))->assertRedirect();

        foreach (['internal_main_id', 'external_main_id'] as $main) {
            $this->assertSame(Examiner::STATE_ON_GAP, $this->fresh($main)->state(), $main);
        }

        foreach (['internal_backup_id', 'external_backup_id'] as $backup) {
            $this->assertSame(Examiner::STATE_AVAILABLE, $this->fresh($backup)->state(), "{$backup} is freed with no gap.");
            $this->assertNull($this->fresh($backup)->last_examination_date);
        }
    }

    public function test_pending_evaluation_is_the_academic_executives_own_department(): void
    {
        $this->release();
        $otherAe = $this->user(Role::ACADEMIC_EXEC, ['email' => 'ae.other@test.my', 'department' => 'Petroleum Engineering']);

        $this->actingAs($otherAe)->get(route('examiner-nomination.pending-evaluation'))
            ->assertOk()->assertDontSee('Application #'.$this->nomination->application_id);
        $this->actingAs($otherAe)->post(route('examiner-nomination.mark-complete', $this->nomination))->assertForbidden();
    }
}
