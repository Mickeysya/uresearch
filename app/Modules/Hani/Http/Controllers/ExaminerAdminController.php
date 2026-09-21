<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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
        // Internal and external are two different records, not one list with a
        // flag: CGS keeps them as two sheets, and the external one carries nine
        // columns the internal one has no equivalent of. The tab strip is this
        // parameter; '' shows both, with only the columns they share.
        $type = in_array($request->input('type'), [Examiner::TYPE_INTERNAL, Examiner::TYPE_EXTERNAL], true)
            ? (string) $request->input('type')
            : '';

        $pool = Examiner::all();
        $tabPool = $type === '' ? $pool : $pool->where('type', $type);

        // Counts for the stat cards follow the tab, never the filters or the
        // page below it -- filtering to 2 examiners shouldn't make "42
        // available" disappear, but "42 available" across both lists is not a
        // figure anyone at CGS works with either.
        $stateCounts = [
            Examiner::STATE_AVAILABLE => 0,
            Examiner::STATE_ON_GAP => 0,
            Examiner::STATE_ASSIGNED => 0,
            Examiner::STATE_UNAVAILABLE => 0,
        ];
        foreach ($tabPool as $examiner) {
            $stateCounts[$examiner->state()]++;
        }

        // Plain strings throughout, not the Stringable Request::string() returns --
        // Examiner::state() is a plain string, and Stringable === string is never
        // true even when the contents match.
        $department = (string) $request->input('department', '');
        $status = (string) $request->input('status', '');
        $search = mb_strtolower((string) $request->input('search', ''));

        $filtered = $tabPool
            ->when($department !== '', fn ($c) => $c->where('department', $department))
            ->when($status !== '', fn ($c) => $c->filter(fn (Examiner $e) => $e->state() === $status))
            ->when($search !== '', fn ($c) => $c->filter(fn (Examiner $e) => str_contains(mb_strtolower($e->name), $search)
                || str_contains(mb_strtolower($e->department), $search)
                || str_contains(mb_strtolower((string) $e->institution), $search)))
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
            'type' => $type,
            'typeCounts' => [
                '' => $pool->count(),
                Examiner::TYPE_INTERNAL => $pool->where('type', Examiner::TYPE_INTERNAL)->count(),
                Examiner::TYPE_EXTERNAL => $pool->where('type', Examiner::TYPE_EXTERNAL)->count(),
            ],
            'stateCounts' => $stateCounts,
            // Scoped to the tab: an external department list on the internal
            // tab only ever filters everything away.
            'departments' => $tabPool->pluck('department')->unique()->sort()->values(),
            'studentsByExaminer' => $this->studentsByExaminer($examiners->getCollection()->pluck('id')),
            'filters' => $request->only(['department', 'type', 'status', 'search']),
        ]);
    }

    /**
     * The sheet's "Student Name" column (and the internal sheet's "Remarks"),
     * read from examiner_nominations rather than stored again on the examiner,
     * so the pool can never disagree with the nominations it is derived from.
     * Backups count: a final approval ties both nominees up, so both are
     * genuinely holding a student.
     *
     * @param  Collection<int, int>  $ids  examiner ids on the page being shown
     * @return array<int, list<string>>
     */
    protected function studentsByExaminer(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $map = [];

        ExaminerNomination::with('application.student')
            ->where(function ($q) use ($ids) {
                foreach (array_keys(ExaminerNomination::SLOTS) as $slot) {
                    $q->orWhereIn($slot, $ids);
                }
            })
            ->get()
            ->each(function (ExaminerNomination $nomination) use (&$map, $ids) {
                $student = $nomination->application?->student;

                if (! $student) {
                    return;
                }

                // "Hussaini Mamman_21000736", the form the CGS sheet uses.
                $label = $student->matric_no ? "{$student->name}_{$student->matric_no}" : $student->name;

                foreach (array_keys(ExaminerNomination::SLOTS) as $slot) {
                    $id = $nomination->{$slot};

                    if ($id && $ids->contains($id)) {
                        $map[$id][] = $label;
                    }
                }
            });

        return array_map(fn (array $labels) => array_values(array_unique($labels)), $map);
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

            // External only. Nullable because CGS regularly adds an examiner
            // before the faculty paper is approved -- except the institution,
            // which is the whole point of the row being external.
            'institution' => ['nullable', 'required_if:type,external', 'string', 'max:150'],
            'faculty_approval' => ['nullable', 'string', 'max:30'],
            'sector' => ['nullable', 'in:technical,research'],
            'expertise' => ['nullable', 'string', 'max:2000'],
            'utp_cluster' => ['nullable', 'string', 'max:150'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'msc_graduated' => ['nullable', 'integer', 'min:0', 'max:999'],
            'phd_graduated' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        // An internal examiner has no external record, whatever was posted:
        // the fields are hidden for internal, so anything arriving in them
        // came from a stale form or by hand.
        if ($data['type'] === Examiner::TYPE_INTERNAL) {
            $data = Arr::except($data, Examiner::EXTERNAL_FIELDS);
        }

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
