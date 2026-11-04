<?php

namespace App\Modules\Hani\Support\Matching;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\Departments;
use Illuminate\Support\Collection;

/**
 * "Suggested examiners" for one candidate: the top few per seat type, each
 * with its reasons.
 *
 * Only examiners who could actually be nominated are ranked -- Available, and
 * for the internal seats from the candidate's own department -- so a
 * suggestion is never one the form would then refuse. The matcher orders them
 * by expertise; config('hani.matcher.weights') breaks near-ties in favour of
 * spreading the load (fewer examinations in the last twelve months, longer
 * since the last one) and, for external examiners, experience.
 *
 * Staff only. The output names examiners and scores them, so it is served to
 * supervisors (their own candidates), Academic Executives (their own
 * department) and the Senior Executive -- never to a student.
 */
class ExaminerSuggestions
{
    /** @var (callable(Examiner, User): array<int, array{level: string, message: string}>)|null */
    protected $flagger = null;

    public function __construct(
        protected ExaminerMatcher $matcher,
        protected Departments $departments,
    ) {}

    /** Attach the conflict-of-interest pre-check's flags to each suggestion. */
    public function withFlags(callable $flagger): static
    {
        $this->flagger = $flagger;

        return $this;
    }

    /**
     * The thesis as the matcher reads it. The viva notice is the authority
     * (title, and abstract and keywords once it carries them); a title typed
     * on the nomination form, then the last nomination's, stand in for it.
     *
     * @return array{text: string, keywords: array<int, string>, source: string}
     */
    public function queryFor(User $student, ?string $typedTitle = null): array
    {
        $notice = VivaDetail::query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id)
                ->where('module_type', 'viva_voce')
                ->where('status', '!=', Application::STATUS_DRAFT))
            ->latest('id')
            ->first();

        $title = trim((string) $typedTitle) !== '' ? trim($typedTitle) : $notice?->thesis_title;
        $title ??= ExaminerNomination::query()
            ->whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->latest('id')
            ->value('thesis_title');

        $keywords = $notice?->getAttribute('keywords');

        return [
            'text' => trim(implode('. ', array_filter([$title, $notice?->getAttribute('abstract')]))),
            'keywords' => is_array($keywords)
                ? $keywords
                : \App\Modules\Hani\Support\Import\Cells::terms($keywords),
            'source' => $notice ? 'the Notice of Thesis Submission' : ($title ? 'the thesis title' : 'nothing yet'),
        ];
    }

    /**
     * @param  array<int, int>  $exclude  examiner ids already on the panel
     * @return array{query: array, internal: array<int, array>, external: array<int, array>}
     */
    public function forStudent(User $student, ?string $typedTitle = null, array $exclude = [], ?int $limit = null): array
    {
        $limit ??= (int) config('hani.matcher.suggestions', 5);
        $query = $this->queryFor($student, $typedTitle);

        $eligible = Examiner::eligible()->whereNotIn('id', $exclude)->get();

        $internal = $eligible->where('type', Examiner::TYPE_INTERNAL)
            ->filter(fn (Examiner $e) => $this->departments->same($e->department, $student->department))
            ->values();
        $external = $eligible->where('type', Examiner::TYPE_EXTERNAL)->values();

        return [
            'query' => $query,
            'internal' => $this->suggest($query, $internal, $student, $limit),
            'external' => $this->suggest($query, $external, $student, $limit),
        ];
    }

    /** @return array<int, array> */
    protected function suggest(array $query, Collection $candidates, User $student, int $limit): array
    {
        if ($candidates->isEmpty() || trim($query['text'].implode(' ', $query['keywords'])) === '') {
            return [];
        }

        $byId = $candidates->keyBy('id');
        $recent = $this->examinationsInLastYear($candidates->pluck('id'));
        $weights = config('hani.matcher.weights');

        $rows = array_map(function (array $match) use ($byId, $recent, $weights, $student) {
            /** @var Examiner $examiner */
            $examiner = $byId[$match['examiner_id']];
            $sinceLast = $examiner->last_examination_date ? (int) $examiner->last_examination_date->diffInDays(now()) : null;
            $recentCount = $recent[$examiner->id] ?? 0;
            $years = $examiner->isExternal() ? (int) $examiner->years_experience : 0;

            $rank = $match['score'] * (float) $weights['similarity']
                - $recentCount * (float) $weights['recent_examination']
                + min(($sinceLast ?? 3650) / 365, (float) $weights['gap_years_cap']) * (float) $weights['per_year_since_last']
                + min($years, (int) $weights['experience_cap']) * (float) $weights['per_year_experience'];

            return [
                'id' => $examiner->id,
                'name' => $examiner->name,
                'where' => $examiner->isExternal() ? ($examiner->institution ?: $examiner->department) : $examiner->department,
                'score' => $match['score'],
                'matched' => $match['matched'],
                'reasons' => array_values(array_filter([
                    $sinceLast === null ? 'No examination on record' : "Last examined {$sinceLast} days ago",
                    $recentCount === 0 ? 'No examinations in the last 12 months' : "{$recentCount} ".str('examination')->plural($recentCount).' in the last 12 months',
                    $examiner->isExternal() && $examiner->years_experience ? "{$examiner->years_experience} years' experience" : null,
                ])),
                'days_since_last' => $sinceLast,
                'recent_examinations' => $recentCount,
                // A reported email rides on the flags list, which the form
                // already renders, as a warning ("Check: ...").
                'flags' => array_merge(
                    $this->flagger ? ($this->flagger)($examiner, $student) : [],
                    $examiner->emailInvalid() ? [['level' => 'warning', 'message' => 'Email needs updating: reported not working.']] : [],
                ),
                'needs_email' => $examiner->needsEmail(),
                '_rank' => $rank,
            ];
        }, $this->matcher->rank($query, $candidates));

        usort($rows, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);

        return array_map(fn ($row) => array_diff_key($row, ['_rank' => true]), array_slice($rows, 0, $limit));
    }

    /**
     * Main-seat examinations per examiner on panels released in the last
     * twelve months. Backups are standby, not examinations.
     *
     * @return array<int, int>
     */
    protected function examinationsInLastYear(Collection $ids): array
    {
        $counts = [];

        ExaminerNomination::query()
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->where('status', Application::STATUS_APPROVED)
                ->where('updated_at', '>=', now()->subYear()))
            ->where(fn ($q) => $q->whereIn('internal_main_id', $ids)->orWhereIn('external_main_id', $ids))
            ->get(['internal_main_id', 'external_main_id'])
            ->each(function ($n) use (&$counts) {
                foreach ([$n->internal_main_id, $n->external_main_id] as $id) {
                    if ($id) {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }
}
