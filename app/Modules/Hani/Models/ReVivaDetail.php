<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hani\Support\VivaProgress;

/**
 * One re-viva cycle. See the 5-level outcome scale in Hani's TODO entry:
 * levels 1-3 are a pass with varying correction tracking, level 4 loops back
 * into another cycle (a new row here, linked via previous_cycle_id, created
 * the next time the student uploads a re-corrected thesis), level 5 is a
 * terminal dismissal.
 */
class ReVivaDetail extends Model
{
    public const OUTCOME_LOOP_BACK = 4;
    public const OUTCOME_DISMISSED = 5;

    protected $fillable = [
        'application_id', 'cycle_number', 'previous_cycle_id', 'viva_application_id',
        'resubmission_at', 'resubmission_opens_at', 'resubmission_closes_at',
        'viva_result_date', 'logged_outside_window', 'sent_to_panel_at', 'sent_to_panel_by_id',
        'correction_deadline', 'hardbound_deadline',
        'outcome_level', 'outcome_remarks',
    ];

    protected function casts(): array
    {
        return [
            'resubmission_at' => 'datetime',
            'resubmission_opens_at' => 'date',
            'resubmission_closes_at' => 'date',
            'viva_result_date' => 'date',
            'logged_outside_window' => 'boolean',
            'sent_to_panel_at' => 'datetime',
            'correction_deadline' => 'date',
            'hardbound_deadline' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function previousCycle(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_cycle_id');
    }

    public function staffDocuments(): HasMany
    {
        return $this->hasMany(ReVivaStaffDocument::class);
    }

    /**
     * The released panel of the linked viva, with its CURRENT main examiners
     * (the seat columns are updated in place after a decline). Null for a
     * legacy round with no viva in UResearch.
     */
    public function panel(): ?ExaminerNomination
    {
        $notice = $this->vivaNotice;

        return $notice ? VivaProgress::forNotices(collect([$notice]))->first()?->panel() : null;
    }

    /**
     * The current main examiners, keyed by seat.
     *
     * @return array<string, Examiner>
     */
    public function currentMains(): array
    {
        $panel = $this->panel();

        return array_filter([
            'internal_main_id' => $panel?->internalMain,
            'external_main_id' => $panel?->externalMain,
        ]);
    }

    /** The viva_voce notice whose category-4 result this round re-examines. */
    public function vivaNotice(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'viva_application_id');
    }

    public function vivaDetail(): ?VivaDetail
    {
        return $this->viva_application_id ? VivaDetail::where('application_id', $this->viva_application_id)->first() : null;
    }

    /** "1 Jun 2027 to 1 Dec 2027", or null for a row logged before Prompt 3. */
    public function windowLabel(): ?string
    {
        return $this->resubmission_opens_at && $this->resubmission_closes_at
            ? $this->resubmission_opens_at->format('j M Y').' to '.$this->resubmission_closes_at->format('j M Y')
            : null;
    }

    public function nextCycle(): HasOne
    {
        return $this->hasOne(self::class, 'previous_cycle_id');
    }

    public function hasOutcome(): bool
    {
        return $this->outcome_level !== null;
    }

    public function requiresLoopBack(): bool
    {
        return $this->outcome_level === self::OUTCOME_LOOP_BACK;
    }

    public function outcomeLabel(): ?string
    {
        return match ($this->outcome_level) {
            1 => 'Level 1: Pass, minor corrections',
            2 => 'Level 2: Pass, moderate corrections',
            3 => 'Level 3: Pass, major corrections',
            4 => 'Level 4: Fail, loop back to another re-viva cycle',
            5 => 'Level 5: Dismissed',
            default => null,
        };
    }
}
