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
        'organizer_publisher', 'conference_journal_fee', 'currency_type', 'cost_centre',
        'cost_centre_other', 'follow_up_emailed_at', 'wants_letter_of_undertaking',
        // Travel Details, a conference only (UAT, 11 Nov 2026). See TRAVEL_FIELDS.
        'travel_start_date', 'travel_end_date', 'duration_days', 'reason_for_travel', 'destination_address',
        'contact_person_name', 'contact_person_no', 'contact_person_email',
    ];

    /**
     * A conference's Travel Details, as the form posts them. Required for a
     * conference, dropped for a journal. duration_days is worked out from
     * the two dates (Support\TravelDates), never posted.
     */
    public const TRAVEL_FIELDS = [
        'travel_start_date', 'travel_end_date', 'reason_for_travel', 'destination_address',
        'contact_person_name', 'contact_person_no', 'contact_person_email',
    ];

    protected function casts(): array
    {
        return [
            'conference_journal_fee' => 'decimal:2',
            'wants_letter_of_undertaking' => 'boolean',
            'travel_start_date' => 'date',
            'travel_end_date' => 'date',
            'duration_days' => 'integer',
            'follow_up_emailed_at' => 'datetime',
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

    /** The stored cost centre keys. Permanent: they are in rows. */
    public const PAY_AND_CLAIMS = 'cgs_pay_and_claims';

    public const LETTER_OF_UNDERTAKING = 'cgs_letter_of_undertaking';

    public const SPLIT_MEMO = 'split_memo';

    public const OTHERS = 'others';

    public const JOURNAL = 'publication_journal';

    public const CONFERENCE = 'publication_conference';

    /** The cost centre a journal chooses, as agreed with CGS: stored key => label. */
    public const COST_CENTRES = [
        self::PAY_AND_CLAIMS => 'CGS – Pay and Claims',
        self::LETTER_OF_UNDERTAKING => 'CGS – Letter of Undertaking',
        self::SPLIT_MEMO => 'CGS & Grant - Split Memo',
        self::OTHERS => 'Others',
    ];

    /**
     * What a conference is stored with. A conference has no dropdown: the
     * student types the cost centre in "Cost Centre to be Utilized", kept in
     * cost_centre_other, and cost_centre is set to "Others" so reports and
     * older rows read the same two columns (UAT, 11 Nov 2026).
     */
    public const CONFERENCE_COST_CENTRE = self::OTHERS;

    /**
     * The cost centres a request of this type may choose from the list: a
     * journal any of the four, a conference none (it types its own). The
     * keys are stored in rows, so only the labels above may be reworded.
     *
     * @return array<string, string>
     */
    public static function costCentresFor(?string $typeOfRequest): array
    {
        return $typeOfRequest === self::CONFERENCE ? [] : self::COST_CENTRES;
    }

    public function isJournal(): bool
    {
        return $this->type_of_request === self::JOURNAL;
    }

    public function isConference(): bool
    {
        return $this->type_of_request === self::CONFERENCE;
    }

    /**
     * "Title of Conference" or "Title of Journal": never the two together
     * (UAT, 11 Nov 2026). A type not yet chosen reads as a journal.
     */
    public static function venueTitleLabel(?string $typeOfRequest): string
    {
        return $typeOfRequest === self::CONFERENCE ? 'Title of Conference' : 'Title of Journal';
    }

    /** "Conference Fee" or "Journal Fee". */
    public static function feeLabel(?string $typeOfRequest): string
    {
        return $typeOfRequest === self::CONFERENCE ? 'Conference Fee' : 'Journal Fee';
    }

    public function titleLabel(): string
    {
        return self::venueTitleLabel($this->type_of_request);
    }

    public function feeLabelText(): string
    {
        return self::feeLabel($this->type_of_request);
    }

    /**
     * A conference's Travel Details, label => value, in form order: the one
     * list the queue card, the summary PDF and the student's View details
     * page all print. Empty for a journal.
     *
     * @return array<string, string>
     */
    public function travelDetailRows(): array
    {
        if (! $this->isConference()) {
            return [];
        }

        return [
            'Travel Start Date' => $this->travel_start_date?->format('j M Y') ?? '—',
            'Travel End Date' => $this->travel_end_date?->format('j M Y') ?? '—',
            'Duration of Travelling (Days)' => $this->duration_days !== null ? (string) $this->duration_days : '—',
            'Reason for Travelling' => $this->reason_for_travel ?: '—',
            'Destination Address' => $this->destination_address ?: '—',
            'Contact Person While Travelling' => trim(implode(' · ', array_filter([
                $this->contact_person_name, $this->contact_person_no, $this->contact_person_email,
            ]))) ?: '—',
        ];
    }

    /** True when there are Travel Details to show: a conference that has any. */
    public function hasTravelDetails(): bool
    {
        return $this->isConference() && ($this->travel_start_date || $this->destination_address || $this->reason_for_travel);
    }

    /**
     * Pay and Claims, LOU and Split Memo are journal arrangements only: the
     * claims list, the Split Memo email and the LOU line all ask these.
     */
    public function usesCostCentre(string $key): bool
    {
        return $this->isJournal() && $this->cost_centre === $key;
    }

    /**
     * "CGS – Pay and Claims", or the student's own words: after "Others: "
     * for a journal, on their own for a conference. Applications filed
     * before the list existed hold free text, shown as is.
     */
    public function costCentreLabel(): string
    {
        if ($this->type_of_request === self::CONFERENCE && $this->cost_centre === self::OTHERS) {
            return $this->cost_centre_other ?: '—';
        }

        if ($this->cost_centre === self::OTHERS) {
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
