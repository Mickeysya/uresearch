<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpdAppealDetail extends Model
{
    protected $fillable = [
        'application_id', 'candidacy_id', 'requested_months',
        'justification', 'deadline_at_filing', 'new_deadline',
    ];

    protected function casts(): array
    {
        return [
            'deadline_at_filing' => 'date',
            'new_deadline' => 'date',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(Candidacy::class);
    }
}
