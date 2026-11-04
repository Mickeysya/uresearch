<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item b: a category-4 viva hands off to the re-viva, with the
 * resubmission window carried across and checked (warned, never blocked).
 */
class ReVivaHandoffTest extends TestCase
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
        Carbon::setTestNow('2027-03-15 09:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_category_four_viva_is_preselected_and_linked_with_its_window(): void
    {
        // Viva 1 Mar 2027: window 1 Sep 2027 to 1 Mar 2028.
        $viva = $this->categoryFourViva();

        $this->actingAs($this->senior())->get(route('reviva.create', ['student' => $viva['student']->id]))
            ->assertOk()
            ->assertViewHas('window', fn ($w) => $w->viva->is($viva['detail']))
            ->assertSee('Expected resubmissions (1)')
            ->assertSee('1 Sep 2027 to 1 Mar 2028')
            ->assertSee('Logged before the resubmission window opens', false);

        Carbon::setTestNow('2027-10-01 09:00');
        $this->logReViva($viva['student'])->assertSessionHasNoErrors()->assertSessionMissing('warning');

        $round = $this->latestRound($viva['student']);
        $this->assertSame($viva['notice']->id, $round->viva_application_id);
        $this->assertSame('2027-09-01', $round->resubmission_opens_at->toDateString());
        $this->assertSame('2028-03-01', $round->resubmission_closes_at->toDateString());
        $this->assertFalse($round->logged_outside_window);
        $this->assertNull($round->correction_deadline);
        $this->assertNull($round->hardbound_deadline);

        // Logging extends the main examiners' hold.
        $this->assertSame('2028-10-01', Examiner::find($viva['panel']->internal_main_id)->assigned_until->toDateString());
    }

    public function test_logging_outside_the_window_warns_but_is_not_blocked(): void
    {
        $early = $this->categoryFourViva('22001001');
        $late = $this->categoryFourViva('22001002');

        // Two weeks after the viva: the window does not open until September.
        $this->logReViva($early['student'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'before the resubmission window opens'));
        $this->assertTrue($this->latestRound($early['student'])->logged_outside_window);

        Carbon::setTestNow('2028-03-10 09:00');
        $this->logReViva($late['student'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'after the resubmission window closed'));
        $this->assertTrue($this->latestRound($late['student'])->logged_outside_window);

        $this->actingAs($this->senior())->get(route('reviva.cycles.index'))->assertSee('Logged outside window');
    }

    public function test_a_legacy_student_needs_the_viva_result_date(): void
    {
        $student = $this->student();

        $this->logReViva($student)->assertSessionHasErrors('viva_result_date');
        $this->assertSame(0, ReVivaDetail::count());

        $this->logReViva($student, ['viva_result_date' => '2026-06-01'])->assertSessionHasNoErrors();

        $round = $this->latestRound($student);
        $this->assertNull($round->viva_application_id);
        $this->assertSame('2026-06-01', $round->viva_result_date->toDateString());
        $this->assertSame('2026-12-01', $round->resubmission_opens_at->toDateString());
        $this->assertSame('2027-06-01', $round->resubmission_closes_at->toDateString());
        $this->assertFalse($round->logged_outside_window);

        // A future result date is refused.
        $other = $this->student('22001009', 'other@test.my');
        $this->logReViva($other, ['viva_result_date' => '2027-04-01'])->assertSessionHasErrors('viva_result_date');
    }

    public function test_round_two_runs_from_the_level_four_outcome_and_keeps_the_viva_link(): void
    {
        $viva = $this->categoryFourViva();
        Carbon::setTestNow('2027-10-01 09:00');
        $this->logReViva($viva['student']);
        $this->latestRound($viva['student'])->forceFill(['outcome_level' => 4, 'updated_at' => '2027-12-10 12:00'])->saveQuietly();

        Carbon::setTestNow('2028-01-05 09:00');
        $this->logReViva($viva['student'])->assertSessionHasNoErrors()->assertSessionMissing('warning');

        $round = $this->latestRound($viva['student']);
        $this->assertSame(2, $round->cycle_number);
        $this->assertSame($viva['notice']->id, $round->viva_application_id);
        $this->assertSame('2027-12-10', $round->resubmission_opens_at->toDateString());
        $this->assertSame('2028-06-10', $round->resubmission_closes_at->toDateString());
    }

    public function test_the_senior_executive_logs_with_both_uploads(): void
    {
        $viva = $this->categoryFourViva();
        Carbon::setTestNow('2027-10-01 09:00');

        $this->logReViva($viva['student'], ['summary_of_corrections' => null])->assertSessionHasErrors('summary_of_corrections');
        $this->logReViva($viva['student'], ['thesis' => \Illuminate\Http\UploadedFile::fake()->create('thesis.docx', 50)])->assertSessionHasErrors('thesis');
        $this->assertSame(0, ReVivaDetail::count());

        $this->logReViva($viva['student'])->assertSessionHasNoErrors();

        $docs = $this->latestRound($viva['student'])->application->documents;
        $this->assertEqualsCanonicalizing(['Re-corrected Thesis', 'Summary of Corrections'], $docs->pluck('doc_type')->all());

        // The student's own documents: they may open both.
        foreach ($docs as $doc) {
            $this->actingAs($viva['student'])->get(route('documents.show', $doc))->assertOk();
        }
    }
}
