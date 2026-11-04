<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\ReVivaCorrections;
use Illuminate\Http\Request;

/**
 * The student's own re-viva rounds: where each stands, the outcome level,
 * the final-thesis deadline and the upload (levels 1-3).
 *
 * Never an examiner's name, an individual recommendation or a staff-only
 * file: this page reads re_viva_details and the student's own DocumentStore
 * files only.
 */
class ReVivaStudentController extends Controller
{
    public function mine(Request $request)
    {
        $rounds = ReVivaDetail::with('application.documents')
            ->whereHas('application', fn ($q) => $q->where('module_type', 're_viva')->where('student_id', $request->user()->id))
            ->orderByDesc('cycle_number')
            ->get();

        return view('hani::re_viva.mine', ['rounds' => $rounds]);
    }

    public function submitFinalThesis(Request $request, ReVivaDetail $detail, ReVivaCorrections $corrections)
    {
        abort_unless($detail->application->module_type === 're_viva' && $detail->application->student_id === $request->user()->id, 404);

        $request->validate([
            'final_thesis' => [...DocumentStore::rules(required: true), 'mimes:pdf'],
        ]);

        $corrections->submit($detail, $request->file('final_thesis'), $request->user());

        return redirect()->route('reviva.mine')->with('status', 'Final thesis submitted. Your Academic Executive has been told.');
    }
}
