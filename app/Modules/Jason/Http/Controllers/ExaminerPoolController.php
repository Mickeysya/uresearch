<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\PoolExaminer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The examiner list the Chair nominates from. Chairs and CGS keep it: a new
 * examiner is added here first, then picked on the nomination form.
 */
class ExaminerPoolController extends Controller
{
    public function index(Request $request)
    {
        return view('jason::examiner_pool.index', [
            'examiners' => PoolExaminer::query()
                ->orderByRaw("CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END")
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'returnToNomination' => $this->returningToNomination($request),
        ]);
    }

    /**
     * Whether this visit came out of the nomination form, which is the only
     * screen that sends anyone here mid-task.
     *
     * The role is re-checked rather than trusted from the query string: CGS
     * keeps this list too, and bouncing them to a Chair-only route would be
     * a 403 at the end of a successful save.
     */
    protected function returningToNomination(Request $request): bool
    {
        return $request->input('return') === 'nominate'
            && $request->user()->role === Role::CHAIR;
    }

    public function create(Request $request)
    {
        return view('jason::examiner_pool.form', [
            'returnToNomination' => $this->returningToNomination($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'examiner_type' => ['required', Rule::in([AppointmentExaminer::TYPE_INTERNAL, AppointmentExaminer::TYPE_EXTERNAL])],
            'name' => ['required', 'string', 'max:150'],
            'institution' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['required', 'email', 'max:150', Rule::unique('appointment_examiner_pool', 'email')],
            'expertise' => ['required', 'string', 'max:255'],
        ], [
            'email.unique' => 'An examiner with that email address is already on the list.',
        ]);

        $examiner = PoolExaminer::create($data);
        $status = "{$examiner->name} added as {$examiner->typeLabel()}.";

        // Came here from the nomination form to add the one missing examiner:
        // go back to it rather than stranding them on the list. The panel they
        // had already picked is restored client-side -- see the script at the
        // foot of appointment_letter/form.blade.php.
        if ($this->returningToNomination($request)) {
            return redirect()->route('appointment-letter.create')->with('status', $status);
        }

        return redirect()->route('appointment-letter.examiners')->with('status', $status);
    }

    /**
     * Retire or reinstate an examiner. Nothing is deleted: past nominations
     * hold their own copy of the details, and a retired examiner can come back.
     */
    public function toggle(PoolExaminer $examiner): RedirectResponse
    {
        $examiner->update(['is_active' => ! $examiner->is_active]);

        return redirect()
            ->route('appointment-letter.examiners')
            ->with('status', $examiner->name.($examiner->is_active ? ' reinstated.' : ' removed from the list.'));
    }
}
