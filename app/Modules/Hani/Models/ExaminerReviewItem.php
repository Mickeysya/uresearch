<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something the import found for a person to check. See the migration. */
class ExaminerReviewItem extends Model
{
    public const KIND_CURRENT_ASSIGNMENT = 'current_assignment';

    public const KIND_MULTIPLE_STUDENTS = 'multiple_students';

    protected $fillable = ['examiner_id', 'kind', 'detail', 'import_batch_id', 'resolved_at', 'resolved_by_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_CURRENT_ASSIGNMENT => 'Current assignment in CGS\'s sheet',
            self::KIND_MULTIPLE_STUDENTS => 'Check: more than one student listed',
            default => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }
}
