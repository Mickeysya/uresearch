<?php

namespace App\Modules\Hani\Support;

use App\Modules\Hani\Models\VivaExaminerProgress;
use Illuminate\Support\Collection;

/**
 * Do the two main examiners' reports disagree? Flagged so the panel talks
 * it through at the pre-viva meeting, before the candidate is in the room.
 *
 *   minor  the categories differ
 *   major  one recommends a pass (1-3) and the other re-examination or a
 *          fail (4-5)
 *
 * Staff only. The chairman's invitation gets a neutral line, never the
 * categories; the student sees nothing of this.
 */
final class ExaminerDivergence
{
    /**
     * @param  Collection<string, VivaExaminerProgress>  $current  current rows keyed by seat
     * @return array{level: string, text: string}|null
     */
    public static function between(Collection $current): ?array
    {
        $internal = $current->get('internal_main_id')?->category;
        $external = $current->get('external_main_id')?->category;

        if (! $internal || ! $external || $internal === $external) {
            return null;
        }

        $major = min($internal, $external) <= 3 && max($internal, $external) >= 4;

        return [
            'level' => $major ? 'major' : 'minor',
            'text' => 'Internal: '.VivaExaminerProgress::categoryLabel($internal).' · External: '.VivaExaminerProgress::categoryLabel($external),
        ];
    }

    /**
     * The re-viva's re-evaluations (UTP/CGS/024) disagree. Compared on the
     * outcome level each maps to, so "Fail, viva again YES" and "Fail, viva
     * again NO" differ even though both are category 4. Major when one is a
     * pass (levels 1-3) and the other a fail (4-5), as for the viva.
     *
     * @param  Collection<string, \App\Modules\Hani\Models\ReVivaEvaluation>  $current  keyed by seat
     * @return array{level: string, text: string}|null
     */
    public static function betweenReEvaluations(Collection $current): ?array
    {
        $internal = $current->get('internal_main_id');
        $external = $current->get('external_main_id');

        if (! $internal || ! $external || $internal->level() === $external->level()) {
            return null;
        }

        $levels = [$internal->level(), $external->level()];

        return [
            'level' => min($levels) <= 3 && max($levels) >= 4 ? 'major' : 'minor',
            'text' => 'Internal: '.$internal->label().' · External: '.$external->label(),
        ];
    }

    /**
     * The same, for many notices at once (the scheduling list).
     *
     * @param  iterable<int>  $noticeIds
     * @return array<int, array{level: string, text: string}> keyed by notice id, only those that diverge
     */
    public static function forNotices(iterable $noticeIds): array
    {
        return VivaExaminerProgress::current()
            ->whereIn('viva_application_id', collect($noticeIds)->all())
            ->whereIn('seat', array_keys(VivaExaminerProgress::SEATS))
            ->get()
            ->groupBy('viva_application_id')
            ->map(fn ($rows) => self::between($rows->keyBy('seat')))
            ->filter()
            ->all();
    }
}
