<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;

/** "Not the same person": a pair duplicate detection must not flag again. */
class ExaminerDuplicateDismissal extends Model
{
    protected $fillable = ['examiner_a_id', 'examiner_b_id', 'dismissed_by_id'];

    /** Lowest id first, the way the unique key stores it. */
    public static function key(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }

    public static function dismiss(int $a, int $b, ?int $userId): self
    {
        return static::firstOrCreate(
            ['examiner_a_id' => min($a, $b), 'examiner_b_id' => max($a, $b)],
            ['dismissed_by_id' => $userId],
        );
    }

    /** @return array<string, true> every dismissed pair's key() */
    public static function keys(): array
    {
        return static::query()->get(['examiner_a_id', 'examiner_b_id'])
            ->mapWithKeys(fn ($d) => [self::key($d->examiner_a_id, $d->examiner_b_id) => true])
            ->all();
    }
}
