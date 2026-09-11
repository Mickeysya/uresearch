<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'student_id', 'period_end', 'sessions_attended', 'sessions_total', 'percentage', 'at_risk',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'percentage' => 'decimal:2',
            'at_risk' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Percentage is always derived from the two counts, never taken
        // as-is from the CSV -- rule 7, "derive, don't trust".
        static::saving(function (self $record) {
            $record->percentage = $record->sessions_total > 0
                ? round(($record->sessions_attended / $record->sessions_total) * 100, 2)
                : 0;
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
