<?php

namespace App\Modules\Norhanis\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaHourLog extends Model
{
    protected $table = 'ta_hour_logs';

    protected $fillable = [
        'student_id', 'application_id', 'activity_type', 'description', 'activity_date',
        'hours', 'recorded_by', 'approver_role', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'hours' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
