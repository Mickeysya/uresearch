<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Http\Request;

/**
 * Day-to-day upkeep of the examiner pool for CGS: add an examiner, or flip
 * one to Unavailable (retired, resigned, deceased) without deleting their
 * history. Everything else about the pool -- eligibility, the gap, the
 * assigned tie-up -- is derived state the nomination and evaluation flows
 * already maintain; this screen only ever touches the fields a human has to
 * set by hand.
 */
class ExaminerAdminController extends Controller
{
    public function index()
    {
        return view('hani::examiner_admin.index', [
            'examiners' => Examiner::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('hani::examiner_admin.form');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:examiners,email'],
            'department' => ['required', 'string', 'max:150'],
            'faculty' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'in:internal,external'],
        ]);

        Examiner::create($data + ['is_active' => true]);

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', "Examiner {$data['name']} added to the pool.");
    }

    /** Toggles Unavailable on or off; never deletes -- nominations already reference the row. */
    public function toggleActive(Examiner $examiner)
    {
        $examiner->update(['is_active' => ! $examiner->is_active]);

        $state = $examiner->is_active ? 'reactivated' : 'marked unavailable';

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', "{$examiner->name} {$state}.");
    }
}
