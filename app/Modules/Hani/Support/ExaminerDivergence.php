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
