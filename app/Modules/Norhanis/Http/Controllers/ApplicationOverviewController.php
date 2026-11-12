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
 * Every Travel, Claims or Publication application, for CGS.
 *
 * A queue answers "what is waiting for me at my stage". This answers the
 * other question CGS gets asked: "has this student applied, and where has it
 * got to?" -- whichever stage each application is on, with the student's name
 * on every row. It is read-only; decisions are still made in the queues,
 * which each row links to when the application is on the reader's own stage.
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

    /**
     * Who may open the lists, and so has them in the sidebar: CGS alone. The
     * list shows every approver's stage, and that is CGS's view to have. The
     * Supervisor, Chair and Dean work from their own queue, which shows only
     * their stage -- the supervisor's students' files are downloaded there.
     */
    public const LIST_ROLES = ApplicationReportController::REPORT_ROLES;

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

        $filters = $request->validate([
            'module' => ['nullable', 'in:'.implode(',', array_keys(self::MODULES))],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'stage' => ['nullable', 'in:'.implode(',', array_keys($stages))],
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

        // And the pending ones again, by whose stage they are waiting on.
        $waiting = array_fill_keys(array_keys($stages), 0);

        $this->visibleTo($request, $shown)
            ->where('status', Application::STATUS_PENDING)
            ->selectRaw('module_type, current_stage, count(*) as total')
            ->groupBy('module_type', 'current_stage')
            ->get()
            ->each(function ($row) use (&$waiting, $registry) {
                $stage = collect($registry->get($row->module_type)->stages())->firstWhere('key', $row->current_stage);

                if ($stage && isset($waiting[$stage->role])) {
                    $waiting[$stage->role] += $row->total;
                }
            });

        // The headline figures: every status, summed over the modules shown.
        $totals = array_fill_keys(self::STATUSES, 0);

        foreach ($counts as $byStatus) {
            foreach (self::STATUSES as $status) {
                $totals[$status] += (int) $byStatus->get($status, 0);
            }
        }

        $applications = $this->visibleTo($request, $shown)
            ->with('student')
            // A module on the combined list that this reader has no part in
            // matches nothing, rather than showing them someone else's list.
            ->when($picked, fn (Builder $query) => $query->where('module_type', $shown[$picked] ?? ''))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $role) => $this->waitingFor($query, $registry, $shown, $role))
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
            'filters' => [
                'module' => $picked ?? '', 'status' => $filters['status'] ?? '', 'stage' => $filters['stage'] ?? '',
                'q' => $search, 'sort' => $filters['sort'] ?? 'newest',
            ],
            'role' => $role,
            // Kept under the page header's 68ch measure, so it is one line.
            'subtitle' => 'Dashboard of every application and where each one is now.',
        ]);
    }

    /**
     * Only the modules whose chain this reader's role is part of: Manager
     * CGS approves claims only, so has no travel or publication list.
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
     * The sidebar links to one module's list and its Reports and Analytics,
     * for CGS when it approves it. Each of the three workflows returns this
     * from its own links(). The report has its own link because the button
     * on the list was the only way in, and CGS could not find it.
     *
     * @return array<int, array{label: string, route: string, params: array}>
     */
    public static function link(WorkflowModule $module, User $user): array
    {
        $slug = array_search($module->key(), self::MODULES, true);

        if ($slug === false || ! in_array($user->role, self::LIST_ROLES, true) || ! self::approves($module, $user)) {
            return [];
        }

        $links = [[
            'label' => ucfirst($slug).' Applications',
            'route' => 'norhanis.overview',
            'params' => ['module' => $slug],
        ]];

        if (in_array($user->role, ApplicationReportController::REPORT_ROLES, true)) {
            $links[] = [
                'label' => ucfirst($slug).' Reports and Analytics',
                'route' => 'norhanis.report',
                'params' => ['module' => $slug],
            ];
        }

        return $links;
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
     * Submitted applications in the given modules that this reader may see:
     *
     *   CGS         every application. CGS runs the process, so it is the
     *               one office that follows every case end to end. The
     *               lists, the report and the summary downloads are theirs.
     *   Supervisor  their own students', for downloading their files
     *               (ApplicationPackController). No list: they act from
     *               their queue.
     *   Anyone else nothing.
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

        return match (true) {
            Role::isCgs($user->role) => $query,
            $user->role === Role::SUPERVISOR => $query->whereHas('student', fn (Builder $student) => $student->where('supervisor_id', $user->id)),
            default => $query->whereRaw('1 = 0'),
        };
    }

}
