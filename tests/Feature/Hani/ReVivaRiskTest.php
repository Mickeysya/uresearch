<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Support\ReVivaRisk;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item j: the rule-based deadline risk score, its factors, and
 * Re-viva Cycles sorted by it.
 */
class ReVivaRiskTest extends TestCase
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

    protected function factor(array $risk, string $key): array
    {
        return collect($risk['factors'])->firstWhere('key', $key);
    }

    public function test_every_point_is_explained_by_a_factor(): void
    {
        $viva = $this->roundUnderPanelReview();   // logged and sent Fri 1 Oct 2027

        // 9 Nov 2027: 25 working days (skipping Deepavali and the Sultan's birthday).
        Carbon::setTestNow('2027-11-09 09:00');
        $risk = ReVivaRisk::for($viva['round']->fresh());

        $this->assertSame(30, $this->factor($risk, 'stage_time')['points'], 'capped at its weight');
        $this->assertSame('25 of 20 working days at Under Panel Review', $this->factor($risk, 'stage_time')['detail']);
        $this->assertSame(19, $this->factor($risk, 'time_used')['points'], '25 of 45 expected working days, x 35');
        $this->assertSame(0, $this->factor($risk, 'divergence')['points']);
        $this->assertSame(0, $this->factor($risk, 'previous_rounds')['points']);
        $this->assertSame(49, $risk['score']);
        $this->assertSame('medium', $risk['band']);
        $this->assertSame($risk['score'], array_sum(array_column($risk['factors'], 'points')));
    }

    public function test_divergence_and_earlier_rounds_add_to_the_score_and_closed_rounds_have_none(): void
    {
        $viva = $this->roundAwaitingOutcome([3], [4, true]);
        $risk = ReVivaRisk::for($viva['round']->fresh());
        $this->assertSame(20, $this->factor($risk, 'divergence')['points']);
        $this->assertStringContainsString('awaiting the outcome', $this->factor($risk, 'stage_time')['detail']);

        $this->recordOutcome($viva, 4, 'Agreed at consolidation.');
        Carbon::setTestNow('2027-12-01 09:00');
        $this->logReViva($viva['student'])->assertSessionHasNoErrors();
        $second = $this->latestRound($viva['student']);
        $this->assertSame(8, $this->factor(ReVivaRisk::for($second), 'previous_rounds')['points'], 'one earlier round of two, x 15');

        $passed = $this->roundAwaitingOutcome([4, false], [4, false], '22001003');
        $this->recordOutcome($passed, 5);
        $this->assertNull(ReVivaRisk::for($passed['round']->fresh()), 'level 5 is closed');
    }

    public function test_cycles_are_sorted_by_risk_and_show_the_factors(): void
    {
        $slow = $this->roundUnderPanelReview('22001001');
        $fresh = $this->roundAtStageOne('22001002');   // logged 1 Oct, still at stage 1
        Carbon::setTestNow('2027-10-04 09:00');
        $this->sendToPanel($fresh);
        Carbon::setTestNow('2027-11-09 09:00');

        $page = $this->actingAs($this->senior())->get(route('reviva.cycles.index'))
            ->assertOk()
            ->assertSee('Time in stage')
            ->assertSee('Examiner divergence')
            ->getContent();

        $this->assertLessThan(
            strpos($page, $fresh['student']->name),
            strpos($page, $slow['student']->name),
            'the round with more time used is listed first',
        );
    }
}
