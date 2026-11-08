<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerSubstitutionRequest;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\DuplicateDetector;
use App\Modules\Hani\Support\ExaminerMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Touchpoint 2: the faculty compilation screen.
 *
 * Touchpoint 1 (ExaminerNominationController::store()) already stops an
 * individually ineligible examiner at nomination time. This screen catches
 * the conflict that check cannot see: two departments nominating the same
 * examiner independently, which only shows once their lists are merged.
 * Per the scope it never auto-rejects -- it flags the clash, shows which list
 * is released and so keeps the examiner, and offers the way to resolve each of
 * the others (ExaminerSubstitutionController, or Manage panel once released).
 *
 * Scoped: an Academic Executive sees the examiners on their own department's
 * nominations (and which other departments also named them), and links only to
 * lists they may open. The Senior Executive, the Senior Director and the Dean
 * see all of it. The likely duplicate records (Support\DuplicateDetector) are
 * the Senior Executive's alone, since they keep the pool.
 */
class ConflictDetectionController extends Controller
{
    public function index(Request $request, CrossDepartmentClashes $clashes, DuplicateDetector $duplicates)
    {
        $user = $request->user();
        $scoped = Role::isDepartmentScoped($user->role);
        $nominations = $clashes->active();

        $requests = ExaminerSubstitutionRequest::open()->with('requestedBy')
            ->whereIn('nomination_id', $nominations->pluck('id'))
            ->get()
            ->keyBy(fn ($r) => $r->nomination_id.'|'.$r->seat);

        $rows = $nominations
            ->flatMap(fn (ExaminerNomination $nomination) => $nomination->examiners()
                ->map(fn ($examiner) => ['examiner' => $examiner, 'nomination' => $nomination]))
            ->groupBy(fn ($row) => $row['examiner']->id)
            ->map(function ($rows) use ($user, $requests) {
                $departments = $rows->pluck('nomination.application.student.department')->filter()->unique()->values();
                $examiner = $rows->first()['examiner'];
                $entries = $rows->pluck('nomination')->unique('id')->values()
                    ->map(fn (ExaminerNomination $n) => $this->entry($n, $examiner, $user, $requests));

                return [
                    'examiner' => $examiner,
                    'entries' => $entries,
                    'departments' => $departments,
                    'conflicted' => $departments->count() > 1,
                    // With two released, neither is the one that "keeps" them:
                    // both hold the examiner and someone has to act.
                    'soleReleased' => $entries->where('released', true)->count() === 1,
                ];
            })
            // Your department's examiners only, if you are one department's.
            ->when($scoped, fn ($c) => $c->filter(fn ($row) => $row['departments']->contains($user->department)));

        $byFaculty = $rows
            ->groupBy(fn ($row) => $row['examiner']->faculty ?? 'Unassigned Faculty')
            ->map(fn ($group) => $group->sortByDesc('conflicted')->values())
            ->sortKeys();

        $pairs = $user->role === Role::SENIOR_EXEC_CGS
            ? array_map(fn ($pair) => $pair + [
                'links' => array_map(fn ($a) => $this->cardUrl($a['nomination'], $user), $pair['applications']),
            ], $duplicates->pairsAcrossNominations())
            : [];

        return view('hani::conflict_detection.index', [
            'byFaculty' => $byFaculty,
            'conflictCount' => $rows->where('conflicted', true)->count(),
            'scopedToDepartment' => $scoped ? $user->department : null,
            'duplicatePairs' => $pairs,
        ]);
    }

    /** "Same person": see Support\ExaminerMerger. */
    public function merge(Request $request, ExaminerMerger $merger): RedirectResponse
    {
        $data = $request->validate([
            'record_a_id' => ['required', 'integer', 'exists:examiners,id'],
            'record_b_id' => ['required', 'integer', 'exists:examiners,id', 'different:record_a_id'],
            'keep_id' => ['required', 'integer', Rule::in([$request->input('record_a_id'), $request->input('record_b_id')])],
        ], [
            'keep_id.required' => 'Choose the record to keep.',
        ]);

        $other = (int) $data['keep_id'] === (int) $data['record_a_id'] ? $data['record_b_id'] : $data['record_a_id'];
        $merge = $merger->merge(Examiner::findOrFail($data['keep_id']), Examiner::findOrFail($other), $request->user());
        $seats = count($merge->summary['seats']);
        $letters = $merge->summary['letters_under_old_record'];

        return redirect()->route('conflict-detection.index')
            ->with('status', "{$merge->duplicate->name} merged into {$merge->kept->name}. {$seats} ".str('seat')->plural($seats).' moved, and the other record is now Unavailable.')
            ->with('warning', $letters === [] ? null
                : 'An appointment letter already went out under the old record for application #'.implode(', #', $letters).'. The letters module may show it as unsent: please tell Non-Executive CGS not to send it twice.');
    }

    /** One nomination's line in a row, with what this viewer may do about it. */
    protected function entry(ExaminerNomination $nomination, Examiner $examiner, User $user, $requests): array
    {
        $application = $nomination->application;
        $seat = collect(array_keys(ExaminerNomination::SLOTS))->first(fn ($s) => $nomination->{$s} === $examiner->id);
        $own = $this->ownDepartment($user, $application);
        $released = $application->status === Application::STATUS_APPROVED;

        return [
            'nomination' => $nomination,
            'seat' => $seat,
            'department' => $application->student?->department ?: 'No department',
            'released' => $released,
            'stageLabel' => $application->currentStage()?->label,
            'cardUrl' => $this->cardUrl($nomination, $user),
            'manageUrl' => $released && $own && in_array($user->role, [Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS], true)
                ? route('examiner-panel.show', $nomination).'#seat-'.$seat
                : null,
            'canAsk' => ExaminerSubstitutionRequest::canAsk($user, $application),
            'request' => $requests[$nomination->id.'|'.$seat] ?? null,
            'own' => $own,
        ];
    }

    /**
     * Where this viewer can see this nomination: its card in their queue if
     * the list is at their stage, the panel page once released, otherwise its
     * row in the compiled report. Null for another department's list, which an
     * Academic Executive has no screen for.
     */
    protected function cardUrl(ExaminerNomination $nomination, User $user): ?string
    {
        $application = $nomination->application;

        if (! $this->ownDepartment($user, $application)) {
            return null;
        }

        $stage = $application->currentStage();

        if ($application->status === Application::STATUS_PENDING && $stage?->role === $user->role) {
            return route('examiner-nomination.queue', ['stage' => $stage->key, 'q' => $application->id]);
        }

        if ($application->status === Application::STATUS_APPROVED && in_array($user->role, [Role::ACADEMIC_EXEC, Role::SENIOR_EXEC_CGS], true)) {
            return route('examiner-panel.show', $nomination);
        }

        return route('examiner-nomination.report').'#nomination-'.$nomination->id;
    }

    protected function ownDepartment(User $user, Application $application): bool
    {
        return ! Role::isDepartmentScoped($user->role)
            || (filled($user->department) && $user->department === $application->student?->department);
    }
}
