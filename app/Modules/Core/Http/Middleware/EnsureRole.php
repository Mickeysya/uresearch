<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route to one or more roles: ->middleware('role:chair,dean_pgr').
 *
 * In the legacy app every approval page checked only that somebody was logged
 * in, so any student could open travel_dean_approval.php and grant final
 * approval to their own application. This is the first of two locks; the
 * second is in WorkflowEngine::decide(), which re-checks that the actor's role
 * matches the stage the application is actually sitting on.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Your role does not have access to this screen.');
        }

        return $next($request);
    }
}
