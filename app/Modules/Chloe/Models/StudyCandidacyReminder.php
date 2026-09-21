<?php

namespace App\Modules\Chloe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyCandidacyReminder extends Model
{
    protected $fillable = ['study_candidacy_id', 'reminder_number', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(StudyCandidacy::class, 'study_candidacy_id');
    }
}
