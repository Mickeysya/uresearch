<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VivaDetail extends Model
{
    protected $fillable = [
        'application_id', 'programme_level', 'thesis_title', 'abstract', 'keywords', 'expected_softbound_date',
        'ae_item_checks', 'ae_checked_by_id', 'ae_checked_at',
        'viva_at', 'venue', 'chair_name', 'chair_email',
        'co_supervisor_name', 'co_supervisor_email', 'scheduled_by_id', 'schedule_sequence',
        'result_category', 'result_recorded_at', 'result_recorded_by_id', 'corrections_due_at',
        'resubmission_opens_at', 'resubmission_closes_at', 'corrections_submitted_at',
        'corrections_accepted_at', 'corrections_accepted_by_id', 'examiners_freed_at',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'expected_softbound_date' => 'date',
            'ae_item_checks' => 'array',
            'ae_checked_at' => 'datetime',
            'viva_at' => 'datetime',
            'result_category' => 'integer',
            'result_recorded_at' => 'datetime',
            'corrections_due_at' => 'date',
            'resubmission_opens_at' => 'date',
            'resubmission_closes_at' => 'date',
            'corrections_submitted_at' => 'datetime',
            'corrections_accepted_at' => 'datetime',
            'examiners_freed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id');
    }

    /**
     * Words in a piece of text, the way a reader counts them: runs of letters
     * or digits (hyphenated and apostrophised words count once), in any script.
     */
    /** Document types on a Notice of Thesis Submission (DocumentStore doc_type). */
    public const DOC_PAPER = 'Publication Paper';

    public const DOC_METHODOLOGY = 'Research Methodology Result';

    /** Before the UAT change, one proof PDF. Old notices keep it. */
    public const DOC_LEGACY_PROOF = 'Publication Proof';

    /** Thesis title limit, in words (UAT 11 Nov 2026), on the notice and the softbound form. */
    public const TITLE_MAX_WORDS = 15;

    /** A validation rule: the thesis title has at most TITLE_MAX_WORDS words. */
    public static function titleWordsRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $words = self::wordCount($value);
            if ($words > self::TITLE_MAX_WORDS) {
                $fail('The thesis title must be '.self::TITLE_MAX_WORDS." words or fewer; it has {$words}.");
            }
        };
    }

    public static function wordCount(?string $text): int
    {
        return preg_match_all("/[\\p{L}\\p{N}]+(?:['\\-’][\\p{L}\\p{N}]+)*/u", (string) $text);
    }

    /**
     * "deep learning; anomaly detection, time series" -> clean, unique,
     * lower-case keywords. Semicolons, commas and new lines all separate.
     *
     * @return array<int, string>
     */
    public static function parseKeywords(?string $value): array
    {
        $terms = array_map(
            fn ($term) => mb_strtolower(trim(preg_replace('/\\s+/u', ' ', $term), " \t.")),
            preg_split('/[;,\\r\\n]+/u', (string) $value),
        );

        return array_values(array_unique(array_filter($terms, fn ($t) => $t !== '')));
    }

    public function isScheduled(): bool
    {
        return $this->viva_at !== null;
    }

    /** Result categories 1-3: passed, with corrections to the final thesis. */
    public const CORRECTIONS = [1, 2, 3];

    public const REEXAMINATION = 4;

    public const FAIL = 5;

    public function hasResult(): bool
    {
        return $this->result_category !== null;
    }

    public function needsCorrections(): bool
    {
        return in_array($this->result_category, self::CORRECTIONS, true);
    }

    /** "2 (Minor)": the final result, which the student does see. */
    public function resultLabel(): ?string
    {
        return VivaExaminerProgress::categoryLabel($this->result_category);
    }

    /** Has the viva happened, so a result can be recorded? */
    public function vivaHeld(): bool
    {
        return $this->viva_at !== null && $this->viva_at->isPast();
    }
}
