<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item g: the suggested outcome, the level mapping, the deadlines,
 * the notifications (no examiner names or categories), the level-4 prompt
 * and the examiners' release.
 */
class ReVivaOutcomeTest extends TestCase
{
    use BuildsReVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Every name and per-examiner wording a student must never be told. */
    protected function assertNamesNoExaminer(ReVivaUpdate $n, array $viva): void
    {
        foreach ([$viva['panel']->internal_main_id, $viva['panel']->external_main_id] as $id) {
            $this->assertStringNotContainsString(Examiner::find($id)->name, $n->text());
        }
        foreach (['Internal', 'External', 'viva again', 'category', 'Consolidation'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $n->text());
        }
    }

    public function test_agreeing_examiners_are_suggested_and_the_outcome_completes_the_evaluation(): void
    {
        $viva = $this->roundAwaitingOutcome([2], [2]);
        Carbon::setTestNow('2027-11-20 10:00');

        $this->actingAs($viva['ae'])->get(route('reviva.outcomes.index'))
            ->assertOk()
            ->assertSee('Suggested')
            ->assertSee('Both examiners recommend Minor corrections')
            ->assertSee('value="2" selected', false)
            ->assertSee('level 4 means an oral examination is required, and CGS logs the next round', false);

        $this->recordOutcome($viva, 2)->assertSessionHasNoErrors();

        $round = $viva['round']->fresh();
        $this->assertSame(2, $round->outcome_level);
        $this->assertSame(2, $round->suggested_level);
        $this->assertSame('2028-02-20', $round->correction_deadline->toDateString());

        // The hold from the viva is released: the mains examined, so the gap starts.
        foreach ([$viva['panel']->internal_main_id, $viva['panel']->external_main_id] as $id) {
            $examiner = Examiner::find($id);
            $this->assertNull($examiner->assigned_until);
            $this->assertSame('2027-11-20', $examiner->last_examination_date->toDateString());
        }

        foreach ([$viva['student'], $viva['supervisor']] as $user) {
            Notification::assertSentTo($user, ReVivaUpdate::class, function (ReVivaUpdate $n) use ($viva) {
                $this->assertNamesNoExaminer($n, $viva);

                return $n->kind === 'outcome' && str_contains($n->text(), 'Level 2') && str_contains($n->text(), '20 Feb 2028');
            });
        }
    }

    public function test_diverging_examiners_need_consolidation_remarks_and_level_four_prompts_the_next_round(): void
    {
        $viva = $this->roundAwaitingOutcome([3], [4, true]);
        $senior = $this->senior();
        Carbon::setTestNow('2027-11-20 10:00');

        $this->actingAs($viva['ae'])->get(route('reviva.outcomes.index'))
            ->assertSee('Consolidation needed')
            ->assertSee('Internal 3 (Major corrections) (level 3) · External 4 (Fail, viva again: Yes) (level 4)');

        $this->recordOutcome($viva, 4)->assertSessionHasErrors('outcome_remarks');
        $this->assertNull($viva['round']->fresh()->outcome_level);

        $this->recordOutcome($viva, 4, 'Panel agreed at consolidation that an oral examination is needed.')->assertSessionHasNoErrors();
        $round = $viva['round']->fresh();
        $this->assertSame(4, $round->outcome_level);
        $this->assertNull($round->suggested_level);
        $this->assertNull($round->correction_deadline);

        // Still held, at least to the close of the next round's window (outcome
        // + 6 months); holds only ever extend, and re-viva:monitor frees them
        // if that window closes with no round logged.
        $examiner = Examiner::find($viva['panel']->internal_main_id);
        $this->assertTrue($examiner->assigned_until->gte(Carbon::parse('2028-05-20')));
        $this->assertNull($examiner->last_examination_date);

        $link = route('reviva.create', ['student' => $viva['student']->id]);
        Notification::assertSentTo($senior, ReVivaUpdate::class, fn ($n) => $n->kind === 'next_round_to_log' && $n->toMail($senior)->actionUrl === $link);
        Notification::assertSentTo($viva['student'], ReVivaUpdate::class, function ($n) use ($viva) {
            $this->assertNamesNoExaminer($n, $viva);

            return str_contains($n->text(), 'oral examination is required') && str_contains($n->text(), 'round 2');
        });

        $this->actingAs($senior)->get(route('reviva.cycles.index'))->assertSee('Next round to log (1)')->assertSee('Log round 2');
        $this->actingAs($senior)->get($link)->assertOk()->assertSee('20 Nov 2027 to 20 May 2028');
    }

    public function test_four_with_viva_no_maps_to_level_five_which_is_final(): void
    {
        $viva = $this->roundAwaitingOutcome([4, false], [4, false]);

        $this->actingAs($viva['ae'])->get(route('reviva.outcomes.index'))->assertSee('Level 5: Fail (final)');
        $this->recordOutcome($viva, 5)->assertSessionHasNoErrors();

        $this->assertSame(5, $viva['round']->fresh()->suggested_level);
        $this->assertNull(Examiner::find($viva['panel']->external_main_id)->assigned_until);
        $this->assertNotNull(Examiner::find($viva['panel']->external_main_id)->last_examination_date);

        $this->logReViva($viva['student'])->assertSessionHasErrors('student_id');
    }

    public function test_overriding_an_agreed_suggestion_needs_a_reason(): void
    {
        $viva = $this->roundAwaitingOutcome([1], [1]);

        $this->recordOutcome($viva, 2)->assertSessionHasErrors('outcome_remarks');
        $this->recordOutcome($viva, 2, 'The Dean asked for minor corrections to the abstract.')->assertSessionHasNoErrors();
        $this->assertSame(1, $viva['round']->fresh()->suggested_level);
        $this->assertSame(2, $viva['round']->fresh()->outcome_level);
    }

    public function test_final_thesis_deadline_per_level(): void
    {
        foreach ([1 => '2027-12-20', 2 => '2028-02-20', 3 => '2028-05-20'] as $level => $due) {
            $viva = $this->roundAwaitingOutcome([$level], [$level], '2200100'.$level);
            Carbon::setTestNow('2027-11-20 10:00');
            $this->recordOutcome($viva, $level)->assertSessionHasNoErrors();
            $this->assertSame($due, $viva['round']->fresh()->correction_deadline->toDateString(), "level {$level}");
        }
    }

    public function test_outcomes_are_scoped_to_the_aes_department(): void
    {
        $viva = $this->roundAwaitingOutcome([2], [2]);
        $other = $this->academicExecFor('Chemical Engineering');

        $this->actingAs($other)->get(route('reviva.outcomes.index'))->assertOk()->assertDontSee($viva['student']->name);
        $this->actingAs($other)->post(route('reviva.outcomes.record', $viva['round']), ['outcome_level' => 2])->assertForbidden();
        $this->assertNull($viva['round']->fresh()->outcome_level);
    }
}
