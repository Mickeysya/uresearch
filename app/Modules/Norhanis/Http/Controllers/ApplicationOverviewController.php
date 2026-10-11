<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * One list of every Travel, Claims and Publication application, for the
 * people who approve them.
 *
 * A queue answers "what is waiting for me at my stage". This answers the
 * other question an approver gets asked: "has this student applied, and where
 * has it got to?" -- across all three modules, whichever stage each
 * application is on, with the student's name on every row. It is read-only;
 * decisions are still made in the queues, which each row links to when the
 * application is on the reader's own stage.
 */
class ApplicationOverviewController extends Controller
{
    /** The modules this list covers. */
    public const MODULES = ['travel', 'claims_student', 'publication'];

    /** Every role that owns a stage in at least one of those chains. */
    public const ROLES = [
        Role::SUPERVISOR, Role::CHAIR, Role::NON_EXEC_CGS,
        Role::DEAN_PGR, Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS,
    ];

    /** A list read a screenful at a time, like the queues. */
    protected const PER_PAGE = 25;

    protected const STATUSES = [
        Application::STATUS_PENDING, Application::STATUS_APPROVED, Application::STATUS_REJECTED,
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'module' => ['nullable', 'in:'.implode(',', self::MODULES)],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,oldest'],
        ]);

        $search = trim($filters['q'] ?? '');

        // The tiles count everything this reader may see, before the filters
        // narrow the list -- so they stay a fixed summary while filtering.
        $counts = $this->visibleTo($request)
            ->selectRaw('module_type, status, count(*) as total')
            ->groupBy('module_type', 'status')
            ->get()
            ->groupBy('module_type')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        $applications = $this->visibleTo($request)
            ->with('student')
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('module_type', $module))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                // Student name, matric number or application number: what an
                // approver is given when someone asks "what happened to mine?".
                $query->where(function (Builder $outer) use ($search) {
                    $term = '%'.$search.'%';

                    $outer->whereHas('student', fn (Builder $student) => $student
                        ->where('name', 'like', $term)
                        ->orWhere('matric_no', 'like', $term));

                    if (ctype_digit($id = ltrim($search, '#'))) {
                        $outer->orWhere('id', (int) $id);
                    }
                });
            })
            ->orderBy('submitted_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', 'desc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('norhanis::overview.index', [
            'applications' => $applications,
            'counts' => $counts,
            'statuses' => self::STATUSES,
            'filters' => ['module' => $filters['module'] ?? '', 'status' => $filters['status'] ?? '', 'q' => $search, 'sort' => $filters['sort'] ?? 'newest'],
            'role' => $request->user()->role,
            'ownStudentsOnly' => $request->user()->role === Role::SUPERVISOR,
        ]);
    }

    /**
     * Submitted applications in the three modules that this reader may see.
     *
     * A supervisor sees their own students'. Every other role here holds a
     * stage for the whole intake, exactly as their queues do. Drafts are left
     * out: nothing has been submitted for anyone to look at yet.
     */
    protected function visibleTo(Request $request): Builder
    {
        $user = $request->user();

        return Application::query()
            ->whereIn('module_type', self::MODULES)
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->when($user->role === Role::SUPERVISOR, fn (Builder $query) => $query
                ->whereHas('student', fn (Builder $student) => $student->where('supervisor_id', $user->id)));
    }
}
