<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;

class CandidacyAppealPublication extends Model
{
    // A backfilled legacy row keeps its free-text `description`; every other row is candidacy_appeal_id + title/venue/status/year.
    protected $fillable = ['description', 'candidacy_appeal_id', 'title', 'venue', 'status', 'year'];
}
