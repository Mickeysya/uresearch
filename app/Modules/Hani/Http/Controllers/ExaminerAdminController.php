<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Models\Examiner;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Day-to-day upkeep of the examiner pool for CGS: add an examiner, or flip
 * one to Unavailable (retired, resigned, deceased) without deleting their
 * history. Everything else about the pool -- eligibility, the gap, the
 * assigned tie-up -- is derived state the nomination and evaluation flows
 * already maintain; this screen only ever touches the fields a human has to
 * set by hand.
 */
class ExaminerAdminController extends Controller
{
    protected const PER_PAGE = 8;

    public function index(Request $request)
    {
        // Counts for the stat cards always reflect the whole pool, never the
        // filtered/paginated table below -- a filtered view of 2 examiners
        // shouldn't make the "42 available" figure disappear.
        $pool = Examiner::all();
        $stateCounts = [
            Examiner::STATE_AVAILABLE => 0,
            Examiner::STATE_ON_GAP => 0,
            Examiner::STATE_ASSIGNED => 0,
            Examiner::STATE_UNAVAILABLE => 0,
        ];
        foreach ($pool as $examiner) {
            $stateCounts[$examiner->state()]++;
        }

        // Plain strings throughout, not the Stringable Request::string() returns --
        // Examiner::state() is a plain string, and Stringable === string is never
        // true even when the contents match.
        $department = (string) $request->input('department', '');
        $type = (string) $request->input('type', '');
        $status = (string) $request->input('status', '');
        $search = mb_strtolower((string) $request->input('search', ''));

        $filtered = $pool
            ->when($department !== '', fn ($c) => $c->where('department', $department))
            ->when($type !== '', fn ($c) => $c->where('type', $type))
            ->when($status !== '', fn ($c) => $c->filter(fn (Examiner $e) => $e->state() === $status))
            ->when($search !== '', fn ($c) => $c->filter(fn (Examiner $e) => str_contains(mb_strtolower($e->name), $search)
                || str_contains(mb_strtolower($e->department), $search)))
            ->sortBy('name')
            ->values();

        $page = $request->integer('page', 1);
        $examiners = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('hani::examiner_admin.index', [
            'examiners' => $examiners,
            'stateCounts' => $stateCounts,
            'departments' => $pool->pluck('department')->unique()->sort()->values(),
            'filters' => $request->only(['department', 'type', 'status', 'search']),
        ]);
    }

    public function create()
    {
        return view('hani::examiner_admin.form');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:examiners,email'],
            'department' => ['required', 'string', 'max:150'],
            'faculty' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'in:internal,external'],
        ]);

        Examiner::create($data + ['is_active' => true]);

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', "Examiner {$data['name']} added to the pool.");
    }

    /**
     * Toggles Unavailable on or off; never deletes -- nominations already
     * reference the row. Going unavailable requires a reason (the modal on
     * the pool list enforces this); coming back doesn't ask for one, and
     * clears whatever reason was on file so it can't mislead next time.
     */
    public function toggleActive(Request $request, Examiner $examiner)
    {
        if ($examiner->is_active) {
            $data = $request->validate([
                'unavailable_reason' => ['required', 'string', 'max:2000'],
            ]);

            $examiner->update(['is_active' => false, 'unavailable_reason' => $data['unavailable_reason']]);
            $status = "{$examiner->name} marked unavailable.";
        } else {
            $examiner->update(['is_active' => true, 'unavailable_reason' => null]);
            $status = "{$examiner->name} reactivated.";
        }

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', $status);
    }
}
