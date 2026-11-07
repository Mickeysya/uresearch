<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerDuplicateDismissal;
use App\Modules\Hani\Models\ExaminerMerge;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Support\Collection;

/**
 * Two pool records that are probably one person.
 *
 * CGS's sheets are typed by hand, so the same examiner turns up as "Prof Dr
 * Hassan bin Omar" with one email and "Hassan Omar, PhD" with another. Exact
 * matching cannot see that; NameNormaliser can. Flagged in two places:
 *
 *   - the pool list (and the import preview, via PoolImporter), so the
 *     Senior Executive can merge or retire one of them;
 *   - the cgs_compile stage, across every department's nominations: two
 *     departments may each have nominated a different record of the same
 *     person, which the exact clash check (same examiner id) never catches.
 *
 * Never blocks anything. A pair marked "Not the same person"
 * (ExaminerDuplicateDismissal) is not flagged again, and neither is a record
 * already merged away (ExaminerMerge).
 */
class DuplicateDetector
{
    public function __construct(protected NameNormaliser $names) {}

    /**
     * Every likely pair in a set of examiners, same type only, best first.
     *
     * @param  Collection<int, Examiner>  $examiners
     * @return array<int, array{a: Examiner, b: Examiner, score: int}>
     */
    public function pairs(Collection $examiners): array
    {
        $dismissed = ExaminerDuplicateDismissal::keys();
        $list = $examiners->whereNotIn('id', ExaminerMerge::duplicateIds())->values()->all();
        $normalised = array_map(fn (Examiner $e) => $this->names->normalise($e->name), $list);
        $threshold = (int) config('hani.duplicates.threshold', 85);
        $pairs = [];

        for ($i = 0, $n = count($list); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($list[$i]->type !== $list[$j]->type || $list[$i]->id === $list[$j]->id) {
                    continue;
                }

                if (isset($dismissed[ExaminerDuplicateDismissal::key($list[$i]->id, $list[$j]->id)])) {
                    continue;
                }

                // Cheap first pass on the normalised names before the full score.
                if ($normalised[$i] === '' || $normalised[$j] === ''
                    || abs(strlen($normalised[$i]) - strlen($normalised[$j])) > max(strlen($normalised[$i]), strlen($normalised[$j])) / 2) {
                    continue;
                }

                $score = $this->names->similarity($list[$i]->name, $list[$j]->name);

                if ($score >= $threshold) {
                    $pairs[] = ['a' => $list[$i], 'b' => $list[$j], 'score' => $score];
                }
            }
        }

        usort($pairs, fn ($x, $y) => $y['score'] <=> $x['score']);

        return $pairs;
    }

    /**
     * Pairs for the pool list, indexed by examiner id: who each one looks like.
     *
     * @return array<int, array<int, array{other: Examiner, score: int}>>
     */
    public function byExaminer(Collection $examiners): array
    {
        $map = [];

        foreach ($this->pairs($examiners) as $pair) {
            $map[$pair['a']->id][] = ['other' => $pair['b'], 'score' => $pair['score']];
            $map[$pair['b']->id][] = ['other' => $pair['a'], 'score' => $pair['score']];
        }

        return $map;
    }

    /**
     * Likely duplicates between examiners on different active nominations, one
     * entry per pair, with every application naming either record. Conflict
     * Detection's list, where it is acted on once for the pair.
     *
     * @return array<int, array{a: Examiner, b: Examiner, score: int, applications: array<int, array{nomination: ExaminerNomination, department: string, names: array<int, string>}>}>
     */
    public function pairsAcrossNominations(): array
    {
        [$where, $examiners] = $this->activeSeats();
        $result = [];

        foreach ($this->pairs($examiners) as $pair) {
            $applications = [];

            foreach ([$pair['a'], $pair['b']] as $examiner) {
                foreach ($where[$examiner->id] as $nomination) {
                    $applications[$nomination->application_id]['nomination'] = $nomination;
                    $applications[$nomination->application_id]['department'] = $nomination->application?->student?->department ?: 'no department';
                    $applications[$nomination->application_id]['names'][] = $examiner->name;
                }
            }

            // Both records on one panel alone is not a clash between lists.
            if (count($applications) > 1) {
                ksort($applications);
                $result[] = $pair + ['applications' => array_values($applications)];
            }
        }

        return $result;
    }

    /**
     * The same finding per application, for that application's card and the
     * compile notification. Keyed by application id, each entry a sentence
     * naming both records and where they were nominated.
     *
     * @return array<int, array<int, string>>
     */
    public function acrossNominations(): array
    {
        [$where, $examiners] = $this->activeSeats();

        $flags = [];

        foreach ($this->pairs($examiners) as $pair) {
            foreach ($where[$pair['a']->id] as $na) {
                foreach ($where[$pair['b']->id] as $nb) {
                    if ($na->application_id === $nb->application_id) {
                        continue;
                    }

                    $sentence = fn ($mine, $mineNom, $theirs, $theirsNom) => sprintf(
                        '"%s" looks like "%s" on application #%d (%s) (%d%% similar). Check they are not the same person before the list goes on.',
                        $mine->name,
                        $theirs->name,
                        $theirsNom->application_id,
                        $theirsNom->application?->student?->department ?: 'no department',
                        $pair['score'],
                    );

                    $flags[$na->application_id][] = $sentence($pair['a'], $na, $pair['b'], $nb);
                    $flags[$nb->application_id][] = $sentence($pair['b'], $nb, $pair['a'], $na);
                }
            }
        }

        return array_map(fn ($list) => array_values(array_unique($list)), $flags);
    }

    /** @return array{0: array<int, array<int, ExaminerNomination>>, 1: Collection<int, Examiner>} examiner id => nominations naming them, and those examiners */
    protected function activeSeats(): array
    {
        $nominations = ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student']))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED]))
            ->get();

        $where = [];
        $examiners = collect();

        foreach ($nominations as $nomination) {
            foreach ($nomination->examiners() as $examiner) {
                $where[$examiner->id][] = $nomination;
                $examiners->put($examiner->id, $examiner);
            }
        }

        return [$where, $examiners];
    }
}
