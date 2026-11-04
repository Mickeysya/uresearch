<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One main examiner's Thesis Re-evaluation Report (UTP/CGS/024). Staff only.
 * See the 2026_11_06_000400 migration.
 */
class ReVivaEvaluation extends Model
{
    /** The form's four recommendations: number => [label, what it means]. */
    public const CATEGORIES = [
        1 => ['Pass', 'final thesis within 1 month'],
        2 => ['Minor corrections', 'up to 3 months'],
        3 => ['Major corrections', '3 to 6 months'],
        4 => ['Fail', 'see "required to attend the viva again"'],
    ];

    public const FAIL = 4;

    protected $fillable = [
        're_viva_detail_id', 'seat', 'examiner_id', 'category', 'viva_again',
        'ratings', 'report_document_id', 'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => 'integer',
            'viva_again' => 'boolean',
            'ratings' => 'array',
        ];
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(ReVivaDetail::class, 're_viva_detail_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ReVivaStaffDocument::class, 'report_document_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * The outcome level this recommendation maps to (agreed 2026-11-04):
     * 1 -> 1, 2 -> 2, 3 -> 3, 4 + viva again YES -> 4, 4 + viva again NO -> 5.
     */
    public function level(): int
    {
        return $this->category === self::FAIL
            ? ($this->viva_again ? ReVivaDetail::OUTCOME_LOOP_BACK : ReVivaDetail::OUTCOME_DISMISSED)
            : $this->category;
    }

    /** "4 (Fail, viva again: Yes)". Staff only. */
    public function label(): string
    {
        return $this->category.' ('.self::CATEGORIES[$this->category][0]
            .($this->category === self::FAIL ? ', viva again: '.($this->viva_again ? 'Yes' : 'No') : '').')';
    }

    public function sideLabel(): string
    {
        return VivaExaminerProgress::SEATS[$this->seat] ?? $this->seat;
    }
}
