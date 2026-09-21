<?php

namespace App\Modules\Chloe\Http\Controllers;

use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The student's own candidacy status, expiry countdown and reminder history.
 */
class CandidacyController extends Controller
{
    public function status(Request $request)
    {
        $candidacy = StudyCandidacy::where('student_id', $request->user()->id)->first();

        $reminders = $candidacy
            ? $candidacy->reminders()->latest('sent_at')->get()
            : collect();

        $appeals = \App\Modules\Core\Models\Application::where('student_id', $request->user()->id)
            ->where('module_type', 'candidacy_appeal')
            ->latest('submitted_at')
            ->get();

        return view('chloe::candidacy.status', compact('candidacy', 'reminders', 'appeals'));
    }
}
