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
        'viva_at', 'venue', 'chair_name', 'chair_email',
        'co_supervisor_name', 'co_supervisor_email', 'scheduled_by_id', 'schedule_sequence',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'expected_softbound_date' => 'date',
            'viva_at' => 'datetime',
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
}
