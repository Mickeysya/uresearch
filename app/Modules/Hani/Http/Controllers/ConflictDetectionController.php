<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerNomination;

/**
 * Touchpoint 2: the CGS faculty-list compilation screen.
 *
 * Touchpoint 1 (ExaminerNominationController::store()) already stops an
 * individually ineligible examiner at nomination time. This screen catches
 * the conflict that check cannot see: two different departments nominating
 * the same examiner independently, which only becomes visible once their
 * lists are merged into one faculty (FOE/FSMC) view. Per the scope, this
 * never auto-rejects -- it flags the clash and shows current pool
 * availability so the Academic Executive can decide on a substitution.
 */
class ConflictDetectionController extends Controller
{
    public function index()
    {
        $nominations = ExaminerNomination::with(['mainExaminer', 'backupExaminer', 'application.student'])
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED]))
            ->get();

        // Group by the nominated examiner's own faculty -- that is the level
        // at which department lists get compiled -- then flag any examiner
        // whose nominations span more than one department within it.
        $byFaculty = $nominations
            ->flatMap(fn (ExaminerNomination $nomination) => collect([
                ['examiner' => $nomination->mainExaminer, 'nomination' => $nomination],
                ['examiner' => $nomination->backupExaminer, 'nomination' => $nomination],
            ]))
            ->filter(fn ($row) => $row['examiner'] !== null)
            ->groupBy(fn ($row) => $row['examiner']->faculty ?? 'Unassigned Faculty')
            ->map(function ($rows) {
                return $rows
                    ->groupBy(fn ($row) => $row['examiner']->id)
                    ->map(function ($rows) {
                        $examiner = $rows->first()['examiner'];
                        $departments = $rows
                            ->pluck('nomination.application.student.department')
                            ->filter()
                            ->unique()
                            ->values();

                        return [
                            'examiner' => $examiner,
                            'nominations' => $rows->pluck('nomination')->unique('id')->values(),
                            'departments' => $departments,
                            'conflicted' => $departments->count() > 1,
                        ];
                    })
                    ->sortByDesc('conflicted')
                    ->values();
            });

        return view('hani::conflict_detection.index', ['byFaculty' => $byFaculty]);
    }
}
