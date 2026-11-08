<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Haziq\Support\DocumentCompleteness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Document completeness never blocks routing" — confirmed explicitly
 * 2026-11-07: "application boleh terus ke approver lain even document tak
 * complete." A reporting view plus one manual action, same "reporting
 * module, not a workflow" shape as Allowance Eligibility and Delay by
 * Actor — see those controllers' own docblocks. Role-gated at the route
 * (Admin/Supervisor/CGS team — same group as Stage Gate/Allowance's own
 * monitoring routes); a student sees their own missing documents on
 * gra-application.mine/ga-application.mine instead, not through this page.
 */
class DocumentCompletenessController extends Controller
{
    public function index(Request $request): View
    {
        $module = $request->query('module') ?: null;
        $rows = DocumentCompleteness::rows($module);

        return view('haziq::document_completeness.index', [
            'module' => $module,
            'rows' => $rows,
            'counts' => [
                'complete' => $rows->where('complete', true)->count(),
                'incomplete' => $rows->where('complete', false)->count(),
            ],
        ]);
    }

    /** "Send Reminder to All" — every incomplete application currently matching the filter, bypassing the weekly throttle. */
    public function remindAll(Request $request): RedirectResponse
    {
        $module = $request->query('module') ?: null;
        $sent = 0;

        foreach (DocumentCompleteness::rows($module) as $row) {
            if ($row['complete']) {
                continue;
            }

            DocumentCompleteness::remind($row['application']);
            $sent++;
        }

        return redirect()->route('document-completeness.index', $module ? ['module' => $module] : [])
            ->with('status', $sent === 1 ? 'Reminder sent to 1 student.' : "Reminders sent to {$sent} students.");
    }
}
