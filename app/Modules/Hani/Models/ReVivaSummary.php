<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's digital Summary of Corrections. See the 2026_11_06_000700 migration. */
class ReVivaSummary extends Model
{
    public const EXAMINERS = [1 => 'Examiner 1 (Internal)', 2 => 'Examiner 2 (External)'];

    protected $fillable = ['student_id', 'rows', 'thesis_pages', 'thesis_name', 'submitted_at', 'used_by_detail_id'];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'thesis_pages' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /** The student's summary not yet attached to a logged round, if any. */
    public static function openFor(User $student): ?self
    {
        return self::where('student_id', $student->id)->whereNull('used_by_detail_id')->latest('id')->first();
    }

    /** @return array<int, array{examiner: int, comment: string, action: string, pages: string}> */
    public function rowsFor(int $examiner): array
    {
        return array_values(array_filter($this->rows ?? [], fn ($r) => (int) ($r['examiner'] ?? 0) === $examiner));
    }
}
