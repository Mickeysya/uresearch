<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerExpertise;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\Matching\TfIdfMatcher;
use App\Modules\Hani\Support\Matching\Tokeniser;
use App\Modules\Hani\Workflows\ExaminerNominationWorkflow;
use Illuminate\Support\Collection;

/**
 * The figures behind the examiner availability dashboard (FYP objective iv).
 *
 * Scoped like everything else: an Academic Executive sees their own
 * department's examiners, nominations and theses; the Senior Executive, the
 * Senior Director and the Dean see the whole university. External examiners
 * belong to the UTP department they are related to.
 *
 * The expertise-gap panel compares what recent theses are about with who in
 * the pool could examine them, using the matcher's own terms, so "nobody
 * covers geopolymer concrete" means exactly what a suggestion would have
 * found.
 */
class ExaminerDashboard
{
    public const STATES = [
        Examiner::STATE_AVAILABLE => 'Available',
        Examiner::STATE_ON_GAP => 'On Gap',
        Examiner::STATE_ASSIGNED => 'Assigned',
        Examiner::STATE_UNAVAILABLE => 'Unavailable',
    ];

    public function __construct(
        protected TfIdfMatcher $matcher,
        protected Tokeniser $tokeniser,
    ) {}

    public function for(User $user): array
    {
        $department = Role::isDepartmentScoped($user->role) ? $user->department : null;

        $pool = Examiner::with('expertiseKeywords', 'publications')
            ->when($department, fn ($q) => $q->where('department', $department))
            ->get();

        $data = [
            'department' => $department,
            'total' => $pool->count(),
            'states' => $this->states($pool),
            'byDepartment' => $this->byDepartment($pool),
            'returning' => $this->returning($pool),
            'stages' => $this->stages($department),
            'poolKeywords' => $this->poolKeywords($pool),
            'gaps' => $this->gaps($department),
            'emails' => $this->emails($pool),
        ];

        return $data + ['chartData' => $this->chartData($data)];
    }

    /** The same figures in the shape public/js/hani/examiner-dashboard.js draws. */
    protected function chartData(array $data): array
    {
        $keys = array_keys(self::STATES);

        return [
            'states' => ['labels' => array_values(self::STATES), 'keys' => $keys, 'values' => array_values($data['states'])],
            'departments' => [
                'labels' => array_column($data['byDepartment'], 'department'),
                'series' => array_combine($keys, array_map(
                    fn ($key) => array_map(fn ($row) => $row['counts'][$key], $data['byDepartment']),
                    $keys
                )),
            ],
            'stages' => ['labels' => array_column($data['stages'], 'label'), 'values' => array_column($data['stages'], 'count')],
            'keywords' => ['labels' => array_column($data['poolKeywords'], 'keyword'), 'values' => array_column($data['poolKeywords'], 'count')],
            'gaps' => [
                'labels' => array_column($data['gaps'], 'topic'),
                'theses' => array_column($data['gaps'], 'theses'),
                'examiners' => array_column($data['gaps'], 'examiners'),
            ],
        ];
    }

    /** @return array<string, int> */
    /** Email upkeep among active examiners: missing or invalid, and not confirmed lately. */
    protected function emails(Collection $pool): array
    {
        $active = $pool->where('is_active', true);

        return [
            'problem' => $active->filter(fn (Examiner $e) => $e->emailNeedsUpdating())->count(),
            'stale' => $active->filter(fn (Examiner $e) => $e->emailStale())->count(),
        ];
    }

    protected function states(Collection $pool): array
    {
        $counts = array_fill_keys(array_keys(self::STATES), 0);

        foreach ($pool as $examiner) {
            $counts[$examiner->state()]++;
        }

        return $counts;
    }

