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
        'system_checks', 'system_checked_at', 'verified_by_id', 'verified_at',
    ];

    protected function casts(): array
    {
        return array_fill_keys(VivaChecklist::COLUMNS, 'boolean') + [
            'verified_at' => 'datetime',
            'system_checks' => 'array',
            'system_checked_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The snapshot taken when the notice was filed, as results.
     *
     * @return array<string, \App\Modules\Hani\Support\Eligibility\EligibilityResult>
     */
    public function systemResults(): array
    {
        return array_map(
            fn ($data) => \App\Modules\Hani\Support\Eligibility\EligibilityResult::fromArray($data),
            $this->system_checks ?? [],
        );
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_id');
    }
}
