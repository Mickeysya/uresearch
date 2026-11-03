<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;

/** One student in one Excel export of the dismissal list. */
class CandidacyDismissalExport extends Model
{
    protected $fillable = ['candidacy_id', 'demo_profile_id', 'exported_at', 'exported_by'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime'];
    }
}
