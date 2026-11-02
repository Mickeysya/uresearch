<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
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
        $allowed = $this->allowedModules($request, $registry, $module);

        // On a module's own list the module is fixed by the URL. On the
        // combined one it is an optional filter.
        $shown = $module !== null ? [$module => $allowed[$module]] : $allowed;
        $stages = $this->stagesOf($registry, $shown);
        $role = $request->user()->role;

        // Nothing this reader can see is waiting before their own stage, nor
        // at a stage on a route they are not on (the Dean never sees the
        // Senior Director's local travel).
        if (self::seesOnlyWhatReachedThem($request->user()) && isset($stages[$role])) {
            $after = $this->stagesAfter($registry, $shown, $role);
            $stages = array_filter($stages, fn (string $stageRole) => $stageRole === $role || isset($after[$stageRole]), ARRAY_FILTER_USE_KEY);
        }

        $filters = $request->validate([
            'module' => ['nullable', 'in:'.implode(',', array_keys(self::MODULES))],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'stage' => ['nullable', 'in:'.implode(',', array_keys($stages))],
            // The reader's own decisions: what they passed on, or rejected.
            'decided' => ['nullable', 'in:passed,rejected'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,oldest'],
        ]);

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

        // And the pending ones again, by whose stage they are waiting on --
        // in total, and per module for the dashboard's stacked bars.
        $waiting = array_fill_keys(array_keys($stages), 0);
        $waitingByModule = array_fill_keys(array_keys($shown), $waiting);
        $slugOf = array_flip($shown);

        $this->visibleTo($request, $shown)
            ->where('status', Application::STATUS_PENDING)
            ->selectRaw('module_type, current_stage, count(*) as total')
            ->groupBy('module_type', 'current_stage')
            ->get()
            ->each(function ($row) use (&$waiting, &$waitingByModule, $slugOf, $registry) {
                $stage = collect($registry->get($row->module_type)->stages())->firstWhere('key', $row->current_stage);

                if ($stage && isset($waiting[$stage->role])) {
                    $waiting[$stage->role] += $row->total;
                    $waitingByModule[$slugOf[$row->module_type]][$stage->role] += $row->total;
                }
            });

        // The headline figures: every status, summed over the modules shown.
        $totals = array_fill_keys(self::STATUSES, 0);

        foreach ($counts as $byStatus) {
            foreach (self::STATUSES as $status) {
                $totals[$status] += (int) $byStatus->get($status, 0);
            }
        }

        $mine = Role::isCgs($role) ? null : $this->myDecisions($request, $registry, $shown, $module, $waiting[$role] ?? 0);

        $applications = $this->visibleTo($request, $shown)
            ->with('student')
            // A module on the combined list that this reader has no part in
            // matches nothing, rather than showing them someone else's list.
            ->when($picked, fn (Builder $query) => $query->where('module_type', $shown[$picked] ?? ''))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $role) => $this->waitingFor($query, $registry, $shown, $role))
            ->when($filters['decided'] ?? null, fn (Builder $query, string $decided) => $query
                ->whereHas('history', fn (Builder $history) => $history
                    ->where('approver_id', $request->user()->id)
                    ->where('decision', $decided === 'rejected' ? '=' : '!=', 'rejected')))
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
            'statuses' => self::STATUSES,
            'modules' => array_map(fn (string $key) => $registry->get($key)->label(), $shown),
            'scope' => $module,
            'stages' => $stages,
            'waiting' => $waiting,
            'totals' => $totals,
            // The same figures in the shape the dashboard's charts read.
            'chart' => [
                'modules' => array_map(fn (string $key) => $registry->get($key)->label(), $shown),
                'stages' => array_values(array_map(
                    fn (string $stageRole, string $label) => $stageRole === $role ? $label.' (you)' : $label,
                    array_keys($stages), $stages,
                )),
                'stageLinks' => array_values(array_map(
                    fn (string $stageRole) => route('norhanis.overview', ['module' => $module, 'stage' => $stageRole]),
                    array_keys($stages),
                )),
                'waiting' => array_map('array_values', $waitingByModule),
                'statuses' => array_map('ucfirst', self::STATUSES),
                'statusTotals' => array_values($totals),
                'statusLinks' => array_map(
                    fn (string $status) => route('norhanis.overview', ['module' => $module, 'status' => $status]),
                    self::STATUSES,
                ),
                'mine' => $mine,
            ],
            'mine' => $mine,
            'filters' => [
                'module' => $picked ?? '', 'status' => $filters['status'] ?? '', 'stage' => $filters['stage'] ?? '',
                'decided' => $filters['decided'] ?? '', 'q' => $search, 'sort' => $filters['sort'] ?? 'newest',
            ],
            'role' => $role,
            // Says which slice of the applications this reader is looking at.
            'subtitle' => match (true) {
                // Kept under the page header's 68ch measure, so it is one line.
                Role::isCgs($role) => 'Dashboard of every application and where each one is now.',
                $role === Role::SUPERVISOR => "Dashboard of your actions and your students' applications.",
                $role === Role::CHAIR => "Dashboard of your actions and your department's applications.",
                default => 'Dashboard of your actions and the applications that reached you.',
            },
            'outcomesNote' => $this->outcomesNote($role, $stages),
        ]);
    }

    /**
     * The line under the Outcomes doughnut: whose applications it counts,
     * and which approvers a pending one may be with now. Built from the
     * stages actually shown, so a Chair on the claims list is told CGS and
     * Manager CGS, and on the travel list CGS and the Dean.
     *
     * @param  array<string, string>  $stages  role => label, in chain order
     */
    protected function outcomesNote(string $role, array $stages): string
    {
        $own = $stages[$role] ?? null;
        $others = array_values(array_diff_key($stages, [$role => true]));

        $list = fn (array $labels) => count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)).' or '.end($labels)
            : ($labels[0] ?? '');

        $whose = match (true) {
            Role::isCgs($role) => 'Every submitted application, at every stage',
            $role === Role::SUPERVISOR => 'Your students\' applications',
            $role === Role::CHAIR => "Applications from your department that reached you as {$own}",
            default => "Applications that reached you as {$own}",
        };

        if (Role::isCgs($role)) {
            return $whose.($stages ? ': '.$list(array_values($stages)).'.' : '.');
        }

        return $whose.'. '.($others
            ? 'A pending one is waiting on '.$list(array_merge(['you'], $others)).'.'
            : 'You are the last approver, so a pending one is waiting on you.');
    }

    /**
     * The dashboard chart for anyone but CGS: their own decisions, not the
     * whole chain. A stage chart answers "where is the process stuck?",
     * which is CGS's question; shown to a Chair, its CGS and Dean bars read
     * as other people's workload even though they only count the Chair's own
     * applications after the Chair passed them on.
     *
     * "Passed on" is every decision that was not a rejection, labelled with
     * the verbs this role's stages actually use -- "endorsed", "approved".
     *
     * @param  array<string, string>  $modules
     * @return array{labels: array<int, string>, counts: array<int, int>, links: array<int, string>}
     */
    protected function myDecisions(Request $request, ModuleRegistry $registry, array $modules, ?string $module, int $waitingOnMe): array
    {
        $user = $request->user();

        $decided = fn (bool $rejected) => ApprovalHistory::query()
            ->where('approver_id', $user->id)
            ->where('decision', $rejected ? '=' : '!=', 'rejected')
            ->whereIn('application_id', $this->visibleTo($request, $modules)->select('id'))
            ->distinct()
            ->count('application_id');

        $verbs = collect($modules)
            ->flatMap(fn (string $key) => $registry->get($key)->stages())
            ->where('role', $user->role)
            ->pluck('decision')
            ->unique()
            ->implode(' / ');

        return [
            'labels' => ['Waiting on me', ucfirst($verbs ?: 'passed on').' by me', 'Rejected by me'],
            'counts' => [$waitingOnMe, $decided(false), $decided(true)],
            'links' => [
                route('norhanis.overview', ['module' => $module, 'stage' => $user->role]),
                route('norhanis.overview', ['module' => $module, 'decided' => 'passed']),
                route('norhanis.overview', ['module' => $module, 'decided' => 'rejected']),
            ],
        ];
    }

    /**
     * Only the modules whose chain this reader's role is part of: the Dean
     * approves travel but has no part in claims, so has no claims list.
     * Refuses a reader with none, or one asking for a module that is not theirs.
     *
     * @return array<string, string>
     */
    protected function allowedModules(Request $request, ModuleRegistry $registry, ?string $module): array
    {
        $allowed = array_filter(
            self::MODULES,
            fn (string $key) => self::approves($registry->get($key), $request->user()),
        );

        abort_if($allowed === [], 403);

        if ($module !== null) {
            abort_unless(isset($allowed[$module]), 403);
        }

        return $allowed;
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

    /**
     * Every approval stage the given modules use, in chain order: the role
     * that holds it => its label. Keyed by role because the same approver's
     * stage has a different key from one module to the next.
     *
     * @param  array<string, string>  $modules
     * @return array<string, string>
     */
    protected function stagesOf(ModuleRegistry $registry, array $modules): array
    {
        $stages = [];

        foreach ($modules as $key) {
            foreach ($registry->get($key)->stages() as $stage) {
                $stages[$stage->role] ??= $stage->label;
            }
        }

        return $stages;
    }

    /**
     * The roles that come after $role on any route through the given modules.
     * A module with more than one route (Travel) lists them in routes();
     * any other has the one, its stages().
     *
     * @param  array<string, string>  $modules
     * @return array<string, true>
     */
    protected function stagesAfter(ModuleRegistry $registry, array $modules, string $role): array
    {
        $after = [];

        foreach ($modules as $key) {
            $module = $registry->get($key);
            $routes = method_exists($module, 'routes') ? $module->routes() : [$module->stages()];

            foreach ($routes as $route) {
                $reached = false;

                foreach ($route as $stage) {
                    if ($reached) {
                        $after[$stage->role] = true;
                    }

                    $reached = $reached || $stage->role === $role;
                }
            }
        }

        return $after;
    }

    /**
     * Narrow to applications waiting on the stage that $role holds.
     *
     * @param  array<string, string>  $modules
     */
    protected function waitingFor(Builder $query, ModuleRegistry $registry, array $modules, string $role): void
    {
        $query->where('status', Application::STATUS_PENDING)->where(function (Builder $outer) use ($registry, $modules, $role) {
            // Never left empty: an empty group would match every row.
            $outer->whereRaw('1 = 0');

            foreach ($modules as $key) {
                $keys = collect($registry->get($key)->stages())->where('role', $role)->pluck('key')->all();

                if ($keys !== []) {
                    $outer->orWhere(fn (Builder $inner) => $inner->where('module_type', $key)->whereIn('current_stage', $keys));
                }
            }
        });
    }

    /** Does this user's role own a stage anywhere in the module's chain? */
    protected static function approves(WorkflowModule $module, User $user): bool
    {
        // stages() with no application is every stage the module can use.
        return collect($module->stages())->contains(fn ($stage) => $stage->role === $user->role);
    }

    /**
     * Submitted applications in the given modules that this reader may see.
     * Need to know, not "everyone in the chain sees everything":
     *
     *   CGS         every application. CGS runs the process, so it is the
     *               one office that follows every case end to end.
     *   Supervisor  their own students', all of which start on their stage.
     *   Chair       what has reached the Chair's stage, from students in the
     *               Chair's own department.
     *   Anyone else what has reached their stage -- the Dean never sees a
     *   (the Dean)  local trip, because local travel stops at the Chair.
     *
     * "Reached" is waiting on the reader's stage now, or decided there
     * before: approval_history has a row at that stage. An application
     * rejected before it got to them never reached them.
     *
     * The queues themselves are still scoped by role alone (the open "Scope
     * approver queues to the right people" item in TODO.md, a Core change),
     * so a Chair's queue can hold a row this list leaves out.
     *
     * Drafts are left out for everyone: nothing has been submitted yet.
     *
     * @param  array<string, string>  $modules
     */
    protected function visibleTo(Request $request, array $modules): Builder
    {
        $user = $request->user();

        $query = Application::query()
            ->whereIn('module_type', array_values($modules))
            ->where('status', '!=', Application::STATUS_DRAFT);

        if (Role::isCgs($user->role)) {
            return $query;
        }

        if ($user->role === Role::SUPERVISOR) {
            return $query->whereHas('student', fn (Builder $student) => $student->where('supervisor_id', $user->id));
        }

        $registry = app(ModuleRegistry::class);

        $query->where(function (Builder $reached) use ($registry, $modules, $user) {
            // Never left empty: an empty group would match every row.
            $reached->whereRaw('1 = 0');

            foreach ($modules as $key) {
                $keys = collect($registry->get($key)->stages())->where('role', $user->role)->pluck('key')->all();

                if ($keys === []) {
                    continue;
                }

                $reached->orWhere(fn (Builder $module) => $module
                    ->where('module_type', $key)
                    ->where(fn (Builder $at) => $at
                        ->where(fn (Builder $now) => $now
                            ->where('status', Application::STATUS_PENDING)
                            ->whereIn('current_stage', $keys))
                        ->orWhereHas('history', fn (Builder $history) => $history->whereIn('stage_key', $keys))));
            }
        });

        if ($user->role === Role::CHAIR) {
            // A Chair with no department on record sees nothing, rather than
            // where('department', null) quietly matching every student who
            // has none either.
            $user->department === null
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('student', fn (Builder $student) => $student->where('department', $user->department));
        }

        return $query;
    }

    /**
     * Sees only what reached their own stage, so the stages before it hold
     * nothing they can see and are left off their counts.
     */
    protected static function seesOnlyWhatReachedThem(User $user): bool
    {
        return ! Role::isCgs($user->role) && $user->role !== Role::SUPERVISOR;
    }
}
