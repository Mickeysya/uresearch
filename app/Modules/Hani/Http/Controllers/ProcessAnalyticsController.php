<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Exports\ProcessAnalyticsCsvExport;
use App\Modules\Hani\Exports\ProcessAnalyticsExport;
use App\Modules\Hani\Support\ProcessAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Process Analytics, linked from the Examiner Dashboard. Read-only: the
 * Academic Executive's own department; the whole university for the Senior
 * Executive, the Senior Director, the Dean and the Non-Executive CGS
 * (routes.php). The export is the same tables, same scope, same period.
 */
class ProcessAnalyticsController extends Controller
{
    public function index(Request $request, ProcessAnalytics $analytics)
    {
        $months = $this->months($request);

        return view('hani::process_analytics.index', $analytics->for($request->user(), $months) + [
            'periods' => ProcessAnalytics::periods(),
        ]);
    }

    public function export(Request $request, ProcessAnalytics $analytics)
    {
        $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv'])]]);
        $months = $this->months($request);
        $data = $analytics->for($request->user(), $months);

        $scope = $data['department'] ? str($data['department'])->slug() : 'all';
        $name = "process-analytics-{$scope}-{$months}m-".now()->format('Ymd');

        if ($request->input('format') === 'csv') {
            $caption = sprintf(
                'Process Analytics, %s, %s to %s (generated %s)',
                $data['department'] ?? 'all departments', $data['from']->format('j M Y'), $data['to']->format('j M Y'), now()->format('j M Y H:i'),
            );

            return Excel::download(new ProcessAnalyticsCsvExport($data['tables'], $caption), "{$name}.csv", ExcelFormat::CSV);
        }

        return Excel::download(new ProcessAnalyticsExport($data['tables']), "{$name}.xlsx");
    }

    protected function months(Request $request): int
    {
        $request->validate(['months' => ['nullable', Rule::in(ProcessAnalytics::periods())]]);

        return ProcessAnalytics::period($request->input('months'));
    }
}
