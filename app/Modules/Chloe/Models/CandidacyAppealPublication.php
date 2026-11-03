<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyAppealPublication extends Model
{
    // Legacy rows: candidacy_appeal_detail_id + description. New rows: candidacy_appeal_id + title/venue/status/year.
    protected $fillable = ['candidacy_appeal_detail_id', 'description', 'candidacy_appeal_id', 'title', 'venue', 'status', 'year'];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(CandidacyAppealDetail::class, 'candidacy_appeal_detail_id');
    }
}
