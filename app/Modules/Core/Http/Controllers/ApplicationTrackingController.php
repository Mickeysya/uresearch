<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ApplicationDetails;
use App\Modules\Core\Services\ApplicationTracker;
use App\Modules\Core\Services\ModuleRegistry;
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
    /**
     * Tabs by the category each module declares, status filters, one card
     * per application, newest activity first, 20 a page -- all from
     * Services\ApplicationTracker. The tab, filter and page live in the
     * query string (?tab=assistantships&status=needs-you&page=2), so
     * refresh and back/forward work.
     */
    public function index(Request $request, ApplicationTracker $tracker, ModuleRegistry $registry)
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(ApplicationTracker::STATUSES))],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $view = $tracker->page(
            $request->user(),
            $filters['tab'] ?? 'all',
            $filters['status'] ?? 'all',
            (int) ($filters['page'] ?? 1),
            route('applications.index'),
            array_filter(['tab' => $filters['tab'] ?? null, 'status' => $filters['status'] ?? null]),
        );

        return view('core::applications.index', $view + [
            // "New application": every module a student can start, as the
            // sidebar already lists them.
            'startable' => collect($registry->submittable())
                ->map(fn ($module) => ['label' => $module->label(), 'url' => route($module->createRoute())])
                ->sortBy('label')->values()->all(),
        ]);
    }

    /**
     * "View details" -- one application, for its own student only. Built by
     * Services\ApplicationDetails; ?tab= and ?status= are the list's, kept
     * for the breadcrumb back.
     */
    public function show(Request $request, Application $application, ApplicationDetails $details)
    {
        // A student may only ever read their own submissions.
        abort_unless($application->student_id === $request->user()->id, 403);

        $back = $request->validate([
            'tab' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(ApplicationTracker::STATUSES))],
        ]);

        return view('core::applications.show', $details->for($application, array_filter($back)));
    }
}
