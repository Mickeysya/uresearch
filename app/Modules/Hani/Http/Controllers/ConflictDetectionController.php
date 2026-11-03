<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\DuplicateDetector;
use Illuminate\Http\Request;

/**
 * Touchpoint 2: the faculty compilation screen.
 *
 * Touchpoint 1 (ExaminerNominationController::store()) already stops an
 * individually ineligible examiner at nomination time. This screen catches
 * the conflict that check cannot see: two departments nominating the same
 * examiner independently, which only shows once their lists are merged.
 * Per the scope it never auto-rejects -- it flags the clash and shows the
 * pool state so a person can decide on a substitution.
 *
 * Scoped: an Academic Executive sees the examiners on their own department's
 * nominations (and which other departments also named them); the Senior
 * Executive, who compiles every department's list, sees all of it, plus the
 * likely duplicate records (Support\DuplicateDetector).
 */
class ConflictDetectionController extends Controller
{
    public function index(Request $request, CrossDepartmentClashes $clashes, DuplicateDetector $duplicates)
    {
        $user = $request->user();
        $scoped = Role::isDepartmentScoped($user->role);
        $nominations = $clashes->active();

        $rows = $nominations
            ->flatMap(fn (ExaminerNomination $nomination) => $nomination->examiners()
                ->map(fn ($examiner) => ['examiner' => $examiner, 'nomination' => $nomination]))
            ->groupBy(fn ($row) => $row['examiner']->id)
            ->map(function ($rows) {
                $departments = $rows->pluck('nomination.application.student.department')->filter()->unique()->values();

                return [
                    'examiner' => $rows->first()['examiner'],
                    'nominations' => $rows->pluck('nomination')->unique('id')->values(),
                    'departments' => $departments,
                    'conflicted' => $departments->count() > 1,
                ];
            })
            // Your department's examiners only, if you are one department's.
            ->when($scoped, fn ($c) => $c->filter(fn ($row) => $row['departments']->contains($user->department)));

        $byFaculty = $rows
            ->groupBy(fn ($row) => $row['examiner']->faculty ?? 'Unassigned Faculty')
            ->map(fn ($group) => $group->sortByDesc('conflicted')->values())
            ->sortKeys();

        return view('hani::conflict_detection.index', [
            'byFaculty' => $byFaculty,
            'conflictCount' => $rows->where('conflicted', true)->count(),
            'scopedToDepartment' => $scoped ? $user->department : null,
            'duplicates' => $scoped ? [] : $duplicates->acrossNominations(),
        ]);
    }
}
