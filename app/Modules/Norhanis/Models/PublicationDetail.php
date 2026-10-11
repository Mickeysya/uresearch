<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublicationDetail extends Model
{
    protected $fillable = [
        'application_id', 'type_of_request', 'conference_scope', 'title_of_paper', 'title_of_conference_journal',
        'organizer_publisher', 'venue', 'conference_journal_fee', 'currency_type', 'cost_centre',
        'wants_letter_of_undertaking', 'conference_start_date', 'conference_end_date',
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

    /** The split budget memo, only when the fee is above the CGS cap. */
    public function memo(): HasOne
    {
        return $this->hasOne(PublicationMemo::class);
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
