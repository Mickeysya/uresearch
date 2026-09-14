<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationDetail extends Model
{
    protected $fillable = [
        'application_id', 'type_of_request', 'title_of_paper', 'title_of_conference_journal',
        'organizer_publisher', 'conference_journal_fee', 'currency_type', 'cost_centre',
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

    /** @return array<string, string> */
    public static function requestTypes(): array
    {
        return [
            'publication_conference' => 'Conference',
            'publication_journal' => 'Journal',
        ];
    }
}
