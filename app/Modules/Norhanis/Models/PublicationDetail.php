<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationDetail extends Model
{
    protected $fillable = [
        'application_id', 'type_of_request', 'conference_scope', 'title_of_paper', 'title_of_conference_journal',
        'organizer_publisher', 'venue', 'conference_journal_fee', 'currency_type', 'cost_centre',
        'cost_centre_other', 'wants_letter_of_undertaking', 'conference_start_date', 'conference_end_date',
    ];

    protected function casts(): array
    {
        return [
            'conference_journal_fee' => 'decimal:2',
            'wants_letter_of_undertaking' => 'boolean',
            'conference_start_date' => 'date',
            'conference_end_date' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function authors(): HasMany
    {
        return $this->hasMany(PublicationAuthor::class);
    }

    /** Every reviewer ever asked, including those since replaced. */
    public function evaluations(): HasMany
    {
        return $this->hasMany(PublicationEvaluation::class)->orderBy('position')->orderBy('id');
    }

    /** The reviewers currently being asked: one per position. */
    public function activeEvaluations(): HasMany
    {
        return $this->evaluations()->whereNull('replaced_at');
    }

    /** The cost centre a student chooses, as agreed with CGS: stored key => label. */
    public const COST_CENTRES = [
        'cgs_pay_and_claims' => 'CGS – Pay and Claims',
        'cgs_letter_of_undertaking' => 'CGS – Letter of Undertaking',
        'split_memo' => 'CGS & Grant - Split Memo',
        'others' => 'Others',
    ];

    /** The only cost centre a conference request may use, as CGS asked. */
    public const CONFERENCE_COST_CENTRE = 'others';

    /**
     * The cost centres a request of this type may choose: a conference
     * uses "Others" only, a journal any of the four. The keys are stored
     * in rows, so only the labels above may be reworded.
     *
     * @return array<string, string>
     */
    public static function costCentresFor(?string $typeOfRequest): array
    {
        return $typeOfRequest === 'publication_conference'
            ? array_intersect_key(self::COST_CENTRES, [self::CONFERENCE_COST_CENTRE => true])
            : self::COST_CENTRES;
    }

    /**
     * "CGS – Pay and Claims", or the student's own words for "Others".
     * Applications filed before the choice existed hold free text, shown as is.
     */
    public function costCentreLabel(): string
    {
        if ($this->cost_centre === 'others') {
            return 'Others: '.($this->cost_centre_other ?: '—');
        }

        return self::COST_CENTRES[$this->cost_centre] ?? (string) $this->cost_centre;
    }

    /** @return array<string, string> */
    public static function requestTypes(): array
    {
        return [
            'publication_conference' => 'Conference',
            'publication_journal' => 'Journal',
        ];
    }

    /** @return array<string, string> */
    public static function conferenceScopes(): array
    {
        return [
            'local' => 'Local',
            'international' => 'International',
        ];
    }

    /**
     * How many internal reviewers must each complete a Paper Evaluation Sheet
     * (UTP/CGS/040): three for an international conference, two for a local
     * conference or a journal.
     */
    public static function reviewersRequired(?string $typeOfRequest, ?string $conferenceScope): int
    {
        return $typeOfRequest === 'publication_conference' && $conferenceScope === 'international' ? 3 : 2;
    }

    /** "Conference (International)", "Conference (Local)" or "Journal". */
    public function requestTypeLabel(): string
    {
        $label = self::requestTypes()[$this->type_of_request] ?? $this->type_of_request;
        $scope = self::conferenceScopes()[$this->conference_scope] ?? null;

        return $scope ? "{$label} ({$scope})" : $label;
    }
}
