<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsReVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Prompt 3 item f: the AE records each main examiner's Thesis Re-evaluation
 * Report (UTP/CGS/024); stage 3 completes only with both; divergence is
 * flagged on the stage-4 card and on Outcomes.
 */
class ReVivaEvaluationsTest extends TestCase
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

    protected function stage(array $viva): string
    {
        return Application::find($viva['round']->application_id)->current_stage;
    }

    public function test_stage_three_completes_only_when_both_re_evaluations_are_recorded(): void
    {
        $viva = $this->roundAtReportReceived();

        $this->recordEvaluation($viva, 'internal_main_id', 2)->assertSessionHasNoErrors();
        $this->advance($viva)->assertSessionHas('error');
        $this->assertSame('report_received', $this->stage($viva));

        $this->recordEvaluation($viva, 'external_main_id', 1)->assertSessionHasNoErrors();
        $this->advance($viva)->assertSessionHasNoErrors();
        $this->assertSame('consolidation_scheduled', $this->stage($viva));

        $internal = ReVivaEvaluation::where('seat', 'internal_main_id')->sole();
        $this->assertSame($viva['panel']->internal_main_id, $internal->examiner_id);
        $this->assertSame(2, $internal->level());
    }

    public function test_validation_storage_and_who_may_record(): void
    {
        $viva = $this->roundAtReportReceived();

        // A fail needs "viva again"; a first record needs the signed form.
        $this->recordEvaluation($viva, 'internal_main_id', 4)->assertSessionHasErrors('viva_again');
        $this->recordEvaluation($viva, 'internal_main_id', 2, extra: ['report' => null])->assertSessionHasErrors('report');
        $this->recordEvaluation($viva, 'internal_main_id', 5)->assertSessionHasErrors('category');

        $this->recordEvaluation($viva, 'internal_main_id', 4, true, ['ratings' => ['criteria' => ['originality' => 4, 'bogus' => 5]]])
            ->assertSessionHasNoErrors();
        $evaluation = ReVivaEvaluation::sole();
        $this->assertTrue($evaluation->viva_again);
        $this->assertSame(['criteria' => ['originality' => 4]], $evaluation->ratings);

        // The signed form is staff-only, on the private disk, not in DocumentStore.
        $report = $evaluation->report;
        $this->assertSame(ReVivaStaffDocument::RE_EVALUATION, $report->kind);
        $this->assertStringStartsWith('hani/reviva-reports/', $report->path);
        Storage::disk('local')->assertExists($report->path);
        $this->assertSame(0, Application::find($viva['round']->application_id)->documents()->where('doc_type', 'like', '%024%')->count());
        $this->actingAs($viva['student'])->get(route('reviva.staff-documents.download', $report))->assertForbidden();
        $this->actingAs($this->cgs())->get(route('reviva.staff-documents.download', $report))->assertOk();

        // Updating without a new file keeps the form on file.
        $this->recordEvaluation($viva, 'internal_main_id', 3, extra: ['report' => null])->assertSessionHasNoErrors();
        $this->assertSame($report->id, $evaluation->fresh()->report_document_id);
        $this->assertNull($evaluation->fresh()->viva_again);

        $this->actingAs($this->academicExecFor('Chemical Engineering'))
            ->post(route('reviva.evaluations.record', [$viva['round']->application_id, 'external_main_id']), ['category' => 1])
            ->assertForbidden();
        $this->actingAs($this->senior())
            ->post(route('reviva.evaluations.record', [$viva['round']->application_id, 'external_main_id']), ['category' => 1])
            ->assertForbidden();
    }

    public function test_re_evaluations_are_recorded_only_at_stage_three(): void
    {
        $viva = $this->roundUnderPanelReview();

        $this->recordEvaluation($viva, 'internal_main_id', 2)->assertSessionHas('error');
        $this->assertSame(0, ReVivaEvaluation::count());
    }

    public function test_divergence_is_flagged_on_the_stage_four_card_and_on_outcomes_but_never_to_the_student(): void
    {
        $viva = $this->roundAtReportReceived();
        $this->recordEvaluation($viva, 'internal_main_id', 3);
        $this->recordEvaluation($viva, 'external_main_id', 4, true);
        $this->advance($viva);

        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'consolidation_scheduled']))
            ->assertSee('Major divergence')
            ->assertSee('Internal: 3 (Major corrections) · External: 4 (Fail, viva again: Yes)');

        $this->advance($viva);
        $this->actingAs($viva['ae'])->get(route('reviva.outcomes.index'))->assertSee('Major divergence');

        $external = Examiner::find($viva['panel']->external_main_id);
        $this->actingAs($viva['student'])->get(route('applications.show', $viva['round']->application_id))
            ->assertOk()
            ->assertDontSee($external->name)
            ->assertDontSee('viva again')
            ->assertDontSee('divergence');
    }

    public function test_two_fails_that_differ_on_viva_again_still_diverge(): void
    {
        $viva = $this->roundAtReportReceived();
        $this->recordEvaluation($viva, 'internal_main_id', 4, true);
        $this->recordEvaluation($viva, 'external_main_id', 4, false);

        $this->actingAs($viva['ae'])->get(route('reviva.queue', ['stage' => 'report_received']))
            ->assertSee('Reports differ');
    }
}
