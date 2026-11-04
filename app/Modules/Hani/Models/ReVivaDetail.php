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
        'outcome_level', 'outcome_remarks', 'suggested_level', 'outcome_recorded_at', 'outcome_recorded_by_id',
        'final_thesis_submitted_at', 'corrections_accepted_at', 'corrections_accepted_by_id',
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
            'outcome_level' => 'integer',
            'suggested_level' => 'integer',
            'outcome_recorded_at' => 'datetime',
            'final_thesis_submitted_at' => 'datetime',
            'corrections_accepted_at' => 'datetime',
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

    public function evaluations(): HasMany
    {
        return $this->hasMany(ReVivaEvaluation::class);
    }

    /**
     * The re-evaluations of the CURRENT main examiners, keyed by seat. A row
     * left by an examiner who has since been replaced does not count.
     *
     * @return \Illuminate\Support\Collection<string, ReVivaEvaluation>
     */
    public function currentEvaluations(?array $mains = null)
    {
        $mains ??= $this->currentMains();

        return $this->evaluations
            ->filter(fn (ReVivaEvaluation $e) => isset($mains[$e->seat]) && $mains[$e->seat]->id === $e->examiner_id)
            ->keyBy('seat');
    }

    /** Both main examiners' re-evaluations are recorded: stage 3 may complete. */
    public function evaluationsComplete(): bool
    {
        return $this->currentEvaluations()->has(['internal_main_id', 'external_main_id']);
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

    /** Levels 1-3: passed, with a final thesis to submit by correction_deadline. */
    public const OUTCOME_CORRECTIONS = [1, 2, 3];

    /**
     * Display only (the stored value is the number). Follows the four
     * recommendations of UTP/CGS/024, with "fail" split on "required to
     * attend the viva again".
     */
    public const OUTCOME_LABELS = [
        1 => 'Level 1: Pass (final thesis within 1 month)',
        2 => 'Level 2: Minor corrections (final thesis within 3 months)',
        3 => 'Level 3: Major corrections (final thesis within 3 to 6 months)',
        4 => 'Level 4: Fail, oral examination required (CGS logs the next round)',
        5 => 'Level 5: Fail (final)',
    ];

    public function outcomeLabel(): ?string
    {
        return self::OUTCOME_LABELS[$this->outcome_level] ?? null;
    }

    public function needsFinalThesis(): bool
    {
        return in_array($this->outcome_level, self::OUTCOME_CORRECTIONS, true);
    }

    /** Levels 1-3, final thesis not accepted yet, past its deadline. */
    public function finalThesisOverdue(): bool
    {
        return $this->needsFinalThesis() && $this->corrections_accepted_at === null
            && $this->correction_deadline !== null && $this->correction_deadline->lt(today());
    }
}
