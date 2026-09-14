<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The candidate side of an examiner nomination: what the letters say about
 * the thesis being examined. The examiners themselves are AppointmentExaminer
 * rows, one per panel member.
 */
class AppointmentDetail extends Model
{
    protected $fillable = [
        'application_id', 'candidate_degree', 'candidate_programme',
        'supervisor_name', 'thesis_title', 'letter_prepared_at',
    ];

    protected function casts(): array
    {
        return ['letter_prepared_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function examiners(): HasMany
    {
        return $this->hasMany(AppointmentExaminer::class, 'application_id', 'application_id')
            ->orderByRaw("FIELD(examiner_type, 'internal', 'external')")
            ->orderBy('id');
    }

    public function isPrepared(): bool
    {
        return $this->letter_prepared_at !== null;
    }
}
