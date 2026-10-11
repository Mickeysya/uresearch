<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Every Travel, Claims or Publication application, for the people who
 * approve them.
 *
 * A queue answers "what is waiting for me at my stage". This answers the
 * other question an approver gets asked: "has this student applied, and where
 * has it got to?" -- whichever stage each application is on, with the
 * student's name on every row. It is read-only; decisions are still made in
 * the queues, which each row links to when the application is on the reader's
 * own stage.
 *
 * Each module has its own list, linked separately from the sidebar. The same
 * page without a module shows all of them together.
 */
class ApplicationOverviewController extends Controller
{
    /** The lists this page serves: the word in the URL => the module_type. */
    public const MODULES = [
        'travel' => 'travel',
        'claims' => 'claims_student',
        'publication' => 'publication',
    ];

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

    public function index(Request $request, ModuleRegistry $registry, ?string $module = null)
    {
        // Only the modules whose chain this reader's role is part of. The
        // Dean approves travel but has no part in claims, so has no claims list.
        $allowed = array_filter(
            self::MODULES,
            fn (string $key) => self::approves($registry->get($key), $request->user()),
        );

        abort_if($allowed === [], 403);

        if ($module !== null) {
            abort_unless(isset($allowed[$module]), 403);
        }

        $filters = $request->validate([
            'module' => ['nullable', 'in:'.implode(',', array_keys(self::MODULES))],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,oldest'],
        ]);

        // On a module's own list the module is fixed by the URL. On the
        // combined one it is an optional filter.
        $shown = $module !== null ? [$module => $allowed[$module]] : $allowed;
        $picked = $module ?? ($filters['module'] ?? null);
        $search = trim($filters['q'] ?? '');

        // The tiles count everything this reader may see, before the filters
        // narrow the list -- so they stay a fixed summary while filtering.
        $counts = $this->visibleTo($request, $shown)
            ->selectRaw('module_type, status, count(*) as total')
            ->groupBy('module_type', 'status')
            ->get()
            ->groupBy('module_type')
            ->map(fn ($rows) => $rows->pluck('total', 'status'));

        $applications = $this->visibleTo($request, $shown)
            ->with('student')
            // A module on the combined list that this reader has no part in
            // matches nothing, rather than showing them someone else's list.
            ->when($picked, fn (Builder $query) => $query->where('module_type', $shown[$picked] ?? ''))
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
            'modules' => array_map(fn (string $key) => $registry->get($key)->label(), $shown),
            'keys' => $shown,
            'scope' => $module,
            'filters' => ['module' => $picked ?? '', 'status' => $filters['status'] ?? '', 'q' => $search, 'sort' => $filters['sort'] ?? 'newest'],
            'role' => $request->user()->role,
            'ownStudentsOnly' => $request->user()->role === Role::SUPERVISOR,
        ]);
    }

    /**
     * The sidebar link to one module's list, for a user who approves it.
     * Each of the three workflows returns this from its own links().
     *
     * @return array<int, array{label: string, route: string, params: array}>
     */
    public static function link(WorkflowModule $module, User $user): array
    {
        $slug = array_search($module->key(), self::MODULES, true);

        if ($slug === false || ! self::approves($module, $user)) {
            return [];
        }

        return [[
            'label' => ucfirst($slug).' Applications',
            'route' => 'norhanis.overview',
            'params' => ['module' => $slug],
        ]];
    }

    /** Does this user's role own a stage anywhere in the module's chain? */
    protected static function approves(WorkflowModule $module, User $user): bool
    {
        // stages() with no application is every stage the module can use.
        return collect($module->stages())->contains(fn ($stage) => $stage->role === $user->role);
    }

    /**
     * Submitted applications in the given modules that this reader may see.
     *
     * A supervisor sees their own students'. Every other role here holds a
     * stage for the whole intake, exactly as their queues do. Drafts are left
     * out: nothing has been submitted for anyone to look at yet.
     *
     * @param  array<string, string>  $modules
     */
    protected function visibleTo(Request $request, array $modules): Builder
    {
        $user = $request->user();

        return Application::query()
            ->whereIn('module_type', array_values($modules))
            ->where('status', '!=', Application::STATUS_DRAFT)
            ->when($user->role === Role::SUPERVISOR, fn (Builder $query) => $query
                ->whereHas('student', fn (Builder $student) => $student->where('supervisor_id', $user->id)));
    }
}
