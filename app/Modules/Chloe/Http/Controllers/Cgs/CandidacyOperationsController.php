<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Support\CandidacyOperationsQuery as Q;
use App\Modules\Chloe\Support\CandidacyReturnPath;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Candidacy Management > Operations (CGS roles): every appeal, in the look of the Approval Portal. The cards, tabs and table are
 * all produced by CandidacyOperationsQuery from the same filters, so they cannot disagree. Rows open the one appeal page.
 */
class CandidacyOperationsController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request)
    {
        return view('chloe::candidacy.cgs.operations', $this->data($request));
    }

    /** The live region (cards, tabs, table) with the CURRENT filters, group and page. */
    public function region(Request $request)
    {
        return view('chloe::candidacy.cgs._operations_region', $this->data($request));
    }

    public function liveVersion()
    {
        return response()->json(['version' => Q::version()]);
    }

    public function liveStats(Request $request)
    {
        return response()->json(['stats' => Q::counts(Q::filters($request->query())), 'version' => Q::version()]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $f = Q::filters($request->query());
        $counts = Q::counts($f);
        $total = $counts[$f['group'] ?? 'all'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $f['page'] = min($f['page'], $pages);

        $rows = Q::forGroup($f, $f['group'])
            ->with(['student', 'demoProfile', 'department', 'supervisorProfile', 'chairProfile', 'deanProfile'])
            ->orderByRaw('CASE WHEN current_stage IS NULL THEN 1 ELSE 0 END, stage_entered_at asc, id desc')
            ->skip(($f['page'] - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get();

        $query = array_filter(['group' => $f['group'], 'q' => $f['q'], 'department' => $f['department'], 'status' => $f['status'], 'step' => $f['step'], 'months' => $f['months'], 'waiting' => $f['waiting'], 'round' => $f['round'], 'page' => $f['page'] > 1 ? $f['page'] : null]);

        return [
            'f' => $f, 'counts' => $counts, 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'query' => $query,
            'returnTo' => CandidacyReturnPath::current($request, '/cgs/candidacy', $query),
            'departments' => CandidacyDepartment::orderBy('name')->get(),
            'liveVersion' => Q::version(),
        ];
    }
}
