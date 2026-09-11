<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationDetail extends Model
{
    protected $fillable = [
        'application_id', 'conference_or_journal_name', 'publication_title',
        'event_date', 'location', 'funding_amount_requested',
        'requires_letter_of_undertaking',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'funding_amount_requested' => 'decimal:2',
            'requires_letter_of_undertaking' => 'boolean',
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
}
