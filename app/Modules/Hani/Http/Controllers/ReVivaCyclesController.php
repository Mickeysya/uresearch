<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\ReVivaRisk;

/** Re-viva Cycles: every round across CGS, read-only for everyone. */
class ReVivaCyclesController extends Controller
{
    public const SECTIONS = [
        'in_progress' => ['In progress', 'Being checked, with the Dean, with the panel, or under CGS review.'],
        'final_thesis' => ['Final thesis due', 'Levels 1 to 3: the student submits the final thesis and the Senior Executive accepts the corrections.'],
        'next_round' => ['Next round expected', 'Level 4: the student submits again for the next round.'],
        'closed' => ['Closed', 'Corrections accepted, level 5 (final), or a later round already opened.'],
    ];

    public function index()
    {
        $details = ReVivaDetail::with(['application.student', 'nextCycle', 'staffDocuments.examiner', 'evaluations.examiner', 'evaluations.report'])
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva'))
            ->latest('resubmission_at')
            ->get()
            ->each(fn (ReVivaDetail $detail) => $detail->application->loadMissing('history'));

        // The deadline risk score, and each section sorted by it, highest first.
        $risks = $details->mapWithKeys(fn (ReVivaDetail $detail) => [$detail->id => ReVivaRisk::for($detail)]);
        $groups = $details
            ->sortByDesc(fn (ReVivaDetail $detail) => $risks[$detail->id]['score'] ?? -1)
            ->groupBy(fn (ReVivaDetail $detail) => $this->section($detail));

        return view('hani::re_viva.cycles', [
            'groups' => $groups,
            'risks' => $risks,
            'sections' => self::SECTIONS,
        ]);
    }

    protected function section(ReVivaDetail $detail): string
    {
        return match (true) {
            $detail->hasOutcome() && $detail->requiresLoopBack() && ! $detail->nextCycle => 'next_round',
            $detail->needsFinalThesis() && $detail->corrections_accepted_at === null => 'final_thesis',
            $detail->hasOutcome(), $detail->application->status === Application::STATUS_REJECTED => 'closed',
            default => 'in_progress',
        };
    }
}
