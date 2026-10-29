<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One softbound thesis attempt (Form #019). The rules it is held to are here
 * so the form, the controller and the AE's queue all read the same numbers.
 */
class VivaSoftboundDetail extends Model
{
    /** Highest similarity index Form #019 accepts, in percent. */
    public const SIMILARITY_MAX = 25;

    /** Word limit per programme level. */
    public const WORD_LIMITS = [
        VivaChecklist::MSC => 50000,
        VivaChecklist::PHD => 100000,
    ];

    /** The guideline's minimum gap between the notice and the softbound. */
    public const NOTICE_LEAD_MONTHS = 3;

    protected $fillable = [
        'application_id', 'viva_application_id', 'similarity_percent', 'word_count',
        'abstract_bilingual', 'copies_certified', 'before_candidacy_end',
    ];

    protected function casts(): array
    {
        return [
            'similarity_percent' => 'decimal:2',
            'abstract_bilingual' => 'boolean',
            'copies_certified' => 'boolean',
            'before_candidacy_end' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The Notice of Thesis Submission this attempt belongs to. */
    public function vivaApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'viva_application_id');
    }

    public static function wordLimitFor(?string $level): int
    {
        return self::WORD_LIMITS[$level] ?? self::WORD_LIMITS[VivaChecklist::MSC];
    }

    /**
     * Filed sooner than 3 months after the notice. A flag for the AE, not a
     * block: the AE keeps discretion over the guideline.
     */
    public function isEarly(): bool
    {
        $noticeAt = $this->vivaApplication?->submitted_at;

        return $noticeAt !== null
            && $this->created_at->lt($noticeAt->copy()->addMonthsNoOverflow(self::NOTICE_LEAD_MONTHS));
    }
}
