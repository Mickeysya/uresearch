<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesOverdueStatus;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\ModuleRegistry;
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
 *
 * Also lists the student's other pending applications whose module hands
 * Core an expected decision date (Core\Contracts\SuppliesDecisionDates),
 * e.g. Norhanis' Travel, Claims and Publication, so a student has one page
 * for every date. Those rows carry only the date and whether the current
 * stage is overdue: the stage-by-stage charter detail is Nureen's own.
 */
class ExpectedDecisionController extends Controller
{
    public function index(Request $request, ClientCharter $charter, ModuleRegistry $registry)
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
            'otherRows' => $this->otherModules($request, $registry),
        ]);
    }

    /**
     * Pending applications in every other module that supplies a date,
     * still scoped to the signed-in student's own rows.
     *
     * @return \Illuminate\Support\Collection<int, array{application: Application, date: \Carbon\CarbonInterface, overdue: bool}>
     */
    protected function otherModules(Request $request, ModuleRegistry $registry)
    {
        $modules = collect($registry->all())
            ->filter(fn ($module) => $module instanceof SuppliesDecisionDates
                && ! in_array($module->key(), ProcessingTimeAnalytics::MODULES, true));

        if ($modules->isEmpty()) {
            return collect();
        }

        return Application::query()
            ->where('student_id', $request->user()->id)
            ->whereIn('module_type', $modules->map->key()->values())
            ->where('status', Application::STATUS_PENDING)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(function (Application $application) use ($modules) {
                $module = $modules->first(fn ($m) => $m->key() === $application->module_type);

                return [
                    'application' => $application,
                    'date' => $module->expectedDecisionDate($application),
                    'overdue' => $module instanceof SuppliesOverdueStatus && $module->decisionIsOverdue($application),
                ];
            })
            ->filter(fn (array $row) => $row['date'] !== null)
            ->values();
    }
}
