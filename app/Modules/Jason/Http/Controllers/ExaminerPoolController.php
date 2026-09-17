<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
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
    public function index()
    {
        return view('jason::examiner_pool.index', [
            'examiners' => PoolExaminer::query()
                ->with('appointments')
                ->orderByRaw("CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END")
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
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

        return redirect()
            ->route('appointment-letter.examiners')
            ->with('status', "{$examiner->name} added as {$examiner->typeLabel()}.");
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
