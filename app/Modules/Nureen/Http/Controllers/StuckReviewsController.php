<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Support\StuckSupervisorStages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CGS's warning list: pending requests no account can act on, and the
 * reassign action for a GA Extension or Certification whose supervisor
 * account can't act. Non-Executive CGS only (route middleware).
 */
class StuckReviewsController extends Controller
{
    public function index(StuckSupervisorStages $stuck)
    {
        return view('nureen::stuck_reviews.index', [
            'stuck' => $stuck->all(),
            'supervisors' => User::where('role', Role::SUPERVISOR)->orderBy('name')->get(['id', 'name', 'email', 'department']),
        ]);
    }

    public function reassign(Request $request, Application $application, StuckSupervisorStages $stuck): RedirectResponse
    {
        $data = $request->validate([
            'supervisor_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ], [
            'reason.required' => 'Say why this request is being given to another supervisor. The student sees this reason.',
            'reason.min' => 'Give the student a short sentence of explanation.',
        ]);

        // Only a request that is genuinely stuck, at the supervisor stage,
        // in a module whose supervisor can be swapped.
        $row = $stuck->all()->first(fn ($s) => $s['application']->is($application));
        abort_unless($row && $row['reassignable'], 404, 'That request is not waiting on a supervisor who cannot act.');

        $to = User::findOrFail($data['supervisor_id']);

        if ($to->role !== Role::SUPERVISOR) {
            return back()->withErrors(['supervisor_id' => 'Choose an account that has the Supervisor role.'])->withInput();
        }

        $stuck->reassign($application, $to, $request->user(), $data['reason']);

        return redirect()->route('nureen.stuck-reviews')
            ->with('status', $application->reference()." is now with {$to->name}, who has been emailed.");
    }
}
