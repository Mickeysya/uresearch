<?php

namespace App\Modules\Core\Http\Controllers\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

/**
 * Drop this into a module controller and approve/reject is done.
 *
 * Every module gets identical routing, auditing, authorisation and email
 * behaviour, because there is exactly one implementation of it.
 */
trait ApprovesApplications
{
    /** The module_type this controller serves. */
    abstract protected function moduleKey(): string;

    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /** Rows per page. A decision screen is read one screenful at a time. */
    protected const QUEUE_PER_PAGE = 20;

    /**
     * The rows sitting on one stage of this module's chain.
     * Pass ?stage=<key>; falls back to the first stage this role owns.
     *
     * Pass $scope to narrow the query further; see the note at the call site
     * in Nureen's SupervisionController for the case it exists for. This is
     * deliberately a per-call closure and NOT the general answer to "scope
     * approver queues to the right people" in TODO.md, which still needs the
     * team to choose between a Stage property and a module hook.
     *
     * PAGINATED, SEARCHABLE AND SORTABLE since 2026-09-17. This used to be a
     * plain ->get(), which is fine for the two rows a demo has and is an
     * out-of-memory error for a department with a real backlog: every
     * Application, every eager-loaded relation and every rendered decision
     * form, all at once. A queue is a work list, so it is now read a page at
     * a time, oldest first, with a way to find one row among thousands.
     *
     * Every module gets this for free -- all fourteen queue controllers call
     * this one method, and none of them changed.
     *
     * `applications` is a LengthAwarePaginator rather than a Collection.
     * AbstractPaginator forwards unknown calls to its underlying collection,
     * so the ->pluck('id') the detail-row lookups do still works, and now
     * only loads the details for the page being shown.
     *
     * @return array{
     *     stage: \App\Modules\Core\Support\Stage,
     *     module: \App\Modules\Core\Contracts\WorkflowModule,
     *     applications: \Illuminate\Pagination\LengthAwarePaginator,
     *     filters: array{q: string, sort: string}
     * }
     */
    protected function queueFor(
        Request $request,
        WorkflowEngine $engine,
        array $with = [],
        ?callable $scope = null,
    ): array {
        $queues = app(ModuleRegistry::class)->queuesForRole($request->user()->role);

        $mine = array_values(array_filter(
            $queues,
            fn ($q) => $q['module']->key() === $this->moduleKey()
        ));

        abort_if($mine === [], 403, 'You have no queue in this module.');

        $stageKey = $request->query('stage', $mine[0]['stage']->key);

        $match = collect($mine)->first(fn ($q) => $q['stage']->key === $stageKey);
        abort_unless($match, 403, 'That stage is not yours to act on.');

        $search = trim((string) $request->query('q', ''));
        $sort = $request->query('sort') === 'newest' ? 'newest' : 'oldest';

        $query = $engine->queue($this->moduleKey(), $match['stage']->key)->with($with);

        // Chair of Department and Academic Executive each own one
        // department's desk, not every department's at once -- see
        // Role::isDepartmentScoped(). Applied to the query, same reasoning
        // as the module $scope below: filtering the page after it is
        // fetched would report the wrong total and page over rows this
        // approver will never be shown.
        if (Role::isDepartmentScoped($request->user()->role)) {
            $query->whereHas('student', fn ($s) => $s->where('department', $request->user()->department));
        }

        // A module narrowing its own queue, applied to the QUERY and so
        // before the count and the page. The engine's queue is role-scoped,
        // not person-scoped, and a module that knows better -- Supervision
        // knows which supervisor a request actually names -- says so here.
        //
        // Filtering the returned rows instead is the trap: it silently
        // reports the unfiltered total, pages over rows it then discards, and
        // (because the paginator forwards unknown calls to its collection)
        // quietly turns the paginator back into a plain Collection.
        if ($scope) {
            $scope($query, $match['stage']);
        }

        // Application number, student name or matric number. Everything an
        // approver is given when someone asks "what happened to mine?".
        if ($search !== '') {
            $query->where(function ($outer) use ($search) {
                $term = '%'.$search.'%';

                $outer->whereHas('student', fn ($s) => $s
                    ->where('name', 'like', $term)
                    ->orWhere('matric_no', 'like', $term));

                // Only when it could be one: an id column compared against a
                // word makes MySQL coerce the column and drop the index.
                if (ctype_digit($id = ltrim($search, '#'))) {
                    $outer->orWhere('id', (int) $id);
                }
            });
        }

        // Oldest first is the default because a queue is first-in-first-out,
        // and the row that has waited longest is the one at risk.
        if ($sort === 'newest') {
            $query->reorder('submitted_at', 'desc');
        }

        return [
            'stage' => $match['stage'],
            'module' => $match['module'],
            'applications' => $query
                ->paginate(static::QUEUE_PER_PAGE)
                ->withQueryString(),
            'filters' => ['q' => $search, 'sort' => $sort],
        ];
    }
}
