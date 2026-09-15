<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpdDismissalDetail extends Model
{
    protected $fillable = [
        'application_id', 'candidacy_id', 'initiated_by',
        'deadline_missed_on', 'grounds', 'terminated_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline_missed_on' => 'date',
            'terminated_at' => 'datetime',
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

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
