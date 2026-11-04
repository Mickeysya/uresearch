<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\PanelSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * A panel whose evaluation is over stays over (Prompt 4c). Pending
 * Evaluation, `examiners:sweep` and the cross-department clash check used to
 * read the examiners' CURRENT assigned_until, so a panel examined months ago
 * reopened the moment one of its examiners was tied up by a later panel.
 * They now read the panel's own seat history (ExaminerNomination::evaluationOpen()).
 */
class EvaluationOverTest extends TestCase
{
    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use RefreshDatabase;

    public function test_a_finished_panel_is_not_reopened_by_its_examiner_joining_a_later_panel(): void
    {
        Notification::fake();

        // Computing's panel: examined on, so finished.
        $first = $this->viva('22001001', department: 'Computing');
        $first['detail']->update(['viva_at' => now()->subDays(20)]);
        app(PanelSeats::class)->complete($first['panel']);

        // Petroleum names the same external main examiner later; released.
        $second = $this->viva('22002001', department: 'Petroleum Engineering');
        $shared = $first['panel']->external_main_id;
        $second['panel']->update(['external_main_id' => $shared]);
        app(PanelSeats::class)->release($second['panel']->refresh());
        $this->assertNotNull(\App\Modules\Hani\Models\Examiner::find($shared)->assigned_until, 'tied up again by the later panel');

        // Not pending for Computing's AE.
        $this->actingAs($first['ae'])->get(route('examiner-nomination.pending-evaluation'))
            ->assertOk()->assertDontSee($first['student']->name);

        // Not swept.
        $this->artisan('examiners:sweep', ['--dry-run' => true])
            ->doesntExpectOutputToContain('#'.$first['panel']->application_id)
            ->assertSuccessful();

        // Not a clash for Petroleum.
        $this->assertSame([], app(CrossDepartmentClashes::class)->elsewhere($shared, 'Petroleum Engineering', $second['panel']->application_id));

        // The later panel is still open, and Computing would see the clash
        // with it while it is.
        $this->assertTrue(ExaminerNomination::evaluationOpen()->whereKey($second['panel']->id)->exists());
        $this->assertFalse(ExaminerNomination::evaluationOpen()->whereKey($first['panel']->id)->exists());
        $this->assertCount(1, app(CrossDepartmentClashes::class)->elsewhere($shared, 'Computing', $first['panel']->application_id));
        $this->assertSame(Application::STATUS_APPROVED, $first['panel']->application->status);
    }
}
