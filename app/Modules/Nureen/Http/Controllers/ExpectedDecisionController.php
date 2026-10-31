<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Support\ClientCharter;
use App\Modules\Nureen\Support\ProcessingTimeAnalytics;
use Illuminate\Http\Request;

/**
 * "When should I expect an answer?" -- the student's own pending GA
 * Extension, Supervision and Certification applications, each with the
 * expected decision date Support\ClientCharter works out from CGS's Client
 * Charter.
 *
 * Its own page because the screens a student normally checks
 * (applications.show, the dashboard's My Application Status panel) are
 * Core's and have no slot a module can write into -- see SHARED_CHANGES.md
 * for the seam that would let this text appear there instead. Reached from
 * the student sidebar via GaExtensionWorkflow::links().
 */
class ExpectedDecisionController extends Controller
{
    public function index(Request $request, ClientCharter $charter)
    {
        // Scoped to the signed-in student's own rows (rule 8), and to
        // pending ones: a decided application has no expected date.
        $applications = Application::query()
            ->where('student_id', $request->user()->id)
            ->whereIn('module_type', ProcessingTimeAnalytics::MODULES)
            ->where('status', Application::STATUS_PENDING)
            ->with('history')
            ->orderByDesc('submitted_at')
            ->get();

        return view('nureen::charter.student', [
            'rows' => $applications
                ->map(fn (Application $application) => [
                    'application' => $application,
                    'expected' => $charter->expectedDecision($application),
                ])
                ->filter(fn (array $row) => $row['expected'] !== null)
                ->values(),
        ]);
    }
}