    /** @return array<int, array{department: string, counts: array<string, int>}> largest first */
    protected function byDepartment(Collection $pool): array
    {
        return $pool->groupBy(fn (Examiner $e) => $e->department ?: 'Unassigned')
            ->map(fn ($group, $department) => [
                'department' => $department,
                'counts' => $this->states($group),
                'total' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Examiners who come back to Available within the window: their gap ends,
     * or their tie-up runs out.
     *
     * @return array<int, array{examiner: Examiner, on: \Carbon\CarbonInterface, days: int}>
     */
    protected function returning(Collection $pool): array
    {
        $window = now()->addDays((int) config('hani.dashboard.returning_days', 30));

        return $pool
            ->filter(fn (Examiner $e) => in_array($e->state(), [Examiner::STATE_ON_GAP, Examiner::STATE_ASSIGNED], true))
            ->map(function (Examiner $e) {
                // Assigned: free when the tie-up ends, unless a gap runs later.
                $on = $e->state() === Examiner::STATE_ASSIGNED ? $e->assigned_until : $e->gapEndsOn();

                if ($e->gapEndsOn() && $e->gapEndsOn()->gt($on)) {
                    $on = $e->gapEndsOn();
                }

                return ['examiner' => $e, 'on' => $on, 'days' => (int) now()->startOfDay()->diffInDays($on->copy()->startOfDay())];
            })
            ->filter(fn ($row) => $row['on']->lte($window))
            ->sortBy('on')
            ->values()
            ->all();
    }

    /** @return array<int, array{label: string, count: int}> in chain order, then released */
    protected function stages(?string $department): array
    {
        $applications = Application::query()
            ->where('module_type', 'examiner_nomination')
            ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED])
            ->where('updated_at', '>=', now()->subYear())
            ->when($department, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('department', $department)))
            ->get(['id', 'status', 'current_stage']);

        $rows = [];

        foreach ((new ExaminerNominationWorkflow())->stages() as $stage) {
            $rows[] = [
                'label' => $stage->label,
                'count' => $applications->where('status', Application::STATUS_PENDING)->where('current_stage', $stage->key)->count(),
            ];
        }

        $rows[] = ['label' => 'Released (12 months)', 'count' => $applications->where('status', Application::STATUS_APPROVED)->count()];

        return $rows;
    }

    /** @return array<int, array{keyword: string, count: int}> */
    protected function poolKeywords(Collection $pool): array
    {
        return ExaminerExpertise::query()
            ->whereIn('examiner_id', $pool->where('is_active', true)->pluck('id'))
            ->selectRaw('keyword, count(*) as examiners')
            ->groupBy('keyword')
            ->orderByDesc('examiners')
            ->orderBy('keyword')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['keyword' => $row->keyword, 'count' => (int) $row->examiners])
            ->all();
    }

    /**
     * Topics of theses in the last twelve months against the active
     * examiners who could examine them. A topic is a term from the thesis
     * titles; a two-word phrase is preferred where the pool uses it.
     *
     * @return array<int, array{topic: string, theses: int, examiners: int, gap: bool}>
     */
    protected function gaps(?string $department): array
    {
        $titles = ExaminerNomination::query()
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->where('created_at', '>=', now()->subYear())
                ->when($department, fn ($a) => $a->whereHas('student', fn ($s) => $s->where('department', $department))))
            ->pluck('thesis_title')
            ->merge(VivaDetail::query()
                ->whereHas('application', fn ($q) => $q->where('created_at', '>=', now()->subYear())
                    ->when($department, fn ($a) => $a->whereHas('student', fn ($s) => $s->where('department', $department))))
                ->pluck('thesis_title'))
            ->filter()
            ->unique();

        if ($titles->isEmpty()) {
            return [];
        }

        // Who could examine: every active examiner the department could use
        // -- its own internal examiners and every external one.
        $examiners = Examiner::with('expertiseKeywords', 'publications')
            ->where('is_active', true)
            ->when($department, fn ($q) => $q->where(fn ($w) => $w->where('type', Examiner::TYPE_EXTERNAL)->orWhere('department', $department)))
            ->get();

        $coverage = [];

        foreach ($examiners as $examiner) {
            foreach (array_unique($this->matcher->termsFor($examiner)) as $term) {
                $coverage[$term] = ($coverage[$term] ?? 0) + 1;
            }
        }

        $topics = [];

        foreach ($titles as $title) {
            $tokens = $this->tokeniser->text($title);
            $phrases = array_values(array_filter($tokens['pairs'], fn ($p) => isset($coverage[$p])));
            $covered = [];

            foreach ($phrases as $phrase) {
                $covered = array_merge($covered, explode(' ', $phrase));
            }

            $terms = array_unique(array_merge($phrases, array_diff($tokens['words'], $covered)));

            foreach ($terms as $term) {
                $topics[$term] = ($topics[$term] ?? 0) + 1;
            }
        }

        arsort($topics);
        $threshold = (int) config('hani.dashboard.gap_threshold', 2);

        return collect(array_slice($topics, 0, (int) config('hani.dashboard.gap_topics', 8), true))
            ->map(fn ($theses, $term) => [
                'topic' => $this->tokeniser->surface($term),
                'theses' => $theses,
                'examiners' => $coverage[$term] ?? 0,
                'gap' => ($coverage[$term] ?? 0) < $threshold,
            ])
            ->values()
            ->all();
    }
}
