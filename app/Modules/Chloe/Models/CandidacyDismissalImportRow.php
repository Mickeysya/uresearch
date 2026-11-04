<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;

/** One student dismissed by an upload. */
class CandidacyDismissalImportRow extends Model
{
    protected $fillable = ['import_id', 'candidacy_id', 'demo_profile_id', 'student_code', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }
}
