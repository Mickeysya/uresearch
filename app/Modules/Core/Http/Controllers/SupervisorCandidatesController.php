<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\SupervisorDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Every student a supervisor is responsible for: the full list behind the
 * dashboard's "My candidates" panel, with a search.
 *
 * The same rows the panel shows (SupervisorDashboard::candidates(), keyed on
 * users.supervisor_id), so the two can never disagree about who a
 * supervisor's students are. Supervisor-only by route middleware, and the
 * query is the signed-in supervisor's own -- no id in the URL to change.
 */
class SupervisorCandidatesController extends Controller
{
    public function index(Request $request, ModuleRegistry $registry)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->query('q', ''));

        $dash = new SupervisorDashboard($request->user(), $registry);

        // Alphabetical here, unlike the panel's at-risk-first: this page is
        // for finding a particular student. At-risk rows still carry a flag.
        $all = $dash->candidates()->sortBy(fn (array $row) => Str::lower($row['student']->name))->values();

        $rows = $search === ''
            ? $all
            : $all->filter(fn (array $row) => Str::contains(Str::lower(implode(' ', [
                $row['student']->name,
                $row['student']->matric_no,
                $row['student']->programme,
                $row['student']->email,
            ])), Str::lower($search)))->values();

        return view('core::supervisor.candidates', [
            'rows' => $rows,
            'total' => $all->count(),
            'atRisk' => $all->filter(fn (array $row) => $row['attendance']?->at_risk)->count(),
            'search' => $search,
            'unavailable' => $dash->unavailable(),
        ]);
    }
}
