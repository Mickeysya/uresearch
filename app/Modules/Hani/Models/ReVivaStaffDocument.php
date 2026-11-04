<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A staff-only re-viva file: never in DocumentStore, never shown to the
 * student. See the 2026_11_06_000200 migration.
 */
class ReVivaStaffDocument extends Model
{
    public const LETTER = 'correction_letter';

    public const RE_EVALUATION = 're_evaluation_report';

    public const CONFIRMATION = 'confirmation_of_correction';

    public const KINDS = [
        self::LETTER => 'Correction letter',
        self::RE_EVALUATION => 'Thesis Re-evaluation Report (UTP/CGS/024)',
        self::CONFIRMATION => 'Confirmation of Correction',
    ];

    protected $fillable = [
        're_viva_detail_id', 'kind', 'seat', 'examiner_id', 'path', 'original_name',
        'emailed_at', 'email_skipped_reason', 'created_by_id',
    ];

    protected function casts(): array
    {
        return ['emailed_at' => 'datetime'];
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(ReVivaDetail::class, 're_viva_detail_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** A letter's emails and refusals, first send and resends (ReVivaLetters). */
    public function sends(): HasMany
    {
        return $this->hasMany(ReVivaLetterSend::class, 're_viva_staff_document_id');
    }

    /** The latest send that reached the examiner: "last sent". */
    public function lastSent(): HasOne
    {
        return $this->hasOne(ReVivaLetterSend::class, 're_viva_staff_document_id')->ofMany(['id' => 'max'], fn ($q) => $q->where('emailed', true));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function sideLabel(): ?string
    {
        return VivaExaminerProgress::SEATS[$this->seat] ?? null;
    }
}
