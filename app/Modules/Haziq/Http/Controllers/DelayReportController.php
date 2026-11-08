<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Haziq\Support\DelayByActorReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Visible to every actor in the GRA/GA chains, not just one role --
 * confirmed explicitly 2026-11-06: "i want all of the actors to see
 * this." A plain read, same "reporting module, not a workflow" shape
 * as Allowance Eligibility -- see AllowanceController's own docblock.
 */
class DelayReportController extends Controller
{
    public function index(Request $request): View
    {
        abort_if($request->user()->isStudent(), 403);

        $module = $request->query('module') ?: null;
        $role = $request->query('role') ?: null;
        $actorId = $request->query('actor') ? (int) $request->query('actor') : null;

        $rows = DelayByActorReport::rows($module);

        if (! $role) {
            // Nothing to narrow an individual to without a role.
            $actorId = null;
        } elseif ($actorId && ! DelayByActorReport::actorsFor($module, $role)->contains('id', $actorId)) {
            // A role switch reloads the page before the Actor select's
            // options catch up client-side, so a stale id left over from
            // the previous role is dropped rather than silently matched
            // against the wrong person.
            $actorId = null;
        }

        $byRole = DelayByActorReport::byRole($rows, $rows);

        return view('haziq::delay_report.index', [
            'module' => $module,
            'role' => $role,
            'actorId' => $actorId,
            'roles' => DelayByActorReport::rolesFor($module),
            'actors' => DelayByActorReport::actorsFor($module, $role),
            'chartBars' => DelayByActorReport::chartBars($rows, $role, $actorId),
            'bottleneck' => DelayByActorReport::bottleneckRole($byRole),
            'byRole' => $byRole,
            'byActor' => DelayByActorReport::byActor($rows, $rows),
            'rows' => $rows->sortByDesc('decided_at')->values(),
        ]);
    }
}
