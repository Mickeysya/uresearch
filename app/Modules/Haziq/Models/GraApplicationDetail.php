<?php

namespace App\Modules\Haziq\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class GraApplicationDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id', 'profile_id', 'application_type', 'parent_application_id',
        'candidature_status',
        'project_leader_name', 'project_leader_contact', 'project_title', 'funding_source',
        'grant_name', 'cost_centre', 'centre_name', 'expected_reporting_date',
        'service_start_date', 'service_end_date', 'proposed_allowance',
        'previous_allowance', 'extension_reason', 'funding_continuation',
    ];

    protected function casts(): array
    {
        return [
            'expected_reporting_date' => 'date',
            'service_start_date' => 'date',
            'service_end_date' => 'date',
            'proposed_allowance' => 'decimal:2',
            'previous_allowance' => 'decimal:2',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function parentApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'parent_application_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(GraGaStudentProfile::class, 'profile_id');
    }

    public function isExtension(): bool
    {
        return $this->application_type === 'extension';
    }

    public function isTerminated(): bool
    {
        return $this->candidature_status === 'terminated';
    }

    /**
     * Extension can only be requested once the current appointment has
     * actually run out — "boleh isi bila dah end service date je", 2026-11.
     * Same-day as service_end_date counts as already ended, not one day
     * early.
     */
    public function canBeExtended(): bool
    {
        return $this->service_end_date->lte(\Illuminate\Support\Carbon::today());
    }

    public function allowanceNotes(): HasMany
    {
        return $this->hasMany(AllowanceRecommendationNote::class, 'application_id', 'application_id');
    }

    /**
     * A brand-new ('new', not 'extension') appointment's length is fixed by
     * level — Master 24 months, PhD 42 months, matching the same maximum
     * GRA/GA funding duration stage_gate_rules' own last stage's
     * grace_end_month already encodes (Master's Stage 3 grace ends at
     * month 24, PhD's Stage 4 at month 42) — so the student never types an
     * end date; it's always derived from this. Corrected 2026-10-15 from
     * an earlier, wrong 12/24 split that didn't match that table at all.
     * Extensions are the deliberate exception: their whole point is a
     * custom period *beyond* this fixed length, so storeExtension() never
     * calls this.
     */
    public static function computeServiceEndDate(Carbon $startDate, string $levelOfStudy): Carbon
    {
        return $startDate->copy()->addMonths($levelOfStudy === 'phd' ? 42 : 24);
    }
}
