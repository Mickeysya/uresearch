<?php

namespace App\Modules\Hani\Support\Matching;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerExpertise;
use App\Modules\Hani\Models\ExaminerPublication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The default ExaminerMatcher: TF-IDF with cosine similarity, in plain PHP.
 *
 *   examiner document = area of expertise + expertise keywords (with their
 *                       multi-word phrases) + publication titles
 *   query document    = thesis title (+ abstract and keywords once the viva
 *                       notice carries them)
 *
 * Term frequency is log-scaled (1 + ln tf) so an examiner who repeats a word
 * in every publication title does not drown out one who lists it once as a
 * keyword. Inverse document frequency, ln((N+1)/(df+1)) + 1, is taken over
 * the ELIGIBLE pool, so a word every available examiner shares ("engineering")
 * counts for little and a rare one ("geopolymer") for a lot. Phrase terms get
 * config('hani.matcher.phrase_boost'). The score is the cosine of the two
 * vectors, 0-100.
 *
 * Explainable by construction: the matched terms are the overlap between the
 * two vectors, ordered by how much each contributed to the score.
 *
 * The term statistics are cached under a key derived from the pool itself --
 * examiners, expertise keywords and publications (count and latest change),
 * plus today's date because eligibility changes as gaps run out -- so any
 * change to the pool is a different key and the table is rebuilt on next use.
 */
class TfIdfMatcher implements ExaminerMatcher
{
    public function __construct(protected Tokeniser $tokeniser) {}

    public function rank(array $query, Collection $candidates): array
    {
        $model = $this->model();
        $this->tokeniser->remember($model['surfaces']);

        $queryVector = $this->vector($this->queryTerms($query, $model), $model['idf']);

        if ($queryVector === []) {
            return [];
        }

        $results = [];

        foreach ($candidates as $examiner) {
            $terms = $model['docs'][$examiner->id] ?? $this->termCounts($this->documentTerms($examiner));
            $docVector = $this->vector($terms, $model['idf']);

            [$score, $contributions] = $this->cosine($queryVector, $docVector);

            if ($score <= 0) {
                continue;
            }

            $results[] = [
                'examiner_id' => $examiner->id,
                'score' => (int) round($score * 100),
                'matched' => $this->explain($contributions),
            ];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * The cached statistics: idf per term, term counts per eligible examiner,
     * and how to show each term to a person.
     *
     * @return array{idf: array<string, float>, docs: array<int, array<string, int>>, surfaces: array<string, string>}
     */
    public function model(): array
    {
        return Cache::remember('hani.matcher.'.$this->poolVersion(), now()->addDay(), fn () => $this->build());
    }

    /** Changes whenever anything a document is built from changes. */
    public function poolVersion(): string
    {
        $parts = [now()->toDateString()];

        foreach ([Examiner::class, ExaminerExpertise::class, ExaminerPublication::class] as $model) {
            $parts[] = $model::query()->count().'@'.$model::query()->max('updated_at');
        }

        return md5(implode('|', $parts));
    }

    protected function build(): array
    {
        $pool = Examiner::eligible()->with('expertiseKeywords', 'publications')->get();
        $docs = [];
        $df = [];

        foreach ($pool as $examiner) {
            $docs[$examiner->id] = $this->termCounts($this->documentTerms($examiner));

            foreach (array_keys($docs[$examiner->id]) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $n = count($docs);
        $idf = [];

        foreach ($df as $term => $count) {
            $idf[$term] = log(($n + 1) / ($count + 1)) + 1;
        }

        return ['idf' => $idf, 'docs' => $docs, 'surfaces' => $this->tokeniser->surfaces()];
    }

    /**
     * The terms an examiner's document is made of -- what the dashboard's
     * expertise-gap panel counts as "covers this topic".
     *
     * @return array<int, string>
     */
    public function termsFor(Examiner $examiner): array
    {
        return $this->documentTerms($examiner);
    }

    /** @return array<int, string> */
    protected function documentTerms(Examiner $examiner): array
    {
        $examiner->loadMissing('expertiseKeywords', 'publications');

        // CGS's numbered list ("1.Anomaly Detection\n2.Time Series") is a
        // keyword list, so its phrases count as phrases; a sentence ("Machine
        // learning and data analytics") is read as text.
        $expertise = preg_match('/[\r\n;]/', (string) $examiner->expertise)
            ? $this->tokeniser->keywords(\App\Modules\Hani\Support\Import\Cells::terms($examiner->expertise))
            : $this->tokeniser->text($examiner->expertise)['words'];

        return array_merge(
            $expertise,
            $this->tokeniser->keywords($examiner->expertiseKeywords->pluck('keyword')->all()),
            ...$examiner->publications->map(fn ($p) => $this->tokeniser->text($p->title)['words'])->all(),
        );
    }

    /**
     * The thesis as terms that exist in the pool's vocabulary. Free-text word
     * pairs are kept only where some examiner has that phrase, which is how a
     * bare title still matches "anomaly detection" as a phrase.
     *
     * @return array<string, int>
     */
    protected function queryTerms(array $query, array $model): array
    {
        $text = $this->tokeniser->text($query['text'] ?? '');

        $terms = array_merge(
            $text['words'],
            array_filter($text['pairs'], fn ($pair) => isset($model['idf'][$pair])),
            $this->tokeniser->keywords($query['keywords'] ?? []),
        );

        return array_filter($this->termCounts($terms), fn ($count, $term) => isset($model['idf'][$term]), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, int> */
    protected function termCounts(array $terms): array
    {
        return array_count_values($terms);
    }

    /** @return array<string, float> tf-idf weights */
    protected function vector(array $counts, array $idf): array
    {
        $boost = (float) config('hani.matcher.phrase_boost', 1.5);
        $vector = [];

        foreach ($counts as $term => $count) {
            if (! isset($idf[$term])) {
                continue;
            }

            $vector[$term] = (1 + log($count)) * $idf[$term] * ($this->tokeniser->isPhrase($term) ? $boost : 1);
        }

        return $vector;
    }

    /** @return array{0: float, 1: array<string, float>} cosine, and each shared term's share of the dot product */
    protected function cosine(array $a, array $b): array
    {
        $dot = 0.0;
        $contributions = [];

        foreach ($a as $term => $weight) {
            if (isset($b[$term])) {
                $contributions[$term] = $weight * $b[$term];
                $dot += $contributions[$term];
            }
        }

        if ($dot === 0.0) {
            return [0.0, []];
        }

        $norm = sqrt(array_sum(array_map(fn ($w) => $w * $w, $a))) * sqrt(array_sum(array_map(fn ($w) => $w * $w, $b)));

        return [$dot / $norm, $contributions];
    }

    /**
     * The shared terms that did the work, in the user's words. A matched
     * phrase stands in for its own words: "anomaly detection", not
     * "anomaly detection, anomaly, detection".
     *
     * @return array<int, string>
     */
    protected function explain(array $contributions): array
    {
        arsort($contributions);

        $covered = [];

        foreach (array_keys($contributions) as $term) {
            if ($this->tokeniser->isPhrase($term)) {
                $covered = array_merge($covered, explode(' ', $term));
            }
        }

        $terms = array_filter(
            array_keys($contributions),
            fn ($t) => $this->tokeniser->isPhrase($t) || ! in_array($t, $covered, true)
        );

        return array_values(array_map(
            fn ($t) => $this->tokeniser->surface($t),
            array_slice($terms, 0, (int) config('hani.matcher.explain_terms', 4))
        ));
    }
}
