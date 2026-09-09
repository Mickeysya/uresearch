<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use Illuminate\Http\Request;

/**
 * "Track My Applications" -- one page for every module.
 *
 * The legacy my_applications.php had a hardcoded if/elseif per module for both
 * the stage list and the detail line, plus an N+1 detail query per row, and it
 * silently mislabelled anything it did not recognise as a Claims application.
 * Both now come from the module itself.
 */
class ApplicationTrackingController extends Controller
{
    public function index(Request $request)
    {
        $applications = $request->user()
            ->applications()
            ->with('history.approver')
            ->latest('submitted_at')
            ->get();

        return view('core::applications.index', compact('applications'));
    }

    public function show(Request $request, Application $application)
    {
        // A student may only ever read their own submissions.
        abort_unless($application->student_id === $request->user()->id, 403);

        $application->load('history.approver', 'documents');

        return view('core::applications.show', compact('application'));
    }
}
