<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's self-declared pre-notice checklist. See VivaChecklist for which
 * columns count for which programme level.
 */
class VivaEligibility extends Model
{
    protected $table = 'viva_eligibility';

    protected $fillable = [
        'student_id',
        'research_methodology_passed', 'publication_accepted', 'rcs_passed',
        'registered_current_semester', 'no_outstanding_fees', 'within_candidacy_period',
    ];

    protected function casts(): array
    {
        return array_fill_keys(VivaChecklist::COLUMNS, 'boolean') + [
            'verified_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
