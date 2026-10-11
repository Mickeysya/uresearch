<?php

namespace App\Modules\Norhanis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One internal reviewer's Paper Evaluation Sheet (UTP/CGS/040).
 */
class PublicationEvaluation extends Model
{
    public const RECOMMENDED = 'recommended';
    public const CORRECTIONS = 'corrections';
    public const REJECTED = 'rejected';

    /** Internal reviewers are UTP staff, so their address is a UTP one. */
    public const REVIEWER_EMAIL_DOMAIN = '@utp.edu.my';

    /**
     * The rated sections of the sheet, worded as on the paper form:
     * key => [heading, the questions under it].
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    public const CRITERIA = [
        'objective' => ['Objective', [
            'How clear are the objectives of the paper?',
        ]],
        'originality' => ['Originality / Scope', [
            'Is this an original contribution?',
            'Is the subject matter relevant to the subject topic?',
            'Does the title of the paper reflect its content?',
        ]],
        'methodology' => ['Methodology', [
            'Were the methods used adequate and appropriate to yield accurate and reliable data?',
        ]],
        'results' => ['Results', [
            'Is the interpretation and conclusion sound and justified by the data?',
            'Are the tables and figures consistent with the contents?',
        ]],
    ];

    /** @var array<int, string> */
    public const ADDITIONAL_QUESTIONS = [
        'Is the presentation of findings, interpretations or calculations free from errors?',
        'Is the abstract sufficiently informative?',
        'Are the references adequate and relevant?',
    ];

    /** @var array<int, string> */
    public const RATINGS = [
        1 => 'Far Below Requirements',
        2 => 'Below Requirements',
        3 => 'Meets Requirements',
        4 => 'Exceeds Requirements',
        5 => 'Far Exceeds Requirements',
    ];

    // Everything the reviewer or the student fills in later is left out on
    // purpose: only PublicationSignOffs writes those columns.
    protected $fillable = ['publication_detail_id', 'position', 'reviewer_name', 'reviewer_email'];

    protected $hidden = ['signature_png'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'responded_at' => 'datetime',
            'replaced_at' => 'datetime',
        ];
    }

    public function publicationDetail(): BelongsTo
    {
        return $this->belongsTo(PublicationDetail::class);
    }

    /** @return array<string, string> */
    public static function recommendations(): array
    {
        return [
            self::RECOMMENDED => 'Recommended',
            self::CORRECTIONS => 'Recommended with Corrections',
            self::REJECTED => 'Rejected',
        ];
    }

    public function recommendationLabel(): string
    {
        return self::recommendations()[$this->recommendation] ?? '—';
    }

    /** "Paper Evaluation Sheet (Reviewer 2)", the label its PDF is filed under. */
    public function sheetLabel(): string
    {
        return "Paper Evaluation Sheet (Reviewer {$this->position})";
    }

    public function isSubmitted(): bool
    {
        return $this->signed_at !== null;
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    public function isReplaced(): bool
    {
        return $this->replaced_at !== null;
    }

    /** "Recommended with corrections", and the student has not answered yet. */
    public function needsResponse(): bool
    {
        return $this->isSubmitted() && $this->recommendation === self::CORRECTIONS && $this->responded_at === null;
    }

    /** Nothing more is owed on this sheet: it no longer holds the application back. */
    public function isSatisfied(): bool
    {
        return $this->isSubmitted() && $this->recommendation !== self::REJECTED && ! $this->needsResponse();
    }

    /**
     * The student may ask someone else while the sheet is still unanswered,
     * or after a rejection. A favourable sheet cannot be swapped out.
     */
    public function canBeReplaced(): bool
    {
        return ! $this->isReplaced() && (! $this->isSubmitted() || $this->recommendation === self::REJECTED);
    }
}
