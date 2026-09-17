<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Application;
use Illuminate\Support\Collection;

/**
 * The Chair of Department dashboard.
 *
 * Everything about "what is on my desk" is in ApproverDashboard, which the
 * Supervisor screen shares. What is left is the one thing only a Chair does:
 * file examiner panels.
 *
 * WHY A CHAIR NEEDS ITS OWN SCREEN. The generic approver dashboard builds one
 * stat card per queue, and a Chair owns five stages, so it rendered six cards
 * of which five normally read zero, plus a bar chart of five categories with
 * one bar in it. None of it told a Chair the only thing they are actually
 * measured on: whether anything has been sitting on their desk too long.
 */
class ChairDashboard extends ApproverDashboard
{
    /**
     * Panels this Chair filed themselves.
     *
     * A Chair files an examiner panel and it leaves their hands entirely --
     * no queue of theirs, and the tracking page is students only. Until this
     * panel, a filed nomination was simply invisible to the person who filed
     * it. `TODO.md` carried it as the one thing a Chair should be able to
     * reach and could not.
     *
     * @return Collection<int, Application>
     */
    public function myNominations(int $limit = 5): Collection
    {
        return $this->safely('nominations', fn () => Application::query()
            ->where('submitted_by_id', $this->user->id)
            ->whereNot('student_id', $this->user->id)
            ->with('student')
            ->latest('submitted_at')
            ->limit($limit)
            ->get(), collect());
    }

    /**
     * "with the Academic Executive" for each nomination still in flight.
     *
     * Resolved here rather than in the view because it needs the engine, and
     * a Blade template calling into the workflow engine per row is how a
     * dashboard ends up with a query it cannot see.
     *
     * @param  Collection<int, Application>  $applications
     * @return array<int, string>
     */
    public function stageLabels(Collection $applications, WorkflowEngine $engine): array
    {
        return $this->safely('nominations', fn () => $applications
            ->mapWithKeys(fn (Application $a) => [$a->id => $engine->currentStage($a)?->label])
            ->filter()
            ->all(), []);
    }
}
