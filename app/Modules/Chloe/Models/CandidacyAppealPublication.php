<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidacyAppealPublication extends Model
{
    protected $fillable = ['candidacy_appeal_detail_id', 'description'];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(CandidacyAppealDetail::class, 'candidacy_appeal_detail_id');
    }
}
