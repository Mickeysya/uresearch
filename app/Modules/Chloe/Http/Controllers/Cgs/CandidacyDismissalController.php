<?php

namespace App\Modules\Chloe\Http\Controllers\Cgs;

use App\Modules\Chloe\Exports\CandidacyDismissalExport;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyDismissalExport as ExportRow;
use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use App\Modules\Chloe\Support\CandidacyPagination as Pg;
use App\Modules\Chloe\Support\CandidacyReturnPath;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Candidacy Management > Dismissal List (CGS roles): students whose candidacy has expired, live. CGS exports the list, the Registry processes it
 * outside this system, and the result comes back through the Dismissal Upload page. No keep status, no per-row reason, no batch.
 */
class CandidacyDismissalController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request)
    {
        return view('chloe::candidacy.cgs.dismissals', $this->data($request));
    }

    public function region(Request $request)
    {
        return view('chloe::candidacy.cgs._dismissals_region', $this->data($request));
    }

    public function liveVersion()
    {
        return response()->json(['version' => L::version()]);
    }

    /** Exports exactly the rows the page shows for these filters (every candidate when none are set), then records the export. */
    public function export(Request $request)
    {
        $f = L::filters($request->all());
        $rows = L::filter(L::candidates(), $f);

        DB::transaction(function () use ($rows, $request) {
            $at = now();

            foreach ($rows as $r) {
                ExportRow::create(['candidacy_id' => $r['candidacy']?->id, 'demo_profile_id' => $r['demo']?->id, 'exported_at' => $at, 'exported_by' => $request->user()->id]);
            }
        });

        return Excel::download(new CandidacyDismissalExport($rows), 'dismissal-candidates_'.now(L::TZ)->format('Y-m-d').'.xlsx');
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $f = L::filters($request->query());
        $all = L::candidates();
        $counts = L::counts($all, $f);
        $rows = L::filter($all, $f);
        $total = $rows->count();
        $pages = Pg::pages($total, self::PER_PAGE);
        $f['page'] = Pg::page($request->query('page'), $pages);
        $query = array_filter(['group' => $f['group'], 'q' => $f['q'], 'department' => $f['department'], 'level' => $f['level'], 'outcome' => $f['outcome'], 'exported' => $f['exported'], 'page' => $f['page'] > 1 ? $f['page'] : null]);

        return [
            'f' => $f, 'counts' => $counts, 'rows' => $rows->slice(($f['page'] - 1) * self::PER_PAGE, self::PER_PAGE)->values(), 'total' => $total, 'pages' => $pages,
            'perPage' => self::PER_PAGE, 'query' => $query, 'filtered' => (bool) array_filter(array_diff_key($f, ['page' => 1])),
            'returnTo' => CandidacyReturnPath::current($request, '/cgs/candidacy/dismissals', $query),
            'departments' => CandidacyDepartment::orderBy('name')->get(), 'liveVersion' => L::version(), 'today' => L::today(),
        ];
    }
}
