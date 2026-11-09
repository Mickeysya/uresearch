<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One record merged into another. Written only by Support\ExaminerMerger. */
class ExaminerMerge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kept_id', 'duplicate_id', 'merged_by_id', 'summary'];

    protected function casts(): array
    {
        return ['summary' => 'array'];
    }

    public function kept(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'kept_id');
    }

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'duplicate_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_id');
    }

    /** @return array<int, int> every record already merged away */
    public static function duplicateIds(): array
    {
        return static::query()->pluck('duplicate_id')->all();
    }

    /** The record this examiner lives on now: themselves, unless they were merged away. */
    public static function resolve(Examiner $examiner): Examiner
    {
        while ($merge = static::with('kept')->where('duplicate_id', $examiner->id)->first()) {
            $examiner = $merge->kept;
        }

        return $examiner;
    }
}
