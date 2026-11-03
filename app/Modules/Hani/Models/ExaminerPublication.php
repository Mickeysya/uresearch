<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A publication on an examiner's record: title for matching, co-authors for COI. */
class ExaminerPublication extends Model
{
    protected $fillable = ['examiner_id', 'title', 'year', 'coauthors'];

    /** See ExaminerExpertise::$touches. */
    protected $touches = ['examiner'];

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    /** @return array<int, string> the co-authors, one name each, blanks dropped */
    public function coauthorList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;\n]+/', (string) $this->coauthors))));
    }
}
