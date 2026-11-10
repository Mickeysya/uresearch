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
     * A display-only concept, never persisted and never confused with
     * `status` (Core's own column, WorkflowEngine's alone to write) or
     * `candidature_status` (Stage Gate's own termination concern, read
     * as-is here, not recomputed). Confirmed explicitly 2026-11-10: once
     * approved, an appointment reads "approved" until its own
     * service_start_date actually arrives, then "active" for as long as
     * today falls within [service_start_date, service_end_date]. Once
     * service_end_date passes with nothing covering today, it reads
     * "inactive" -- not terminated, just not currently in service -- and
     * an approved extension (a new Application row with its own, later
     * service_end_date) is what brings it back to "active", purely by
     * this same date check running against the new row. Meaningless
     * before approval, so callers only use this once status is approved.
     */
    public function serviceStatus(): string
    {
        if ($this->isTerminated()) {
            return 'terminated';
        }

        $today = Carbon::today();

        if ($today->lt($this->service_start_date)) {
            return 'approved';
        }

        if ($today->gt($this->service_end_date)) {
            return 'inactive';
        }

        return 'active';
    }

    /**
     * Extension can be requested any time once the current appointment is
     * approved — no longer gated on the service period having actually run
     * out. Confirmed explicitly 2026-11-07, reversing the earlier
     * "boleh isi bila dah end service date je" rule (2026-11): extension is
     * "applicable once die dah approve as gra je."
     */
    public function canBeExtended(): bool
    {
        return $this->application->status === Application::STATUS_APPROVED;
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
