<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Haziq\Support\DelayByActorReport;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Visible to every actor in the GRA/GA chains, not just one role --
 * confirmed explicitly 2026-11-06: "i want all of the actors to see
 * this." A plain read, same "reporting module, not a workflow" shape
 * as Allowance Eligibility -- see AllowanceController's own docblock.
 *
 * Research Centre is the one exception, confirmed explicitly
 * 2026-11-10: "boleh je tengok delay by actor tapi yang role die
 * related sahaja ah which is ga application" -- locked to that one
 * scope below, since GRA Application/Extension and GA Extension are
 * none of Research Centre's business (it is not even an actor on
 * those chains).
 */
class DelayReportController extends Controller
{
    protected const RESEARCH_CENTRE_MODULE = 'ga_application';

    public function index(Request $request): View
    {
        abort_if($request->user()->isStudent(), 403);

        $lockedToModule = $request->user()->role === Roles::RESEARCH_CENTRE ? self::RESEARCH_CENTRE_MODULE : null;

        // One of DelayByActorReport::SCOPES, always -- "theres no such
        // thing as both, kene choose either one" -- confirmed explicitly
        // 2026-11-10. Defaults to the first scope on first visit, since
        // the Role filter (not this one) is the one allowed an "any"
        // choice.
        $module = $lockedToModule ?? ($request->query('module') ?: array_key_first(DelayByActorReport::SCOPES));
        abort_unless(array_key_exists($module, DelayByActorReport::SCOPES), 404);

        $narrowableRoles = DelayByActorReport::narrowableRolesFor($module);

        $role = $request->query('role') ?: null;
        // Research Centre's key is its department (a string); every other
        // narrowable role's key is a person's id -- both travel as a plain
        // query string, never cast to int here, see actorOptionsFor().
        $actorKey = $request->query('actor') ?: null;

        // Confirmed explicitly 2026-11-10: a role that anyone can pick up
        // (Admin, CGS, Senior Director) can't be narrowed down to "which
        // person" -- see DelayByActorReport::NARROWABLE_ROLES's own
        // docblock for why. A role query string naming one of those is
        // dropped rather than honoured.
        if ($role && ! $narrowableRoles->contains($role)) {
            $role = null;
        }

        $rows = DelayByActorReport::rows($module);
        $actorOptions = DelayByActorReport::actorOptionsFor($role);

        if (! $role) {
            // Nothing to narrow an individual (or department) to without a role.
            $actorKey = null;
        } elseif ($actorKey && ! $actorOptions->pluck('key')->contains($actorKey)) {
            // A role switch reloads the page before the Actor select's
            // options catch up client-side, so a stale key left over from
            // the previous role is dropped rather than silently matched
            // against the wrong person or department.
            $actorKey = null;
        }

        $byRole = DelayByActorReport::byRole($rows, $rows);

        return view('haziq::delay_report.index', [
            'module' => $module,
            'lockedToModule' => $lockedToModule,
            'role' => $role,
            'actorId' => $actorKey,
            'roles' => $narrowableRoles,
            'actors' => $actorOptions,
            'chartBars' => DelayByActorReport::chartBars($rows, $role, $actorKey),
            'bottleneck' => DelayByActorReport::bottleneckRole($byRole),
            'byRole' => $byRole,
            // Only the roles a person-level number is actually meaningful
            // for -- the role-level "By role" table above already covers
            // Admin/CGS/Senior Director, unrestricted.
            'byActor' => DelayByActorReport::byActor(
                $rows->whereIn('approver_role', $narrowableRoles->all()),
                $rows
            ),
            'rows' => $rows->sortByDesc('decided_at')->values(),
        ]);
    }
}
