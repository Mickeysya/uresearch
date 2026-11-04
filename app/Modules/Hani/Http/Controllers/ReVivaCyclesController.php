<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use Illuminate\Http\Request;

/**
 * Re-viva Cycles: every cycle across CGS, read-only.
 *
 * For the Senior Executive (who logs the cycles) and the Non-Executive CGS
 * (a return contact on the correction letter). Nothing here changes a cycle:
 * the Academic Executive runs the stages and the outcome from the queue and
 * Re-viva Outcomes, and the Non-Executive gets no action buttons at all.
 * The only action shown, and only to the Senior Executive, is a link to the
 * logging form, which is itself Senior-Executive-only.
 */
class ReVivaCyclesController extends Controller
{
    public const SECTIONS = [
        'in_progress' => ['In progress', 'With the Academic Executive: letters, panel review, re-evaluations or consolidation.'],
        'awaiting_outcome' => ['Awaiting outcome', 'Consolidation is done. The Academic Executive records the outcome.'],
        'next_round' => ['Next round to log', 'Level 4: an oral examination is required. The Senior Executive logs the next round when the corrected thesis arrives.'],
        'closed' => ['Closed', 'Levels 1 to 3 (passed with corrections), level 5 (final), or a later round already logged.'],
    ];

    public function index(Request $request)
    {
        $details = ReVivaDetail::with(['application.student', 'nextCycle', 'staffDocuments.examiner'])
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva'))
            ->latest('resubmission_at')
            ->get();

        $groups = $details->groupBy(fn (ReVivaDetail $detail) => $this->section($detail));

        return view('hani::re_viva.cycles', [
            'groups' => $groups,
            'sections' => self::SECTIONS,
            'canLog' => $request->user()->role === Role::SENIOR_EXEC_CGS,
        ]);
    }

    protected function section(ReVivaDetail $detail): string
    {
        return match (true) {
            $detail->hasOutcome() && $detail->requiresLoopBack() && ! $detail->nextCycle => 'next_round',
            $detail->hasOutcome(), $detail->application->status === Application::STATUS_REJECTED => 'closed',
            $detail->application->status === Application::STATUS_APPROVED => 'awaiting_outcome',
            default => 'in_progress',
        };
    }
}
