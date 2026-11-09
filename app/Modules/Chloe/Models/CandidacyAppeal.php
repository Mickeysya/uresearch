<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentIdentity;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Study Candidacy Appeal -- the single source of truth for appeals. Status changes ONLY through
 * CandidacyAppealService (never set status / current_stage anywhere else). New appeals are never written into the
 * shared engine's applications table.
 */
class CandidacyAppeal extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_WITH_SUPERVISOR = 'with_supervisor';

    public const STATUS_WITH_CHAIR = 'with_chair';

    public const STATUS_WITH_CGS = 'with_cgs';

    public const STATUS_WITH_DEAN = 'with_dean';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED_EXPIRED = 'closed_expired';

    public const STAGE_SUPERVISOR = 'supervisor';

    public const STAGE_CHAIR = 'chair';

    public const STAGE_CGS = 'cgs';

    public const STAGE_DEAN = 'dean';

    /** The stages in order, and the status an appeal has while it waits at each. */
    public const STAGE_STATUS = [
        self::STAGE_SUPERVISOR => self::STATUS_WITH_SUPERVISOR,
        self::STAGE_CHAIR => self::STATUS_WITH_CHAIR,
        self::STAGE_CGS => self::STATUS_WITH_CGS,
        self::STAGE_DEAN => self::STATUS_WITH_DEAN,
    ];

    /** "Open" = still in somebody's hands (waiting at a stage, or returned to the student). */
    public const OPEN_STATUSES = [
        self::STATUS_WITH_SUPERVISOR, self::STATUS_WITH_CHAIR, self::STATUS_WITH_CGS, self::STATUS_WITH_DEAN, self::STATUS_RETURNED,
    ];

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_REJECTED_FINAL = 'rejected_final';

    public const OUTCOME_CLOSED_EXPIRED = 'closed_expired';

    protected $table = 'candidacy_appeals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'needs_cgs_recheck' => 'boolean', 'gsc_extension' => 'boolean', 'vc_extension' => 'boolean',
            'expiry_at_submission' => 'date', 'new_expiry_date' => 'date', 'rcs_date' => 'date', 'rcs_expected_date' => 'date',
            'declaration_accepted_at' => 'datetime', 'submitted_at' => 'datetime', 'stage_entered_at' => 'datetime',
            'last_action_at' => 'datetime', 'closed_at' => 'datetime', 'memo_generated_at' => 'datetime', 'dean_adjusted_at' => 'datetime',
        ];
    }

    /** The months the Dean approved (what the student gets): approved_months once decided, the requested months before that. */
    public function monthsApproved(): ?int
    {
        return $this->approved_months !== null ? (int) $this->approved_months : null;
    }

    /** True when the Dean approved a different number of months than the student asked for. */
    public function wasAdjustedByDean(): bool
    {
        return $this->approved_months !== null && (int) $this->approved_months !== (int) $this->requested_months;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function demoProfile(): BelongsTo
    {
        return $this->belongsTo(DemoStudentProfile::class, 'demo_profile_id')->withoutGlobalScopes();
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(StudyCandidacy::class, 'study_candidacy_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(CandidacyDepartment::class, 'department_id');
    }

    public function supervisorProfile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'supervisor_profile_id');
    }

    public function chairProfile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'chair_profile_id');
    }

    public function deanProfile(): BelongsTo
    {
        return $this->belongsTo(CandidacyStaffProfile::class, 'dean_profile_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(CandidacyAppealPublication::class, 'candidacy_appeal_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CandidacyAppealDocument::class, 'candidacy_appeal_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CandidacyAppealEvent::class, 'candidacy_appeal_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CandidacyAppealApproval::class, 'candidacy_appeal_id');
    }

    /** The student the appeal belongs to, resolved exactly as the Workstation module does. */
    public function identity(): ?StudentIdentity
    {
        return $this->student_id ? StudentDirectory::forUserId($this->student_id) : StudentDirectory::forDemoId($this->demo_profile_id);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** The label shown wherever the number is: the appeal number, or "Draft" before submission. */
    public function displayNo(): string
    {
        return $this->appeal_no ?? 'Draft';
    }

    /** Whole days the appeal has waited at its current stage (never negative). */
    public function waitingDays(): int
    {
        return $this->stage_entered_at && $this->current_stage ? max(0, (int) $this->stage_entered_at->diffInDays(now(), true)) : 0;
    }

    /** @return array<string, string> */
    public static function phases(): array
    {
        return [
            'writing' => 'Writing',
            'simulation' => 'Simulation',
            'experiment' => 'Experiment',
            'data_analysis' => 'Data Analysis',
        ];
    }
}
