<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expertise keyword or phrase on an examiner. Stored lower case. */
class ExaminerExpertise extends Model
{
    protected $table = 'examiner_expertise';

    protected $fillable = ['examiner_id', 'keyword'];

    /**
     * Touching the examiner on every save or delete is what tells the
     * matcher's cached word statistics that the pool changed -- see
     * Support\Matching\TfIdfMatcher::poolVersion().
     */
    protected $touches = ['examiner'];

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }
}
