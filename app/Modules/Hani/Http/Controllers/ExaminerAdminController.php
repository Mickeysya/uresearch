<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ExaminerReviewItem;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\ExaminerContact;
use App\Modules\Hani\Support\DuplicateDetector;
use App\Modules\Hani\Models\ExaminerDuplicateDismissal;
use App\Modules\Hani\Support\Import\Cells;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Day-to-day upkeep of the examiner pool for the Senior Executive CGS: add
 * or edit an examiner, or flip one to Unavailable (retired, resigned, deceased) without deleting their
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
            'openReviewItems' => ExaminerReviewItem::open()->count(),
            // Across the whole pool, not just this page: a duplicate is
            // usually on another page or the other tab.
            'duplicates' => app(DuplicateDetector::class)->byExaminer($pool),
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
        return view('hani::examiner_admin.form', ['examiner' => null]);
    }

    public function store(Request $request, Departments $departments, ExaminerContact $contact)
    {
        $data = $this->validated($request, $departments);

        // The email goes in through ExaminerContact, so the history starts
        // with where the first address came from.
        $examiner = Examiner::create(Arr::except($data, ['keywords', 'email', 'email_change_reason']) + ['is_active' => true]);
        $contact->changeEmail($examiner, $data['email'], $request->user(), 'manual', 'Added with the examiner.');
        $this->syncKeywords($examiner, $data['keywords']);

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', "Examiner {$data['name']} added to the pool.");
    }

    public function edit(Examiner $examiner)
    {
        return view('hani::examiner_admin.form', ['examiner' => $examiner->load('expertiseKeywords')]);
    }

    /**
     * The same rules as adding one. Availability is not edited here: going
     * Unavailable needs its reason modal on the pool list (toggleActive()).
     */
    public function update(Request $request, Examiner $examiner, Departments $departments, ExaminerContact $contact)
    {
        $data = $this->validated($request, $departments, $examiner);

        // A switch to internal empties the external record rather than
        // leaving it behind on a row that no longer shows it.
        if ($data['type'] === Examiner::TYPE_INTERNAL) {
            $data += array_fill_keys(Examiner::EXTERNAL_FIELDS, null);
        }

        $examiner->update(Arr::except($data, ['keywords', 'email', 'email_change_reason']));
        $contact->changeEmail($examiner, $data['email'], $request->user(), 'manual', $data['email_change_reason'] ?? null);
        $this->syncKeywords($examiner, $data['keywords']);

        return redirect()
            ->route('examiner-admin.index')
            ->with('status', "Examiner {$examiner->name} updated.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, Departments $departments, ?Examiner $examiner = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            // Optional since 2026-11-03: CGS's own pool file has no email
            // column, and an examiner without one is still in the pool --
            // shown as "Email needed" and skipped by anything that writes.
            'email' => ['nullable', 'email', 'max:150', Rule::unique('examiners', 'email')->ignore($examiner?->id)],
            'email_change_reason' => ['nullable', 'string', 'max:500'],
            'department' => ['required', 'string', 'max:150'],
            'faculty' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'in:internal,external'],
            'expertise' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:2000'],

            // External only. Nullable because CGS regularly adds an examiner
            // before the faculty paper is approved -- except the institution,
            // which is the whole point of the row being external.
            'institution' => ['nullable', 'required_if:type,external', 'string', 'max:150'],
            'faculty_approval' => ['nullable', 'string', 'max:30'],
            'sector' => ['nullable', 'in:technical,research'],
            'utp_cluster' => ['nullable', 'string', 'max:150'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
            'msc_graduated' => ['nullable', 'integer', 'min:0', 'max:999'],
            'phd_graduated' => ['nullable', 'integer', 'min:0', 'max:999'],
            'current_utp_appointment' => ['nullable', 'string', 'max:150'],
            'former_utp_until' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        // "Computer & Information Sciences" is filed as "Computing", so the
        // own-department rule can match it against a student's account. A
        // name nobody knows is kept as typed.
        if ($resolved = $departments->resolve($data['department'])) {
            $data['department'] = $resolved['name'];
            $data['faculty'] = ($data['faculty'] ?? null) ?: $resolved['faculty'];
        }

        $data['email'] = filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null;
        $data['keywords'] = Cells::terms($data['keywords'] ?? '');

        // An internal examiner has no external record, whatever was posted:
        // the fields are hidden for internal, so anything arriving in them
        // came from a stale form or by hand.
        if ($data['type'] === Examiner::TYPE_INTERNAL) {
            $data = Arr::except($data, Examiner::EXTERNAL_FIELDS);
        }

        return $data;
    }

    /** The form's keyword list is the examiner's list. */
    protected function syncKeywords(Examiner $examiner, array $keywords): void
    {
        $current = $examiner->expertiseKeywords()->pluck('keyword')->all();

        foreach (array_diff($keywords, $current) as $keyword) {
            $examiner->expertiseKeywords()->create(['keyword' => $keyword]);
        }

        $examiner->expertiseKeywords()->whereNotIn('keyword', $keywords ?: [''])->get()->each->delete();
    }

    /**
     * "Not the same person": the Senior Executive has checked a flagged pair
     * and they are two people. Stored, so the pair is never flagged again.
     */
    public function dismissDuplicate(Request $request)
    {
        $data = $request->validate([
            'examiner_id' => ['required', 'integer', 'exists:examiners,id'],
            'other_id' => ['required', 'integer', 'exists:examiners,id', 'different:examiner_id'],
        ]);

        ExaminerDuplicateDismissal::dismiss($data['examiner_id'], $data['other_id'], $request->user()->id);

        return back()->with('status', 'Marked as two different people. They will not be flagged again.');
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
