<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Support\VivaChecklist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One softbound thesis attempt: CGS Online Form #019 (UTP/CGS/V3/VV/019).
 * The rules it is held to are here so the form, the controller and the AE's
 * queue all read the same numbers.
 */
class VivaSoftboundDetail extends Model
{
    /** Highest similarity index the Handbook accepts, in percent. */
    public const SIMILARITY_MAX = 25;

    /** Word limit per programme level. */
    public const WORD_LIMITS = [
        VivaChecklist::MSC => 50000,
        VivaChecklist::PHD => 100000,
    ];

    /** The guideline's minimum gap between the notice and the softbound. */
    public const NOTICE_LEAD_MONTHS = 3;

    /** Form #019's Session dropdown. */
    public const SESSIONS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Form #019's uploads: field => [document type, max KB, PhD only].
     *
     * The real form makes MSc students upload a blank document for the two
     * RCS forms; here they are simply not asked. The thesis cap is 50 MB, not
     * the form's 100 MB: PHP takes at most 100 MB per request, and these five
     * files travel in one.
     */
    public const UPLOADS = [
        'format_confirmation' => ['Thesis Format Confirmation Form', 10240, false],
        'thesis' => ['Softbound Thesis', 51200, false],
        'turnitin_report' => ['Turnitin Report', 10240, false],
        'rcs_correction_confirmation' => ['RCS Correction Confirmation Form', 10240, true],
        'rcs_correction_summary' => ['RCS Correction Summary', 10240, true],
    ];

    protected $fillable = [
        'application_id', 'viva_application_id',
        'field_of_study', 'contact_number', 'personal_email',
        'session', 'year', 'thesis_title',
        'similarity_percent', 'similarity_source', 'similarity_extracted', 'word_count',
        'co_supervisor_email', 'declaration_accepted',
    ];

    protected function casts(): array
    {
        return [
            'similarity_percent' => 'decimal:2',
            'similarity_extracted' => 'decimal:2',
            'year' => 'integer',
            'declaration_accepted' => 'boolean',
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
     * The uploads that apply at this level.
     *
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function uploadsFor(?string $level): array
    {
        return array_filter(self::UPLOADS, fn ($upload) => ! $upload[2] || $level === VivaChecklist::PHD);
    }

    /** Last year, this year and next: the window a softbound is filed in. */
    public static function years(): array
    {
        return range(now()->year - 1, now()->year + 1);
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
